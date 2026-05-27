<?php
// src/backend/ajax_like_post.php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$user_id = $_SESSION['user_id'];
// Lê os dados enviados via Fetch API (JSON)
$data = json_decode(file_get_contents('php://input'), true);
$post_id = intval($data['post_id'] ?? 0);

if ($post_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Post inválido']);
    exit();
}

// Verifica se já curtiu
$stmt_check = $conn->prepare("SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ?");
$stmt_check->bind_param("ii", $post_id, $user_id);
$stmt_check->execute();
$already_liked = $stmt_check->get_result()->num_rows > 0;

if ($already_liked) {
    // Remove o Like (Descurtir)
    $stmt_del = $conn->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
    $stmt_del->bind_param("ii", $post_id, $user_id);
    $stmt_del->execute();
    $action = 'unliked';
} else {
    // Adiciona o Like
    $stmt_ins = $conn->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
    $stmt_ins->bind_param("ii", $post_id, $user_id);
    $stmt_ins->execute();
    $action = 'liked';
}

// Retorna a nova contagem de likes atualizada
$stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM post_likes WHERE post_id = ?");
$stmt_count->bind_param("i", $post_id);
$stmt_count->execute();
$new_total = $stmt_count->get_result()->fetch_assoc()['total'];

echo json_encode(['success' => true, 'action' => $action, 'new_total' => $new_total]);