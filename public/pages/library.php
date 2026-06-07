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

$user_id = $_SESSION['user_id'];

// --- HELPER: Resolução de Imagens ---
if (!function_exists('resolveImageUrl')) {
    function resolveImageUrl($url, $genre = '') {
        if (empty($url)) {
            $placeholders = [
                'Ação'      => 'Placeholder_acao.png',
                'Arcade'    => 'Placeholder_Arcade.png',
                'Aventura'  => 'Placeholder_Aventura.png',
                'Estratégia'=> 'Placeholder_Estrategia.png',
                'Plataforma'=> 'Placeholder_Plataforma.png',
                'Puzzle'    => 'Placeholder_Puzzle.png',
                'RPG'       => 'Placeholder_RPG.png',
                'Simulador' => 'Placeholder_Simulador.png',
                'Terror'    => 'Placeholder_terror.png'
            ];
            $file = $placeholders[$genre] ?? 'Placeholder_Padrao.png';
            return APP_URL . '/assets/img/' . $file;
        }
        if (strpos($url, 'http') === 0) return htmlspecialchars($url);
        $clean_path = ltrim(str_replace('../', '/', $url), '/');
        return APP_URL . '/' . htmlspecialchars($clean_path);
    }
}

// =========================================
// 1. PARÂMETROS DE ENTRADA E ORDENAÇÃO
// =========================================
$lib_search   = trim($_GET['lib_q'] ?? '');
$sort         = $_GET['sort'] ?? 'newest';
$genre_filter = (int)($_GET['genre_id'] ?? 0);

$sort_map = [
    'newest'    => 'l.acquired_at DESC',
    'oldest'    => 'l.acquired_at ASC',
    'name_asc'  => 'g.title ASC',
    'name_desc' => 'g.title DESC',
];
$order_clause = $sort_map[$sort] ?? $sort_map['newest'];

// =========================================
// 2. DADOS DO USUÁRIO
// =========================================
$display_name  = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url    = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username'] ?? 'user');


// =========================================
// 3. STATS DA BIBLIOTECA (Totais sem playtime)
// =========================================
$stmt_stats = mysqli_prepare($conn,
    "SELECT COUNT(DISTINCT l.game_id) as total_games,
            COUNT(DISTINCT gg.genre_id) as total_genres
     FROM library l
     JOIN games g ON g.game_id = l.game_id
     LEFT JOIN game_genres gg ON gg.game_id = g.game_id
     WHERE l.user_id = ?"
);
mysqli_stmt_bind_param($stmt_stats, "i", $user_id);
mysqli_stmt_execute($stmt_stats);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_stats));

$total_games_count = (int)$stats['total_games'];
$total_genres      = (int)$stats['total_genres'];

// =========================================
// 4. LISTA DE GÊNEROS DA BIBLIOTECA
// =========================================
$stmt_genres = mysqli_prepare($conn,
    "SELECT DISTINCT gn.genre_id, gn.name
     FROM genres gn
     JOIN game_genres gg ON gn.genre_id = gg.genre_id
     JOIN library l ON l.game_id = gg.game_id
     WHERE l.user_id = ?
     ORDER BY gn.name ASC"
);
mysqli_stmt_bind_param($stmt_genres, "i", $user_id);
mysqli_stmt_execute($stmt_genres);
$res_genres = mysqli_stmt_get_result($stmt_genres);
$genres_list = [];
while ($g = mysqli_fetch_assoc($res_genres)) {
    $genres_list[] = $g;
}

// =========================================
// 5. QUERY PRINCIPAL DOS JOGOS
// =========================================
$base_query = "SELECT g.*,
               d.studio_name as dev_name,
               l.acquired_at,
               (SELECT name FROM genres gn JOIN game_genres gg ON gn.genre_id = gg.genre_id WHERE gg.game_id = g.game_id LIMIT 1) as genre_name
               FROM games g
               JOIN library l ON g.game_id = l.game_id
               JOIN developers d ON g.developer_id = d.user_id
               WHERE l.user_id = ?";

$types  = "i";
$params = [$user_id];

if (!empty($lib_search)) {
    $base_query .= " AND g.title LIKE ?";
    $types  .= "s";
    $params[] = "%" . $lib_search . "%";
}

