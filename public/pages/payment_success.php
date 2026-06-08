<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

/** @var mysqli $conn */

session_start();

// VERIFICA LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: " . APP_URL . "/auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// DADOS DO USUÁRIO PARA O HEADER
$display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
$primeiro_nome = explode(' ', $display_name)[0];
$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);

// HELPER DE IMAGEM
function resolveImageUrl($url) {
    if (empty($url)) return APP_URL . '/assets/img/Placeholder_Padrao.png';
    if (strpos($url, 'http') === 0) return htmlspecialchars($url);
    $clean_path = ltrim(str_replace('../', '/', $url), '/');
    return APP_URL . '/' . htmlspecialchars($clean_path);
}

// ─────────────────────────────────────────────────────────────
// VALIDAÇÃO REAL: consulta o Stripe pelo session_id
// O Stripe redireciona para cá após o pagamento externo,
// então a sessão PHP não carrega o game_id — precisamos
// validar direto na API do Stripe com o CHECKOUT_SESSION_ID.
// ─────────────────────────────────────────────────────────────
$stripe_session_id = $_GET['session_id'] ?? '';

if (empty($stripe_session_id)) {
    header("Location: " . APP_URL . "/pages/store.php");
    exit();
}

\Stripe\Stripe::setApiKey('sk_test_51TTQkVQofN5CPfSpDoSypyKLXRECarLWBkexafeVvsq01rtz0I4cvS9E05TlNlF75tAZLFTRQpNFjHksdZ4roKF000nMl5SDw8');

try {
    $stripe_session = \Stripe\Checkout\Session::retrieve($stripe_session_id);
} catch (\Exception $e) {
    die("Sessão de pagamento inválida.");
}

// VERIFICA SE O PAGAMENTO FOI REALMENTE APROVADO
if ($stripe_session->payment_status !== 'paid') {
    header("Location: " . APP_URL . "/pages/store.php");
    exit();
}

// VERIFICA SE O user_id DO STRIPE BATE COM O LOGADO (segurança)
$meta_user_id = intval($stripe_session->metadata->user_id ?? 0);
$game_id      = intval($stripe_session->metadata->game_id ?? 0);

if ($meta_user_id !== $user_id || $game_id <= 0) {
    header("Location: " . APP_URL . "/pages/store.php");
    exit();
}

// BUSCA O JOGO
$stmt = mysqli_prepare($conn, "SELECT * FROM games WHERE game_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $game_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) == 0) {
    die("Jogo não encontrado.");
}

$game     = mysqli_fetch_assoc($result);
$cover_src = resolveImageUrl($game['cover_image_url'] ?? '');

// EVITA DUPLICAR NA BIBLIOTECA
$check_stmt = mysqli_prepare($conn, "SELECT * FROM library WHERE user_id = ? AND game_id = ? LIMIT 1");
mysqli_stmt_bind_param($check_stmt, 'ii', $user_id, $game_id);
mysqli_stmt_execute($check_stmt);
$check = mysqli_stmt_get_result($check_stmt);

if (mysqli_num_rows($check) == 0) {
    $insert_stmt = mysqli_prepare($conn, "INSERT INTO library (user_id, game_id) VALUES (?, ?)");
    mysqli_stmt_bind_param($insert_stmt, 'ii', $user_id, $game_id);
    mysqli_stmt_execute($insert_stmt);
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
    <link rel="stylesheet" href="../assets/css/store.css">
    <link rel="stylesheet" href="../assets/css/payment_success.css">
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
                <a href="library.php" class="btn btn-primary">Ir para a Biblioteca</a>
                <a href="store.php" class="btn btn-secondary">Voltar para a Loja</a>
            </div>

        </div>
    </main>

</body>
</html>