<!-- FONT AWESOME -->
<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

<?php
// BUSCA O JOGO MAIS RECENTE PARA O LINK DE LANÇAMENTOS
$latest_game_id = null;
$latest_result = mysqli_query($conn, "SELECT game_id FROM games ORDER BY game_id DESC LIMIT 1");
if ($latest_result && mysqli_num_rows($latest_result) > 0) {
    $latest_game_id = mysqli_fetch_assoc($latest_result)['game_id'];
}
$lancamentos_url = $latest_game_id
    ? APP_URL . '/pages/game_details.php?id=' . $latest_game_id
    : '#';

// LINK DE DESENVOLVEDOR BASEADO NO ROLE DO USUÁRIO
$user_role = $_SESSION['role'] ?? 'player';

if ($user_role === 'admin') {
    $dev_url   = APP_URL . '/pages/admin.php';
    $dev_label = 'Administração';
} elseif ($user_role === 'dev') {
    $dev_url   = APP_URL . '/dashboard/dashboard.php';
    $dev_label = 'Painel do Estúdio';
} else {
    $dev_url   = APP_URL . '/pages/become_dev.php';
    $dev_label = 'Desenvolvedor';
}
?>

<footer class="store-footer">

    <div class="footer-wrapper">

        <!-- ESQUERDA -->
        <div class="footer-brand">

            <h2 class="footer-logo">
                IndieZone
            </h2>

            <div class="footer-description">

                © <?php echo date('Y'); ?> IndieZone Corporation.<br>

                Plataforma brasileira focada em jogos independentes,
                comunidade gamer e publicação de experiências únicas.

            </div>

            <!-- SOCIALS -->
            <div class="footer-socials">

                <a href="https://www.youtube.com/@indiezone2026" target="_blank" aria-label="YouTube">
                    <i class="fab fa-youtube"></i>
                </a>

                <a href="https://x.com/IndieZone2026" target="_blank" aria-label="X">
                    <i class="fab fa-x-twitter"></i>
                </a>

                <a href="https://www.instagram.com/indiezone2026?igsh=MWZpa3NyZWxqaTJveQ==" target="_blank" aria-label="Instagram">
                    <i class="fab fa-instagram"></i>
                </a>

            </div>

        </div>

        <!-- DIREITA -->
        <div class="footer-right">

            <!-- LOJA -->
            <div class="footer-column">

                <h4>LOJA</h4>

                <a href="store.php">
                    Página Inicial
                </a>

                <a href="<?php echo $lancamentos_url; ?>">
                    Lançamentos
                </a>

            </div>

            <!-- COMUNIDADE -->
            <div class="footer-column">

                <h4>COMUNIDADE</h4>

                <a href="community.php">
                    Central
                </a>

                <a href="community.php?tab=following">
                    Estúdios
                </a>

            </div>

            <!-- SUPORTE -->
            <div class="footer-column">

                <h4>SUPORTE</h4>

                <a href="<?php echo APP_URL; ?>/pages/privacidade.php">Privacidade</a>

                <a href="<?php echo APP_URL; ?>/pages/termos.php">Termos</a>

                <!-- BLOCO DE DENÚNCIAS / CONTATO -->
                <div class="footer-denuncia">
                    <span>Possui alguma denúncia?</span>
                    <a href="mailto:indiezone2026@gmail.com" class="denuncia-link">
                        <i class="fas fa-envelope"></i> indiezone2026@gmail.com
                    </a>
                </div>

            </div>

            <!-- DESENVOLVEDORES -->
            <div class="footer-column">

                <h4>DESENVOLVEDORES</h4>

                <a href="<?php echo $dev_url; ?>">
                    <?php echo $dev_label; ?>
                </a>

            </div>

        </div>

    </div>

</footer>