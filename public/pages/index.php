<?php
// [ARQUITETURA] A sessão é iniciada imediatamente. Como o painel centraliza a navegação entre a loja e a área de desenvolvedores, o estado do usuário precisa estar disponível antes da renderização de qualquer componente HTML.
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

// [SEGURANÇA] Validação de Identidade. Se o token 'user_id' não existir em memória, o visitante é considerado anônimo e bloqueado na origem, não podendo acessar dados sensíveis.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'player';

// [LÓGICA] Uso estratégico do LEFT JOIN. O sistema busca os dados vitais em 'users' e as preferências em 'user_settings'. Se o usuário for recém-cadastrado e ainda não tiver um registro de preferências, o LEFT JOIN impede que o SQL falhe, retornando os dados base corretamente com NULL nas configurações.
$sql = "SELECT u.display_name, u.username, u.email, u.bio, u.last_username_change, u.last_password_change, u.password_change_count, 
               s.social_twitter, s.social_discord, s.social_twitch, s.pref_marketing, s.pref_alerts 
        FROM users u 
        LEFT JOIN user_settings s ON u.user_id = s.user_id 
        WHERE u.user_id = ?";

// [SEGURANÇA] Mesmo buscando pelos dados do próprio usuário logado, o uso de Prepared Statements é inegociável. Garante uniformidade arquitetural e blinda totalmente o sistema contra SQL Injection.
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

// Tratamento para evitar erros caso o usuário ainda não tenha registro na tabela settings
$pref_marketing = isset($user_data['pref_marketing']) ? $user_data['pref_marketing'] : 1;
$pref_alerts = isset($user_data['pref_alerts']) ? $user_data['pref_alerts'] : 1;

$avatar_url = $_SESSION['avatar_url'] ?? "https://api.dicebear.com/7.x/pixel-art/svg?seed=" . urlencode($user_data['username']);

// --- LÓGICA DE COOLDOWN ---
// [AUDITORIA] Bloqueio contra evasão e spoofing. O banco registra o timestamp em 'last_username_change'. O sistema calcula a diferença e impõe um congelamento de 30 dias. Isso evita que usuários fiquem trocando de @username para aplicar golpes na comunidade e sumir sem deixar rastro.
$now = new DateTime();

$can_change_username = true;
$days_left_username = 0;
if (!empty($user_data['last_username_change'])) {
    $last_user_change = new DateTime($user_data['last_username_change']);
    $diff_user = $now->diff($last_user_change)->days;
    if ($diff_user < 30) {
        $can_change_username = false;
        $days_left_username = 30 - $diff_user;
    }
}

// [SEGURANÇA] Mecanismo Anti-Sequestro de Conta. Se um hacker conseguir acesso à conta, ele tentará alterar a senha repetidas vezes para trancar o usuário legítimo para fora. O limite de 'password_change_count' (3 trocas por 24h) cria um gargalo artificial, dando tempo para o usuário original acionar o suporte e recuperar o perfil via e-mail.
$can_change_password = true;
$hours_left_pass = 0;
$changes_left = 3;

