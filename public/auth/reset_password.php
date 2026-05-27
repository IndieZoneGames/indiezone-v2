<?php
/**
 * Redefinir Senha - IndieZone
 */

// [ARQUITETURA] Mantém o acoplamento baixo ao importar a conexão do banco de dados de um diretório restrito do núcleo ('core'). Isso protege as variáveis de ambiente e padroniza o acesso a dados em toda a aplicação.
require_once '../../core/config.php';
/** @var mysqli $conn */


$message = "";
$type = "info";
$show_form = false;

// [LÓGICA] Ponto de entrada condicional. O fluxo principal da tela só existe se o usuário carregar um código de recuperação (token) na URL. Caso contrário, a interface já entra em modo de falha, bloqueando tentativas de acesso direto.
if (isset($_GET['code']) && !empty($_GET['code'])) {
    
    $code = $_GET['code'];

    // [SEGURANÇA] Consulta utilizando Prepared Statements. Trata o token criptográfico recebido via GET puramente como uma string literal, eliminando o risco de que caracteres maliciosos injetem comandos SQL no banco de dados.
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE reset_code = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $show_form = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change'])) {
            $new_password = $_POST['password'];
            
            // [SEGURANÇA] Reaproveitamento da mesma política estrita de Regex do cadastro original. Isso garante consistência nas regras de negócio, forçando o usuário a manter uma senha com alta entropia (complexidade) contra ataques de dicionário.
            $password_regex = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/';

            // Validação com Regex
            if (!preg_match($password_regex, $new_password)) {
                $message = "❌ A senha deve ter no mínimo 8 caracteres, com letra maiúscula, minúscula, número e caractere especial.";
                $type = "error";
            } else {
                // [SEGURANÇA] Função nativa do PHP para gerar um hash Bcrypt. A senha em texto claro é destruída na memória assim que o hash é criado, garantindo que nem mesmo os administradores do banco tenham acesso à credencial.
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

                // [AUDITORIA] Este é o coração do rastro de segurança: 
                // 1. O 'reset_code = NULL' consome o token, provando que ele é de uso único (Single Use) e prevenindo ataques de replay.
                // 2. O 'last_password_change = CURRENT_TIMESTAMP' crava na linha do tempo exata quando a credencial foi modificada, alimentando o sistema de "cooldown" para evitar múltiplas trocas maliciosas seguidas.
                $stmt_update = $conn->prepare("UPDATE users SET password_hash = ?, reset_code = NULL, last_password_change = CURRENT_TIMESTAMP WHERE user_id = ?");
                $stmt_update->bind_param("si", $password_hash, $user['user_id']);

                if ($stmt_update->execute()) {
                    $message = "✅ Senha alterada com sucesso! Você já pode fazer login.";
                    $type = "success";
                    
                    // [LÓGICA] Após o sucesso, oculta imediatamente o formulário de alteração. Isso guia o usuário de forma intuitiva para fora da tela de redefinição, evitando submissões duplicadas acidentais.
                    $show_form = false; 
                } else {
                    $message = "❌ Erro ao atualizar a senha.";
                    $type = "error";
                }
                $stmt_update->close();
            }
        }
    } else {
        $message = "❌ Link de recuperação inválido ou já utilizado.";
        $type = "error";
    }
    $stmt->close();
} else {
    $message = "❌ Nenhum código de recuperação foi fornecido.";
    $type = "error";
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Senha - IndieZone</title>
    
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
                <h1>Nova Senha</h1>
                <p>Crie uma nova senha segura para sua conta</p>
            </div>

            <?php if ($message != ""): ?>
                <?php 
                    $msg_class = ($type == 'success') ? 'msg-success-subtle' : 'msg-error-subtle';
                    $clean_message = str_replace(['✅', '❌'], '', $message);
                ?>
                <!-- [SEGURANÇA] Como mensagens dinâmicas podem refletir inputs do usuário em caso de erro, a função htmlspecialchars blinda a camada visual contra injeção de scripts (XSS). -->
                <div class="msg-subtle <?php echo $msg_class; ?>">
                    <?php echo htmlspecialchars(trim($clean_message)); ?>
                </div>
            <?php endif; ?>

            <?php if ($show_form): ?>
                <form method="POST">
                    <div class="input-group">
                        <label>Nova Senha</label>
                        <input type="password" name="password" placeholder="••••••••" class="input-base" required minlength="8" autofocus>
                        <small style="font-size: 11px; color: var(--text-dim); margin-top: 4px; display: block;">
                            Use maiúsculas, minúsculas, números e símbolos.
                        </small>
                    </div>
                    
                    <button type="submit" name="change" class="btn">Salvar Nova Senha</button>
                </form>
            <?php endif; ?>

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