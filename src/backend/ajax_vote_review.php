<?php
// src/backend/ajax_vote_review.php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$review_id = intval($data['review_id'] ?? 0);

if ($review_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit();
}

// Verifica se já votou
$check = $conn->prepare("SELECT 1 FROM review_votes WHERE review_id = ? AND user_id = ?");
$check->bind_param("ii", $review_id, $user_id);
$check->execute();
$has_voted = $check->get_result()->num_rows > 0;

if ($has_voted) {
    // Remove o voto (Toggle Off)
    $stmt = $conn->prepare("DELETE FROM review_votes WHERE review_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $review_id, $user_id);
    $stmt->execute();
    $voted = false;
} else {
    // Adiciona o voto (Toggle On)
    $stmt = $conn->prepare("INSERT INTO review_votes (review_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $review_id, $user_id);
    $stmt->execute();
    $voted = true;
}

// Retorna a nova contagem
$count_query = $conn->query("SELECT COUNT(*) as total FROM review_votes WHERE review_id = $review_id");
$new_count = $count_query->fetch_assoc()['total'];

echo json_encode([
    'success' => true,
    'voted' => $voted,
    'new_count' => $new_count
]);