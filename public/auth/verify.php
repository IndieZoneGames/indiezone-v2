<?php
/**
 * Página de Verificação - IndieZone
 */

// [ARQUITETURA] A inclusão do arquivo de configuração a partir do diretório restrito 'core' isola as credenciais de banco de dados da camada pública da aplicação, organizando a estrutura do projeto e protegendo dados sensíveis.
require_once '../../core/config.php';
/** @var mysqli $conn */

$message = "";
$type = "info"; 

if (isset($_GET['code']) && !empty($_GET['code'])) {
    
    $code = $_GET['code'];

    // [SEGURANÇA] A validação do token recebido via GET é blindada com Prepared Statements. A string é tratada como um dado puro pelo banco, impossibilitando tentativas de injeção de comandos maliciosos (SQL Injection).
    $stmt = $conn->prepare("SELECT user_id, is_active FROM users WHERE verification_code = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // [LÓGICA] Verificação de estado para garantir a idempotência da ação. Se o sistema identifica que a conta já foi liberada ('is_active = 1'), o fluxo é interrompido amigavelmente, evitando reprocessamento inútil no banco de dados.
        if ($user['is_active'] == 1) {
            $message = "⚠️ Esta conta já foi verificada e está ativa.";
            $type = "info";
        } else {
            // [AUDITORIA] Momento crítico de rastreabilidade e transição de estado: 
            // 1. O perfil do usuário sai da "quarentena" (UPDATE is_active = 1), ganhando privilégios na plataforma.
            // 2. O token de verificação é instantaneamente destruído (verification_code = NULL), garantindo a regra de "Uso Único" (Single Use) e barrando permanentemente ataques de replay com links antigos.
            $stmt_update = $conn->prepare("UPDATE users SET is_active = 1, verification_code = NULL WHERE user_id = ?");
            $stmt_update->bind_param("i", $user['user_id']);

            if ($stmt_update->execute()) {
                $message = "✅ Conta verificada com sucesso! Bem-vindo à IndieZone.";
                $type = "success";
            } else {
                $message = "❌ Erro técnico ao ativar sua conta.";
                $type = "error";
            }
            $stmt_update->close();
        }
    } else {
        $message = "❌ Código de verificação inválido ou expirado.";
        $type = "error";
    }
    $stmt->close();
} else {
    $message = "❌ Nenhum código de verificação foi fornecido.";
    $type = "error";
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Conta - IndieZone</title>
    
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
                <h1>Verificação</h1>
                <p>Validando seu acesso à IndieZone</p>
            </div>

            <?php if (!empty($message)): ?>
                <?php 
                    // [ARQUITETURA] Lógica de visualização dinâmica. O backend apenas dita o "estado" (sucesso, erro, info), e a View fica responsável por traduzir isso em classes CSS correspondentes para a interface.
                    $msg_class = 'msg-info-subtle'; // Padrão
                    if ($type == 'success') $msg_class = 'msg-success-subtle';
                    if ($type == 'error') $msg_class = 'msg-error-subtle';
                    
                    $clean_message = str_replace(['✅', '❌', '⚠️'], '', $message);
                ?>
                <!-- [SEGURANÇA] O uso do htmlspecialchars sanitiza a string renderizada na tela, neutralizando qualquer vetor de ataque de Cross-Site Scripting (XSS) caso a mensagem contivesse caracteres HTML forjados. -->
                <div class="msg-subtle <?php echo $msg_class; ?>">
                    <?php echo htmlspecialchars(trim($clean_message)); ?>
                </div>
            <?php endif; ?>

            <div class="auth-footer" style="margin-top: 32px;">
                <a href="login.php" class="btn">Ir para o Login</a>
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