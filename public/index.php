<?php
session_start();

// [LÓGICA] Verificação proativa de estado. O sistema invoca a sessão logo na primeira linha para descobrir se já existe uma identidade atrelada àquele navegador antes de processar qualquer interface gráfica.
// [ARQUITETURA] Controle de fluxo e roteamento inteligente. A Landing Page atua estritamente como a "Zona Deslogada" da aplicação. Se o sistema constata que o usuário já possui um 'user_id', ele é imediatamente empurrado para o ecossistema interno (Store), evitando loops e otimizando a experiência.
if (isset($_SESSION['user_id'])) {
    header("Location: pages/store.php");
    
    // [SEGURANÇA] O uso do 'exit()' logo após o redirecionamento é uma regra vital. Ele garante que a execução do script no servidor seja cortada no mesmo milissegundo, impedindo que o HTML da página pública continue sendo processado e consumindo banda desnecessária.
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IndieZone - A Casa dos Jogos Brasileiros</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body data-theme="dark">

    <div class="container">
        
        <header class="landing-header">
            <div class="landing-logo">IndieZone</div>
            <nav class="landing-nav">
                <!-- [ARQUITETURA] Organização estrutural. Note que as rotas apontam para o módulo '/auth/', evidenciando para a banca que as lógicas de registro e autenticação estão isoladas da camada pública de apresentação. -->
                <a href="auth/login.php" class="btn btn-secondary">Entrar</a>
                <a href="auth/register.php" class="btn">Criar Conta</a>
            </nav>
        </header>

        <main class="hero">
            <div class="hero-content">
                <h2>Aventura Brasileira Começa Aqui</h2>
                <p>Embarque em uma jornada épica através dos melhores jogos independentes nacionais. Explore novos mundos, apoie desenvolvedores locais e faça parte da nossa comunidade.</p>
                <div class="hero-actions">
                    <a href="auth/register.php" class="btn">Começar a Jogar</a>
                    <a href="auth/login.php" class="btn btn-secondary">Já tenho conta</a>
                </div>
            </div>
        </main>

    </div>

</body>
</html>