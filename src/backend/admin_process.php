<?php
// src/backend/admin_process.php

// [ARQUITETURA] Padrão Controller e PRG (Post/Redirect/Get). Este arquivo atua exclusivamente no backend. Ele recebe a submissão dos formulários do painel de administração, processa as regras de banco de dados e redireciona o usuário de volta à View, separando completamente a lógica da apresentação.
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php"); // [AUDITORIA] Instanciando a "câmera de segurança"
/** @var mysqli $conn */

// BLINDAGEM DE SEGURANÇA: Só aceita ordens de um Admin real!
// [SEGURANÇA] Proteção de Endpoint contra Bypass. Esta validação de RBAC ('role' === 'admin') logo na primeira linha barra ataques onde invasores tentam forjar requisições POST diretas para a URL do script via ferramentas como cURL ou Postman.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Acesso não autorizado.");
}

$logger = new SystemLogger($conn);
$admin_id = $_SESSION['user_id']; // Guarda quem está fazendo a ação

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    
    $action = $_POST['action'];
    
    // [SEGURANÇA] Sanitização Estrita por Type Casting. Forçar a conversão de todos os IDs recebidos do formulário para números inteiros `(int)` anula instantaneamente qualquer tentativa de injeção de código ou poluição de parâmetros nessas variáveis.
    $target_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
    $target_game_id = isset($_POST['target_game_id']) ? (int)$_POST['target_game_id'] : 0;
    $target_with_id = isset($_POST['target_with_id']) ? (int)$_POST['target_with_id'] : 0;

    // --- 1. MODERAÇÃO DE USUÁRIOS ---
    if ($action === 'ban_user') {
        // [AUDITORIA] Exemplo perfeito de Soft Delete. A exclusão real ('DELETE') destruiria o rastro financeiro do usuário. Aqui, a conta é desativada e a coluna 'deleted_at' atua como um carimbo de tempo da ação disciplinar.
        // [LÓGICA] A trava condicional "AND role != 'admin'" é uma proteção contra falhas operacionais, impedindo que administradores punam ou excluam a si mesmos acidentalmente.
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, deleted_at = CURRENT_TIMESTAMP WHERE user_id = ? AND role != 'admin'");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_BAN_USER', 'WARNING', ['user_id' => $admin_id, 'entity_table' => 'users', 'entity_id' => $target_id]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Usuário banido/inativado com sucesso.";
        }
    } 
    elseif ($action === 'reactivate_user') {
        // [AUDITORIA] Restauração rastreável. A conta é reativada limpando o campo 'deleted_at', recuperando todos os vínculos passados sem afetar o histórico relacional.
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, deleted_at = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_REACTIVATE_USER', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'users', 'entity_id' => $target_id]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Conta de usuário reativada com sucesso.";
        }
    }

    // --- 2. APROVAÇÃO DE ESTÚDIOS ---
    elseif ($action === 'approve_dev') {
        // [AUDITORIA] Transação ACID de Elevação de Privilégio. O sistema precisa alterar o status da requisição ('approved') E elevar a role global do usuário ('dev'). O bloco BEGIN/COMMIT garante que não haverá inconsistências se o banco falhar no meio do processo.
        mysqli_begin_transaction($conn);
        try {
            $stmt1 = $conn->prepare("UPDATE developers SET approval_status = 'approved' WHERE user_id = ?");
            $stmt1->bind_param("i", $target_id);
            $stmt1->execute();

            $stmt2 = $conn->prepare("UPDATE users SET role = 'dev' WHERE user_id = ?");
            $stmt2->bind_param("i", $target_id);
            $stmt2->execute();

            mysqli_commit($conn);
            $logger->log('ADMIN_APPROVE_DEV', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'developers', 'entity_id' => $target_id]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Estúdio aprovado! O usuário agora é um Desenvolvedor oficial.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $logger->log('ADMIN_APPROVE_DEV_ERROR', 'CRITICAL', ['user_id' => $admin_id, 'entity_table' => 'developers', 'entity_id' => $target_id, 'new_data' => ['error' => $e->getMessage()]]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Erro ao aprovar estúdio.";
        }
    }
    elseif ($action === 'reject_dev') {
        $stmt = $conn->prepare("UPDATE developers SET approval_status = 'rejected' WHERE user_id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_REJECT_DEV', 'WARNING', ['user_id' => $admin_id, 'entity_table' => 'developers', 'entity_id' => $target_id]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Solicitação de estúdio rejeitada.";
        }
    }

    // --- 3. APROVAÇÃO DE JOGOS ---
    elseif ($action === 'approve_game') {
        // [AUDITORIA] Timestamp de publicação. Além de liberar o acesso ('published'), o 'published_at = CURRENT_TIMESTAMP' crava permanentemente na base quando o jogo se tornou público, servindo como métrica oficial para os contratos do estúdio.
        $stmt = $conn->prepare("UPDATE games SET status = 'published', published_at = CURRENT_TIMESTAMP WHERE game_id = ?");
        $stmt->bind_param("i", $target_game_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_APPROVE_GAME', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'games', 'entity_id' => $target_game_id]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Jogo aprovado e publicado na loja com sucesso!";
        }
    }
    elseif ($action === 'reject_game') {
        // Volta o jogo para rascunho para o dev corrigir
        $stmt = $conn->prepare("UPDATE games SET status = 'draft' WHERE game_id = ?");
        $stmt->bind_param("i", $target_game_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_REJECT_GAME', 'WARNING', ['user_id' => $admin_id, 'entity_table' => 'games', 'entity_id' => $target_game_id]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Jogo rejeitado e devolvido para rascunho.";
        }
    }

    // --- 4. GESTÃO FINANCEIRA (SAQUES) ---
    elseif ($action === 'complete_withdrawal') {
        $stmt = $conn->prepare("UPDATE withdrawals SET status = 'completed' WHERE id = ?");
        $stmt->bind_param("i", $target_with_id);
        if ($stmt->execute()) {
            $logger->log('ADMIN_COMPLETE_WITHDRAWAL', 'INFO', ['user_id' => $admin_id, 'entity_table' => 'withdrawals', 'entity_id' => $target_with_id]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Saque marcado como PAGO com sucesso!";
        }
    }
    elseif ($action === 'reject_withdrawal') {
        // Se rejeitar o saque, tem que devolver o dinheiro para o saldo do dev!
        // [LÓGICA] Compensação de Estorno Segura. Rejeitar um saque exige que o saldo retirado anteriormente volte para a carteira do usuário. O uso da transação (BEGIN/COMMIT) blinda a plataforma contra a perda desse dinheiro se houver oscilação de conectividade.
        mysqli_begin_transaction($conn);
        try {
            // Pega os dados do saque
            $stmt_info = $conn->prepare("SELECT developer_id, amount FROM withdrawals WHERE id = ?");
            $stmt_info->bind_param("i", $target_with_id);
            $stmt_info->execute();
            $with_info = $stmt_info->get_result()->fetch_assoc();

            if ($with_info) {
                // Rejeita o saque
                $stmt_rej = $conn->prepare("UPDATE withdrawals SET status = 'rejected' WHERE id = ?");
                $stmt_rej->bind_param("i", $target_with_id);
                $stmt_rej->execute();

                // Devolve o dinheiro
                $stmt_refund = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE user_id = ?");
                $stmt_refund->bind_param("di", $with_info['amount'], $with_info['developer_id']);
                $stmt_refund->execute();
            }
            mysqli_commit($conn);
            // [AUDITORIA] Log robusto gravando no 'new_data' o valor que foi estornado e para quem foi
            $logger->log('ADMIN_REJECT_WITHDRAWAL', 'WARNING', [
                'user_id' => $admin_id, 
                'entity_table' => 'withdrawals', 
                'entity_id' => $target_with_id,
                'new_data' => ['refunded_amount' => $with_info['amount'], 'to_developer_id' => $with_info['developer_id']]
            ]);
            $_SESSION['admin_msg'] = "Saque rejeitado e valor devolvido à carteira do Desenvolvedor.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $logger->log('ADMIN_REJECT_WITHDRAWAL_ERROR', 'CRITICAL', ['user_id' => $admin_id, 'entity_table' => 'withdrawals', 'entity_id' => $target_with_id, 'new_data' => ['error' => $e->getMessage()]]); // LOG INSERIDO
            $_SESSION['admin_msg'] = "Erro ao rejeitar saque.";
        }
    }

    // [ARQUITETURA] Encerramento do ciclo PRG. Após a persistência segura dos dados e a definição das variáveis Flash de feedback, o controlador aborta a execução e redireciona o usuário para visualizar as novidades.
    header("Location: ../../public/pages/admin.php");
    exit();
} else {
    header("Location: ../../public/pages/admin.php");
    exit();
}
?>