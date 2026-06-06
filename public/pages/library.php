<?php
// [ARQUITETURA] A importação do arquivo de configuração a partir da pasta isolada 'core' mantém o encapsulamento do sistema, protegendo as credenciais de infraestrutura fora do diretório público de visualização.
require_once __DIR__ . '/../../core/config.php';
/** @var mysqli $conn */

session_start();

// [SEGURANÇA] Controle de acesso rígido na borda da aplicação. Visitantes não autenticados são bloqueados antes de qualquer consulta à base de dados, garantindo que o processamento pesado só ocorra para sessões com tokens válidos.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// [SEGURANÇA] Sanitização do input de busca. Como a query abaixo é construída dinamicamente via concatenação, o uso do 'mysqli_real_escape_string' é a barreira imediata contra SQL Injection, neutralizando aspas e caracteres de escape enviados pelo método GET.
$search = isset($_GET['q']) ? mysqli_real_escape_string($conn, $_GET['q']) : '';

// Dados do Usuário
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);

// --- CONSULTA DA BIBLIOTECA COM BUSCA ---
// [AUDITORIA] Rastreio de posse e Prevenção contra IDOR. A cláusula 'WHERE l.user_id = $user_id' força o sistema a cruzar a tabela de jogos com a tabela 'library', garantindo que o usuário veja estritamente o que ele comprou/adquiriu, impedindo o acesso não autorizado ao catálogo inteiro.
// [LÓGICA] A consulta também extrai o 'playtime_minutes', um dado de telemetria contínua que atua como log de auditoria de engajamento para a plataforma.
$query_lib = "SELECT g.*, u.username as dev_name, l.playtime_minutes 
              FROM games g 
              JOIN library l ON g.game_id = l.game_id 
              JOIN users u ON g.developer_id = u.user_id 
              WHERE l.user_id = $user_id";

// [LÓGICA] Construção dinâmica de Query. O filtro 'LIKE' só é anexado ao comando SQL se o usuário ativamente utilizar a barra de pesquisa, economizando processamento no banco quando não for necessário.
if (!empty($search)) {
    $query_lib .= " AND g.title LIKE '%$search%'";
}

$query_lib .= " ORDER BY l.acquired_at DESC";
$res_library = mysqli_query($conn, $query_lib);
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
            <a href="store.php" class="btn-nav-header active" style="color: #22c55e;">Loja</a>
            <a href="library.php" class="btn-nav-header">Biblioteca</a>
            <a href="community.php" class="btn-nav-header">Comunidade</a>
        </div>
        <a href="<?php echo htmlspecialchars($profile_link ?? 'index.php'); ?>" class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($primeiro_nome); ?></span>
            <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar" class="user-avatar">
        </a>
    </header>

    <main class="store-container">
        <div class="library-title-container">
            <h1 class="library-page-title">Minha Biblioteca</h1>
        </div>

        <div class="games-grid">
            <?php if ($res_library && mysqli_num_rows($res_library) > 0): ?>
                <?php while ($game = mysqli_fetch_assoc($res_library)): ?>
                    <div class="game-card">
                        <div class="cover-container">
                            <?php if (!empty($game['cover_image_url'])): ?>
                                <!-- [SEGURANÇA] Mitigação de Stored XSS. Todos os atributos vindos do banco de dados (URLs, Nomes) que interagem com o DOM são higienizados na saída. -->
                                <img src="<?php echo htmlspecialchars($game['cover_image_url']); ?>" class="game-cover">
                            <?php else: ?>
                                <div class="no-image-placeholder"><span>Sem Imagem</span></div>
                            <?php endif; ?>
                        </div>
                        <div class="game-info">
                            <h3 class="game-title"><?php echo htmlspecialchars($game['title']); ?></h3>
                            <p class="game-dev">de <?php echo htmlspecialchars($game['dev_name']); ?></p>
                            <div class="game-footer" style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                                <span style="font-size: 11px; color: #64748b;">
                                    <?php echo $game['playtime_minutes']; ?> min jogados
                                </span>
                                <a href="game_details.php?id=<?php echo $game['game_id']; ?>" class="btn-buy" style="padding: 8px 16px; font-size: 13px;">Jogar</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-library" style="grid-column: 1/-1;">
                    <h3>Nenhum jogo encontrado</h3>
                    <p><?php echo empty($search) ? "Sua biblioteca está vazia." : "Nenhum resultado para '" . htmlspecialchars($search) . "'."; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <?php require_once __DIR__ . '/../partials/rodape.php'; ?>
</body>

</html>