<?php
require_once __DIR__ . '/../../core/config.php';
session_start();

$is_logged_in = isset($_SESSION['user_id']);
if ($is_logged_in) {
  $display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Usuário';
  $primeiro_nome = explode(' ', $display_name)[0];
  $avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($_SESSION['username']);

  // Removemos a declaração da função resolveImageUrl daqui, pois ela já vem do config.php
  // Apenas a utilizamos diretamente:
  $avatar_src = resolveImageUrl($avatar_url);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Termos de Uso — IndieZone</title>
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
      <a href="index.php" style="display: flex; align-items: center; gap: 15px; text-decoration: none; color: #e2e8f0;">
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
    <h1>Termos de Uso</h1>
    <span class="legal-date">Última atualização: Junho de 2026</span>

    <div class="legal-warning">
      <p><strong>Aviso de Projeto Acadêmico (Isenção de Responsabilidade)</strong><br>
        A plataforma IndieZone é um ambiente experimental desenvolvido estritamente para avaliação acadêmica. Não asseguramos disponibilidade contínua (SLA de uptime) e nos reservamos o direito de realizar limpezas de banco de dados (wipes) que podem resultar na perda de perfis, conquistas, e jogos adicionados à sua biblioteca.</p>
    </div>

    <h2>1. Simulação Financeira</h2>
    <p><strong>ATENÇÃO: Todas as transações financeiras na IndieZone são fictícias.</strong></p>
    <ul>
      <li>Toda e qualquer aquisição na loja, modelo de contribuição "Pague o Quanto Quiser" (PWYW) e painel de repasses ao desenvolvedor utilizam o ambiente de testes (Sandbox) da Stripe.</li>
      <li>Não há movimentação de valores fiduciários reais e nenhuma prestação de serviços de gateway real está sendo ativamente operada.</li>
    </ul>

    <h2>2. Compras, Aquisições e Reembolsos</h2>
    <p>Buscando simular práticas justas de mercado (com base no Código de Defesa do Consumidor e padrões da indústria de software), o IndieZone adota a seguinte política de cancelamentos fictícios:</p>
    <ul>
      <li>O jogador pode solicitar o cancelamento e reembolso total de um jogo dentro de um prazo de <strong>7 (sete) dias após a aquisição</strong>.</li>
      <li>Este direito ao cancelamento <strong>se encerra imediatamente caso o usuário clique no botão de download</strong> do jogo, independentemente do tempo decorrido desde a compra.</li>
      <li>Após o cancelamento, o jogo será imediatamente revogado da biblioteca do usuário.</li>
    </ul>

    <h2>3. Regras para Desenvolvedores e Estúdios</h2>
    <p>A publicação na plataforma é um privilégio sujeito a aprovação técnica e curadoria da nossa equipe. O desenvolvedor declara aceitar que:</p>
    <ul>
      <li><strong>Taxa de Manutenção:</strong> A IndieZone retém, de forma simulada, uma comissão de 10% sobre qualquer venda ou doação realizada para o jogo.</li>
      <li><strong>Disponibilidade Perpétua para Compradores:</strong> Caso um desenvolvedor decida deletar sua conta ou remover um jogo da loja, o título não poderá mais ser adquirido por novos usuários. Contudo, <strong>o jogo permanecerá indefinidamente disponível para download nas bibliotecas dos jogadores que já o haviam adquirido</strong>, garantindo os direitos do consumidor.</li>
    </ul>

    <h2>4. Propriedade Intelectual e DMCA</h2>
    <p>O IndieZone não compactua com a violação de direitos autorais, pirataria ou roubo de <i>assets</i>. Todo o conteúdo é de inteira responsabilidade do desenvolvedor que o submeteu. Caso identifique uma violação de sua propriedade intelectual na loja, notificações de remoção devem ser encaminhadas para o e-mail fictício <strong>copyright@indiezone.com.br</strong> com as devidas comprovações.</p>

    <h2>5. Conduta Comunitária e Sanções</h2>
    <p>A comunidade IndieZone é baseada no respeito mútuo. É terminantemente proibido:</p>
    <ul>
      <li>Veicular discurso de ódio, conteúdo discriminatório, adulto, ilegal ou praticar assédio em comentários, fóruns ou dentro dos próprios jogos publicados.</li>
      <li>Praticar manipulação de avaliações (<i>review bombing</i> coordenado).</li>
      <li>Subverter intencionalmente os sistemas de segurança da plataforma.</li>
    </ul>
    <p>Administradores reservam-se o direito de julgar, moderar, silenciar, ou banir permanentemente contas infratoras sem a necessidade de aviso prévio.</p>
  </main>

  <?php require_once __DIR__ . '/../partials/rodape.php'; ?>
</body>

</html>