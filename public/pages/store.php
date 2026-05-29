<?php
// [ARQUITETURA] Importação segura e isolada.
require_once __DIR__ . '/../../core/config.php';
/** @var mysqli $conn */

session_start();

// [SEGURANÇA] Validação de Identidade.
if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/auth/login.php");
    exit();
}

// --- HELPER: Resolução de Imagens ---
// [ARQUITETURA] Centraliza a lógica para evitar duplicação. Resolve de forma limpa caminhos relativos e absolutos com base na APP_URL definida no config.php.
function resolveImageUrl($url) {
    if (empty($url)) return '';
    if (strpos($url, 'http') === 0) return htmlspecialchars($url);
    
    // Remove os ../ e a primeira barra para padronizar
    $clean_path = ltrim(str_replace('../', '/', $url), '/');
    return APP_URL . '/' . htmlspecialchars($clean_path);
}

// 1. Busca e Limpeza
$search = $_GET['q'] ?? '';
$genre_selecionado = $_GET['genre'] ?? null;

// Dados do Usuário
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);
$avatar_src = resolveImageUrl($avatar_url);

// --- CONSULTAS ---

// Gêneros
$res_genres = mysqli_query($conn, "SELECT genre_id, name FROM genres ORDER BY name ASC");

// Hero Banner (Só aparece se não houver busca/filtro)
$hero_game = null;
if (empty($search) && empty($genre_selecionado)) {
    // [AUDITORIA] Curadoria de Conteúdo e isolamento de rascunhos.
    $query_hero = "SELECT g.*, u.username as dev_name FROM games g JOIN users u ON g.developer_id = u.user_id WHERE g.status = 'published' ORDER BY g.created_at DESC LIMIT 1";
    $res_hero = mysqli_query($conn, $query_hero);
    $hero_game = ($res_hero && mysqli_num_rows($res_hero) > 0) ? mysqli_fetch_assoc($res_hero) : null;
}

// Grid de Jogos (Com Prepared Statements para máxima segurança)
// [SEGURANÇA] O uso de prepared statements (stmt) com bind_param neutraliza 100% o risco de SQL Injection.
$query_favs = "SELECT g.*, u.username as dev_name FROM games g JOIN users u ON g.developer_id = u.user_id WHERE g.status = 'published'";
$types = "";
$params = [];

if (!empty($search)) {
    $query_favs .= " AND g.title LIKE ?";
    $types .= "s";
    $params[] = "%" . $search . "%";
}

if (!empty($genre_selecionado)) {
    // NOTA: Baseado no código original. Veja a observação no final da resposta.
    $query_favs .= " AND g.genre_id = ?"; 
    $types .= "i";
    $params[] = (int)$genre_selecionado;
}

$query_favs .= " ORDER BY g.created_at DESC";

// Prepara e executa a query dinamicamente
$stmt = mysqli_prepare($conn, $query_favs);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$res_favs = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loja IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/store.css">
</head>

<body data-theme="dark">

    <header class="store-header">
        <div class="header-left">
            <a href="store.php" class="store-logo">IndieZone</a>
            <form action="store.php" method="GET" class="search-bar">
                <span class="search-icon">🔎</span>
                <input type="text" name="q" placeholder="Pesquisar jogos na loja..." value="<?php echo htmlspecialchars($search); ?>">
            </form>
            <a href="store.php" class="btn-nav-header active">Loja</a>
            <a href="library.php" class="btn-nav-header">Biblioteca</a>
            <a href="community.php" class="btn-nav-header">Comunidade</a>
        </div>
        <a href="<?php echo htmlspecialchars($profile_link ?? 'index.php'); ?>" class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($primeiro_nome); ?></span>
            <img src="<?php echo $avatar_src; ?>" alt="Avatar de <?php echo htmlspecialchars($primeiro_nome); ?>" class="user-avatar">
        </a>
    </header>

    <nav class="categories-nav">
        <div class="nav-scroll-container">
            <a href="store.php" class="cat-link <?php echo !$genre_selecionado ? 'active' : ''; ?>">Discover</a>
            <?php if ($res_genres): ?>
                <?php while ($genre = mysqli_fetch_assoc($res_genres)): ?>
                    <a href="?genre=<?php echo $genre['genre_id']; ?>" class="cat-link <?php echo ($genre_selecionado == $genre['genre_id']) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($genre['name']); ?>
                    </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="store-container">
        <?php if ($hero_game): ?>
            <?php $hero_cover_src = resolveImageUrl($hero_game['cover_image_url']); ?>
            <div class="hero-banner" style="background: url('<?php echo $hero_cover_src; ?>') center/cover;">
                <div class="hero-overlay">
                    <div class="hero-content">
                        <h1 class="hero-logo"><?php echo htmlspecialchars($hero_game['title']); ?></h1>
                        <p class="hero-desc"><?php echo htmlspecialchars(substr($hero_game['description'] ?? '', 0, 300)); ?>...</p>
                        <div class="hero-actions">
                            <a href="game_details.php?id=<?php echo $hero_game['game_id']; ?>" class="btn-buy" style="text-decoration:none;">Garantir Cópia</a>
                            <div class="hero-price-tag">
                                <span class="highlight-label">BR Highlight</span>
                                <span class="price-now">
                                    <?php echo $hero_game['price'] > 0 ? 'R$ ' . number_format($hero_game['price'], 2, ',', '.') : 'Grátis'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2 class="section-title">
                <?php 
                    if (!empty($search)) {
                        echo "Resultados para '" . htmlspecialchars($search) . "'";
                    } else {
                        echo "Community Favorites";
                    }
                ?>
            </h2>
        </div>

        <?php if ($res_favs && mysqli_num_rows($res_favs) > 0): ?>
            <div class="games-grid">
                <?php while ($game = mysqli_fetch_assoc($res_favs)): ?>
                    <a href="game_details.php?id=<?php echo $game['game_id']; ?>" class="game-card">
                        <div class="cover-container">
                            <?php if (!empty($game['cover_image_url'])): ?>
                                <img src="<?php echo resolveImageUrl($game['cover_image_url']); ?>" alt="Capa do jogo <?php echo htmlspecialchars($game['title']); ?>" class="game-cover">
                            <?php else: ?>
                                <div class="no-image-placeholder"><span>Sem Imagem</span></div>
                            <?php endif; ?>
                        </div>
                        <div class="game-info">
                            <h3 class="game-title"><?php echo htmlspecialchars($game['title']); ?></h3>
                            <p class="game-dev"><?php echo htmlspecialchars($game['dev_name']); ?></p>
                            <span class="price-final">
                                <?php echo $game['price'] > 0 ? 'R$ ' . number_format($game['price'], 2, ',', '.') : 'Grátis'; ?>
                            </span>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state" style="text-align: center; padding: 40px 20px; color: #a1a1aa;">
                <span style="font-size: 3rem; display: block; margin-bottom: 16px;">🕹️</span>
                <h3>Nenhum jogo encontrado</h3>
                <p>Não encontramos resultados para a sua busca ou filtro atual.</p>
                <a href="store.php" style="display: inline-block; margin-top: 16px; padding: 10px 20px; background: #22c55e; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">Limpar Filtros</a>
            </div>
        <?php endif; ?>
    </main>
    
</body>
</html>