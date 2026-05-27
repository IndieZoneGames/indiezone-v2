<?php
// [ARQUITETURA] Padrão MVC e Isolamento.
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php"); // Instanciando nosso Logger
/** @var mysqli $conn */

$logger = new SystemLogger($conn);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $login = trim($_POST['login']);
    $password = $_POST['password'];

    if (empty($login) || empty($password)) {
        $_SESSION['login_error'] = "Por favor, preencha todos os campos.";
        header("Location: ../../public/auth/login.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT user_id, display_name, username, email, password_hash, role, is_active, deleted_at FROM users WHERE email = ? OR username = ?");
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password_hash'])) {
            
            // 1. Conta desativada
            if ($user['is_active'] == 0 && $user['deleted_at'] !== null) {
                $logger->log('USER_LOGIN_BLOCKED_DELETED', 'WARNING', ['user_id' => $user['user_id']]); // LOG INSERIDO
                $_SESSION['reactivation_user_id'] = $user['user_id'];
                header("Location: ../../public/auth/login.php?action=reactivate");
                exit();
            }
            
            // 2. E-mail não confirmado
            if ($user['is_active'] == 0 && $user['deleted_at'] === null) {
                $logger->log('USER_LOGIN_BLOCKED_UNVERIFIED', 'WARNING', ['user_id' => $user['user_id']]); // LOG INSERIDO
                $_SESSION['login_error'] = "Sua conta ainda não foi ativada. <a href='resend_verification.php?email=".urlencode($user['email'])."' style='color: #22c55e; text-decoration: underline;'>Reenviar e-mail de ativação?</a>";
                header("Location: ../../public/auth/login.php");
                exit();
            }

            // 3. Sucesso!
            $logger->log('USER_LOGIN_SUCCESS', 'INFO', ['user_id' => $user['user_id']]); // LOG INSERIDO
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['display_name'] = $user['display_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header("Location: ../../public/pages/store.php");
            exit();
            
        } else {
            // Falha na senha
            $logger->log('USER_LOGIN_FAILED', 'WARNING', ['new_data' => ['login_attempt' => $login]]); // LOG INSERIDO
            $_SESSION['login_error'] = "Credenciais incorretas. Tente novamente.";
            header("Location: ../../public/auth/login.php");
            exit();
        }
    } else {
        // Usuário não existe
        $logger->log('USER_LOGIN_FAILED', 'WARNING', ['new_data' => ['login_attempt' => $login]]); // LOG INSERIDO
        $_SESSION['login_error'] = "Credenciais incorretas. Tente novamente.";
        header("Location: ../../public/auth/login.php");
        exit();
    }
}
?>