<?php
// src/backend/ajax_review.php
error_reporting(0); // Evita que avisos do PHP sujem o JSON de resposta
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Você precisa estar logado.']);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents('php://input'), true);

    $game_id = intval($data['game_id'] ?? 0);
    $rating = intval($data['rating'] ?? 0);
    $comment = trim(htmlspecialchars($data['content'] ?? '', ENT_QUOTES, 'UTF-8'));

    if ($game_id <= 0 || $rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'error' => 'Dados de avaliação inválidos.']);
        exit();
    }

    // 1. Verifica se o usuário TEM o jogo na biblioteca
    $check_lib = $conn->prepare("SELECT 1 FROM library WHERE user_id = ? AND game_id = ?");
    $check_lib->bind_param("ii", $user_id, $game_id);
    $check_lib->execute();
    if ($check_lib->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Você precisa ter o jogo na sua biblioteca para avaliar.']);
        exit();
    }

    // 2. Verifica se o usuário JÁ AVALIOU
    $check_rev = $conn->prepare("SELECT review_id FROM reviews WHERE user_id = ? AND game_id = ?");
    $check_rev->bind_param("ii", $user_id, $game_id);
    $check_rev->execute();
    $rev_result = $check_rev->get_result();

    if ($rev_result->num_rows > 0) {
        // UPDATE (Edição)
        $row = $rev_result->fetch_assoc();
        $review_id = $row['review_id'];
        
        $stmt = $conn->prepare("UPDATE reviews SET rating = ?, comment = ?, updated_at = CURRENT_TIMESTAMP WHERE review_id = ?");
        $stmt->bind_param("isi", $rating, $comment, $review_id);
        $action_text = "editada";
    } else {
        // INSERT (Nova)
        $stmt = $conn->prepare("INSERT INTO reviews (user_id, game_id, rating, comment) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $user_id, $game_id, $rating, $comment);
        $action_text = "publicada";
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => "Avaliação $action_text com sucesso!"]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Falha interna ao salvar no banco.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro crítico: ' . $e->getMessage()]);
}