if (!empty($user_data['last_password_change'])) {
    $last_pass_change = new DateTime($user_data['last_password_change']);
    $diff_pass_hours = ($now->getTimestamp() - $last_pass_change->getTimestamp()) / 3600;

    if ($diff_pass_hours < 24) {
        $changes_left = 3 - (int)$user_data['password_change_count'];
        if ($changes_left <= 0) {
            $can_change_password = false;
            $hours_left_pass = ceil(24 - $diff_pass_hours);
        }
    } else {
        $changes_left = 3;
    }
}

    // Verifica se há solicitação de Dev Pendente
    $stmt_dev = $conn->prepare("SELECT approval_status FROM developers WHERE user_id = ?");
    $stmt_dev->bind_param("i", $user_id);
    $stmt_dev->execute();
    $dev_req_result = $stmt_dev->get_result();
    $has_pending_dev = false;
    if ($dev_req_result->num_rows > 0) {
        $req = $dev_req_result->fetch_assoc();
        if ($req['approval_status'] === 'pending') {
            $has_pending_dev = true;
        }
    }
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - IndieZone</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/profile.css">
</head>
<body>

    <div style="background: rgba(5, 14, 8, 0.95); padding: 16px 40px; border-bottom: 1px solid rgba(34,197,94,0.15); display: flex; justify-content: space-between; align-items: center;">
        <a href="store.php" style="color: #22c55e; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; text-decoration: none;">IndieZone</a>
        <a href="store.php" style="color: #e2e8f0; text-decoration: none; font-weight: 500; transition: color 0.2s;" onmouseover="this.style.color='#22c55e'" onmouseout="this.style.color='#e2e8f0'">Voltar para Loja</a>
    </div>

    <div class="profile-container">
        
        <aside class="profile-sidebar">
            <div class="avatar-wrapper">
                <!-- [SEGURANÇA] Blindagem do frontend contra XSS. Dados gerados de forma dinâmica ou inputs do usuário (como o avatar baseado no username) são sanitizados com htmlspecialchars na exibição. -->
                <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar">
                <span class="role-badge"><?php echo ($user_role === 'dev') ? 'Desenvolvedor' : strtoupper($user_role); ?></span>
            </div>
            
            <h2 class="sidebar-name"><?php echo htmlspecialchars($user_data['display_name']); ?></h2>
            <p class="sidebar-username">@<?php echo htmlspecialchars($user_data['username']); ?></p>

            <div class="action-buttons">
                <!-- [LÓGICA] Renderização condicional de interface baseada nos privilégios capturados no banco, moldando a experiência de navegação do Player comum, do Dev pendente/aprovado e do Administrador do sistema. -->
                <?php if ($user_role === 'player'): ?>
                    <?php if ($has_pending_dev): ?>
                        <a href="become_dev.php" class="btn-sidebar btn-outline" style="color: #facc15; border-color: rgba(250, 204, 21, 0.3);">⏳ Dev em Análise</a>
                    <?php else: ?>
                        <a href="become_dev.php" class="btn-sidebar btn-primary">Tornar-se Desenvolvedor</a>
                    <?php endif; ?>
                <?php elseif ($user_role === 'dev'): ?>
                    <a href="../dashboard/dashboard.php" class="btn-sidebar btn-dev">Painel do Estúdio</a>
                <?php elseif ($user_role === 'admin'): ?>
                    <a href="admin.php" class="btn-sidebar" style="color: #fff; background: #3b82f6; border: 1px solid #2563eb;">Administração</a>
                <?php endif; ?>
                
                <a href="./library.php" class="btn-sidebar btn-outline">Minha Biblioteca</a>
                <a href="../auth/logout.php" class="btn-sidebar btn-danger" style="margin-top: 15px;">Sair da Conta</a>
            </div>
        </aside>

        <main class="profile-content">
            
            <?php if (isset($_SESSION['profile_msg'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['profile_msg']; unset($_SESSION['profile_msg']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['profile_error'])): ?>
                <div class="alert alert-error"><?php echo $_SESSION['profile_error']; unset($_SESSION['profile_error']); ?></div>
            <?php endif; ?>

            <div class="tabs">
                <button class="tab-btn active" onclick="openTab(event, 'tab-geral')">Editar Perfil</button>
                <button class="tab-btn" onclick="openTab(event, 'tab-notificacoes')">Notificações</button>
                <button class="tab-btn" onclick="openTab(event, 'tab-seguranca')">Segurança & Login</button>
            </div>

            <div id="tab-geral" class="tab-content active">
                <!-- [ARQUITETURA] Orientação a MVC. A interface apenas recolhe os dados. A responsabilidade de realizar os UPDATEs no banco é enviada para o controlador 'update_profile.php', usando o campo hidden 'action' como roteador de comandos. -->
                <form action="../../src/backend/update_profile.php" method="POST">
                    <input type="hidden" name="action" value="update_general">
                    
                    <div class="form-group">
                        <label>Nome de Exibição</label>
                        <input type="text" name="display_name" value="<?php echo htmlspecialchars($user_data['display_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Biografia</label>
                        <textarea name="bio" rows="3" placeholder="Fale um pouco sobre seus jogos favoritos..."><?php echo htmlspecialchars($user_data['bio'] ?? ''); ?></textarea>
                    </div>

                    <h3 style="color: #fff; margin: 30px 0 15px 0; font-size: 16px;">Redes Sociais & Links</h3>
                    
                    <div class="form-group">
                        <label>Twitter / X (Apenas o @)</label>
                        <input type="text" name="social_twitter" placeholder="ex: @seunome" value="<?php echo htmlspecialchars($user_data['social_twitter'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Discord (Nome de usuário)</label>
                        <input type="text" name="social_discord" placeholder="ex: seu_user#1234" value="<?php echo htmlspecialchars($user_data['social_discord'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Twitch</label>
                        <input type="text" name="social_twitch" placeholder="ex: twitch.tv/seucanal" value="<?php echo htmlspecialchars($user_data['social_twitch'] ?? ''); ?>">
                    </div>
                    
                    <button type="submit" class="btn-sidebar btn-primary" style="width: auto; padding: 10px 24px;">Salvar Perfil</button>
                </form>

                <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.1); margin: 30px 0;">

                <form action="../../src/backend/update_profile.php" method="POST">
                    <input type="hidden" name="action" value="update_username">
                    <div class="form-group">
                        <label>Nome de Usuário (@username)</label>
                        <!-- [LÓGICA] Trava de Cooldown reativa. Se o backend avaliou que o tempo de troca (30 dias) não expirou, o input HTML é desabilitado visualmente, melhorando a Experiência do Usuário (UX) antes que ele tente processar um formulário fadado à rejeição. -->
                        <input type="text" name="new_username" value="<?php echo htmlspecialchars($user_data['username']); ?>" 
                               <?php echo !$can_change_username ? 'disabled' : ''; ?> required>
                        
                        <?php if (!$can_change_username): ?>
                            <span class="info-text" style="color: #6ee7b7;">⏳ Você só pode mudar seu @username novamente em <?php echo $days_left_username; ?> dias.</span>
                        <?php else: ?>
                            <span class="info-text">Atenção: Você só poderá alterar isso a cada 30 dias.</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($can_change_username): ?>
                        <button type="submit" class="btn-sidebar btn-outline" style="width: auto; padding: 10px 24px;">Alterar @username</button>
                    <?php endif; ?>
                </form>
            </div>

            <div id="tab-notificacoes" class="tab-content">
                <form action="../../src/backend/update_profile.php" method="POST">
                    <input type="hidden" name="action" value="update_notifications">
                    
                    <h3 style="color: #fff; margin: 0 0 20px 0; font-size: 16px;">Preferências de E-mail</h3>
                    
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; margin-bottom: 16px;">
                        <div>
                            <strong style="display: block; color: #fff; font-size: 15px; margin-bottom: 4px;">Promoções e Ofertas</strong>
                            <span style="color: #94a3b8; font-size: 13px;">Receba e-mails com descontos e jogos gratuitos da semana.</span>
                        </div>
                        <input type="checkbox" name="pref_marketing" value="1" <?php echo ($pref_marketing == 1) ? 'checked' : ''; ?> style="width: 20px; height: 20px; accent-color: #22c55e;">
                    </div>

                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; margin-bottom: 24px;">
                        <div>
                            <strong style="display: block; color: #fff; font-size: 15px; margin-bottom: 4px;">Alertas da Plataforma</strong>
                            <span style="color: #94a3b8; font-size: 13px;">Avisos importantes sobre a sua conta, compras e comunidade.</span>
                        </div>
                        <input type="checkbox" name="pref_alerts" value="1" <?php echo ($pref_alerts == 1) ? 'checked' : ''; ?> style="width: 20px; height: 20px; accent-color: #22c55e;">
                    </div>

                    <button type="submit" class="btn-sidebar btn-primary" style="width: auto; padding: 10px 24px;">Salvar Preferências</button>
                </form>
            </div>

            <div id="tab-seguranca" class="tab-content">
                <form action="../../src/backend/update_profile.php" method="POST">
                    <input type="hidden" name="action" value="update_password">
                    
                    <div class="form-group">
                        <label>Email da Conta (Não alterável)</label>
                        <input type="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" disabled>
                        <span class="info-text">Para alterar o e-mail, entre em contato com o suporte.</span>
                    </div>

                    <h3 style="color: #fff; margin: 30px 0 15px 0; font-size: 16px;">Alterar Senha</h3>
                    
                    <?php if (!$can_change_password): ?>
                        <div class="alert" style="background: rgba(34, 197, 94, 0.05); border: 1px solid rgba(34, 197, 94, 0.3); color: #a7f3d0;">
                            ⏳ Limite diário atingido. Você poderá alterar sua senha novamente em <strong><?php echo $hours_left_pass; ?> horas</strong>.
                        </div>
                    <?php else: ?>
                        <?php if ($changes_left < 3): ?>
                            <p style="color: #6ee7b7; font-size: 13px; margin-bottom: 15px;">Você ainda pode alterar sua senha <strong><?php echo $changes_left; ?></strong> vez(es) hoje.</p>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Senha Atual</label>
                            <input type="password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label>Nova Senha</label>
                            <input type="password" name="new_password" required>
                            <span class="info-text">Use maiúsculas, minúsculas, números e símbolos (mín. 8 caracteres).</span>
                        </div>
                        <div class="form-group">
                            <label>Confirmar Nova Senha</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn-sidebar btn-outline" style="width: auto; padding: 10px 24px;">Atualizar Senha</button>
                    <?php endif; ?>
                </form>

                <!-- [AUDITORIA] Exemplo claro de Soft Delete aplicado para adequação à LGPD (Lei Geral de Proteção de Dados). A conta não é apagada instantaneamente. Em vez disso, o backend atualiza a coluna 'deleted_at', inativando acessos, mas preservando o rastro fiscal até a anonimização definitiva em 30 dias. -->
                <div style="margin-top: 50px; border: 1px solid #ef4444; border-radius: 8px; padding: 24px; background: rgba(239, 68, 68, 0.05);">
                    <h3 style="color: #ef4444; margin: 0 0 10px 0; font-size: 18px;">Zona de Perigo</h3>
                    <p style="color: #94a3b8; font-size: 14px; line-height: 1.5; margin-bottom: 20px;">
                        Ao desativar sua conta, seu perfil será ocultado imediatamente. Você tem <strong>30 dias</strong> para recuperar o acesso fazendo login novamente. Após esse período, seus dados pessoais serão permanentemente anonimizados para fins de conformidade com a LGPD.
                    </p>
                    <button type="button" class="btn-sidebar btn-danger" style="width: auto; padding: 10px 24px;" onclick="openDeleteModal()">Desativar Minha Conta</button>
                </div>

            </div>
        </main>
    </div>

    <!-- [SEGURANÇA] Validação de Identidade para Ações Críticas. O modal exige a reinserção da senha antes de desativar a conta, impedindo que acessos acidentais (ex: computador deixado destravado) resultem em perdas destrutivas para o usuário. -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-box">
            <h3 class="modal-title">⚠️ Ação Destrutiva</h3>
            <p class="modal-text">Para confirmar que é realmente você desativando a conta, por favor, digite sua senha atual abaixo.</p>
            
            <form action="../../src/backend/update_profile.php" method="POST">
                <input type="hidden" name="action" value="delete_account">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <input type="password" name="confirm_delete_password" placeholder="Digite sua senha..." required autofocus
                           style="width: 100%; padding: 12px 16px; border-radius: 8px; background: rgba(0,0,0,0.5); border: 1px solid rgba(239, 68, 68, 0.4); color: #fff; font-family: inherit;">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-sidebar btn-outline" style="width: auto; padding: 10px 20px; border-color: rgba(255,255,255,0.1);" onclick="closeDeleteModal()">Cancelar</button>
                    <button type="submit" class="btn-sidebar btn-danger" style="width: auto; padding: 10px 20px;">Confirmar Desativação</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- 1. MEMÓRIA DAS ABAS (TABS) CORRIGIDA ---
        function openTab(evt, tabName) {
            let i, tabcontent, tablinks;
            
            // Esconde todos os conteúdos
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].classList.remove("active");
            }
            
            // Tira a cor de todos os botões
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("active");
            }
            
            // Mostra a aba escolhida
            document.getElementById(tabName).classList.add("active");
            
            // Pinta o botão correto (busca pelo nome da aba no HTML, não pelo clique do mouse)
            let activeBtn = document.querySelector(`button[onclick*="${tabName}"]`);
            if (activeBtn) {
                activeBtn.classList.add("active");
            }
            
            // [LÓGICA] Manutenção de Estado da UI no navegador (Client-Side). Salva a aba ativa no Session Storage para que o usuário não seja jogado de volta à aba "Geral" sempre que o formulário for atualizado pelo PHP.
            sessionStorage.setItem('activeProfileTab', tabName);
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Se o usuário veio de OUTRA página (ex: da loja), limpa a memória da aba
            if (!document.referrer.includes('index.php')) {
                sessionStorage.removeItem('activeProfileTab');
            }

            const savedTab = sessionStorage.getItem('activeProfileTab');
            if (savedTab && document.getElementById(savedTab)) {
                openTab(null, savedTab);
            } else {
                // Força abrir a aba Editar Perfil por padrão
                openTab(null, 'tab-geral');
            }
        });

        // --- 2. LÓGICA DO MODAL DE EXCLUSÃO ---
        const modal = document.getElementById('deleteModal');
        function openDeleteModal() {
            modal.classList.add('active');
            setTimeout(() => { document.querySelector('input[name="confirm_delete_password"]').focus(); }, 100);
        }
        function closeDeleteModal() {
            modal.classList.remove('active');
            document.querySelector('input[name="confirm_delete_password"]').value = ''; 
        }
        modal.addEventListener('click', function(e) {
            if (e.target === this) { closeDeleteModal(); }
        });

        // --- 3. VALIDAÇÃO DE SENHA EM TEMPO REAL ---
        const newPassInput = document.querySelector('input[name="new_password"]');
        const confirmPassInput = document.querySelector('input[name="confirm_password"]');
        const passBtn = newPassInput ? newPassInput.closest('form').querySelector('button[type="submit"]') : null;
        
        // [SEGURANÇA] Regra de negócio dupla. A mesma política rigorosa de Regex utilizada no cadastro do backend é espelhada aqui no frontend do perfil. Isso obriga a alta entropia da senha e evita o desperdício de requisições de formulários com senhas fracas.
        const regexStrong = /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/;

        function validatePassword() {
            if(!newPassInput || !confirmPassInput) return;

            const newPass = newPassInput.value;
            const confirmPass = confirmPassInput.value;
            let isValid = true;
            let msg = "Use maiúsculas, minúsculas, números e símbolos (mín. 8 caracteres).";
            let color = "#64748b"; 

            if (newPass.length > 0) {
                if (!regexStrong.test(newPass)) {
                    msg = "❌ Senha fraca. Falta letra maiúscula, número ou símbolo.";
                    color = "#ef4444"; 
                    isValid = false;
                } else if (confirmPass.length > 0 && newPass !== confirmPass) {
                    msg = "❌ As senhas não coincidem!";
                    color = "#ef4444";
                    isValid = false;
                } else if (newPass === confirmPass) {
                    msg = "✅ Senha forte e confirmada!";
                    color = "#22c55e"; 
                }
            }

            const infoText = newPassInput.nextElementSibling;
            infoText.textContent = msg;
            infoText.style.color = color;

            passBtn.disabled = !isValid && newPass.length > 0;
            passBtn.style.opacity = passBtn.disabled ? '0.5' : '1';
            passBtn.style.cursor = passBtn.disabled ? 'not-allowed' : 'pointer';
        }

        if(newPassInput && confirmPassInput) {
            newPassInput.addEventListener('input', validatePassword);
            confirmPassInput.addEventListener('input', validatePassword);
        }
    </script>
</body>
</html>