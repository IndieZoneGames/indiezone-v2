<?php
// [ARQUITETURA] A importação do arquivo de configuração a partir da pasta 'core' garante que o front-end (público) não tenha acesso direto às lógicas de conexão e credenciais do banco, respeitando o isolamento de camadas.
require_once __DIR__ . '/../../core/config.php';
/** @var mysqli $conn */

session_start();

// [SEGURANÇA] Validação de Identidade. A loja exige autenticação prévia para garantir que todas as interações (cliques, tempo de jogo, compras) possam ser rastreadas e atreladas a um 'user_id' válido no banco de dados.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// 1. Busca e Limpeza
// [SEGURANÇA] Sanitização de Entrada. Como a query da loja precisa ser montada dinamicamente, o uso do 'mysqli_real_escape_string' neutraliza caracteres especiais (como aspas simples), impedindo ataques de SQL Injection através da barra de pesquisa.
$search = isset($_GET['q']) ? mysqli_real_escape_string($conn, $_GET['q']) : '';
$genre_selecionado = $_GET['genre'] ?? null;

// Dados do Usuário
// [LÓGICA] Fallback de identidade. O sistema tenta usar o 'display_name', cai para o 'username' se necessário, e extrai apenas o primeiro nome para deixar a interface da loja mais limpa e amigável.
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);

// --- CONSULTAS ---
$res_genres = mysqli_query($conn, "SELECT * FROM genres ORDER BY name ASC");

// Hero Banner (Só aparece se não houver busca)
$hero_game = null;
if (empty($search) && empty($genre_selecionado)) {
    // [AUDITORIA] Curadoria de Conteúdo. A cláusula "g.status = 'published'" é vital. Ela garante que a vitrine principal espelhe estritamente as aprovações feitas pelos administradores, impedindo que jogos em rascunho ou sob revisão ('pending') vazem para o público.
    $query_hero = "SELECT g.*, u.username as dev_name FROM games g JOIN users u ON g.developer_id = u.user_id WHERE g.status = 'published' ORDER BY g.created_at DESC LIMIT 1";
    $res_hero = mysqli_query($conn, $query_hero);
    $hero_game = ($res_hero) ? mysqli_fetch_assoc($res_hero) : null;
}

