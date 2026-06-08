<?php
require_once __DIR__ . '/../../core/config.php';
/** @var mysqli $conn */

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$profile_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($profile_id <= 0) {
    die("Perfil inválido.");
}

// ========================================
// HELPER DE IMAGEM (igual ao store.php)
// ========================================
function resolveGameCover($url) {
    if (empty($url)) return null;
    if (strpos($url, 'http') === 0) return htmlspecialchars($url);
    $clean = ltrim(str_replace('../', '/', $url), '/');
    return APP_URL . '/' . htmlspecialchars($clean);
}

function profile_time_ago($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return "agora";
    if ($diff < 3600) return round($diff / 60) . " min atrás";
    if ($diff < 86400) return round($diff / 3600) . "h atrás";
    if ($diff < 604800) return round($diff / 86400) . "d atrás";
    return date('d M Y', $time);
}

// ========================================
// BUSCAR DEV
// ========================================
$query_dev = "SELECT * FROM developers WHERE user_id = $profile_id LIMIT 1";
$res_dev   = mysqli_query($conn, $query_dev);

if (!$res_dev || mysqli_num_rows($res_dev) <= 0) {
    die("Desenvolvedor não encontrado.");
}
$dev = mysqli_fetch_assoc($res_dev);

// ========================================
// FOLLOW
// ========================================
$query_follow = "
    SELECT 1 FROM user_follows
    WHERE follower_id = $user_id AND developer_id = $profile_id LIMIT 1
";
$is_following = mysqli_query($conn, $query_follow)->num_rows > 0;

// ========================================
// CONTAGEM DE SEGUIDORES
// ========================================
$followers = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT COUNT(*) as total FROM user_follows WHERE developer_id = $profile_id")
)['total'];

$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username'] ?? 'user');

// ========================================
// POSTS
// ========================================
$query_posts = "
    SELECT cp.*,
           (SELECT COUNT(*) FROM post_likes   pl WHERE pl.post_id = cp.post_id) as total_likes,
           (SELECT COUNT(*) FROM post_comments pc WHERE pc.post_id = cp.post_id) as total_comments,
           (SELECT 1      FROM post_likes      pl WHERE pl.post_id = cp.post_id AND pl.user_id = $user_id LIMIT 1) as is_liked
    FROM community_posts cp
    WHERE cp.developer_id = $profile_id
    ORDER BY cp.created_at DESC
    LIMIT 10
";
$res_posts = mysqli_query($conn, $query_posts);

// ========================================
// JOGOS — mesmo SELECT do store.php
// cover_image_url é o campo correto na tabela games
// ========================================
$query_games = "
    SELECT g.*,
           (SELECT name FROM genres gn
            JOIN game_genres gg ON gn.genre_id = gg.genre_id
            WHERE gg.game_id = g.game_id LIMIT 1) as genre_name
    FROM games g
    WHERE g.developer_id = $profile_id
    ORDER BY g.created_at DESC
