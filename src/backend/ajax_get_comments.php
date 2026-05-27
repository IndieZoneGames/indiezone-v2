<?php
// src/backend/ajax_get_comments.php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$user_id = $_SESSION['user_id'];
$post_id = intval($_GET['post_id'] ?? 0);

if ($post_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Post inválido']);
    exit();
}

function time_ago_backend($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return "agora mesmo";
    if ($diff < 3600) return round($diff / 60) . " min atrás";
    if ($diff < 86400) return round($diff / 3600) . "h atrás";
    if ($diff < 604800) return round($diff / 86400) . "d atrás";
    return date('d M Y', $time);
}

// Junta a tabela community_posts para sabermos quem é o developer_id dono da publicação
$q_comments = "SELECT c.comment_id, c.content, c.created_at, c.user_id as comment_author_id, 
                      u.display_name, u.username, u.avatar_url, cp.developer_id 
               FROM post_comments c 
               JOIN users u ON c.user_id = u.user_id 
               JOIN community_posts cp ON c.post_id = cp.post_id
               WHERE c.post_id = ? ORDER BY c.created_at ASC";

$stmt = $conn->prepare($q_comments);
$stmt->bind_param("i", $post_id);
$stmt->execute();
$res = $stmt->get_result();

$comments = [];
while ($row = $res->fetch_assoc()) {
    $c_name = $row['display_name'] ?? $row['username'];
    $c_avatar = $row['avatar_url'] ?: "https://api.dicebear.com/7.x/pixel-art/svg?seed=".urlencode($row['username']);
    
    // A MÁGICA: Pode apagar se for o autor do comentário OU se for o dev dono do post
    $can_delete = ($user_id == $row['comment_author_id'] || $user_id == $row['developer_id']);

    $comments[] = [
        'id' => $row['comment_id'],
        'author' => htmlspecialchars($c_name),
        'avatar' => htmlspecialchars($c_avatar),
        'time' => time_ago_backend($row['created_at']),
        'content' => nl2br(htmlspecialchars($row['content'])),
        'can_delete' => $can_delete
    ];
}

echo json_encode(['success' => true, 'comments' => $comments]);