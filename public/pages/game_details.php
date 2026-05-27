<?php
// public/pages/game_details.php
require_once __DIR__ . '/../../core/config.php';
/** @var mysqli $conn */
session_start();

// Função auxiliar para resolver URLs de imagem
function resolve_img_url(string $url): string {
    if (empty($url)) return '';
    if (strpos($url, 'http') === 0) return htmlspecialchars($url);
    // Remove ../ e monta caminho absoluto
    $clean = ltrim(str_replace('../', '/', $url), '/');
    return APP_URL . '/' . $clean;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$game_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($game_id <= 0) {
    header("Location: store.php");
    exit();
}

// 1. Busca os detalhes estruturais do Jogo
$query = "SELECT g.*, u.username as dev_name, gen.name as genre_name 
          FROM games g 
          JOIN users u ON g.developer_id = u.user_id 
          LEFT JOIN game_genres gg ON g.game_id = gg.game_id
          LEFT JOIN genres gen ON gg.genre_id = gen.genre_id
          WHERE g.game_id = $game_id AND g.status = 'published' LIMIT 1";
$result = mysqli_query($conn, $query);
$game = mysqli_fetch_assoc($result);
if (!$game) {
    header("Location: store.php");
    exit();
}

// 2. Busca o Trailer e a Galeria de Imagens
$media_query = "SELECT media_type, media_url FROM game_media WHERE game_id = $game_id ORDER BY display_order ASC";
$media_result = mysqli_query($conn, $media_query);
$trailer_url = null;
$screenshots = [];
while ($media = mysqli_fetch_assoc($media_result)) {
    if ($media['media_type'] == 'trailer') {
        preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i', $media['media_url'], $match);
        if (isset($match[1])) $trailer_url = "https://www.youtube.com/embed/" . $match[1] . "?autoplay=0&rel=0";
    } else {
        $screenshots[] = $media['media_url'];
    }
}

// 3. Mapeia Plataformas Ativas e Versões Disponíveis para Download
$builds_query = mysqli_query($conn, "SELECT * FROM game_builds WHERE game_id = $game_id AND is_active = 1 ORDER BY created_at DESC");
$builds = [];
$platforms = [];
while ($row = mysqli_fetch_assoc($builds_query)) {
    $builds[] = $row;
    if (!in_array($row['platform_os'], $platforms)) {
        $platforms[] = $row['platform_os'];
    }
}

$stage_labels = [
    'alpha' => ['label' => 'Alpha', 'color' => '#ef4444'],
    'beta' => ['label' => 'Beta', 'color' => '#f59e0b'],
    'early_access' => ['label' => 'Acesso Antecipado', 'color' => '#3b82f6'],
    'full_release' => ['label' => 'Versão Final', 'color' => '#22c55e']
];
$current_stage = $stage_labels[$game['release_stage']] ?? $stage_labels['full_release'];

// 4. Estatísticas de Avaliação e Primeira Página da Listagem (Limite de 10 itens)
$stats_query = mysqli_query($conn, "SELECT COUNT(*) as total_reviews, AVG(rating) as avg_rating FROM reviews WHERE game_id = $game_id");
$stats = mysqli_fetch_assoc($stats_query);
$total_reviews = $stats['total_reviews'] ?: 0;
$avg_rating = $stats['avg_rating'] ? number_format($stats['avg_rating'], 1) : 0;

$reviews_query = "SELECT r.*, u.username, u.display_name, u.avatar_url,
                  (SELECT COUNT(*) FROM review_votes rv WHERE rv.review_id = r.review_id) as helpful_count,
                  (SELECT 1 FROM review_votes rv2 WHERE rv2.review_id = r.review_id AND rv2.user_id = $user_id) as user_voted
                  FROM reviews r JOIN users u ON r.user_id = u.user_id 
                  WHERE r.game_id = $game_id 
                  ORDER BY helpful_count DESC, r.created_at DESC LIMIT 10";
$reviews_result = mysqli_query($conn, $reviews_query);

// 5. Validação de Vínculo com a Conta do Usuário Logado
$owns_game = mysqli_query($conn, "SELECT 1 FROM library WHERE user_id = $user_id AND game_id = $game_id")->num_rows > 0;
$my_review_query = mysqli_query($conn, "SELECT rating, comment FROM reviews WHERE user_id = $user_id AND game_id = $game_id");
$my_review = mysqli_fetch_assoc($my_review_query);
$has_reviewed = $my_review ? true : false;

$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($game['title']); ?> - IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/store.css">
    <link rel="stylesheet" href="../assets/css/game_details.css">
</head>
<body>

    <header class="store-header">
        <div class="header-left">
            <a href="store.php" class="store-logo">IndieZone</a>
            <form action="store.php" method="GET" class="search-bar">
                <span class="search-icon">🔎</span>
                <input type="text" name="q" placeholder="Pesquisar jogos na loja...">
            </form>
            <a href="store.php" class="btn-nav-header active">Loja</a>
            <a href="library.php" class="btn-nav-header">Biblioteca</a>
            <a href="community.php" class="btn-nav-header">Comunidade</a>
        </div>
        <a href="index.php" class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($primeiro_nome); ?></span>
            <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar" class="user-avatar">
        </a>
    </header>

    <main class="details-container">
        
        <section class="game-main-info">
            <div class="media-gallery">
                <div class="main-media-view" id="main-media-container">
                    <?php if ($trailer_url): ?>
                        <iframe id="main-media" src="<?php echo $trailer_url; ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                    <?php elseif (!empty($screenshots)): ?>
                        <img id="main-media" src="<?php echo htmlspecialchars($screenshots[0]); ?>" alt="Screenshot principal">
                    <?php else: ?>
                        <img id="main-media" src="<?php echo resolve_img_url($game['cover_image_url']); ?>" alt="Capa do Jogo">
<img src="<?php echo resolve_img_url($game['cover_image_url']); ?>" class="thumbnail active" ...>
                    <?php endif; ?>
                </div>

                <div class="thumbnails-container">
                    <?php if ($trailer_url): ?>
                        <div class="thumbnail-video">
                            <img src="<?php echo htmlspecialchars($game['cover_image_url']); ?>" class="thumbnail active" onclick="changeMedia('iframe', '<?php echo $trailer_url; ?>', this)" alt="Trailer">
                        </div>
                    <?php endif; ?>
                    <?php 
                    $is_first = !$trailer_url; 
                    foreach ($screenshots as $index => $img_url): ?>
                        <img src="<?php echo htmlspecialchars($img_url); ?>" class="thumbnail <?php echo ($is_first && $index === 0) ? 'active' : ''; ?>" onclick="changeMedia('img', '<?php echo htmlspecialchars($img_url); ?>', this)" alt="Screenshot <?php echo $index + 1; ?>">
                    <?php endforeach; ?>
                </div>
            </div>

            <span class="game-genre-tag"><?php echo htmlspecialchars($game['genre_name'] ?? 'Indie'); ?></span>
            <h1 class="game-title-large"><?php echo htmlspecialchars($game['title']); ?></h1>
            
            <div class="review-summary">
                <div class="stars-display">
                    <?php
                    for ($i = 1; $i <= 5; $i++) {
                        $color = $i <= round($avg_rating) ? '#f59e0b' : '#334155';
                        echo '<svg width="18" height="18" fill="'.$color.'" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
                    }
                    ?>
                </div>
                <span class="review-text-summary">
                    <?php echo $total_reviews > 0 ? "<b>{$avg_rating}/5</b> baseado em {$total_reviews} avaliações" : "Nenhuma avaliação ainda"; ?>
                </span>
            </div>

            <div class="game-description">
                <?php echo nl2br(htmlspecialchars($game['description'])); ?>
            </div>
        </section>

        <aside class="purchase-side">
            <div class="purchase-card">
                
                <?php if ($owns_game): ?>
                    <span class="price-big">Na sua Biblioteca</span>
                    
                    <?php if (!empty($builds)): ?>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.85rem; color: var(--text-dim); margin-bottom: 8px;">Selecione a versão para baixar:</label>
                            <select id="download-selector" class="download-select">
                                <?php foreach ($builds as $b): ?>
                                    <option value="<?php echo htmlspecialchars($b['drive_file_id']); ?>">
                                        <?php echo ucfirst($b['platform_os']); ?> - <?php echo htmlspecialchars($b['version_name']); ?> 
                                        (<?php echo number_format($b['file_size_bytes'] / 1048576, 1); ?> MB)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button onclick="downloadSelected()" class="btn-checkout">⬇ Baixar Jogo</button>
                    <?php else: ?>
                        <button class="btn-checkout" style="background: var(--surface-hover); color: var(--text-dim); cursor: default;">Nenhum arquivo disponível</button>
                    <?php endif; ?>

                <?php elseif ($game['is_pwyw'] == 1): ?>
                    <span class="price-big">Apoie o Projeto</span>
                    <div class="pwyw-container">
                        <span class="pwyw-currency">R$</span>
                        <input type="number" id="pwyw-amount" class="pwyw-input" min="<?php echo $game['min_price']; ?>" step="0.50" value="<?php echo number_format($game['min_price'], 2, '.', ''); ?>">
                    </div>
                    <?php if ($game['min_price'] > 0): ?>
                        <span class="pwyw-min-notice">Mínimo sugerido: R$ <?php echo number_format($game['min_price'], 2, ',', '.'); ?></span>
                    <?php endif; ?>
                    <button onclick="goToCheckoutPWYW(<?php echo $game['game_id']; ?>)" class="btn-checkout">Contribuir e Obter Jogo</button>
                    
                <?php elseif ($game['price'] == 0): ?>
                    <span class="price-big">Gratuito</span>
                    <a href="checkout.php?game_id=<?php echo $game['game_id']; ?>" class="btn-checkout">Adicionar à Conta</a>
                    
                <?php else: ?>
                    <span class="price-big">R$ <?php echo number_format($game['price'], 2, ',', '.'); ?></span>
                    <a href="checkout.php?game_id=<?php echo $game['game_id']; ?>" class="btn-checkout">Comprar Agora</a>
                <?php endif; ?>

                <div class="game-meta-list">
                    <div class="meta-item">
                        <span class="meta-label">Estágio</span>
                        <span class="meta-value stage-badge" style="background: <?php echo $current_stage['color']; ?>"><?php echo $current_stage['label']; ?></span>
                    </div>

                    <div class="meta-item">
                        <span class="meta-label">Plataformas</span>
                        <span class="meta-value">
                            <?php 
                            if (empty($platforms)) {
                                echo '<span style="color: var(--text-dim); font-size: 0.8rem;">Em breve</span>';
                            } else {
                                $plat_icons = ['windows' => '🪟', 'linux' => '🐧', 'mac' => '🍎', 'android' => '📱', 'web' => '🌐'];
                                foreach ($platforms as $p) echo '<span class="platform-icon" title="'.ucfirst($p).'">' . ($plat_icons[$p] ?? $p) . '</span>';
                            }
                            ?>
                        </span>
                    </div>

                    <div class="meta-item"><span class="meta-label">Desenvolvedor</span><span class="meta-value"><?php echo htmlspecialchars($game['dev_name']); ?></span></div>
                    <div class="meta-item"><span class="meta-label">Lançamento</span><span class="meta-value"><?php echo date('d/m/Y', strtotime($game['created_at'])); ?></span></div>
                </div>
            </div>
        </aside>

        <!-- Jogos Similares — ocupa toda a largura do grid -->
        <div style="grid-column: 1 / -1; padding: 0 0 8px;">
            <?php require_once __DIR__ . '/../../scripts/widget/similares.php'; ?>
        </div>

        <section class="reviews-section" style="grid-column: 1 / -1;">
            <h2 class="reviews-title">Avaliações da Comunidade</h2>

            <?php if ($owns_game): ?>
                <div class="review-form-card" id="review-form-container">
                    <h3 id="form-title" style="margin-bottom: 15px; color: #fff;"><?php echo $has_reviewed ? 'Editar sua avaliação' : 'Deixe sua avaliação'; ?></h3>
                    <div class="msg-subtle msg-success-subtle" id="review-success" style="display: none; text-align: left;"></div>
                    
                    <form id="form-review">
                        <div class="star-rating-input">
                            <?php 
                            $curr_rating = $has_reviewed ? $my_review['rating'] : 5;
                            for($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="star<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" <?php echo $curr_rating == $i ? 'checked' : ''; ?>>
                                <label for="star<?php echo $i; ?>" title="<?php echo $i; ?> estrelas">★</label>
                            <?php endfor; ?>
                        </div>
                        <textarea name="comment" class="review-textarea" placeholder="O que você achou do jogo? Conte para a comunidade..." required><?php echo $has_reviewed ? htmlspecialchars($my_review['comment']) : ''; ?></textarea>
                        <div id="review-error" style="color: #ef4444; font-size: 14px; margin-bottom: 10px; display: none;"></div>
                        <button type="submit" id="btn-submit-review" class="btn" style="width: auto; padding: 10px 25px;">
                            <?php echo $has_reviewed ? 'Atualizar Avaliação' : 'Publicar Avaliação'; ?>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="msg-subtle" style="background: rgba(255,255,255,0.05); text-align: left; margin-bottom: 30px;">
                    Você precisa adquirir este jogo para publicar uma avaliação.
                </div>
            <?php endif; ?>

            <div class="review-list" id="reviews-list">
                <?php if (mysqli_num_rows($reviews_result) > 0): ?>
                    <?php while ($rev = mysqli_fetch_assoc($reviews_result)): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="review-header-left">
                                    <img src="<?php echo htmlspecialchars($rev['avatar_url']); ?>" alt="Avatar" class="review-avatar">
                                    <div>
                                        <span class="review-author"><?php echo htmlspecialchars($rev['display_name'] ?: $rev['username']); ?></span>
                                        <div class="stars-display" style="margin-top: 4px;">
                                            <?php
                                            for ($i = 1; $i <= 5; $i++) {
                                                $color = $i <= $rev['rating'] ? '#f59e0b' : '#334155';
                                                echo '<svg width="14" height="14" fill="'.$color.'" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
                                            }
                                            ?>
                                            <span class="review-date" style="margin-left: 8px;">
                                                <?php 
                                                echo date('d/m/Y', strtotime($rev['created_at']));
                                                if($rev['updated_at'] != $rev['created_at']) echo " (Editado)";
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="review-header-right">
                                    <button onclick="toggleHelpful(<?php echo $rev['review_id']; ?>, this)" class="btn-helpful <?php echo $rev['user_voted'] ? 'voted' : ''; ?>" title="Marcar como útil">
                                        👍 <span class="helpful-count"><?php echo $rev['helpful_count']; ?></span>
                                    </button>
                                </div>
                            </div>
                            <div class="review-content"><?php echo nl2br(htmlspecialchars($rev['comment'])); ?></div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p id="no-reviews-msg" style="color: var(--text-dim);">Ainda não há avaliações para este jogo.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($total_reviews > 10): ?>
                <button id="btn-load-more" onclick="loadMoreReviews()" class="btn-load-more">Ver mais avaliações</button>
            <?php endif; ?>

        </section>
    </main>
         

    <script>
        function downloadSelected() {
            const fileId = document.getElementById('download-selector').value;
            if (fileId) {
                window.open(`https://drive.google.com/file/d/${fileId}/view?usp=drivesdk`, '_blank');
            }
        }

        let reviewOffset = 10;
        async function loadMoreReviews() {
            const btn = document.getElementById('btn-load-more');
            const originalText = btn.innerText;
            btn.innerText = 'Carregando...';
            
            try {
                const response = await fetch(`../../src/backend/ajax_load_reviews.php?game_id=<?php echo $game_id; ?>&offset=${reviewOffset}`);
                const html = await response.text();
                
                if (html.trim() !== '') {
                    document.getElementById('reviews-list').insertAdjacentHTML('beforeend', html);
                    reviewOffset += 10;
                    btn.innerText = originalText;
                } else {
                    btn.style.display = 'none';
                }
            } catch (error) {
                console.error("Erro ao carregar mais avaliações:", error);
                btn.innerText = "Erro ao carregar";
                setTimeout(() => btn.innerText = originalText, 2000);
            }
        }

        function goToCheckoutPWYW(gameId) {
            const amount = document.getElementById('pwyw-amount').value;
            window.location.href = `checkout.php?game_id=${gameId}&amount=${amount}`;
        }

        function changeMedia(type, url, element) {
            const container = document.getElementById('main-media-container');
            document.querySelectorAll('.thumbnail').forEach(el => el.classList.remove('active'));
            element.classList.add('active');
            if (type === 'iframe') {
                container.innerHTML = `<iframe id="main-media" src="${url}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>`;
            } else {
                container.innerHTML = `<img id="main-media" src="${url}" alt="Screenshot">`;
            }
        }

        async function toggleHelpful(reviewId, btnElement) {
            const countSpan = btnElement.querySelector('.helpful-count');
            let currentCount = parseInt(countSpan.innerText);
            const isVoted = btnElement.classList.contains('voted');

            if (isVoted) {
                btnElement.classList.remove('voted');
                countSpan.innerText = currentCount - 1;
            } else {
                btnElement.classList.add('voted');
                countSpan.innerText = currentCount + 1;
            }

            try {
                const response = await fetch('../../src/backend/ajax_vote_review.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ review_id: reviewId })
                });
                const result = await response.json();
                
                if (!result.success) {
                    if (isVoted) { btnElement.classList.add('voted'); countSpan.innerText = currentCount; }
                    else { btnElement.classList.remove('voted'); countSpan.innerText = currentCount; }
                }
            } catch (error) {
                if (isVoted) { btnElement.classList.add('voted'); countSpan.innerText = currentCount; }
                else { btnElement.classList.remove('voted'); countSpan.innerText = currentCount; }
            }
        }

        const reviewForm = document.getElementById('form-review');
        if (reviewForm) {
            reviewForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = document.getElementById('btn-submit-review');
                const errorDiv = document.getElementById('review-error');
                const successDiv = document.getElementById('review-success');
                const rating = document.querySelector('input[name="rating"]:checked').value;
                const content = document.querySelector('textarea[name="comment"]').value;
                
                const originalText = btn.innerText;
                btn.innerText = 'Processando...';
                btn.disabled = true;
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';

                try {
                    const response = await fetch('../../src/backend/ajax_review.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ game_id: <?php echo $game_id; ?>, rating: rating, content: content })
                    });
                    
                    const result = await response.json();

                    if (result.success) {
                        successDiv.innerText = result.message;
                        successDiv.style.display = 'block';
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        errorDiv.innerText = result.error || 'Erro desconhecido.';
                        errorDiv.style.display = 'block';
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                } catch (error) {
                    errorDiv.innerText = 'Falha na comunicação com o servidor.';
                    errorDiv.style.display = 'block';
                    btn.innerText = originalText;
                    btn.disabled = false;
                }
            });
        }
    </script>
</body>
</html>