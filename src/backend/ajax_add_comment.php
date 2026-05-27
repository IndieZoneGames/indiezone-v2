<?php
// src/backend/ajax_add_comment.php
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

$post_id = intval($data['post_id'] ?? 0);
$content = trim(htmlspecialchars($data['content'] ?? '', ENT_QUOTES, 'UTF-8'));

if ($post_id <= 0 || empty($content)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit();
}

$stmt = $conn->prepare("INSERT INTO post_comments (post_id, user_id, content) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $post_id, $user_id, $content);

if ($stmt->execute()) {
    $comment_id = $conn->insert_id; // Captura o ID real no banco
    
    $display_name = $_SESSION['display_name'] ?? $_SESSION['username'];
    $avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);

    echo json_encode([
        'success' => true,
        'comment' => [
            'id' => $comment_id,
            'author' => $display_name,
            'avatar' => $avatar_url,
            'content' => nl2br($content),
            'can_delete' => true // O autor sempre pode apagar o próprio comentário
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar no banco']);
}