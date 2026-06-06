<?php

require_once __DIR__ . '/../../core/config.php';

/** @var mysqli $conn */

session_start();

// VERIFICA LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/auth/login.php");
    exit();
}

// USER
$user_id = $_SESSION['user_id'];

// DADOS DO USUÁRIO PARA O HEADER
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);

// HELPER DE IMAGEM (Mesmo padrão da loja)
function resolveImageUrl($url) {
    if (empty($url)) return APP_URL . '/assets/img/Placeholder_Padrao.png';
    if (strpos($url, 'http') === 0) return htmlspecialchars($url);
    $clean_path = ltrim(str_replace('../', '/', $url), '/');
    return APP_URL . '/' . htmlspecialchars($clean_path);
}

// GAME
$game_id = intval($_GET['game_id'] ?? 0);

if ($game_id <= 0) {
    die("Jogo inválido.");
}

// BUSCA O JOGO
$query = "
    SELECT *
    FROM games
    WHERE game_id = $game_id
    LIMIT 1
";

$result = mysqli_query($conn, $query);

if (!$result || mysqli_num_rows($result) == 0) {
    die("Jogo não encontrado.");
}

$game = mysqli_fetch_assoc($result);
$cover_src = resolveImageUrl($game['cover_image_url'] ?? '');

// EVITA DUPLICAR NA BIBLIOTECA
$check = mysqli_query($conn, "
    SELECT *
    FROM library
    WHERE user_id = $user_id AND game_id = $game_id
    LIMIT 1
");

// SE NÃO EXISTIR → INSERE
if (mysqli_num_rows($check) == 0) {
    mysqli_query($conn, "
        INSERT INTO library (user_id, game_id)
        VALUES ($user_id, $game_id)
    ");
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento Aprovado — IndieZone</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/store.css"> <link rel="stylesheet" href="../assets/css/payment_success.css">
</head>

<body data-theme="dark">

    <header class="store-header">
        <div class="header-left">
            <a href="store.php" class="store-logo">IndieZone</a>
            <a href="store.php" class="btn-nav-header">Loja</a>
            <a href="library.php" class="btn-nav-header">Biblioteca</a>
        </div>
        <a href="profile.php" class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($primeiro_nome); ?></span>
            <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar" class="user-avatar">
        </a>
    </header>

    <main class="success-container">
        <div class="success-card">
            
            <div class="success-icon-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>

            <h1 class="success-title">Pagamento Concluído!</h1>
            <p class="success-desc">Seu pagamento foi processado com sucesso e o jogo já está disponível na sua conta.</p>

            <div class="purchased-game">
                <div class="purchased-cover-wrapper">
                    <img src="<?php echo $cover_src; ?>" alt="Capa de <?php echo htmlspecialchars($game['title']); ?>" class="purchased-cover">
                </div>
                <div class="purchased-info">
                    <span class="purchased-label">Adicionado à Biblioteca</span>
                    <h2 class="purchased-title"><?php echo htmlspecialchars($game['title']); ?></h2>
                </div>
            </div>

            <div class="success-actions">
                <a href="library.php" class="btn btn-primary">
                    Ir para a Biblioteca
                </a>
                <a href="store.php" class="btn btn-secondary">Voltar para a Loja</a>
            </div>

        </div>
    </main>

</body>
</html>