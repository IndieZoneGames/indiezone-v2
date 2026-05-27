<?php
// src/backend/process_become_dev.php
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php"); // Instanciando nosso Logger
/** @var mysqli $conn */

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'player') {
    header("Location: ../../public/auth/login.php");
    exit();
}

$logger = new SystemLogger($conn);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION['user_id'];
    
    $studio_name = trim($_POST['studio_name']);
    $support_email = trim($_POST['support_email']);
    $bio = trim($_POST['bio']);
    $document_number = preg_replace('/\D/', '', $_POST['document_number']); 
    $cep = preg_replace('/\D/', '', $_POST['cep']);
    $logradouro = trim($_POST['logradouro']);
    $numero = trim($_POST['numero']);
    $complemento = trim($_POST['complemento']);
    $bairro = trim($_POST['bairro']);
    $cidade = trim($_POST['cidade']);
    $estado = trim($_POST['estado']);
    $portfolio_url = trim($_POST['portfolio_url']);
    $first_game_pitch = trim($_POST['first_game_pitch']);

    $_SESSION['form_data'] = $_POST;

    function validaDocumento($doc) { /* Sua lógica perfeita mantida intacta */
        if (strlen($doc) == 11) {
            if (preg_match('/(\d)\1{10}/', $doc)) return false; 
            for ($t = 9; $t < 11; $t++) {
                for ($d = 0, $c = 0; $c < $t; $c++) $d += $doc[$c] * (($t + 1) - $c);
                $d = ((10 * $d) % 11) % 10;
                if ($doc[$c] != $d) return false;
            }
            return true;
        } elseif (strlen($doc) == 14) {
            if (preg_match('/(\d)\1{13}/', $doc)) return false;
            for ($i = 0, $j = 0; $i < 2; $i++) {
                $soma = 0;
                $multiplicador = ($i == 0) ? 5 : 6;
                for ($j = 0; $j < 12 + $i; $j++) {
                    $soma += $doc[$j] * $multiplicador;
                    $multiplicador = ($multiplicador == 2) ? 9 : $multiplicador - 1;
                }
                $resto = $soma % 11;
                $digito = ($resto < 2) ? 0 : 11 - $resto;
                if ($doc[12 + $i] != $digito) return false;
            }
            return true;
        }
        return false;
    }

    if (!validaDocumento($document_number)) {
        $logger->log('BECOME_DEV_INVALID_DOC', 'WARNING', ['user_id' => $user_id, 'new_data' => ['doc_attempt' => $document_number]]); // LOG INSERIDO
        $_SESSION['form_error'] = 'document_number';
        $_SESSION['form_error_msg'] = 'O CPF ou CNPJ informado é inválido.';
        header("Location: ../../public/pages/become_dev.php");
        exit();
    }

    if (!filter_var($portfolio_url, FILTER_VALIDATE_URL)) {
        $logger->log('BECOME_DEV_INVALID_URL', 'WARNING', ['user_id' => $user_id, 'new_data' => ['url_attempt' => $portfolio_url]]); // LOG INSERIDO
        $_SESSION['form_error'] = 'global';
        $_SESSION['form_error_msg'] = 'O link do portfólio informado é inválido.';
        header("Location: ../../public/pages/become_dev.php");
        exit();
    }

    if (strlen($document_number) == 11) {
        $doc_formatado = preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $document_number);
    } else {
        $doc_formatado = preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $document_number);
    }

    $stmt_check = $conn->prepare("SELECT approval_status FROM developers WHERE user_id = ?");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    
    $exists = false;
    if ($row = $result->fetch_assoc()) {
        $exists = true;
        if ($row['approval_status'] === 'pending' || $row['approval_status'] === 'approved') {
            $logger->log('BECOME_DEV_BLOCKED_STATUS', 'WARNING', ['user_id' => $user_id, 'new_data' => ['current_status' => $row['approval_status']]]); // LOG INSERIDO
            $_SESSION['form_error'] = 'global';
            $_SESSION['form_error_msg'] = $row['approval_status'] === 'pending' ? 'Você já possui uma solicitação em análise!' : 'Você já está cadastrado como desenvolvedor.';
            header("Location: ../../public/pages/become_dev.php");
            exit();
        }
    }
    $stmt_check->close();

    mysqli_begin_transaction($conn);

    try {
        if ($exists) {
            $stmt_dev = $conn->prepare("UPDATE developers SET studio_name = ?, support_email = ?, document_number = ?, bio = ?, portfolio_url = ?, first_game_pitch = ?, approval_status = 'pending' WHERE user_id = ?");
            $stmt_dev->bind_param("ssssssi", $studio_name, $support_email, $doc_formatado, $bio, $portfolio_url, $first_game_pitch, $user_id);
            
            $stmt_addr = $conn->prepare("UPDATE user_addresses SET cep = ?, logradouro = ?, numero = ?, complemento = ?, bairro = ?, cidade = ?, estado = ? WHERE user_id = ? AND address_type = 'commercial'");
            $stmt_addr->bind_param("sssssssi", $cep, $logradouro, $numero, $complemento, $bairro, $cidade, $estado, $user_id);
        } else {
            $stmt_dev = $conn->prepare("INSERT INTO developers (user_id, studio_name, support_email, document_number, bio, portfolio_url, first_game_pitch, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt_dev->bind_param("issssss", $user_id, $studio_name, $support_email, $doc_formatado, $bio, $portfolio_url, $first_game_pitch);
            
            $address_type = 'commercial';
            $stmt_addr = $conn->prepare("INSERT INTO user_addresses (user_id, address_type, cep, logradouro, numero, complemento, bairro, cidade, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_addr->bind_param("issssssss", $user_id, $address_type, $cep, $logradouro, $numero, $complemento, $bairro, $cidade, $estado);
        }

        $stmt_dev->execute();
        $stmt_addr->execute();
        mysqli_commit($conn);

        // LOG DE SUCESSO INSERIDO
        $action = $exists ? 'BECOME_DEV_REAPPLIED' : 'BECOME_DEV_REQUESTED';
        $logger->log($action, 'INFO', [
            'user_id' => $user_id, 
            'entity_table' => 'developers', 
            'entity_id' => $user_id,
            'new_data' => ['studio_name' => $studio_name]
        ]);

        unset($_SESSION['form_data'], $_SESSION['form_error'], $_SESSION['form_error_msg']);
        $_SESSION['profile_msg'] = "Sua nova solicitação foi enviada para análise! 🎉";
        header("Location: ../../public/pages/become_dev.php");
        exit();

    } catch (Throwable $e) { 
        mysqli_rollback($conn);
        // LOG DE ERRO CRÍTICO NO BANCO INSERIDO
        $logger->log('BECOME_DEV_ERROR', 'CRITICAL', [
            'user_id' => $user_id, 
            'new_data' => ['error_message' => $e->getMessage()]
        ]);

        $_SESSION['form_error'] = 'global';
        $_SESSION['form_error_msg'] = 'Erro ao processar: ' . $e->getMessage();
        header("Location: ../../public/pages/become_dev.php");
        exit();
    }
}
?>