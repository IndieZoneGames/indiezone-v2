<?php
// src/backend/ajax_delete_comment.php
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php"); // [AUDITORIA] Inclusão do Logger
/** @var mysqli $conn */

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$logger = new SystemLogger($conn);
$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$comment_id = intval($data['comment_id'] ?? 0);

if ($comment_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Comentário inválido']);
    exit();
}

// Verifica de quem é o comentário e de quem é o post
$stmt_check = $conn->prepare("
    SELECT c.user_id as author_id, cp.developer_id as post_owner_id, c.post_id 
    FROM post_comments c
    JOIN community_posts cp ON c.post_id = cp.post_id
    WHERE c.comment_id = ?
");
$stmt_check->bind_param("i", $comment_id);
$stmt_check->execute();
$res_check = $stmt_check->get_result();

if ($res_check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Comentário não encontrado']);
    exit();
}

$row = $res_check->fetch_assoc();

// Regra de validação estrita
if ($user_id != $row['author_id'] && $user_id != $row['post_owner_id']) {
    // [AUDITORIA] Registra tentativa de excluir comentário de terceiros sem ser o dono do post
    $logger->log('DELETE_COMMENT_UNAUTHORIZED', 'WARNING', ['user_id' => $user_id, 'new_data' => ['attempted_comment_id' => $comment_id]]);
    echo json_encode(['success' => false, 'error' => 'Sem permissão para excluir']);
    exit();
}

// Exclui de facto
$stmt_del = $conn->prepare("DELETE FROM post_comments WHERE comment_id = ?");
$stmt_del->bind_param("i", $comment_id);

if ($stmt_del->execute()) {
    // [AUDITORIA] Grava se o comentário foi excluído pelo autor ou moderado pelo dono do post
    $deleted_by = ($user_id == $row['author_id']) ? 'author' : 'moderator';
    $logger->log('DELETE_COMMENT', 'INFO', [
        'user_id' => $user_id, 
        'entity_table' => 'post_comments', 
        'entity_id' => $comment_id, 
        'new_data' => [
            'post_id' => $row['post_id'], 
            'deleted_by' => $deleted_by, 
            'original_author' => $row['author_id']
        ]
    ]);

    echo json_encode(['success' => true]);
} else {
    // [AUDITORIA] Grava erro na exclusão do banco
    $logger->log('DELETE_COMMENT_ERROR', 'CRITICAL', ['user_id' => $user_id, 'entity_id' => $comment_id, 'new_data' => ['error' => $conn->error]]);
    echo json_encode(['success' => false, 'error' => 'Falha ao excluir no banco de dados']);
}
?>