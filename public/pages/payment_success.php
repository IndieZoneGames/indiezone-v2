<?php

require_once __DIR__ . '/../../core/config.php';

/** @var mysqli $conn */

session_start();

// VERIFICA LOGIN
if (!isset($_SESSION['user_id'])) {

    die("Faça login.");
}

// USER
$user_id = $_SESSION['user_id'];

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

// EVITA DUPLICAR NA BIBLIOTECA
$check = mysqli_query($conn, "

SELECT *

FROM library

WHERE user_id = $user_id
AND game_id = $game_id

LIMIT 1

");

// SE NÃO EXISTIR → INSERE
if (mysqli_num_rows($check) == 0) {

    mysqli_query($conn, "

    INSERT INTO library (

        user_id,
        game_id

    )

    VALUES (

        $user_id,
        $game_id

    )

    ");
}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Pagamento aprovado</title>
</head>

<body style="
background:#0f172a;
color:white;
font-family:Arial;
padding:40px;
">

    <h1 style="color:#22c55e;">
        ✅ Pagamento concluído!
    </h1>

    <p style="font-size:20px;">
        Seu pagamento foi aprovado com sucesso.
    </p>

    <div style="
        background:#1e293b;
        padding:20px;
        border-radius:10px;
        margin-top:25px;
    ">

        <h2>
            🎮 Jogo liberado na sua biblioteca
        </h2>

        <p>
            O jogo foi adicionado automaticamente à sua conta.
        </p>

        <p>
            Agora você já pode baixar quando quiser.
        </p>

    </div>

    <div style="
        background:#111827;
        padding:20px;
        border-radius:10px;
        margin-top:25px;
    ">

        <h3>
            📁 Caminho atual do jogo
        </h3>

        <p style="color:#22c55e;word-break:break-all;">
            <?php echo htmlspecialchars($game['download_url'] ?? 'Download ainda não configurado'); ?>
        </p>

    </div>

    <br><br>

    <a href="library.php"
    style="
    background:#22c55e;
    color:black;
    padding:15px 25px;
    text-decoration:none;
    border-radius:8px;
    font-weight:bold;
    ">
        Ir para Biblioteca
    </a>

</body>
</html>