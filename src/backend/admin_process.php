<?php
// src/backend/admin_process.php
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php");
/** @var mysqli $conn */

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Acesso não autorizado.");
}

$logger = new SystemLogger($conn);
$admin_id = $_SESSION['user_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    
    // [SEGURANÇA TCC] Validação do Token Anti-CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $logger->log('CSRF_ATTEMPT', 'CRITICAL', ['user_id' => $admin_id, 'new_data' => 'Tentativa de requisição forjada bloqueada.']);
        die("Ação não autorizada. Token de segurança inválido.");
    }

    $action = $_POST['action'];
    $target_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
    $target_game_id = isset($_POST['target_game_id']) ? (int)$_POST['target_game_id'] : 0;
    $target_with_id = isset($_POST['target_with_id']) ? (int)$_POST['target_with_id'] : 0;

    // --- 1. MODERAÇÃO DE USUÁRIOS ---
    if ($action === 'ban_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, deleted_at = CURRENT_TIMESTAMP WHERE user_id = ? AND role != 'admin'");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_BAN_USER', 'WARNING', ['user_id' => $admin_id, 'entity_table' => 'users', 'entity_id' => $target_id]);
            $_SESSION['admin_msg'] = "Usuário banido/inativado com sucesso.";
        }
    } 
    elseif ($action === 'reactivate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, deleted_at = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_REACTIVATE_USER', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'users', 'entity_id' => $target_id]);
            $_SESSION['admin_msg'] = "Conta de usuário reativada com sucesso.";
        }
    }

    // --- 2. APROVAÇÃO DE ESTÚDIOS ---
    elseif ($action === 'approve_dev') {
        mysqli_begin_transaction($conn);
        try {
            $stmt1 = $conn->prepare("UPDATE developers SET approval_status = 'approved' WHERE user_id = ?");
            $stmt1->bind_param("i", $target_id);
            $stmt1->execute();

            $stmt2 = $conn->prepare("UPDATE users SET role = 'dev' WHERE user_id = ?");
            $stmt2->bind_param("i", $target_id);
            $stmt2->execute();

            mysqli_commit($conn);
            $logger->log('ADMIN_APPROVE_DEV', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'developers', 'entity_id' => $target_id]);
            $_SESSION['admin_msg'] = "Estúdio aprovado! O usuário agora é um Desenvolvedor oficial.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $logger->log('ADMIN_APPROVE_DEV_ERROR', 'CRITICAL', ['user_id' => $admin_id, 'entity_table' => 'developers', 'entity_id' => $target_id, 'new_data' => ['error' => $e->getMessage()]]);
            $_SESSION['admin_msg'] = "Erro ao aprovar estúdio.";
        }
    }
    elseif ($action === 'reject_dev') {
        $stmt = $conn->prepare("UPDATE developers SET approval_status = 'rejected' WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_REJECT_DEV', 'WARNING', ['user_id' => $admin_id, 'entity_table' => 'developers', 'entity_id' => $target_id]);
            $_SESSION['admin_msg'] = "Solicitação de estúdio rejeitada.";
        }
    }

    // --- 3. APROVAÇÃO DE JOGOS ---
    elseif ($action === 'approve_game') {
        $stmt = $conn->prepare("UPDATE games SET status = 'published', published_at = CURRENT_TIMESTAMP WHERE game_id = ?");
        $stmt->bind_param("i", $target_game_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_APPROVE_GAME', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'games', 'entity_id' => $target_game_id]);
            $_SESSION['admin_msg'] = "Jogo aprovado e publicado na loja com sucesso!";
        }
    }
    elseif ($action === 'reject_game') {
        $stmt = $conn->prepare("UPDATE games SET status = 'draft' WHERE game_id = ?");
        $stmt->bind_param("i", $target_game_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_REJECT_GAME', 'WARNING', ['user_id' => $admin_id, 'entity_table' => 'games', 'entity_id' => $target_game_id]);
            $_SESSION['admin_msg'] = "Jogo rejeitado e devolvido para rascunho.";
        }
    }

    // --- 4. GESTÃO FINANCEIRA (SAQUES) ---
    elseif ($action === 'complete_withdrawal') {
        $stmt = $conn->prepare("UPDATE withdrawals SET status = 'completed' WHERE id = ?");
        $stmt->bind_param("i", $target_with_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_COMPLETE_WITHDRAWAL', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'withdrawals', 'entity_id' => $target_with_id]);
            $_SESSION['admin_msg'] = "Saque marcado como PAGO com sucesso!";
        }
    }
    elseif ($action === 'reject_withdrawal') {
        mysqli_begin_transaction($conn);
        try {
            $stmt_info = $conn->prepare("SELECT developer_id, amount FROM withdrawals WHERE id = ?");
            $stmt_info->bind_param("i", $target_with_id);
            $stmt_info->execute();
            $with_info = $stmt_info->get_result()->fetch_assoc();

            if ($with_info) {
                $stmt_rej = $conn->prepare("UPDATE withdrawals SET status = 'rejected' WHERE id = ?");
                $stmt_rej->bind_param("i", $target_with_id);
                $stmt_rej->execute();

                $stmt_refund = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE user_id = ?");
                $stmt_refund->bind_param("di", $with_info['amount'], $with_info['developer_id']);
                $stmt_refund->execute();
            }
            mysqli_commit($conn);
            $logger->log('ADMIN_REJECT_WITHDRAWAL', 'WARNING', [
                'user_id' => $admin_id, 
                'entity_table' => 'withdrawals', 
                'entity_id' => $target_with_id,
                'new_data' => ['refunded_amount' => $with_info['amount'], 'to_developer_id' => $with_info['developer_id']]
            ]);
            $_SESSION['admin_msg'] = "Saque rejeitado e valor devolvido à carteira do Desenvolvedor.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $logger->log('ADMIN_REJECT_WITHDRAWAL_ERROR', 'CRITICAL', ['user_id' => $admin_id, 'entity_table' => 'withdrawals', 'entity_id' => $target_with_id, 'new_data' => ['error' => $e->getMessage()]]);
            $_SESSION['admin_msg'] = "Erro ao rejeitar saque.";
        }
    }

    header("Location: ../../public/pages/admin.php");
    exit();
} else {
    header("Location: ../../public/pages/admin.php");
    exit();
}
?>