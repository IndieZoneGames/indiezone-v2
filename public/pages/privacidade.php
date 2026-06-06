<?php
require_once __DIR__ . '/../../core/config.php';
session_start();

$is_logged_in = isset($_SESSION['user_id']);
if ($is_logged_in) {
    $display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
    $primeiro_nome = explode(' ', $display_name)[0];
    $avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);
    
    function resolveImageUrl($url) {
        if (empty($url)) return '';
        if (strpos($url, 'http') === 0) return htmlspecialchars($url);
        $clean_path = ltrim(str_replace('../', '/', $url), '/');
        return APP_URL . '/' . htmlspecialchars($clean_path);
    }
    $avatar_src = resolveImageUrl($avatar_url);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidade — IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/legal.css">
    <link rel="stylesheet" href="../assets/css/rodape.css">
</head>
<body data-theme="dark">

    <header class="legal-header-simple">
        <a href="index.php" class="legal-logo">IndieZone</a>
        
        <?php if ($is_logged_in): ?>
            <a href="profile.php" style="display: flex; align-items: center; gap: 15px; text-decoration: none; color: #e2e8f0;">
                <span style="font-weight: 700; font-size: 14px;"><?php echo htmlspecialchars($primeiro_nome); ?></span>
                <img src="<?php echo $avatar_src; ?>" alt="Avatar" style="width: 42px; height: 42px; border-radius: 50%; border: 2px solid #22c55e; object-fit: cover;">
            </a>
        <?php else: ?>
            <div class="legal-nav-auth">
                <a href="../auth/login.php" class="legal-btn-login">Entrar</a>
                <a href="../auth/register.php" class="legal-btn-register">Criar Conta</a>
            </div>
        <?php endif; ?>
    </header>

    <main class="legal-container">
        <h1>Política de Privacidade</h1>
        <span class="legal-date">Última atualização: Junho de 2026</span>

        <p>O IndieZone é um projeto acadêmico de conclusão de curso (TCC) desenvolvido para o Centro Universitário de Maringá (UniCesumar). Temos o compromisso rigoroso de proteger a sua privacidade, guiados pela Lei Geral de Proteção de Dados Pessoais (LGPD - Lei nº 13.709/2018) e pelo Marco Civil da Internet (Lei nº 12.965/2014).</p>

        <h2>1. Restrição de Idade e Consentimento</h2>
        <p>Para criar uma conta e utilizar o IndieZone, <strong>você deve ter no mínimo 16 anos completos</strong>. Não realizamos a coleta intencional de dados de crianças. Contas identificadas como pertencentes a menores de 16 anos serão sumariamente excluídas, bem como todos os dados a elas vinculados.</p>

        <h2>2. Coleta de Dados Pessoais</h2>
        <p>A coleta de informações ocorre estritamente sob os princípios de finalidade e necessidade:</p>
        <ul>
            <li><strong>Jogadores:</strong> Nome de exibição, nome de usuário, e-mail, data de nascimento e hash de senha criptografada. Opcionalmente: biografia, avatar e links de redes sociais.</li>
            <li><strong>Desenvolvedores:</strong> Além dos dados básicos, exigimos número de documento (CPF/CNPJ), nome do estúdio, e-mail de suporte técnico e portfólio para fins de validação do perfil criador.</li>
        </ul>

        <h2>3. Dados Coletados Automaticamente e Cookies</h2>
        <p>Para viabilizar a segurança e o funcionamento técnico, nosso sistema registra dados de telemetria e acesso:</p>
        <ul>
            <li><strong>Logs do Sistema:</strong> Endereço IP, características do dispositivo, navegador (User Agent) e logs de ações (login, compra, denúncias). Por força do Marco Civil da Internet, registros de acesso são guardados sob sigilo por, no mínimo, 6 meses.</li>
            <li><strong>Cookies e Sessões:</strong> Utilizamos apenas cookies estritamente necessários para manter a sua sessão autenticada de forma segura e salvar suas preferências de interface.</li>
        </ul>

        <h2>4. Política de Retenção e Exclusão de Contas</h2>
        <p>O usuário pode solicitar a exclusão de sua conta a qualquer momento nas configurações do perfil. A exclusão obedece ao seguinte fluxo legal:</p>
        <ul>
            <li><strong>Soft Delete (Prazo de Arrependimento):</strong> A conta é desativada imediatamente, mas os dados permanecem congelados em nosso banco por 30 (trinta) dias. Neste período, o usuário pode solicitar a reativação.</li>
            <li><strong>Anonimização (Pseudonimização):</strong> Após 30 dias, todos os dados de identificação direta (nome, e-mail, CPF, documentos) são permanentemente destruídos.</li>
            <li><strong>Retenção de Conteúdo Público:</strong> Avaliações, comentários no fórum e histórico de métricas de jogos permanecerão na plataforma para preservar a integridade das estatísticas, mas passarão a ser atribuídos a um "Usuário Anônimo", sem qualquer vínculo com a sua identidade original.</li>
        </ul>

        <h2>5. Compartilhamento e Transferência Internacional</h2>
        <p>O IndieZone não vende dados. O compartilhamento ocorre apenas com operadores essenciais para o sistema:</p>
        <ul>
            <li><strong>Stripe:</strong> Processadora de transações financeiras (operando em modo de teste). Dados sensíveis de cartão não transitam nem são armazenados em nossos servidores.</li>
            <li><strong>Infraestrutura Cloud:</strong> Nossos servidores e o armazenamento de jogos (via Google Drive) podem estar localizados fisicamente fora do Brasil. Ao usar a plataforma, você consente com a transferência internacional dos seus dados, protegida pelas cláusulas-padrão contratuais das provedoras.</li>
        </ul>

        <h2>6. Seus Direitos como Titular (LGPD)</h2>
        <p>De acordo com o Art. 18 da LGPD, você possui o direito de: acessar, corrigir, anonimizar, portar e eliminar seus dados, além de poder revogar seu consentimento para comunicações de marketing a qualquer momento através do seu painel de configurações.</p>
    </main>

    <?php require_once __DIR__ . '/../partials/rodape.php'; ?>
</body>
</html>