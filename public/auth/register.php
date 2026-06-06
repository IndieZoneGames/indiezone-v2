<?php
/**
 * Página de Registro - IndieZone
 */

// [ARQUITETURA] As conexões de banco e bibliotecas sensíveis (PHPMailer) ficam isoladas na pasta 'core', fora do alcance público. 
// O arquivo de visualização apenas orquestra a lógica, não expõe as credenciais de infraestrutura.
require_once '../../core/config.php';
require_once '../../src/backend/SystemLogger.php'; // [AUDITORIA] Inclusão do Logger
/** @var mysqli $conn */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../../core/PHPMailer/src/Exception.php';
require '../../core/PHPMailer/src/PHPMailer.php';
require '../../core/PHPMailer/src/SMTP.php';

$logger = new SystemLogger($conn);
$message = "";
$success_state = false; 

// [LÓGICA] Calcula a data limite dinamicamente baseada no dia de hoje para travar o calendário no frontend HTML.
$max_date = date('Y-m-d', strtotime('-16 years'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    
    $display_name = trim($_POST['display_name']);
    $username     = trim($_POST['username']);
    
    // [SEGURANÇA] A sanitização do e-mail remove caracteres invisíveis ou ilegais antes mesmo da validação lógica, 
    // prevenindo comportamentos anômalos que poderiam tentar burlar os filtros de entrada.
    $email        = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $birth_date   = $_POST['birth_date'];
    $raw_password = $_POST['password'];

    // [SEGURANÇA] Regex estrito aplicado para garantir a política de força de senhas (Complexidade). 
    // Obriga o uso de diferentes classes de caracteres, protegendo as contas contra ataques de força bruta ou baseados em dicionário de senhas.
    $password_regex = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/';

    // 1. Validação de campos vazios
    if (empty($display_name) || empty($username) || empty($email) || empty($birth_date) || empty($raw_password)) {
        $message = "❌ Por favor, preencha todos os campos obrigatórios.";
    } 
    // 2. Validação de formato de E-mail
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "❌ Por favor, insira um e-mail válido.";
    }
    // 3. Validação de Idade (Mínimo 16 anos e ano base 1920)
    else {
        // [LÓGICA] O backend recalcula e verifica rigorosamente as datas submetidas, desconfiando do cliente. 
        // Isso evita que usuários mal-intencionados burlem o bloqueio de idade manipulando o HTML (via DevTools).
        $dob = DateTime::createFromFormat('Y-m-d', $birth_date);
        $now = new DateTime();
        $age = $dob ? $now->diff($dob)->y : 0;

        if (!$dob || $dob->format('Y-m-d') !== $birth_date) {
            $message = "❌ Data de nascimento inválida.";
        } elseif ((int)$dob->format('Y') < 1920) {
            $message = "❌ O ano de nascimento deve ser a partir de 1920.";
        } elseif ($age < 16) {
            $message = "❌ Você precisa ter pelo menos 16 anos para se cadastrar.";
        } 
        // 4. Validação de Força da Senha
        elseif (!preg_match($password_regex, $raw_password)) {
            $message = "❌ A senha deve ter no mínimo 8 caracteres, com letra maiúscula, minúscula, número e caractere especial.";
        } else {
            
            // [SEGURANÇA] A senha é irremediavelmente transformada em um hash criptográfico usando o padrão bcrypt. 
            // Em caso de vazamento da base de dados, as senhas originais dos usuários permanecem ilegíveis.
            $password_hash = password_hash($raw_password, PASSWORD_DEFAULT);
            
            // [AUDITORIA] Gera um token criptográfico de uso único para validação da conta. 
            // O sistema amarra esse token ao novo usuário para garantir a prova de propriedade do e-mail antes de liberar permissões na plataforma.
            $verification_code = bin2hex(random_bytes(16));
            $role = 'player'; 

            $avatar_url = "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($username);

            // [SEGURANÇA] Uso imperativo de Prepared Statements para a consulta de duplicidade. 
            // Impede qualquer tentativa de injeção de comandos SQL (SQL Injection) nos campos de email ou usuário.
            $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
            $stmt_check->bind_param("ss", $email, $username);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                // [AUDITORIA] Registra tentativa de cadastro com e-mail/usuário já existente
                $logger->log('USER_REGISTER_DUPLICATE', 'WARNING', [
                    'new_data' => ['attempted_email' => $email, 'attempted_username' => $username]
                ]);
                $message = "❌ E-mail ou Nome de Usuário já estão em uso.";
            } else {
                // [AUDITORIA] A entidade do usuário nasce com 'is_active = 0'. Isso documenta na base que a conta existe, 
                // mas cria uma barreira (quarentena) onde nenhuma ação no sistema pode ser tomada até que o processo de verificação gere um log de ativação (UPDATE is_active = 1).
                $stmt_insert = $conn->prepare("INSERT INTO users (display_name, username, email, password_hash, birth_date, verification_code, is_active, role, avatar_url) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                $stmt_insert->bind_param("ssssssss", $display_name, $username, $email, $password_hash, $birth_date, $verification_code, $role, $avatar_url);

                if ($stmt_insert->execute()) {
                    
                    $new_user_id = $conn->insert_id;
                    // [AUDITORIA] Grava na trilha que um novo usuário nasceu na base de dados
                    $logger->log('USER_REGISTERED', 'INFO', [
                        'user_id' => $new_user_id, 
                        'entity_table' => 'users', 
                        'entity_id' => $new_user_id, 
                        'new_data' => ['email' => $email, 'username' => $username, 'role' => $role]
                    ]);

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
                        $mail->addAddress($email, $display_name);

                        $mail->isHTML(true);
                        $mail->Subject = "Ative sua conta na IndieZone";
                        $link = APP_URL . "/auth/verify.php?code=$verification_code";

                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; color: #333;'>
                                <h2>Bem-vindo à IndieZone, $display_name! 👋</h2>
                                <p>Sua conta foi criada com sucesso. Para começar a explorar e jogar, precisamos apenas que você confirme seu e-mail.</p>
                                <div style='margin: 30px 0;'>
                                    <a href='$link' style='background: #2ecc71; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Confirmar meu E-mail</a>
                                </div>
                                <p>Se o botão acima não funcionar, copie e cole o link abaixo no seu navegador:</p>
                                <p><small>$link</small></p>
                            </div>
                        ";

                        $mail->send();
                        $success_state = true; 
                    } catch (Exception $e) {
                        $message = "❌ Cadastro realizado, mas não conseguimos enviar o e-mail de ativação. Entre em contato com o suporte.";
                    }
                } else {
                    // [AUDITORIA] Caso ocorra falha ao inserir no banco
                    $logger->log('USER_REGISTER_DB_ERROR', 'CRITICAL', [
                        'new_data' => ['error_message' => $conn->error, 'attempted_email' => $email]
                    ]);
                    $message = "❌ Erro técnico ao cadastrar. Por favor, tente novamente.";
                }
                $stmt_insert->close();
            }
            $stmt_check->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Conta - IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body data-theme="dark">
    <div class="auth-screen">
        <div class="auth-box">
            <?php if ($success_state): ?>
                <div class="brand" style="animation: fadeIn 0.4s ease-out;">
                    <div style="font-size: 48px; margin-bottom: 16px;">✉️</div>
                    <h1>Falta Pouco!</h1>
                    <p>Conta criada com sucesso.</p>
                </div>
                
                <div style="text-align: center; color: var(--text); font-size: 15px; margin-bottom: 32px; line-height: 1.6;">
                    Enviamos um link de ativação para o e-mail:<br>
                    <strong style="color: var(--accent);"><?php echo htmlspecialchars($email); ?></strong><br><br>
                    <span style="color: var(--text-dim); font-size: 13px;">Por favor, verifique sua caixa de entrada e a pasta de spam. Você precisa clicar no link para fazer login.</span>
                </div>

                <div class="action-buttons" style="display: flex; flex-direction: column; gap: 12px;">
                    <a href="login.php" class="btn">Ir para o Login</a>
                    <p style="font-size: 13px; color: var(--text-dim); margin-top: 10px; text-align: center;">
                        Não recebeu? <a href="resend_verification.php?email=<?php echo urlencode($email); ?>" style="color: var(--accent); text-decoration: underline;">Reenviar e-mail</a>
                    </p>
                </div>

            <?php else: ?>
                <div class="brand">
                    <h1>Criar Conta</h1>
                    <p>Junte-se à comunidade IndieZone</p>
                </div>

                <?php if (!empty($message)): ?>
                    <?php 
                        $status = (strpos($message, '❌') !== false) ? 'error' : 'success';
                        $msg_class = ($status === 'error') ? 'msg-error-subtle' : 'msg-success-subtle';
                        $clean_message = str_replace(['✅', '❌'], '', $message);
                    ?>
                    <div class="msg-subtle <?php echo $msg_class; ?>">
                        <?php echo htmlspecialchars(trim($clean_message)); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php">
                    <div class="form-row">
                        <div class="input-group">
                            <label>Nome de Exibição</label>
                            <input type="text" name="display_name" value="<?php echo isset($display_name) ? htmlspecialchars($display_name) : ''; ?>" placeholder="Ex: João Silva" class="input-base" required autofocus>
                        </div>
                        <div class="input-group">
                            <label>Nome de Usuário</label>
                            <input type="text" name="username" value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" placeholder="joao_indie" class="input-base" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <label>E-mail</label>
                        <input type="email" name="email" value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" placeholder="seu@email.com" class="input-base" required>
                    </div>
                    <div class="input-group">
                        <label>Data de Nascimento</label>
                        <input type="date" name="birth_date" max="<?php echo $max_date; ?>" class="input-base" required>
                    </div>
                    <div class="input-group">
                        <label>Senha</label>
                        <input type="password" name="password" placeholder="••••••••" class="input-base" required minlength="8">
                        <small style="font-size: 11px; color: var(--text-dim); margin-top: 4px; display: block;">
                            Use maiúsculas, minúsculas, números e símbolos.
                        </small>
                    </div>
                    <button type="submit" name="register" class="btn">Criar Minha Conta</button>
                </form>
                <div class="auth-footer">
                    <p>Já tem conta? <a href="login.php">Fazer login</a></p>
                    <p style="margin-top: 16px; font-size: 12px; opacity: 0.7;">
                        Desenvolvedor? O painel dev é ativado no seu perfil após o cadastro.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.documentElement.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');
        });
    </script>
</body>
</html>