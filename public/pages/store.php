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
function resolveImageUrl($url, $genre = '') {
    if (empty($url)) {
        $placeholders = [
            'Ação' => 'Placeholder_acao.png',
            'Arcade' => 'Placeholder_Arcade.png',
            'Aventura' => 'Placeholder_Aventura.png',
            'Estratégia' => 'Placeholder_Estrategia.png',
            'Plataforma' => 'Placeholder_Plataforma.png',
            'Puzzle' => 'Placeholder_Puzzle.png',
            'RPG' => 'Placeholder_RPG.png',
            'Simulador' => 'Placeholder_Simulador.png',
            'Terror' => 'Placeholder_terror.png'
        ];
        $file = $placeholders[$genre] ?? 'Placeholder_Padrao.png';
        return APP_URL . '/assets/img/' . $file;
    }
    if (strpos($url, 'http') === 0) return htmlspecialchars($url);
    $clean_path = ltrim(str_replace('../', '/', $url), '/');
    return APP_URL . '/' . htmlspecialchars($clean_path);
}

// =========================================
// 1. PARÂMETROS DE ENTRADA (com sanitização)
// =========================================
$search           = trim($_GET['q']      ?? '');
$genre_selecionado = isset($_GET['genre']) && is_numeric($_GET['genre']) ? (int)$_GET['genre'] : null;
$price_filter     = in_array($_GET['price'] ?? '', ['all', 'free', 'paid']) ? $_GET['price'] : 'all';
$sort             = $_GET['sort'] ?? 'newest';
$page             = max(1, (int)($_GET['page'] ?? 1));
$per_page         = 20;
$offset           = ($page - 1) * $per_page;

// Mapa de ordenação seguro (valores hardcoded — sem injeção possível)
$sort_map = [
    'newest'     => 'g.created_at DESC',
    'oldest'     => 'g.created_at ASC',
    'price_asc'  => 'g.price ASC, g.title ASC',
    'price_desc' => 'g.price DESC, g.title ASC',
    'name_asc'   => 'g.title ASC',
    'name_desc'  => 'g.title DESC',
];
$order_clause = $sort_map[$sort] ?? $sort_map['newest'];

// =========================================
// 2. DADOS DO USUÁRIO
// =========================================
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);
$avatar_src = resolveImageUrl($avatar_url);

// =========================================
// 3. CONSULTA DE GÊNEROS
// =========================================
$res_genres = mysqli_query($conn, "SELECT genre_id, name FROM genres ORDER BY name ASC");

// =========================================
// 4. HERO BANNER
// Só aparece na página inicial (sem busca, sem filtro, página 1)
// =========================================
$hero_game = null;
if (empty($search) && empty($genre_selecionado) && $price_filter === 'all' && $page === 1) {
    // Prioriza o jogo publicado mais recentemente com base em published_at
    $query_hero = "
        SELECT g.*, d.studio_name AS dev_name,
               (SELECT name FROM genres gn JOIN game_genres gg ON gn.genre_id = gg.genre_id WHERE gg.game_id = g.game_id LIMIT 1) as genre_name
        FROM games g 
        JOIN developers d ON g.developer_id = d.user_id 
        WHERE g.status = 'published'
        ORDER BY g.published_at DESC, g.created_at DESC 
        LIMIT 1
    ";
    $res_hero = mysqli_query($conn, $query_hero);
    $hero_game = ($res_hero && mysqli_num_rows($res_hero) > 0) ? mysqli_fetch_assoc($res_hero) : null;
}

// =========================================
// 5. CONSTRUÇÃO DA QUERY PRINCIPAL (Prepared Statement)
// =========================================
$base_conditions = "FROM games g JOIN developers d ON g.developer_id = d.user_id WHERE g.status = 'published'";
$types  = "";
$params = [];

if (!empty($search)) {
    $base_conditions .= " AND g.title LIKE ?";
    $types   .= "s";
    $params[] = "%" . $search . "%";
}

if (!empty($genre_selecionado)) {
    $base_conditions .= " AND g.game_id IN (SELECT game_id FROM game_genres WHERE genre_id = ?)";
    $types   .= "i";
    $params[] = $genre_selecionado;
}

