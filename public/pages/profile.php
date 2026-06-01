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
// BUSCAR DEV
// ========================================

$query_dev = "
    SELECT *
    FROM developers
    WHERE user_id = $profile_id
    LIMIT 1
";

$res_dev = mysqli_query($conn, $query_dev);

if (!$res_dev || mysqli_num_rows($res_dev) <= 0) {
    die("Desenvolvedor não encontrado.");
}

$dev = mysqli_fetch_assoc($res_dev);

// ========================================
// FOLLOW
// ========================================

$query_follow = "
    SELECT 1
    FROM user_follows
    WHERE follower_id = $user_id
    AND developer_id = $profile_id
    LIMIT 1
";

$is_following = mysqli_query($conn, $query_follow)->num_rows > 0;

// ========================================
// CONTAGEM
// ========================================

$query_followers = "
    SELECT COUNT(*) as total
    FROM user_follows
    WHERE developer_id = $profile_id
";

$followers = mysqli_fetch_assoc(
    mysqli_query($conn, $query_followers)
)['total'];

// ========================================
// POSTS
// ========================================

$query_posts = "
    SELECT cp.*,
           (
                SELECT COUNT(*)
                FROM post_likes pl
                WHERE pl.post_id = cp.post_id
           ) as total_likes,

           (
                SELECT COUNT(*)
                FROM post_comments pc
                WHERE pc.post_id = cp.post_id
           ) as total_comments

    FROM community_posts cp
    WHERE cp.developer_id = $profile_id
    ORDER BY cp.created_at DESC
    LIMIT 10
";

$res_posts = mysqli_query($conn, $query_posts);

// ========================================
// JOGOS
// ========================================

$query_games = "
    SELECT *
    FROM games
    WHERE developer_id = $profile_id
    ORDER BY created_at DESC
";

$res_games = mysqli_query($conn, $query_games);

$games_count = $res_games ? mysqli_num_rows($res_games) : 0;
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?php echo htmlspecialchars($dev['studio_name']); ?> - IndieZone
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/pfile.css">
    
</head>
<body>

<div class="profile-hero">

    <img
        src="<?php echo htmlspecialchars($dev['banner_url'] ?? 'https://images.unsplash.com/photo-1511512578047-dfb367046420?q=80&w=1600'); ?>"
        class="profile-banner"
    >

   <div class="profile-overlay">

    <a href="javascript:history.back()" class="btn-back">
        ← Voltar
    </a>

    <div class="profile-left">

        <img
            src="<?php echo htmlspecialchars($dev['studio_logo_url'] ?: 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($dev['studio_name'])); ?>"
            class="profile-avatar"
        >

        <div class="profile-info">

            <h1>
                <?php echo htmlspecialchars($dev['studio_name']); ?>
            </h1>

            <p class="profile-bio">
                <?php echo htmlspecialchars($dev['bio'] ?? 'Estúdio independente criando experiências únicas para jogadores apaixonados.'); ?>
            </p>

            <div class="profile-stats">
                <span>👥 <?php echo $followers; ?> seguidores</span>
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

<div class="page-wrapper">

    <h2 class="section-title">Jogos Publicados</h2>

    <?php if($res_games && mysqli_num_rows($res_games) > 0): ?>

        <div class="games-grid">

            <?php while($game = mysqli_fetch_assoc($res_games)): ?>

                <div class="game-card">

                    <img
                        src="<?php echo htmlspecialchars($game['cover_image'] ?? 'https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=1200'); ?>"
                        class="game-thumb"
                    >

                    <div class="game-content">

                        <h3 class="game-title">
                            <?php echo htmlspecialchars($game['title']); ?>
                        </h3>

                        <p class="game-description">
                            <?php echo htmlspecialchars(substr($game['description'] ?? 'Sem descrição.', 0, 120)); ?>...
                        </p>

                        <div class="game-footer">

                            <span class="game-price">
                                <?php
                                    echo isset($game['price'])
                                    ? 'R$ ' . number_format($game['price'], 2, ',', '.')
                                    : 'Gratuito';
                                ?>
                            </span>

                             <a
                            href="game_details.php?id=<?php echo $game['game_id']; ?>"
                            class="btn-view"
                            >  Ver Jogo
                            </a>

                        </div>

                    </div>

                </div>

            <?php endwhile; ?>

        </div>

    <?php else: ?>

        <div class="empty-box">
            Este estúdio ainda não publicou jogos.
        </div>

    <?php endif; ?>


    <h2 class="section-title">Últimas Postagens</h2>

    <?php if($res_posts && mysqli_num_rows($res_posts) > 0): ?>

        <div class="posts-list">

            <?php while($post = mysqli_fetch_assoc($res_posts)): ?>

                <div class="post-card">

                    <h3 class="post-title">
                        <?php echo htmlspecialchars($post['title']); ?>
                    </h3>

                    <p class="post-content">
                        <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                    </p>

                    <?php if(!empty($post['image_url'])): ?>

                        <img
                            src="<?php echo htmlspecialchars($post['image_url']); ?>"
                            class="post-image"
                        >

                    <?php endif; ?>

                    <div class="post-footer">
                        <span>🔥 <?php echo $post['total_likes']; ?> curtidas</span>
                        <span>💬 <?php echo $post['total_comments']; ?> comentários</span>
                    </div>

                </div>

            <?php endwhile; ?>

        </div>

    <?php else: ?>

        <div class="empty-box">
            Este estúdio ainda não fez postagens.
        </div>

    <?php endif; ?>

</div>

<script>

    const followBtn = document.getElementById('followBtn');

    if (followBtn) {

        followBtn.addEventListener('click', async function() {

            const devId = this.getAttribute('data-dev-id');

            const isFollowing = this.classList.contains('following');

            try {

                const response = await fetch('../../src/backend/ajax_follow_dev.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        developer_id: devId
                    })
                });

                const data = await response.json();

                if (data.success) {

                    const followersCount = document.getElementById('followersCount');

                    let currentCount = parseInt(followersCount.innerText);

                    if (isFollowing) {

                        this.classList.remove('following');

                        this.innerText = 'Seguir';

                        followersCount.innerText = currentCount - 1;

                    } else {

                        this.classList.add('following');

                        this.innerText = 'Seguindo';

                        followersCount.innerText = currentCount + 1;
                    }

                } else {

                    alert(data.error || 'Erro ao seguir estúdio.');

                }

            } catch (error) {

                console.error(error);

                alert('Erro de conexão.');

            }

        });

    }

</script>
