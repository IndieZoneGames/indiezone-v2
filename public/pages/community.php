<?php
// public/pages/community.php
require_once __DIR__ . '/../../core/config.php';
/** @var mysqli $conn */

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// --- CORREÇÃO AQUI: VERIFICA DIRETAMENTE NO BANCO SE É DEV ---
$stmt_check_dev = $conn->prepare("SELECT 1 FROM developers WHERE user_id = ?");
$stmt_check_dev->bind_param("i", $user_id);
$stmt_check_dev->execute();
$is_dev = $stmt_check_dev->get_result()->num_rows > 0;

$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);

// Link de perfil dinâmico no header
$header_profile_link = $is_dev ? "../pages/index.php" : "index.php";

function time_ago($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return "agora";
    if ($diff < 3600) return round($diff / 60) . " min atrás";
    if ($diff < 86400) return round($diff / 3600) . "h atrás";
    if ($diff < 604800) return round($diff / 86400) . "d atrás";
    return date('d M Y', $time);
}

// 1. CAPTURA DE PARÂMETROS
$search = isset($_GET['q']) ? mysqli_real_escape_string($conn, $_GET['q']) : '';
$tab = $_GET['tab'] ?? 'trending';
$single_post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0; 
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// 2. CONSTRUÇÃO DA QUERY
$is_following_tab = ($tab === 'following');

$from_and_joins = "
    FROM community_posts cp
    JOIN developers d ON cp.developer_id = d.user_id
";

$where_clauses = [];

if ($single_post_id > 0) {

    $where_clauses[] = "cp.post_id = $single_post_id";

} else {

    // 🔥 FOLLOWING (SEM QUEBRAR POSTS)
    if ($is_following_tab) {

        $where_clauses[] = "
            cp.developer_id IN (
                SELECT developer_id 
                FROM user_follows 
                WHERE follower_id = $user_id
            )
        ";
    }

    // 🔥 MEUS POSTS
    if ($tab === 'meus_posts' && $is_dev) {
        $where_clauses[] = "cp.developer_id = $user_id";
    }

    // 🔥 TRENDING
    if ($tab === 'trending') {
        $where_clauses[] = "cp.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)";
    }

    // 🔥 SEARCH
    if (!empty($search)) {
        $where_clauses[] = "(cp.title LIKE '%$search%' OR cp.content LIKE '%$search%' OR d.studio_name LIKE '%$search%')";
    }
}

$where_sql = !empty($where_clauses)
    ? " WHERE " . implode(" AND ", $where_clauses)
    : "";

// 3. PAGINAÇÃO
$count_query = "SELECT COUNT(*) as total " . $from_and_joins . $where_sql;
$total_posts = mysqli_query($conn, $count_query)->fetch_assoc()['total'];
$total_pages = ceil($total_posts / $limit);

// 4. QUERY FINAL
$base_query = "
    SELECT cp.*, d.studio_name, d.studio_logo_url,
           (SELECT COUNT(*) FROM post_likes WHERE post_id = cp.post_id) as total_likes,
           (SELECT COUNT(*) FROM post_comments WHERE post_id = cp.post_id) as total_comments,
           (SELECT 1 FROM post_likes WHERE post_id = cp.post_id AND user_id = $user_id) as is_liked
    " . $from_and_joins . $where_sql;

if ($single_post_id === 0 && $tab === 'trending') {
    $base_query .= " ORDER BY (total_likes + total_comments) DESC, cp.created_at DESC";
} else {
    $base_query .= " ORDER BY cp.created_at DESC";
}

$base_query .= " LIMIT $limit OFFSET $offset";
$res_posts = mysqli_query($conn, $base_query);

$res_stats = mysqli_query($conn, "SELECT COUNT(*) as total_posts, COUNT(DISTINCT developer_id) as active_devs FROM community_posts");
$stats = mysqli_fetch_assoc($res_stats);

$query_trending_devs = "
    SELECT * FROM (
        SELECT d.user_id, d.studio_name, d.studio_logo_url, COUNT(uf.follower_id) as followers_count
        FROM developers d
        LEFT JOIN user_follows uf ON d.user_id = uf.developer_id
        WHERE d.user_id NOT IN (SELECT developer_id FROM user_follows WHERE follower_id = $user_id) 
        AND d.user_id != $user_id
        GROUP BY d.user_id
        ORDER BY followers_count DESC
        LIMIT 10
    ) as top_devs
    ORDER BY RAND()
    LIMIT 4
