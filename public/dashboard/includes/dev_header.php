<?php
/** @var mysqli $conn */

// public/dashboard/includes/dev_header.php

// [LÓGICA] Prevenção de conflito de cabeçalhos. Verifica se a sessão já está ativa antes de iniciá-la, evitando os clássicos avisos de "Session already started" que podem quebrar o layout ou a entrega do documento HTML.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// [ARQUITETURA] Inclusão de dependências subindo a árvore de diretórios (../../../core). Isso isola as configurações sensíveis do banco de dados na raiz do sistema, bem longe das pastas de acesso público do dashboard.
require_once(__DIR__ . "/../../../core/config.php");

// [SEGURANÇA] Implementação rigorosa de RBAC (Role-Based Access Control). Esta é a principal barreira de roteamento do painel. 
// O sistema não apenas exige uma sessão ativa, mas valida se o nível de privilégio ('role') do usuário é estritamente 'dev' ou 'admin'. Qualquer jogador comum ('player') ou visitante é expulso imediatamente, prevenindo a Escalada de Privilégios.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'dev' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit();
}

// [AUDITORIA] A variável que dita qual estúdio será carregado é extraída diretamente do 'user_id' validado e trancado na sessão do servidor, e nunca via parâmetros manipuláveis (como $_GET['id']). Isso garante que o usuário só possa auditar e modificar seus próprios dados.
$user_id = $_SESSION['user_id'];
$current_page = basename($_SERVER['PHP_SELF']);

// Buscar dados reais do estúdio no banco
$studio_name = "Meu Estúdio Indie"; // Fallback

// [SEGURANÇA] Mesmo utilizando o ID travado na sessão, o sistema mantém o uso disciplinado de Prepared Statements. Isso consolida a padronização e blinda a aplicação contra qualquer brecha remota de SQL Injection na consulta dos dados do estúdio.
$stmt = $conn->prepare("SELECT studio_name FROM developers WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($data = $res->fetch_assoc()) {
    $studio_name = $data['studio_name'];
}

// [LÓGICA] Aplicação de avatar dinâmico gerado via API caso o desenvolvedor ainda não tenha feito o upload de uma logo oficial, melhorando a experiência visual nativa.
$studio_avatar = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/shapes/svg?seed=" . urlencode($studio_name);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Desenvolvedor - IndieZone</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/dev_dashboard.css">
</head>
<body>

    <aside class="dashboard-sidebar">
        <div class="studio-profile">
            <!-- [SEGURANÇA] Todo dado originado do banco de dados (neste caso, o nome do estúdio e o avatar) deve ser sanitizado com htmlspecialchars na renderização. Isso impede que nomes de estúdios contendo scripts maliciosos causem vulnerabilidades de Stored XSS. -->
            <img src="<?php echo htmlspecialchars($studio_avatar); ?>" alt="Logo do Estúdio">
            <h2><?php echo htmlspecialchars($studio_name); ?></h2>
            <span class="studio-badge">Estúdio Verificado</span>
        </div>

        <nav class="dash-nav">
            <!-- [ARQUITETURA] O sistema injeta a classe 'active' dinamicamente com base na página atual do servidor. Isso permite que um único arquivo de cabeçalho controle o menu de todas as páginas do dashboard, centralizando a manutenção do código. -->
            <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">Visão Geral</a>
            <a href="my_games.php" class="<?php echo ($current_page == 'my_games.php') ? 'active' : ''; ?>">Meus Jogos</a>
            <a href="add_game.php" class="<?php echo ($current_page == 'add_game.php') ? 'active' : ''; ?>">Publicar Novo Jogo</a>
            <a href="wallet.php" class="<?php echo ($current_page == 'wallet.php') ? 'active' : ''; ?>">Carteira & Vendas</a>
            <a href="settings.php" class="<?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>">Configurações</a>
            
            <div style="flex: 1;"></div> 
            <a href="../pages/index.php" style="color: #cbd5e1;">Voltar para o perfil</a>
            <a href="../auth/logout.php" class="danger-link">Sair da Conta</a>
        </nav>
    </aside>