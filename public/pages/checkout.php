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

// STRIPE
\Stripe\Stripe::setApiKey('sk_test_51TTQkVQofN5CPfSpDoSypyKLXRECarLWBkexafeVvsq01rtz0I4cvS9E05TlNlF75tAZLFTRQpNFjHksdZ4roKF000nMl5SDw8');

// CRIA CHECKOUT
$session = \Stripe\Checkout\Session::create([

    'payment_method_types' => [
        'card'
    ],

    'line_items' => [[

        'price_data' => [

            'currency' => 'brl',

            'product_data' => [
                'name' => $game['title']
            ],

            // PREÇO DINÂMICO
            'unit_amount' => intval($game['price'] * 100),
        ],

        'quantity' => 1,
    ]],

    'mode' => 'payment',

    'success_url' =>
        'http://localhost/indiezone-main/public/pages/payment_success.php?game_id=' . $game_id,

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