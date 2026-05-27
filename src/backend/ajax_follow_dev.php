<?php
// src/backend/ajax_follow_dev.php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$follower_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$developer_id = intval($data['developer_id'] ?? 0);

if ($developer_id <= 0 || $follower_id == $developer_id) {
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    exit();
}

// Verifica se já segue
$stmt_check = $conn->prepare("SELECT 1 FROM user_follows WHERE follower_id = ? AND developer_id = ?");
$stmt_check->bind_param("ii", $follower_id, $developer_id);
$stmt_check->execute();
$already_following = $stmt_check->get_result()->num_rows > 0;

if ($already_following) {
    // Deixar de seguir
    $stmt_del = $conn->prepare("DELETE FROM user_follows WHERE follower_id = ? AND developer_id = ?");
    $stmt_del->bind_param("ii", $follower_id, $developer_id);
    $stmt_del->execute();
    $action = 'unfollowed';
} else {
    // Começar a seguir
    $stmt_ins = $conn->prepare("INSERT INTO user_follows (follower_id, developer_id) VALUES (?, ?)");
    $stmt_ins->bind_param("ii", $follower_id, $developer_id);
    $stmt_ins->execute();
    $action = 'followed';
}

echo json_encode(['success' => true, 'action' => $action]);