if ($price_filter === 'free') {
    $base_conditions .= " AND g.price = 0";
} elseif ($price_filter === 'paid') {
    $base_conditions .= " AND g.price > 0";
}

// --- 5a. Contagem total (para paginação e contador) ---
$count_query = "SELECT COUNT(*) AS total " . $base_conditions;
$stmt_count  = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt_count, $types, ...$params);
}
mysqli_stmt_execute($stmt_count);
$count_result = mysqli_stmt_get_result($stmt_count);
$total_games  = (int)(mysqli_fetch_assoc($count_result)['total'] ?? 0);
$total_pages  = max(1, (int)ceil($total_games / $per_page));
// Garante que a página não ultrapasse o limite real
$page = min($page, max(1, $total_pages));

// --- 5b. Query de dados com LIMIT/OFFSET ---
$data_query  = "SELECT g.*, d.studio_name AS dev_name,
                       (SELECT name FROM genres gn JOIN game_genres gg ON gn.genre_id = gg.genre_id WHERE gg.game_id = g.game_id LIMIT 1) as genre_name " . $base_conditions . " ORDER BY " . $order_clause . " LIMIT ? OFFSET ?";
$data_types  = $types . "ii";
$data_params = array_merge($params, [$per_page, $offset]);

$stmt = mysqli_prepare($conn, $data_query);
mysqli_stmt_bind_param($stmt, $data_types, ...$data_params);
mysqli_stmt_execute($stmt);
$res_favs = mysqli_stmt_get_result($stmt);

// =========================================
// 6. HELPERS DE URL (mantém filtros ativos ao paginar/ordenar)
// =========================================
function buildUrl($overrides = []) {
    $defaults = [
        'q'     => $_GET['q']     ?? '',
        'genre' => $_GET['genre'] ?? '',
        'price' => $_GET['price'] ?? 'all',
        'sort'  => $_GET['sort']  ?? 'newest',
        'page'  => $_GET['page']  ?? 1,
    ];
    $merged = array_merge($defaults, $overrides);
    // Remove parâmetros vazios para URL mais limpa
    // Agora filtramos '1' apenas para a página, para não sumir com o ID do gênero 1
    $merged = array_filter($merged, function($v, $k) {
        if ($k === 'page' && ($v == 1 || $v == '1')) return false;
        return $v !== '' && $v !== 'all';
     }, ARRAY_FILTER_USE_BOTH);
    return 'store.php' . (!empty($merged) ? '?' . http_build_query($merged) : '');
}

$is_filtered = !empty($search) || !empty($genre_selecionado) || $price_filter !== 'all';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo !empty($search) ? htmlspecialchars($search) . ' — ' : ''; ?>Loja IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/store.css">
    <link rel="stylesheet" href="../assets/css/rodape.css">
</head>

