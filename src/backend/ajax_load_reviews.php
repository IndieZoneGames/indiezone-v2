<?php
// src/backend/ajax_load_reviews.php
error_reporting(0);
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

$game_id = isset($_GET['game_id']) ? intval($_GET['game_id']) : 0;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$limit = 10;
$user_id = $_SESSION['user_id'] ?? 0;

if ($game_id <= 0) exit();

$query = "SELECT r.*, u.username, u.display_name, u.avatar_url,
          (SELECT COUNT(*) FROM review_votes rv WHERE rv.review_id = r.review_id) as helpful_count,
          (SELECT 1 FROM review_votes rv2 WHERE rv2.review_id = r.review_id AND rv2.user_id = $user_id) as user_voted
          FROM reviews r 
          JOIN users u ON r.user_id = u.user_id 
          WHERE r.game_id = $game_id 
          ORDER BY helpful_count DESC, r.created_at DESC
          LIMIT $limit OFFSET $offset";

$result = mysqli_query($conn, $query);

if (mysqli_num_rows($result) > 0) {
    while ($rev = mysqli_fetch_assoc($result)) {
        $avatar = htmlspecialchars($rev['avatar_url']);
        $author = htmlspecialchars($rev['display_name'] ?: $rev['username']);
        $date = date('d/m/Y', strtotime($rev['created_at']));
        if($rev['updated_at'] != $rev['created_at']) $date .= " (Editado)";
        
        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            $color = $i <= $rev['rating'] ? '#f59e0b' : '#334155';
            $stars .= '<svg width="14" height="14" fill="'.$color.'" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
        }

        $content = nl2br(htmlspecialchars($rev['comment']));
        $votedClass = $rev['user_voted'] ? 'voted' : '';
        $review_id = $rev['review_id'];
        $count = $rev['helpful_count'];

        // Mantém a mesma estrutura HTML dos cards originais
        echo "
        <div class='review-card' style='animation: fadeIn 0.5s ease-in-out'>
            <div class='review-header'>
                <div class='review-header-left'>
                    <img src='$avatar' alt='Avatar' class='review-avatar'>
                    <div>
                        <span class='review-author'>$author</span>
                        <div class='stars-display' style='margin-top: 4px;'>
                            $stars
                            <span class='review-date' style='margin-left: 8px;'>$date</span>
                        </div>
                    </div>
                </div>
                <div class='review-header-right'>
                    <button onclick='toggleHelpful($review_id, this)' class='btn-helpful $votedClass' title='Marcar como útil'>
                        👍 <span class='helpful-count'>$count</span>
                    </button>
                </div>
            </div>
            <div class='review-content'>$content</div>
        </div>";
    }
}