";
$res_games   = mysqli_query($conn, $query_games);
$games_count = $res_games ? mysqli_num_rows($res_games) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($dev['studio_name']); ?> - IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- CSS base da comunidade para herdar feed-card-premium, post-media-container, etc. -->
    <link rel="stylesheet" href="../assets/css/community.css?v=2">
    <link rel="stylesheet" href="../assets/css/pfile.css?v=2">
    <style>
        .profile-stats { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; }
        .profile-stats > span { background: none !important; border: none !important; padding: 0 !important; border-radius: 0 !important; box-shadow: none !important; color: #cbd5e1; font-size: 14px; font-weight: 600; }
        .btn-delete-comment { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px; padding: 0 4px; margin-left: auto; opacity: 0.7; transition: opacity 0.2s; }
        .btn-delete-comment:hover { opacity: 1; }
        .comment-header-row { display: flex; justify-content: space-between; align-items: center; width: 100%; }
    </style>
</head>
<body>

<!-- ===================== HERO ===================== -->
<div class="profile-hero">
    <img
        src="<?php echo htmlspecialchars($dev['banner_url'] ?? 'https://images.unsplash.com/photo-1511512578047-dfb367046420?q=80&w=1600'); ?>"
        class="profile-banner"
    >
    <div class="profile-overlay">

        <a href="javascript:history.back()" class="btn-back">← Voltar</a>

        <div class="profile-left">
            <img
                src="<?php echo htmlspecialchars($dev['studio_logo_url'] ?: 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($dev['studio_name'])); ?>"
                class="profile-avatar"
            >
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($dev['studio_name']); ?></h1>
                <p class="profile-bio">
                    <?php echo htmlspecialchars($dev['bio'] ?? 'Estúdio independente criando experiências únicas para jogadores apaixonados.'); ?>
                </p>
                <div class="profile-stats">
                    <span>👥 <span id="followersCount"><?php echo $followers; ?></span> seguidores</span>
                    <span>🎮 <?php echo $games_count; ?> jogos</span>
                </div>
            </div>
        </div>

        <button
            class="btn-follow <?php echo $is_following ? 'following' : ''; ?>"
            id="followBtn"
            data-dev-id="<?php echo $profile_id; ?>"
        >
            <?php echo $is_following ? 'Seguindo' : 'Seguir'; ?>
        </button>

    </div>
</div>

<!-- ===================== CONTEÚDO ===================== -->
<div class="page-wrapper">

    <!-- ---- JOGOS ---- -->
    <h2 class="section-title">Jogos Publicados</h2>

    <?php if ($res_games && mysqli_num_rows($res_games) > 0): ?>
        <div class="games-grid">
            <?php while ($game = mysqli_fetch_assoc($res_games)):

                // Mesmo campo que o store.php: cover_image_url
                // Usa o placeholder por gênero exatamente como o store
                $placeholders = [
                    'Ação'      => 'Placeholder_acao.png',
                    'Arcade'    => 'Placeholder_Arcade.png',
                    'Aventura'  => 'Placeholder_Aventura.png',
                    'Estratégia'=> 'Placeholder_Estrategia.png',
                    'Plataforma'=> 'Placeholder_Plataforma.png',
                    'Puzzle'    => 'Placeholder_Puzzle.png',
                    'RPG'       => 'Placeholder_RPG.png',
                    'Simulador' => 'Placeholder_Simulador.png',
                    'Terror'    => 'Placeholder_terror.png',
                ];

                if (!empty($game['cover_image_url'])) {
                    if (strpos($game['cover_image_url'], 'http') === 0) {
                        $cover_src = htmlspecialchars($game['cover_image_url']);
                    } else {
                        $clean = ltrim(str_replace('../', '/', $game['cover_image_url']), '/');
                        $cover_src = APP_URL . '/' . htmlspecialchars($clean);
                    }
                } else {
                    $placeholder_file = $placeholders[$game['genre_name']] ?? 'Placeholder_Padrao.png';
                    $cover_src = APP_URL . '/assets/img/' . $placeholder_file;
                }
            ?>

                <div class="game-card">
                    <div class="game-thumb-wrapper">
                        <img
                            src="<?php echo $cover_src; ?>"
                            class="game-thumb"
                            alt="<?php echo htmlspecialchars($game['title']); ?>"
                            loading="lazy"
                        >
                        <div class="game-thumb-overlay"></div>
                    </div>
                    <div class="game-content">
                        <h3 class="game-title"><?php echo htmlspecialchars($game['title']); ?></h3>
                        <p class="game-description">
                            <?php echo htmlspecialchars(substr($game['description'] ?? 'Sem descrição.', 0, 120)); ?>...
                        </p>
                        <div class="game-footer">
                            <span class="game-price">
                                <?php echo (isset($game['price']) && $game['price'] > 0)
                                    ? 'R$ ' . number_format($game['price'], 2, ',', '.')
                                    : 'Gratuito';
                                ?>
                            </span>
                            <a href="game_details.php?id=<?php echo $game['game_id']; ?>" class="btn-view">Ver Jogo</a>
                        </div>
                    </div>
                </div>

            <?php endwhile; ?>
        </div>

    <?php else: ?>
        <div class="empty-box">Este estúdio ainda não publicou jogos.</div>
    <?php endif; ?>


    <!-- ---- POSTS ---- -->
    <h2 class="section-title section-title-posts">Últimas Postagens</h2>

    <?php if ($res_posts && mysqli_num_rows($res_posts) > 0): ?>
        <div class="posts-list">

            <?php while ($post = mysqli_fetch_assoc($res_posts)): ?>

                <article class="feed-card-premium" id="post-card-<?php echo $post['post_id']; ?>">

                    <div class="post-header-premium">
                        <div class="dev-info-block">
                            <img
                                src="<?php echo htmlspecialchars($dev['studio_logo_url'] ?: 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($dev['studio_name'])); ?>"
                                class="dev-avatar"
                                alt="Logo do estúdio"
                            >
                            <div class="dev-titles">
                                <span class="dev-name"><?php echo htmlspecialchars($dev['studio_name']); ?></span>
                                <span class="post-time"><?php echo profile_time_ago($post['created_at']); ?></span>
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
                                <img
                                    src="<?php echo htmlspecialchars($post['image_url']); ?>"
                                    alt="Imagem do post"
                                    loading="lazy"
                                >
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
        </div>

    <?php else: ?>
        <div class="empty-box">Este estúdio ainda não fez postagens.</div>
    <?php endif; ?>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ---- FOLLOW ----
    const followBtn = document.getElementById('followBtn');
    if (followBtn) {
        followBtn.addEventListener('click', async function () {
            const devId = this.getAttribute('data-dev-id');
            const isFollowing = this.classList.contains('following');
            try {
                const res  = await fetch('../../src/backend/ajax_follow_dev.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ developer_id: devId })
                });
                const data = await res.json();
                if (data.success) {
                    const span = document.getElementById('followersCount');
                    let n = parseInt(span.innerText);
                    if (isFollowing) { this.classList.remove('following'); this.innerText = 'Seguir';   span.innerText = n - 1; }
                    else             { this.classList.add('following');    this.innerText = 'Seguindo'; span.innerText = n + 1; }
                } else { alert(data.error || 'Erro ao seguir estúdio.'); }
            } catch (e) { console.error(e); alert('Erro de conexão.'); }
        });
    }

    // ---- LIKES ----
    document.querySelectorAll('.like-action').forEach(btn => {
        btn.addEventListener('click', function () {
            const postId = this.getAttribute('data-id');
            const span   = document.getElementById(`like-count-${postId}`);
            const isLiked = this.classList.contains('active-like');
            this.classList.toggle('active-like');
            span.innerText = parseInt(span.innerText) + (isLiked ? -1 : 1);
            fetch('../../src/backend/ajax_like_post.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_id: postId })
            }).catch(e => console.error(e));
        });
    });

    // ---- ABRIR COMENTÁRIOS ----
    document.querySelectorAll('.comment-action').forEach(btn => {
        btn.addEventListener('click', async function () {
            const postId = this.getAttribute('data-id');
            const section = document.getElementById(`comments-section-${postId}`);
            const list    = document.getElementById(`comments-list-${postId}`);
            section.classList.toggle('active');
            if (!section.classList.contains('active')) return;
            document.getElementById(`comment-input-${postId}`).focus();
            if (list.getAttribute('data-loaded') !== 'false') return;
            list.innerHTML = '<p style="color:#64748b;font-size:13px;text-align:center;padding:10px;">Carregando...</p>';
            try {
                const res  = await fetch(`../../src/backend/ajax_get_comments.php?post_id=${postId}`);
                const data = await res.json();
                list.innerHTML = '';
                if (data.success && data.comments.length > 0) {
                    data.comments.forEach(c => {
                        const delBtn = c.can_delete ? `<button class="btn-delete-comment" data-id="${c.id}" data-post-id="${postId}" title="Excluir">🗑️</button>` : '';
                        list.insertAdjacentHTML('beforeend', `
                            <div class="comment-item" id="comment-item-${c.id}">
                                <img src="${c.avatar}" class="comment-avatar">
                                <div class="comment-content">
                                    <div class="comment-header-row">
                                        <h5 class="comment-author">${c.author} <span class="comment-time">${c.time}</span></h5>
                                        ${delBtn}
                                    </div>
                                    <p class="comment-text">${c.content}</p>
                                </div>
                            </div>`);
                    });
                } else {
                    list.innerHTML = '<p style="color:#64748b;font-size:13px;text-align:center;padding:10px;">Seja o primeiro a comentar!</p>';
                }
                list.setAttribute('data-loaded', 'true');
            } catch (e) {
                list.innerHTML = '<p style="color:#ef4444;font-size:13px;text-align:center;">Erro ao carregar comentários.</p>';
            }
        });
    });

    // ---- ENVIAR COMENTÁRIO ----
    document.querySelectorAll('.btn-send-comment').forEach(btn => {
        btn.addEventListener('click', async function () {
            const postId  = this.getAttribute('data-id');
            const input   = document.getElementById(`comment-input-${postId}`);
            const content = input.value.trim();
            const list    = document.getElementById(`comments-list-${postId}`);
            if (!content) return;
            this.disabled = true; this.innerText = '...';
            try {
                const res  = await fetch('../../src/backend/ajax_add_comment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ post_id: postId, content })
                });
                const data = await res.json();
                if (data.success) {
                    if (list.innerHTML.includes('Seja o primeiro a comentar')) list.innerHTML = '';
                    const delBtn = data.comment.can_delete ? `<button class="btn-delete-comment" data-id="${data.comment.id}" data-post-id="${postId}" title="Excluir">🗑️</button>` : '';
                    list.insertAdjacentHTML('beforeend', `
                        <div class="comment-item" id="comment-item-${data.comment.id}">
                            <img src="${data.comment.avatar}" class="comment-avatar">
                            <div class="comment-content">
                                <div class="comment-header-row">
                                    <h5 class="comment-author">${data.comment.author} <span class="comment-time">agora mesmo</span></h5>
                                    ${delBtn}
                                </div>
                                <p class="comment-text">${data.comment.content}</p>
                            </div>
                        </div>`);
                    input.value = '';
                    list.scrollTop = list.scrollHeight;
                    const span = document.getElementById(`comment-count-${postId}`);
                    if (span) span.innerText = parseInt(span.innerText) + 1;
                } else { alert(data.error); }
            } catch (e) { console.error(e); }
            finally { this.disabled = false; this.innerText = 'Enviar'; }
        });
    });

    // ---- EXCLUIR COMENTÁRIO ----
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-delete-comment');
        if (!btn) return;
        if (!confirm("Pretende eliminar este comentário?")) return;
        const commentId = btn.getAttribute('data-id');
        const postId    = btn.getAttribute('data-post-id');
        const item      = document.getElementById(`comment-item-${commentId}`);
        item.style.transition = "opacity 0.3s ease";
        item.style.opacity = "0";
        setTimeout(() => item.remove(), 300);
        const span = document.getElementById(`comment-count-${postId}`);
        if (span) span.innerText = parseInt(span.innerText) - 1;
        fetch('../../src/backend/ajax_delete_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ comment_id: commentId })
        }).catch(e => console.error(e));
    });

});
</script>
</body>
</html>