if ($genre_filter > 0) {
    $base_query .= " AND g.game_id IN (SELECT game_id FROM game_genres WHERE genre_id = ?)";
    $types  .= "i";
    $params[] = $genre_filter;
}

$base_query .= " ORDER BY " . $order_clause;

$stmt = mysqli_prepare($conn, $base_query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res_library = mysqli_stmt_get_result($stmt);
$total_games = mysqli_num_rows($res_library);

// Mapa de badges para release_stage
$stage_badge = [
    'alpha'        => ['label' => 'Alpha',        'class' => 'badge-stage-alpha'],
    'beta'         => ['label' => 'Beta',          'class' => 'badge-stage-beta'],
    'early_access' => ['label' => 'Early Access',  'class' => 'badge-stage-early'],
    'full_release' => ['label' => null,             'class' => ''],
];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Biblioteca - IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/store.css">
    <link rel="stylesheet" href="../assets/css/library.css">
    <link rel="stylesheet" href="../assets/css/rodape.css">
</head>
<body data-theme="dark">

<header class="store-header">
    <div class="header-left">
        <a href="store.php" class="store-logo">IndieZone</a>
        <form action="store.php" method="GET" class="search-bar">
            <span class="search-icon">🔎</span>
            <input type="text" name="q" placeholder="Pesquisar jogos na loja...">
        </form>
        <a href="store.php" class="btn-nav-header">Loja</a>
        <a href="library.php" class="btn-nav-header active">Biblioteca</a>
        <a href="community.php" class="btn-nav-header">Comunidade</a>
    </div>
    <a href="<?php echo htmlspecialchars($profile_link ?? 'index.php'); ?>" class="user-menu">
        <span class="user-name"><?php echo htmlspecialchars($primeiro_nome); ?></span>
        <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar" class="user-avatar">
    </a>
</header>

<main class="store-container library-main">

    <div class="library-hero">
        <h1 class="library-page-title">Minha Biblioteca</h1>
        <div class="library-stats">
            <div class="lib-stat">
                <span class="lib-stat-val"><?php echo $total_games_count; ?></span>
                <span class="lib-stat-lbl"><?php echo $total_games_count === 1 ? 'jogo' : 'jogos'; ?></span>
            </div>
            <?php if ($total_genres > 0): ?>
            <div class="lib-stat">
                <span class="lib-stat-val"><?php echo $total_genres; ?></span>
                <span class="lib-stat-lbl"><?php echo $total_genres === 1 ? 'gênero' : 'gêneros'; ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="store-toolbar library-toolbar">
        <div class="toolbar-left">
            <span class="results-count">
                <strong><?php echo $total_games; ?></strong>
                <?php echo $total_games === 1 ? 'jogo listado' : 'jogos listados'; ?>
            </span>
        </div>

        <div class="toolbar-right">
            <form method="GET" action="library.php" class="library-filter-form" id="lib-filter-form">
                <?php if ($genre_filter > 0): ?>
                    <input type="hidden" name="genre_id" value="<?php echo $genre_filter; ?>">
                <?php endif; ?>

                <div class="library-search-wrapper">
                    <span class="lib-search-icon">🔎</span>
                    <input type="text" name="lib_q" class="input-base lib-search-input" placeholder="Buscar na biblioteca..."
                           value="<?php echo htmlspecialchars($lib_search); ?>">
                </div>

                <div class="library-sort-wrapper">
                    <select name="sort" class="sort-select" onchange="this.form.submit()">
                        <option value="newest"    <?php echo $sort === 'newest'   ? 'selected' : ''; ?>>Adicionados recentemente</option>
                        <option value="oldest"    <?php echo $sort === 'oldest'   ? 'selected' : ''; ?>>Adicionados mais antigos</option>
                        <option value="name_asc"  <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Nome (A-Z)</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc'? 'selected' : ''; ?>>Nome (Z-A)</option>
                    </select>
                </div>

                <div class="view-toggle" id="view-toggle">
                    <button type="button" class="vt-btn active" data-view="grid" title="Grade">⊞</button>
                    <button type="button" class="vt-btn" data-view="list" title="Lista">☰</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($genres_list)): ?>
    <div class="genre-strip">
        <a href="?sort=<?php echo urlencode($sort); ?><?php echo $lib_search ? '&lib_q=' . urlencode($lib_search) : ''; ?>"
           class="genre-chip <?php echo $genre_filter === 0 ? 'active' : ''; ?>">
            Todos
        </a>
        <?php foreach ($genres_list as $genre): ?>
        <a href="?genre_id=<?php echo $genre['genre_id']; ?>&sort=<?php echo urlencode($sort); ?><?php echo $lib_search ? '&lib_q=' . urlencode($lib_search) : ''; ?>"
           class="genre-chip <?php echo $genre_filter === (int)$genre['genre_id'] ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($genre['name']); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($total_games > 0): ?>

        <div class="games-grid" id="lib-grid">
            <?php while ($game = mysqli_fetch_assoc($res_library)): 
                $stage     = $game['release_stage'] ?? 'full_release';
                $badge_cfg = $stage_badge[$stage] ?? $stage_badge['full_release'];
                $img_src   = resolveImageUrl($game['cover_image_url'], $game['genre_name'] ?? '');
            ?>
                <a href="game_details.php?id=<?php echo (int)$game['game_id']; ?>" class="game-card lib-card">
                    <div class="cover-container">
                        <img src="<?php echo $img_src; ?>"
                             alt="Capa de <?php echo htmlspecialchars($game['title']); ?>"
                             class="game-cover" loading="lazy">

                        <?php if ($badge_cfg['label']): ?>
                            <span class="lib-card-badge <?php echo htmlspecialchars($badge_cfg['class']); ?>">
                                <?php echo htmlspecialchars($badge_cfg['label']); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="game-info">
                        <h3 class="game-title" title="<?php echo htmlspecialchars($game['title']); ?>">
                            <?php echo htmlspecialchars($game['title']); ?>
                        </h3>
                        <p class="game-dev" title="<?php echo htmlspecialchars($game['dev_name']); ?>">
                            de <?php echo htmlspecialchars($game['dev_name']); ?>
                        </p>

                        <div class="lib-card-footer">
                            <span class="btn lib-play-btn">Visualizar / Baixar</span>
                        </div>
                    </div>
                </a>
            <?php endwhile; ?>

            <?php if (empty($lib_search) && $genre_filter === 0): ?>
                <a href="store.php" class="lib-explore-card">
                    <span class="lib-explore-icon">+</span>
                    <span class="lib-explore-label">Explorar mais jogos</span>
                </a>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <div class="empty-state library-empty">
            <span class="empty-icon-large">🎮</span>
            <h3 class="empty-title">Nenhum jogo por aqui</h3>
            <p class="empty-desc">
                <?php if (!empty($lib_search)): ?>
                    Nenhum jogo encontrado com o termo "<strong><?php echo htmlspecialchars($lib_search); ?></strong>".
                <?php elseif ($genre_filter > 0): ?>
                    Você não tem jogos desse gênero na biblioteca.
                <?php else: ?>
                    Sua biblioteca está vazia. Que tal descobrir novos jogos na loja?
                <?php endif; ?>
            </p>
            <?php if (!empty($lib_search) || $genre_filter > 0): ?>
                <a href="library.php" class="btn btn-empty-action">Limpar filtros</a>
            <?php else: ?>
                <a href="store.php" class="btn btn-empty-action">Explorar a loja</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../partials/rodape.php'; ?>

<script>
// ===== Toggle grade / lista =====
(function () {
    const toggle  = document.getElementById('view-toggle');
    if (!toggle) return;

    const grids   = document.querySelectorAll('.games-grid');
    const savedView = localStorage.getItem('lib_view') || 'grid';

    function applyView(view) {
        grids.forEach(function(g) {
            g.classList.toggle('list-view', view === 'list');
        });
        toggle.querySelectorAll('.vt-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.view === view);
        });
        localStorage.setItem('lib_view', view);
    }

    applyView(savedView);

    toggle.addEventListener('click', function(e) {
        const btn = e.target.closest('.vt-btn');
        if (btn) applyView(btn.dataset.view);
    });
})();
</script>

</body>
</html>