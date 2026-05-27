<?php
/**
 * Reenvio de Verificação - IndieZone
 */

// [ARQUITETURA] A importação das configurações e bibliotecas de e-mail é feita de forma modular, buscando os dados da pasta 'core'. Isso mantém o ponto de entrada limpo e as credenciais protegidas fora da raiz pública.
require_once '../../core/config.php';
/** @var mysqli $conn */


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../../core/PHPMailer/src/Exception.php';
require '../../core/PHPMailer/src/PHPMailer.php';
require '../../core/PHPMailer/src/SMTP.php';

$message = "";

// [LÓGICA] Captura proativa do parâmetro GET para pré-preencher o formulário. Isso melhora a experiência do usuário que acabou de sair da tela de registro, sem realizar o disparo do e-mail automaticamente (o que exigiria um POST), evitando abusos.
$email_input_value = isset($_GET['email']) ? $_GET['email'] : "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    $email = trim($_POST['email']);
    if (empty($email)) {
        $message = "❌ Por favor, informe seu e-mail.";
    } else {
        // [SEGURANÇA] O uso de Prepared Statements na busca do usuário blinda o sistema contra injeções SQL, garantindo que o e-mail submetido seja interpretado estritamente como texto.
        $stmt = $conn->prepare("SELECT user_id, display_name, is_active FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // [LÓGICA] Verificação de integridade de estado. O sistema barra a emissão de novos tokens se o usuário já estiver com a flag 'is_active = 1', economizando recursos do servidor SMTP e prevenindo envio de spam indiscriminado.
            if ($user['is_active'] == 1) {
                $message = "✅ Esta conta já está verificada. Faça login normalmente.";
            } else {
                
                // [AUDITORIA] Sempre que um novo envio é solicitado, o sistema gera um token criptográfico inédito e sobrescreve o antigo na base de dados. 
                // Isso invalida imediatamente qualquer e-mail de ativação anterior que possa ter sido interceptado, garantindo que apenas a intenção mais recente do usuário tenha validade.
                $code = bin2hex(random_bytes(16));
                $stmt_update = $conn->prepare("UPDATE users SET verification_code = ? WHERE user_id = ?");
                $stmt_update->bind_param("si", $code, $user['user_id']);

                if ($stmt_update->execute()) {
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
                        $mail->Subject = "Novo link de ativação - IndieZone";
                        $link = APP_URL . "/auth/verify.php?code=$code";

                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; color: #333;'>
                                <h2>Olá, {$user['display_name']}! 👋</h2>
                                <p>Você solicitou um novo link para ativar sua conta.</p>
                                <div style='margin: 30px 0;'>
                                    <a href='$link' style='background: #22c55e; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Reativar minha conta</a>
                                </div>
                            </div>
                        ";
                        $mail->send();
                        $message = "✅ Novo link de verificação enviado! Confira seu e-mail.";
                    } catch (Exception $e) {
                        $message = "❌ Erro técnico ao enviar o e-mail.";
                    }
                }
                $stmt_update->close();
            }
        } else {
            // [LÓGICA] Diferente da recuperação de senha (onde mitigamos User Enumeration), aqui o trade-off pende para a usabilidade. O feedback direto de erro permite que o usuário perceba rapidamente se digitou o e-mail errado durante o cadastro.
            $message = "❌ E-mail não encontrado.";
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
    <title>Reenviar Verificação - IndieZone</title>
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
                <h1>Ativar Conta</h1>
                <p>Não recebeu o e-mail? Te enviamos outro.</p>
            </div>

            <?php if ($message != ""): ?>
                <?php 
                    $status = (strpos($message, '❌') !== false) ? 'error' : 'success';
                    $msg_class = ($status === 'error') ? 'msg-error-subtle' : 'msg-success-subtle';
                    $clean_message = str_replace(['✅', '❌'], '', $message);
                ?>
                <div class="msg-subtle <?php echo $msg_class; ?>">
                    <?php echo htmlspecialchars(trim($clean_message)); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>E-mail cadastrado</label>
                    <!-- [SEGURANÇA] Blindagem contra Cross-Site Scripting (XSS). O valor capturado via URL ($_GET) é rigorosamente escapado antes de ser impresso no atributo 'value' do input, neutralizando tentativas de injeção de scripts no frontend. -->
                    <input type="email" name="email" 
                           value="<?php echo htmlspecialchars($email_input_value); ?>" 
                           placeholder="seu@email.com" class="input-base" required autofocus>
                </div>
                <button type="submit" name="resend" class="btn">Reenviar Código</button>
            </form>

            <div class="auth-footer">
                <p><a href="login.php">Voltar ao Login</a></p>
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