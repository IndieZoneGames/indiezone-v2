<?php
session_start();

// [LÓGICA] Redirecionamento preventivo. Se o sistema já atesta uma identidade validada na sessão, o usuário é enviado direto para a área logada, evitando loops de autenticação ou quebra no fluxo de navegação.
if (isset($_SESSION['user_id'])) {
    header("Location: ../pages/store.php");
    exit();
}

// [SEGURANÇA] Implementação do padrão "Flash Messages". O sistema captura o alerta gerado pelo backend e destrói (unset) a variável na sessão imediatamente a seguir. 
// Isso garante que avisos sensíveis (como tentativas inválidas) existam apenas durante uma única requisição, evitando o vazamento contínuo da mensagem ao recarregar a página.
$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_success']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - IndieZone</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body data-theme="dark">

    <div class="auth-screen">
        <div class="auth-box">
            
            <?php 
            // [AUDITORIA] Interceptação de "Soft Delete". Em vez de barrar o usuário com um erro genérico, a interface identifica (via ID em sessão temporária) que o usuário existe na base, 
            // mas possui uma flag de inativação lógica. Isso preserva a integridade referencial do banco de dados e abre um fluxo direto de reativação para o dono da conta.
            if (isset($_GET['action']) && $_GET['action'] == 'reactivate' && isset($_SESSION['reactivation_user_id'])): 
            ?>
                
                <div class="brand" style="text-align: center; animation: fadeIn 0.4s ease-out;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🌱</div>
                    <h1 style="color: #22c55e;">Bem-vindo de volta!</h1>
                    <p style="color: #94a3b8; font-size: 14px; line-height: 1.6; margin-top: 10px;">
                        Sua conta está desativada. Parece que você sentiu saudade da IndieZone! Deseja reativar seu perfil e recuperar o acesso aos seus jogos?
                    </p>
                </div>
                
                <!-- [ARQUITETURA] A submissão do formulário aponta diretamente para o controlador no backend, isolando completamente as instruções de manipulação de banco (UPDATE deleted_at = NULL) desta camada de visualização (View). -->
                <form action="../../src/backend/reactivate_account.php" method="POST" style="margin-top: 30px;">
                    <button type="submit" class="btn" style="background: #22c55e; color: #000; font-weight: 700; width: 100%; border: none;">Sim, Reativar Minha Conta</button>
                    <a href="login.php" style="display: block; text-align: center; color: #94a3b8; margin-top: 20px; text-decoration: none; font-size: 14px; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#94a3b8'">Não, deixar como está</a>
                </form>

            <?php else: ?>
                
                <div class="brand">
                    <h1 style="color: #22c55e;">IndieZone</h1>
                    <p>Bem-vindo de volta</p>
                </div>

                <?php if ($error): ?>
                    <div class="msg-subtle msg-error-subtle">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="msg-subtle msg-success-subtle">
                        <!-- [SEGURANÇA] A impressão da mensagem de sucesso é envelopada por htmlspecialchars. Se por acaso a mensagem de sucesso originada na sessão trouxer algum caractere especial inserido acidentalmente, a função anula o risco de injeção de Cross-Site Scripting (XSS). -->
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="../../src/backend/login_process.php">
                    <div class="input-group">
                        <label>E-mail ou Nome de Usuário</label>
                        <input type="text" name="login" placeholder="seu@email.com ou usuario" class="input-base" required autofocus>
                    </div>
                    <div class="input-group">
                        <label>Senha</label>
                        <input type="password" name="password" placeholder="••••••••" class="input-base" required>
                    </div>
                    <button type="submit" class="btn" style="background: #22c55e; color: #000; font-weight: 700;">Entrar</button>
                </form>
                
                <div class="auth-footer" style="margin-top: 24px; text-align: center;">
                    <p>Não tem conta? <a href="register.php" style="color: #22c55e; font-weight: 600;">Criar conta</a></p>
                    <p style="margin-top: 12px;"><a href="forgot_password.php" style="color: #22c55e; font-weight: 600;">Esqueci minha senha</a></p>
                </div>
                
            <?php endif; ?>

        </div>
    </div>

</body>
</html>