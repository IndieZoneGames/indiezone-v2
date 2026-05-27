<?php
/**
 * Esqueci minha Senha - IndieZone
 */

// [ARQUITETURA] A inclusão do 'config.php' e das bibliotecas externas (PHPMailer) ocorre isolada da camada visual. 
// Manter esses arquivos na pasta 'core' garante que as lógicas sensíveis e de conexão não fiquem expostas publicamente.
require_once '../../core/config.php';
/** @var mysqli $conn */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../../core/PHPMailer/src/Exception.php';
require '../../core/PHPMailer/src/PHPMailer.php';
require '../../core/PHPMailer/src/SMTP.php';

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {

    // [LÓGICA] Sanitização inicial simples para garantir que espaços acidentais digitados pelo usuário não invalidem a busca no banco de dados.
    $email = trim($_POST['email']);

    if (empty($email)) {
        $message = "❌ Por favor, informe seu e-mail.";
    } else {
        // [SEGURANÇA] Utilização estrita de Prepared Statements. O input do usuário nunca é concatenado diretamente na instrução SQL, 
        // o que neutraliza por completo qualquer tentativa de ataque via SQL Injection na busca do usuário.
        $stmt = $conn->prepare("SELECT user_id, display_name FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // [SEGURANÇA] Geração de token utilizando random_bytes(), uma função criptograficamente segura. 
            // Isso impossibilita que invasores usem engenharia reversa ou força bruta para adivinhar a URL de recuperação.
            $code = bin2hex(random_bytes(16));
            
            // [AUDITORIA] Ao atualizar a tabela users com o 'reset_code', o sistema cria um rastro direto associando o token temporário ao 'user_id' exato que solicitou a troca. 
            // Isso garante que o evento de alteração de senha seja rastreável e que o token seja de uso único e pessoal.
            $stmt_update = $conn->prepare("UPDATE users SET reset_code = ? WHERE user_id = ?");
            $stmt_update->bind_param("si", $code, $user['user_id']);

            if ($stmt_update->execute()) {
                
                // [LÓGICA] Instanciação do PHPMailer para garantir que o envio do e-mail seja feito de forma autenticada (SMTP) 
                // e não utilizando a função nativa mail() do PHP, o que evita que a mensagem caia no spam dos usuários.
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = $_ENV['SMTP_HOST'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $_ENV['SMTP_USER'];
                    $mail->Password   = $_ENV['SMTP_PASS'];
                    $mail->SMTPSecure = $_ENV['SMTP_SECURE'];
                    $mail->Port       = $_ENV['SMTP_PORT'];
                    $mail->CharSet    = 'UTF-8';

                    $mail->setFrom($_ENV['SMTP_USER'], 'IndieZone');
                    $mail->addAddress($email, $user['display_name']);

                    $mail->isHTML(true);
                    $mail->Subject = "Recuperação de Senha - IndieZone";

                    $link = APP_URL . "/auth/reset_password.php?code=$code";

                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; color: #333;'>
                            <h2>Olá, {$user['display_name']}! 👋</h2>
                            <p>Recebemos uma solicitação para redefinir a senha da sua conta na IndieZone.</p>
                            <p>Se foi você, clique no botão abaixo para criar uma nova senha:</p>
                            <div style='margin: 30px 0;'>
                                <a href='$link' style='background: #22c55e; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Redefinir minha senha</a>
                            </div>
                            <p>Se você não solicitou isso, pode ignorar este e-mail com segurança.</p>
                        </div>
                    ";

                    $mail->send();
                    $message = "✅ Se o e-mail estiver cadastrado, você receberá um link de recuperação em instantes.";
                } catch (Exception $e) {
                    $message = "❌ Erro técnico ao enviar o e-mail. Tente novamente mais tarde.";
                }
            }
            $stmt_update->close();
        } else {
            // [SEGURANÇA] Estratégia crucial contra 'User Enumeration' (Enumeração de Usuários). 
            // O sistema responde com a exata mesma mensagem de sucesso mesmo se o e-mail não existir, impedindo que hackers descubram quem tem conta ativa na plataforma.
            $message = "✅ Se o e-mail estiver cadastrado, você receberá um link de recuperação em instantes.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha - IndieZone</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body data-theme="dark">

    <div class="auth-screen">
        <div class="auth-box">
            <div class="brand">
                <h1>Recuperar Senha</h1>
                <p>Enviaremos as instruções para o seu e-mail</p>
            </div>

            <?php if (!empty($message)): ?>
                <?php
                    // [ARQUITETURA] Separação limpa da lógica de apresentação. A cor da mensagem é definida no backend, mas renderizada via classes CSS estáticas.
                    $status = (strpos($message, '❌') !== false) ? 'error' : 'success';
                    $msg_class = ($status === 'error') ? 'msg-error-subtle' : 'msg-success-subtle';
                    
                    $clean_message = str_replace(['✅', '❌'], '', $message);
                ?>
                <!-- [SEGURANÇA] O uso de htmlspecialchars impede ataques de Cross-Site Scripting (XSS), garantindo que qualquer string exibida na tela seja tratada como texto puro e não como código executável no navegador. -->
                <div class="msg-subtle <?php echo $msg_class; ?>">
                    <?php echo htmlspecialchars(trim($clean_message)); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>E-mail cadastrado</label>
                    <input type="email" name="email" placeholder="seu@email.com" class="input-base" required autofocus>
                </div>
                
                <button type="submit" name="reset" class="btn">Enviar Link de Recuperação</button>
            </form>

            <div class="auth-footer">
                <p>Lembrou a senha? <a href="login.php">Voltar ao Login</a></p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');
        });
    </script>
</body>
</html>