// Grid de Jogos com Filtros
// [LÓGICA] Construtor Dinâmico de Queries. O sistema adiciona filtros (Busca e Gênero) apenas se o usuário os acionou, otimizando o processamento do banco de dados ao não enviar parâmetros vazios.
$query_favs = "SELECT g.*, u.username as dev_name FROM games g JOIN users u ON g.developer_id = u.user_id WHERE g.status = 'published'";
if (!empty($search)) $query_favs .= " AND g.title LIKE '%$search%'";
if (!empty($genre_selecionado)) $query_favs .= " AND g.genre_id = '$genre_selecionado'"; // Ajuste para sua coluna de genero se necessário
$res_favs = mysqli_query($conn, $query_favs);
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
                <input type="text" name="q" placeholder="Pesquisar jogos na loja...">
            </form>
            <a href="store.php" class="btn-nav-header active" style="color: #22c55e;">Loja</a>
            <a href="library.php" class="btn-nav-header">Biblioteca</a>
            <a href="community.php" class="btn-nav-header">Comunidade</a>
        </div>
        <a href="<?php echo htmlspecialchars($profile_link ?? 'index.php'); ?>" class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($primeiro_nome); ?></span>
            <?php
            $avatar_src = (strpos($avatar_url, 'http') === 0)
                ? htmlspecialchars($avatar_url)
                : APP_URL . htmlspecialchars($avatar_url);
            ?>
            <img src="<?php echo $avatar_src; ?>" alt="Avatar" class="user-avatar">
        </a>
    </header>

    <nav class="categories-nav">
        <div class="nav-scroll-container">
            <!-- [LÓGICA] Manutenção de Estado da Interface. O PHP injeta a classe 'active' dinamicamente com base no parâmetro GET, mostrando ao usuário exatamente qual filtro está aplicado no momento. -->
            <a href="store.php" class="cat-link <?php echo !$genre_selecionado ? 'active' : ''; ?>">Discover</a>
            <?php if ($res_genres): ?>
                <?php while ($genre = mysqli_fetch_assoc($res_genres)):
                    $g_id = $genre['id'] ?? $genre['genre_id'] ?? array_values($genre)[0];
                ?>
                    <a href="?genre=<?php echo $g_id; ?>" class="cat-link <?php echo ($genre_selecionado == $g_id) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($genre['name']); ?>
                    </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="store-container">
        <?php if ($hero_game): ?>
            <!-- [ARQUITETURA] Renderização Condicional de Destaques. O Hero Banner consome um grande espaço visual e só é montado se a busca estiver limpa, focando na curadoria do último jogo aprovado pelos administradores. -->
            <?php
            $hero_cover = $hero_game['cover_image_url'];
            $hero_cover_src = (strpos($hero_cover, 'http') === 0)
                ? htmlspecialchars($hero_cover)
                : APP_URL . htmlspecialchars($hero_cover);
            ?>
            <div class="hero-banner" style="background: url('<?php echo $hero_cover_src; ?>') center/cover;">
                <div class="hero-overlay">
                    <div class="hero-content">
                        <h1 class="hero-logo"><?php echo htmlspecialchars($hero_game['title']); ?></h1>
                        <p class="hero-desc"><?php echo htmlspecialchars(substr($hero_game['description'] ?? '', 0, 180)); ?>...</p>
                        <div class="hero-actions">
                            <a href="game_details.php?id=<?php echo $hero_game['game_id']; ?>" class="btn-buy" style="text-decoration:none;">Garantir Cópia</a>
                            <div class="hero-price-tag">
                                <span class="highlight-label">BR Highlight</span>
                                <!-- [LÓGICA] Formatação financeira direta na View (number_format) assegura que o dado cru no banco permaneça exato (float), mas a apresentação para o usuário obedeça a cultura local (R$). -->
                                <span class="price-now">R$ <?php echo number_format($hero_game['price'], 2, ',', '.'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2 class="section-title"><?php echo empty($search) ? "Community Favorites" : "Resultados"; ?></h2>
        </div>

        <div class="games-grid">
            <?php if ($res_favs && mysqli_num_rows($res_favs) > 0): ?>
                <?php while ($game = mysqli_fetch_assoc($res_favs)): ?>
                    <a href="game_details.php?id=<?php echo $game['game_id']; ?>" class="game-card">
                        <div class="cover-container">
                            <?php if (!empty($game['cover_image_url'])): ?>
                                <!-- [SEGURANÇA] Mitigação de Stored XSS. Todos os dados que compõem o Card do jogo e vêm do banco (imagem, título, desenvolvedor) são higienizados. -->
                                <?php
                                $cover = $game['cover_image_url'];
                                if (strpos($cover, 'http') === 0) {
                                    // URL externa — usa direto
                                    $cover_src = htmlspecialchars($cover);
                                } elseif (strpos($cover, '..') === 0) {
                                    // Caminho relativo com ../ — resolve a partir da raiz do projeto
                                    $cover_src = htmlspecialchars(str_replace('../', '/', $cover));
                                    $cover_src = '/indiezone-main/public/' . ltrim($cover_src, '/');
                                } else {
                                    // Caminho absoluto relativo — adiciona APP_URL
                                    $cover_src = APP_URL . htmlspecialchars($cover);
                                }
                                ?>
                                <img src="<?php echo $cover_src; ?>" class="game-cover">
                            <?php else: ?>
                                <div class="no-image-placeholder"><span>Sem Imagem</span></div>
                            <?php endif; ?>
                        </div>
                        <div class="game-info">
                            <h3 class="game-title"><?php echo htmlspecialchars($game['title']); ?></h3>
                            <p class="game-dev"><?php echo htmlspecialchars($game['dev_name']); ?></p>
                            <span class="price-final">R$ <?php echo number_format($game['price'], 2, ',', '.'); ?></span>
                        </div>
                    </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </main>
    
</body>

</html>