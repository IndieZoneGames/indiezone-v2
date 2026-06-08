<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die("Você precisa estar logado.");
}

$user_id = $_SESSION['user_id'];

$game_id = intval($_GET['game_id'] ?? 0);

if ($game_id <= 0) {
    die("Jogo inválido.");
}

// BUSCA O JOGO com prepared statement
$stmt = mysqli_prepare($conn, "SELECT * FROM games WHERE game_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $game_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) == 0) {
    die("Jogo não encontrado.");
}

$game = mysqli_fetch_assoc($result);

// STRIPE
\Stripe\Stripe::setApiKey('sk_test_51TTQkVQofN5CPfSpDoSypyKLXRECarLWBkexafeVvsq01rtz0I4cvS9E05TlNlF75tAZLFTRQpNFjHksdZ4roKF000nMl5SDw8');

// CRIA CHECKOUT
$session = \Stripe\Checkout\Session::create([

    'payment_method_types' => ['card'],

    'line_items' => [[
        'price_data' => [
            'currency' => 'brl',
            'product_data' => [
                'name' => $game['title']
            ],
            'unit_amount' => intval($game['price'] * 100),
        ],
        'quantity' => 1,
    ]],

    'mode' => 'payment',

    // Passa session_id do Stripe na URL — game_id só serve de hint, a validação real é pelo session_id
    'success_url' =>
        'http://localhost/indiezone-main/public/pages/payment_success.php?session_id={CHECKOUT_SESSION_ID}&game_id=' . $game_id,

    'cancel_url' =>
        'http://localhost/indiezone-main/public/pages/game_details.php?id=' . $game_id,

    'metadata' => [
        'user_id' => $user_id,
        'game_id' => $game_id
    ]
]);

// REDIRECIONA PARA STRIPE
header("Location: " . $session->url);
exit;