<body data-theme="dark">

    <header class="store-header">
        <div class="header-left">
            <a href="store.php" class="store-logo">IndieZone</a>
            <form action="store.php" method="GET" class="search-bar">
                <?php if ($genre_selecionado): ?>
                    <input type="hidden" name="genre" value="<?php echo $genre_selecionado; ?>">
                <?php endif; ?>
                <?php if ($price_filter !== 'all'): ?>
                    <input type="hidden" name="price" value="<?php echo htmlspecialchars($price_filter); ?>">
                <?php endif; ?>
                <?php if ($sort !== 'newest'): ?>
                    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <?php endif; ?>
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
                    <a href="<?php echo buildUrl(['genre' => $genre['genre_id'], 'page' => 1]); ?>"
                       class="cat-link <?php echo ($genre_selecionado == $genre['genre_id']) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($genre['name']); ?>
                    </a>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="store-container">

        <?php if ($hero_game): ?>
            <?php $hero_cover_src = resolveImageUrl($hero_game['cover_image_url'], $hero_game['genre_name']); ?>
            <div class="hero-banner" style="background: url('<?php echo $hero_cover_src; ?>') center/cover;">
                <div class="hero-overlay">
                    <div class="hero-content">
                        <?php if ($hero_game['release_stage'] === 'early_access'): ?>
                            <div class="hero-badge early-access">
                                <span>🚀</span> Acesso Antecipado
                            </div>
                        <?php else: ?>
                            <div class="hero-badge">
                                <span>🆕</span> Lançamento Recente
                            </div>
                        <?php endif; ?>
                        
                        <h1 class="hero-logo"><?php echo htmlspecialchars($hero_game['title']); ?></h1>
                        <p class="hero-desc"><?php echo htmlspecialchars(substr($hero_game['description'] ?? '', 0, 300)); ?>...</p>
                        <div class="hero-actions">
                            <a href="game_details.php?id=<?php echo $hero_game['game_id']; ?>" class="btn-buy">Garantir Cópia</a>
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
                        echo 'Resultados para "' . htmlspecialchars($search) . '"';
                    } elseif (!empty($genre_selecionado)) {
                        // Busca o nome do gênero selecionado
                        $stmt_genre_name = mysqli_prepare($conn, "SELECT name FROM genres WHERE genre_id = ?");
                        mysqli_stmt_bind_param($stmt_genre_name, 'i', $genre_selecionado);
                        mysqli_stmt_execute($stmt_genre_name);
                        $genre_name_result = mysqli_stmt_get_result($stmt_genre_name);
                        $genre_name_row = mysqli_fetch_assoc($genre_name_result);
                        echo htmlspecialchars($genre_name_row['name'] ?? 'Gênero');
                    } else {
                        echo "Community Favorites";
                    }
                ?>
            </h2>
        </div>

        <?php if ($is_filtered): ?>
            <div class="active-filters-bar">
                <span>Filtros ativos:</span>
                <?php if (!empty($search)): ?>
                    <span class="filter-tag">🔎 "<?php echo htmlspecialchars($search); ?>"</span>
                <?php endif; ?>
                <?php if ($price_filter === 'free'): ?>
                    <span class="filter-tag">Grátis</span>
                <?php elseif ($price_filter === 'paid'): ?>
                    <span class="filter-tag">Pagos</span>
                <?php endif; ?>
                <a href="store.php" class="clear-filters-link">✕ Limpar filtros</a>
            </div>
        <?php endif; ?>

        <div class="store-toolbar">
            <div class="toolbar-left">
                <span class="results-count">
                    <strong><?php echo number_format($total_games, 0, ',', '.'); ?></strong>
                    <?php echo $total_games === 1 ? 'jogo encontrado' : 'jogos encontrados'; ?>
                    <?php if ($total_pages > 1): ?>
                        — página <?php echo $page; ?> de <?php echo $total_pages; ?>
                    <?php endif; ?>
                </span>

                <a href="<?php echo buildUrl(['price' => 'all', 'page' => 1]); ?>"
                   class="filter-chip <?php echo $price_filter === 'all' ? 'active' : ''; ?>">
                    Todos
                </a>
                <a href="<?php echo buildUrl(['price' => 'free', 'page' => 1]); ?>"
                   class="filter-chip <?php echo $price_filter === 'free' ? 'active' : ''; ?>">
                    <span class="chip-dot"></span> Grátis
                </a>
                <a href="<?php echo buildUrl(['price' => 'paid', 'page' => 1]); ?>"
                   class="filter-chip <?php echo $price_filter === 'paid' ? 'active' : ''; ?>">
                    <span class="chip-dot"></span> Pagos
                </a>
            </div>

            <div class="toolbar-right">
                <form method="GET" action="store.php" id="sort-form">
                    <?php if (!empty($search)):        ?><input type="hidden" name="q"     value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
                    <?php if ($genre_selecionado):     ?><input type="hidden" name="genre" value="<?php echo $genre_selecionado; ?>"><?php endif; ?>
                    <?php if ($price_filter !== 'all'):?><input type="hidden" name="price" value="<?php echo htmlspecialchars($price_filter); ?>"><?php endif; ?>
                    <input type="hidden" name="page" value="1">
                    <select name="sort" class="sort-select" onchange="document.getElementById('sort-form').submit()">
                        <option value="newest"     <?php echo $sort === 'newest'     ? 'selected' : ''; ?>>Mais Recentes</option>
                        <option value="oldest"     <?php echo $sort === 'oldest'     ? 'selected' : ''; ?>>Mais Antigos</option>
                        <option value="price_asc"  <?php echo $sort === 'price_asc'  ? 'selected' : ''; ?>>Menor Preço</option>
                        <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Maior Preço</option>
                        <option value="name_asc"   <?php echo $sort === 'name_asc'   ? 'selected' : ''; ?>>Nome A–Z</option>
                        <option value="name_desc"  <?php echo $sort === 'name_desc'  ? 'selected' : ''; ?>>Nome Z–A</option>
                    </select>
                </form>
            </div>
        </div>

        <?php if ($res_favs && mysqli_num_rows($res_favs) > 0): ?>
            <div class="games-grid">
                <?php while ($game = mysqli_fetch_assoc($res_favs)): ?>
                    <a href="game_details.php?id=<?php echo $game['game_id']; ?>" class="game-card">
                        <div class="cover-container">
                            <img src="<?php echo resolveImageUrl($game['cover_image_url'], $game['genre_name']); ?>"
                                 alt="Capa de <?php echo htmlspecialchars($game['title']); ?>"
                                 class="game-cover"
                                 loading="lazy">
                        </div>
                        <div class="game-info">
                            <h3 class="game-title"><?php echo htmlspecialchars($game['title']); ?></h3>
                            <p class="game-dev"><?php echo htmlspecialchars($game['dev_name']); ?></p>
                            <?php if ($game['price'] > 0): ?>
                                <span class="price-final">R$ <?php echo number_format($game['price'], 2, ',', '.'); ?></span>
                            <?php else: ?>
                                <span class="price-badge-free">GRÁTIS</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav class="pagination-wrapper" aria-label="Navegação de páginas">

                    <?php if ($page > 1): ?>
                        <a href="<?php echo buildUrl(['page' => $page - 1]); ?>" class="page-btn" aria-label="Página anterior">← Anterior</a>
                    <?php else: ?>
                        <span class="page-btn disabled">← Anterior</span>
                    <?php endif; ?>

                    <?php
                    $window = 2; // páginas ao redor da atual
                    $shown_pages = [];

                    for ($i = 1; $i <= $total_pages; $i++) {
                        if ($i === 1 || $i === $total_pages || ($i >= $page - $window && $i <= $page + $window)) {
                            $shown_pages[] = $i;
                        }
                    }

                    $prev_printed = null;
                    foreach ($shown_pages as $p):
                        if ($prev_printed !== null && $p > $prev_printed + 1): ?>
                            <span class="page-ellipsis">…</span>
                        <?php endif;
                        if ($p === $page): ?>
                            <span class="page-btn active" aria-current="page"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo buildUrl(['page' => $p]); ?>" class="page-btn"><?php echo $p; ?></a>
                        <?php endif;
                        $prev_printed = $p;
                    endforeach; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo buildUrl(['page' => $page + 1]); ?>" class="page-btn" aria-label="Próxima página">Próxima →</a>
                    <?php else: ?>
                        <span class="page-btn disabled">Próxima →</span>
                    <?php endif; ?>

                </nav>

                <p class="pagination-info">
                    Exibindo <?php echo (($page - 1) * $per_page) + 1; ?>–<?php echo min($page * $per_page, $total_games); ?>
                    de <?php echo number_format($total_games, 0, ',', '.'); ?> jogos
                </p>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <span class="empty-icon">🕹️</span>
                <h3 class="empty-title">Nenhum jogo encontrado</h3>
                <p class="empty-desc">
                    Não encontramos resultados para os filtros aplicados. Tente ajustar sua busca ou remover alguns filtros.
                </p>
                <a href="store.php" class="empty-btn">
                    Limpar Filtros
                </a>
            </div>
        <?php endif; ?>

    </main>

    <?php require_once __DIR__ . '/../partials/rodape.php'; ?>

</body>
</html>