";
$res_trending_devs = mysqli_query($conn, $query_trending_devs);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunidade - IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/store.css">
    <link rel="stylesheet" href="../assets/css/community.css">
    <link rel="stylesheet" href="../assets/css/rodape.css">
    <style>
        .btn-delete-comment { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px; padding: 0 4px; margin-left: auto; opacity: 0.7; transition: opacity 0.2s; }
        .btn-delete-comment:hover { opacity: 1; }
        .comment-header-row { display: flex; justify-content: space-between; align-items: center; width: 100%; }
        .nav-link-clean { color: inherit; text-decoration: none; transition: color 0.2s; }
        .nav-link-clean:hover { color: #22c55e; }
        .nav-img-hover { transition: transform 0.2s, box-shadow 0.2s; }
        .nav-img-hover:hover { transform: scale(1.05); box-shadow: 0 0 10px rgba(34, 197, 94, 0.3); }
        .pagination-container { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); }
        .btn-page { background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.2); color: #22c55e; padding: 10px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.2s; }
        .btn-page:hover { background: #22c55e; color: #000; }
        .page-info { color: #64748b; font-size: 14px; font-weight: 500; }
    </style>
</head>
<body data-theme="dark">

    <header class="store-header">
        <div class="header-left">
            <a href="index.php" class="store-logo">IndieZone</a>
            <form action="community.php" method="GET" class="search-bar">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <span class="search-icon">🔎</span>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Pesquisar posts ou estúdios...">
            </form>
            <a href="store.php" class="btn-nav-header">Loja</a>
            <a href="library.php" class="btn-nav-header">Biblioteca</a>
            <a href="community.php" class="btn-nav-header active" style="color: #22c55e;">Comunidade</a>
        </div>
        <a href="<?php echo $header_profile_link; ?>" class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($primeiro_nome); ?></span>
            <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar" class="user-avatar">
        </a>
    </header>

    <section class="community-hero" id="communityHeroBanner">
        <button class="btn-close-hero" id="closeHeroBtn" title="Ocultar banner">×</button>
        <div class="hero-glow"></div>
        <div class="community-hero-content">
            <h1>Central da Comunidade</h1>
            <p>Conecte-se com os criadores. Descubra os bastidores. Faça parte da jornada.</p>
        </div>
    </section>

    <main class="community-page-wrapper">
        
        <div class="feed-column">
            
            <?php if ($single_post_id === 0): ?>
                <div class="feed-tabs">
                    <a href="?tab=trending<?php echo !empty($search) ? '&q='.$search : ''; ?>" class="tab-link <?php echo $tab === 'trending' ? 'active' : ''; ?>">Em Alta</a>
                    <a href="?tab=geral<?php echo !empty($search) ? '&q='.$search : ''; ?>" class="tab-link <?php echo $tab === 'geral' ? 'active' : ''; ?>">Geral</a>
                    <a href="?tab=following<?php echo !empty($search) ? '&q='.$search : ''; ?>" class="tab-link <?php echo $tab === 'following' ? 'active' : ''; ?>">Seguindo</a>
                    <?php if ($is_dev): ?>
                        <a href="?tab=meus_posts<?php echo !empty($search) ? '&q='.$search : ''; ?>" class="tab-link <?php echo $tab === 'meus_posts' ? 'active' : ''; ?>" style="margin-left: auto;">Meus Posts</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <a href="community.php" style="display: inline-block; margin-bottom: 20px; color: #94a3b8; text-decoration: none; font-weight: 600;">← Voltar para todos os posts</a>
            <?php endif; ?>
      <?php if (!empty($search)): ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 class="search-result-title" style="margin: 0;">
            Resultados para:
            <span>"<?php echo htmlspecialchars($search); ?>"</span>
        </h3>

        <a href="community.php"
           style="color: #ef4444; font-size: 13px; text-decoration: none; font-weight: 600;">
            Limpar Busca ✖
        </a>
    </div>
<?php endif; ?>


<?php if ($tab === 'following'): ?>

    <?php
    $query_following_devs = "
        SELECT d.user_id,
               d.studio_name,
               d.studio_logo_url,
               COUNT(uf2.follower_id) as followers_count
        FROM user_follows uf
        JOIN developers d ON uf.developer_id = d.user_id
        LEFT JOIN user_follows uf2 ON d.user_id = uf2.developer_id
        WHERE uf.follower_id = $user_id
        GROUP BY d.user_id
        ORDER BY d.studio_name ASC
    ";

    $res_following_devs = mysqli_query($conn, $query_following_devs);
    ?>

    <div class="sidebar-widget" style="margin-bottom: 20px;">

        <h3 class="widget-title">Estúdios Seguidos</h3>

        <?php if($res_following_devs && mysqli_num_rows($res_following_devs) > 0): ?>

            <ul class="trending-list">

                <?php while($dev = mysqli_fetch_assoc($res_following_devs)): ?>

                    <li class="trending-item">

                        <a href="profile.php?id=<?php echo $dev['user_id']; ?>">
                            <img
                                src="<?php echo htmlspecialchars(
                                    $dev['studio_logo_url']
                                    ?: 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($dev['studio_name'])
                                ); ?>"
                                class="t-avatar nav-img-hover"
                                alt="Avatar"
                            >
                        </a>

                        <div class="t-info">
                            <h4>
                                <a href="profile.php?id=<?php echo $dev['user_id']; ?>" class="nav-link-clean">
                                    <?php echo htmlspecialchars($dev['studio_name']); ?>
                                </a>
                            </h4>

                            <span>
                                <?php echo $dev['followers_count']; ?> seguidores
                            </span>
                        </div>

                        <button
                            class="btn-follow-small follow-action following"
                            data-dev-id="<?php echo $dev['user_id']; ?>"
                        >
                            Seguindo
                        </button>

                    </li>

                <?php endwhile; ?>

            </ul>

        <?php else: ?>

            <p class="widget-text">
                Você ainda não segue nenhum estúdio.
            </p>

        <?php endif; ?>

    </div>

<?php endif; ?>
            <?php if($tab !== 'following' && $res_posts && mysqli_num_rows($res_posts) > 0): ?>
                <?php while($post = mysqli_fetch_assoc($res_posts)): ?>
                    
                    <article class="feed-card-premium" id="post-card-<?php echo $post['post_id']; ?>">
                        
                        <div class="post-header-premium">
                            <div class="dev-info-block">
                                <a href="profile.php?id=<?php echo $post['developer_id']; ?>">
                                    <img src="<?php echo htmlspecialchars($post['studio_logo_url'] ?: 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($post['studio_name'])); ?>" alt="Logo" class="dev-avatar nav-img-hover">
                                </a>
                                <div class="dev-titles">
                                    <h3 class="dev-name">
                                        <a href="profile.php?id=<?php echo $post['developer_id']; ?>" class="nav-link-clean">
                                            <?php echo htmlspecialchars($post['studio_name']); ?>
                                        </a>
                                    </h3>
                                    <span class="post-time"><?php echo time_ago($post['created_at']); ?></span>
                                </div>
                            </div>
                            
                            <div class="post-options-wrapper">
                                <button class="btn-options toggle-menu-btn" data-id="<?php echo $post['post_id']; ?>">⋮</button>
                                <div class="options-dropdown" id="dropdown-<?php echo $post['post_id']; ?>">
                                    <button class="dropdown-item btn-copy-link" data-id="<?php echo $post['post_id']; ?>">Copiar Link</button>
                                    
                                    <?php if ($user_id == $post['developer_id']): ?>
                                        <a href="../dashboard/edit_post.php?id=<?php echo $post['post_id']; ?>" class="dropdown-item nav-link-clean" style="display: block;">Editar Postagem</a>
                                        <button class="dropdown-item text-danger btn-delete-post" data-id="<?php echo $post['post_id']; ?>">Excluir Postagem</button>
                                    <?php else: ?>
                                        <button class="dropdown-item text-danger" onclick="alert('Denúncia enviada!')">Denunciar</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="post-body-premium">
                            <h4 class="post-title"><?php echo htmlspecialchars($post['title']); ?></h4>
                            <div class="post-text-content">
                                <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                            </div>
                            
                            <?php if (!empty($post['image_url'])): ?>
                                <div class="post-media-container">
                                    <img src="<?php echo htmlspecialchars($post['image_url']); ?>" alt="Anexo" loading="lazy">
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="post-stats-bar">
                            <span>🔥 <span id="like-count-<?php echo $post['post_id']; ?>"><?php echo $post['total_likes']; ?></span> curtidas</span>
                            <span><span id="comment-count-<?php echo $post['post_id']; ?>"><?php echo $post['total_comments']; ?></span> comentários</span>
                        </div>

                        <div class="post-actions-premium">
                            <button class="action-btn like-action <?php echo $post['is_liked'] ? 'active-like' : ''; ?>" data-id="<?php echo $post['post_id']; ?>">
                                <span class="action-icon">👍</span> Curtir
                            </button>
                            <button class="action-btn comment-action" data-id="<?php echo $post['post_id']; ?>">
                                <span class="action-icon">💬</span> Comentar
                            </button>
                        </div>

                        <div class="comments-section" id="comments-section-<?php echo $post['post_id']; ?>">
                            <div class="comments-list" id="comments-list-<?php echo $post['post_id']; ?>" data-loaded="false"></div>

                            <div class="comment-input-wrapper">
                                <img src="<?php echo htmlspecialchars($avatar_url); ?>" class="comment-avatar" alt="Seu Avatar">
                                <textarea class="comment-input" id="comment-input-<?php echo $post['post_id']; ?>" placeholder="Adicione um comentário..." rows="1"></textarea>
                                <button class="btn-send-comment" data-id="<?php echo $post['post_id']; ?>">Enviar</button>
                            </div>
                        </div>

                    </article>

                <?php endwhile; ?>
                
                <?php if ($total_pages > 1 && $single_post_id === 0): ?>
                    <?php 
                        $url_base = "?tab=" . urlencode($tab);
                        if (!empty($search)) $url_base .= "&q=" . urlencode($search);
                    ?>
                    <div class="pagination-container">
                        <div>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo $url_base . "&page=" . ($page - 1); ?>" class="btn-page">← Anterior</a>
                            <?php endif; ?>
                        </div>
                        <span class="page-info">Página <?php echo $page; ?> de <?php echo $total_pages; ?></span>
                        <div>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo $url_base . "&page=" . ($page + 1); ?>" class="btn-page">Próxima →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

           <?php elseif($tab !== 'following'): ?>
                <div class="empty-feed-premium">
                    <div class="empty-icon">🏜️</div>
                    <h2>Nenhum post encontrado.</h2>
                    <p>
                        <?php 
                            if ($single_post_id > 0) echo "Esta postagem não existe ou foi excluída.";
                            elseif ($tab === 'following') echo "Você ainda não segue nenhum estúdio.";
                            elseif ($tab === 'meus_posts') echo "Você ainda não publicou nenhuma novidade para a comunidade.";
                            elseif ($tab === 'trending') echo "Ainda não existem postagens recentes em alta. Volte em breve!";
                            else echo "Ainda não existem publicações na comunidade."; 
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <aside class="sidebar-column">
            
            <div class="sidebar-widget">
                <h3 class="widget-title">Sobre a Comunidade</h3>
                <p class="widget-text">Interaja diretamente com os criadores da IndieZone. Descubra novidades exclusivas e apoie seus estúdios favoritos.</p>
                <div class="widget-stats">
                    <div class="w-stat"><strong><?php echo $stats['active_devs']; ?></strong><span>Devs Ativos</span></div>
                    <div class="w-stat"><strong><?php echo $stats['total_posts']; ?></strong><span>Postagens</span></div>
                </div>
            </div>

            <div class="sidebar-widget">
                <h3 class="widget-title">Descubra Estúdios</h3>
                <?php if($res_trending_devs && mysqli_num_rows($res_trending_devs) > 0): ?>
                    <ul class="trending-list">
                        <?php while($dev = mysqli_fetch_assoc($res_trending_devs)): ?>
                            <li class="trending-item">
                                <a href="profile.php?id=<?php echo $dev['user_id']; ?>">
                                    <img src="<?php echo htmlspecialchars($dev['studio_logo_url'] ?: 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($dev['studio_name'])); ?>" class="t-avatar nav-img-hover" alt="Avatar">
                                </a>
                                <div class="t-info">
                                    <h4>
                                        <a href="profile.php?id=<?php echo $dev['user_id']; ?>" class="nav-link-clean">
                                            <?php echo htmlspecialchars($dev['studio_name']); ?>
                                        </a>
                                    </h4>
                                    <span><span id="follower-count-<?php echo $dev['user_id']; ?>"><?php echo $dev['followers_count']; ?></span> seguidores</span>
                                </div>
                                <button class="btn-follow-small follow-action" data-dev-id="<?php echo $dev['user_id']; ?>">Seguir</button>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php elseif($tab !== 'following'): ?>
                    <p class="widget-text" style="text-align: center;">Você já segue todos os principais estúdios!</p>
                <?php endif; ?>
            </div>

            <div class="sidebar-footer">
                <a href="#">Termos</a> • <a href="#">Regras</a><br>
                <span>© <?php echo date('Y'); ?> IndieZone Brasil</span>
            </div>

        </aside>

    </main>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
        
            const heroBanner = document.getElementById("communityHeroBanner");
            const closeBtn = document.getElementById("closeHeroBtn");
            if (localStorage.getItem("hideIndieZoneCommunityHero") === "true") { heroBanner.style.display = "none"; }
            if (closeBtn) {
                closeBtn.addEventListener("click", function() {
                    heroBanner.style.display = "none";
                    localStorage.setItem("hideIndieZoneCommunityHero", "true");
                });
            }

            document.querySelectorAll('.like-action').forEach(button => {
                button.addEventListener('click', function() {
                    const postId = this.getAttribute('data-id');
                    const countSpan = document.getElementById(`like-count-${postId}`);
                    let currentCount = parseInt(countSpan.innerText);
                    const isLiked = this.classList.contains('active-like');

                    if (isLiked) {
                        this.classList.remove('active-like');
                        countSpan.innerText = currentCount - 1; 
                    } else {
                        this.classList.add('active-like');
                        countSpan.innerText = currentCount + 1; 
                    }
                    
                    fetch('../../src/backend/ajax_like_post.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ post_id: postId })
                    }).catch(e => console.error('Erro')); 
                });
            });

            document.querySelectorAll('.follow-action').forEach(button => {
                button.addEventListener('click', function() {
                    const devId = this.getAttribute('data-dev-id');
                    const countSpan = document.getElementById(`follower-count-${devId}`);
                    let currentCount = countSpan ? parseInt(countSpan.innerText) : 0;
                    const isFollowing = this.classList.contains('following');

                    if (isFollowing) {
                        this.classList.remove('following');
                        this.innerText = 'Seguir';
                        if (countSpan) countSpan.innerText = currentCount - 1;
                    } else {
                        this.classList.add('following');
                        this.innerText = 'Seguindo';
                        if (countSpan) countSpan.innerText = currentCount + 1;
                    }
                    
                    fetch('../../src/backend/ajax_follow_dev.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ developer_id: devId })
                    }).catch(e => console.error('Erro'));
                });
            });

            document.querySelectorAll('.comment-action').forEach(button => {
                button.addEventListener('click', async function() {
                    const postId = this.getAttribute('data-id');
                    const commentsSection = document.getElementById(`comments-section-${postId}`);
                    const commentsList = document.getElementById(`comments-list-${postId}`);
                    
                    commentsSection.classList.toggle('active');
                    
                    if (commentsSection.classList.contains('active')) {
                        document.getElementById(`comment-input-${postId}`).focus();
                        
                        if (commentsList.getAttribute('data-loaded') === 'false') {
                            commentsList.innerHTML = '<p style="color: #64748b; font-size: 13px; text-align: center; padding: 10px;">Carregando...</p>';
                            
                            try {
                                const response = await fetch(`../../src/backend/ajax_get_comments.php?post_id=${postId}`);
                                const data = await response.json();
                                
                                commentsList.innerHTML = ''; 
                                
                                if (data.success && data.comments.length > 0) {
                                    data.comments.forEach(c => {
                                        const deleteBtn = c.can_delete ? `<button class="btn-delete-comment" data-id="${c.id}" data-post-id="${postId}" title="Excluir Comentário">🗑️</button>` : '';
                                        commentsList.insertAdjacentHTML('beforeend', `
                                            <div class="comment-item" id="comment-item-${c.id}">
                                                <img src="${c.avatar}" class="comment-avatar">
                                                <div class="comment-content">
                                                    <div class="comment-header-row">
                                                        <h5 class="comment-author">${c.author} <span class="comment-time">${c.time}</span></h5>
                                                        ${deleteBtn}
                                                    </div>
                                                    <p class="comment-text">${c.content}</p>
                                                </div>
                                            </div>
                                        `);
                                    });
                                } else {
                                    commentsList.innerHTML = '<p style="color: #64748b; font-size: 13px; text-align: center; padding: 10px;">Seja o primeiro a comentar!</p>';
                                }
                                commentsList.setAttribute('data-loaded', 'true');
                            } catch (e) {
                                commentsList.innerHTML = '<p style="color: #ef4444; font-size: 13px; text-align: center;">Erro ao carregar comentários.</p>';
                            }
                        }
                    }
                });
            });

            document.querySelectorAll('.btn-send-comment').forEach(button => {
                button.addEventListener('click', async function() {
                    const postId = this.getAttribute('data-id');
                    const input = document.getElementById(`comment-input-${postId}`);
                    const content = input.value.trim();
                    const commentsList = document.getElementById(`comments-list-${postId}`);
                    
                    if (!content) return;
                    
                    this.disabled = true;
                    this.innerText = '...';
                    
                    try {
                        const response = await fetch('../../src/backend/ajax_add_comment.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ post_id: postId, content: content })
                        });
                        const data = await response.json();
                        
                        if (data.success) {
                            if (commentsList.innerHTML.includes('Seja o primeiro a comentar')) {
                                commentsList.innerHTML = '';
                            }

                            const deleteBtn = data.comment.can_delete ? `<button class="btn-delete-comment" data-id="${data.comment.id}" data-post-id="${postId}" title="Excluir Comentário">🗑️</button>` : '';
                            
                            const newCommentHTML = `
                                <div class="comment-item" id="comment-item-${data.comment.id}">
                                    <img src="${data.comment.avatar}" class="comment-avatar">
                                    <div class="comment-content">
                                        <div class="comment-header-row">
                                            <h5 class="comment-author">${data.comment.author} <span class="comment-time">agora mesmo</span></h5>
                                            ${deleteBtn}
                                        </div>
                                        <p class="comment-text">${data.comment.content}</p>
                                    </div>
                                </div>
                            `;
                            commentsList.insertAdjacentHTML('beforeend', newCommentHTML);
                            input.value = '';
                            commentsList.scrollTop = commentsList.scrollHeight;
                            
                            const countSpan = document.getElementById(`comment-count-${postId}`);
                            if (countSpan) countSpan.innerText = parseInt(countSpan.innerText) + 1;
                        } else { alert(data.error); }
                    } catch (e) { console.error('Erro'); } finally {
                        this.disabled = false;
                        this.innerText = 'Enviar';
                    }
                });
            });

            document.addEventListener('click', function(e) {
                if (e.target.closest('.btn-delete-comment')) {
                    const btn = e.target.closest('.btn-delete-comment');
                    if (!confirm("Pretende eliminar este comentário?")) return;
                    
                    const commentId = btn.getAttribute('data-id');
                    const postId = btn.getAttribute('data-post-id');
                    const commentItem = document.getElementById(`comment-item-${commentId}`);
                    
                    commentItem.style.transition = "opacity 0.3s ease";
                    commentItem.style.opacity = "0";
                    setTimeout(() => commentItem.remove(), 300);

                    const countSpan = document.getElementById(`comment-count-${postId}`);
                    if (countSpan) countSpan.innerText = parseInt(countSpan.innerText) - 1;

                    fetch('../../src/backend/ajax_delete_comment.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ comment_id: commentId })
                    }).catch(e => console.error('Erro ao excluir comentário'));
                }
            });

            document.querySelectorAll('.toggle-menu-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation(); 
                    document.querySelectorAll('.options-dropdown').forEach(d => d.classList.remove('active'));
                    const id = this.getAttribute('data-id');
                    document.getElementById(`dropdown-${id}`).classList.toggle('active');
                });
            });

            document.addEventListener('click', () => {
                document.querySelectorAll('.options-dropdown').forEach(d => d.classList.remove('active'));
            });

            document.querySelectorAll('.btn-copy-link').forEach(btn => {
                btn.addEventListener('click', function() {
                    const postId = this.getAttribute('data-id');
                    const baseUrl = window.location.origin + window.location.pathname;
                    const dynamicLink = baseUrl + "?post_id=" + postId;

                    navigator.clipboard.writeText(dynamicLink).then(() => {
                        const originalText = this.innerText;
                        this.innerText = 'Link Copiado!';
                        setTimeout(() => { this.innerText = originalText; }, 2000);
                    });
                });
            });

            document.querySelectorAll('.btn-delete-post').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (!confirm("Tem certeza que deseja excluir esta postagem? Ela sumirá para sempre.")) return;
                    
                    const postId = this.getAttribute('data-id');
                    const postCard = document.getElementById(`post-card-${postId}`);
                    
                    postCard.style.transition = "opacity 0.3s ease";
                    postCard.style.opacity = "0";
                    setTimeout(() => postCard.remove(), 300);

                    fetch('../../src/backend/ajax_delete_post.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ post_id: postId })
                    }).catch(e => console.error('Erro'));
                });
            });
        });
    </script>
    
    <?php require_once __DIR__ . '/../partials/rodape.php'; ?>
</body>
</html>