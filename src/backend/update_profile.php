<?php
// [ARQUITETURA] Padrão Controller (MVC) com Post/Redirect/Get (PRG). Este arquivo captura os dados enviados pela camada visual, processa todas as rotinas críticas de negócio no backend e devolve o usuário à página original com mensagens de feedback em memória (Flash Messages).
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php"); // [AUDITORIA] Inclusão do Logger
/** @var mysqli $conn */

// [SEGURANÇA] Blindagem de Endpoint. Qualquer acesso direto a esta URL de processamento sem uma sessão ativa é instantaneamente barrado.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../public/auth/login.php");
    exit();
}

$logger = new SystemLogger($conn);
$user_id = $_SESSION['user_id'];

// [LÓGICA] Roteamento de Ações. Utiliza a chave oculta 'action' do formulário para centralizar múltiplas lógicas de atualização em um único controlador limpo e organizado.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- 1. ATUALIZAR GERAL (DUAS TABELAS) ---
    if ($action === 'update_general') {
        // [SEGURANÇA] Sanitização básica (trim) previne que espaços invisíveis causem problemas na formatação das páginas do perfil.
        $display_name = trim($_POST['display_name']);
        $bio = trim($_POST['bio']);
        $twitter = trim($_POST['social_twitter']);
        $discord = trim($_POST['social_discord']);
        $twitch = trim($_POST['social_twitch']);

        if (empty($display_name)) {
            $_SESSION['profile_error'] = "O nome de exibição não pode ficar vazio.";
        } else {
            // [AUDITORIA] Transação ACID (Atomicidade). Como a atualização do perfil escreve dados tanto na tabela 'users' quanto na 'user_settings', o bloco begin_transaction garante que falhas de servidor não criem perfis corrompidos ou pela metade.
            mysqli_begin_transaction($conn);
            try {
                // Tabela 1: users
                $stmt_u = $conn->prepare("UPDATE users SET display_name = ?, bio = ? WHERE user_id = ?");
                $stmt_u->bind_param("ssi", $display_name, $bio, $user_id);
                $stmt_u->execute();

                // Tabela 2: user_settings (Upsert)
                // [LÓGICA] Comando Upsert (ON DUPLICATE KEY UPDATE). Um recurso altamente otimizado do banco: se o usuário recém-criou a conta e não tem uma linha de configurações, ela é inserida (INSERT). Caso já exista, é apenas atualizada (UPDATE).
                $stmt_s = $conn->prepare("INSERT INTO user_settings (user_id, social_twitter, social_discord, social_twitch) 
                                          VALUES (?, ?, ?, ?) 
                                          ON DUPLICATE KEY UPDATE social_twitter = ?, social_discord = ?, social_twitch = ?");
                $stmt_s->bind_param("issssss", $user_id, $twitter, $discord, $twitch, $twitter, $discord, $twitch);
                $stmt_s->execute();

                // [AUDITORIA] Commit finalizando a consistência relacional.
                mysqli_commit($conn);
                
                // [AUDITORIA] Registra a alteração de perfil
                $logger->log('USER_UPDATE_PROFILE', 'INFO', ['user_id' => $user_id, 'entity_table' => 'users', 'entity_id' => $user_id]);

                $_SESSION['display_name'] = $display_name;
                $_SESSION['profile_msg'] = "Perfil atualizado com sucesso!";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $logger->log('USER_UPDATE_PROFILE_ERROR', 'CRITICAL', ['user_id' => $user_id, 'new_data' => ['error' => $e->getMessage()]]);
                $_SESSION['profile_error'] = "Erro ao atualizar perfil.";
            }
        }
    }

    // --- 2. ATUALIZAR PREFERÊNCIAS DE NOTIFICAÇÃO ---
    elseif ($action === 'update_notifications') {
        // [LÓGICA] Operador ternário para conversão de booleanos de formulário HTML (checkboxes) para bits que o banco de dados processa de forma binária e leve (1 ou 0).
        $pref_marketing = isset($_POST['pref_marketing']) ? 1 : 0;
        $pref_alerts = isset($_POST['pref_alerts']) ? 1 : 0;

        $stmt = $conn->prepare("INSERT INTO user_settings (user_id, pref_marketing, pref_alerts) 
                                VALUES (?, ?, ?) 
                                ON DUPLICATE KEY UPDATE pref_marketing = ?, pref_alerts = ?");
        $stmt->bind_param("iiiii", $user_id, $pref_marketing, $pref_alerts, $pref_marketing, $pref_alerts);
        
        if ($stmt->execute()) {
            $logger->log('USER_UPDATE_NOTIFICATIONS', 'INFO', ['user_id' => $user_id, 'entity_table' => 'user_settings', 'entity_id' => $user_id]);
            $_SESSION['profile_msg'] = "Preferências de notificação salvas!";
        } else {
            $_SESSION['profile_error'] = "Erro ao salvar preferências.";
        }
    }

    // --- 3. ATUALIZAR @USERNAME ---
    elseif ($action === 'update_username') {
        // [SEGURANÇA] Limpeza radical via Regex. Aceita apenas caracteres alfanuméricos e underscores, blindando o banco e as URLs dinâmicas do projeto contra caracteres especiais nocivos.
        $new_username = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['new_username']));

        $stmt_current = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt_current->bind_param("i", $user_id);
        $stmt_current->execute();
        $curr_user_db = $stmt_current->get_result()->fetch_assoc();

        if ($new_username === $curr_user_db['username']) {
            $_SESSION['profile_msg'] = "Você não fez nenhuma alteração no seu nome de usuário.";
            header("Location: ../../public/pages/index.php");
            exit();
        }

        // [LÓGICA] Prevenção de Concorrência. Verifica rigorosamente se o nome de usuário desejado já foi reservado por outra conta antes de tentar gravar.
        $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmt_check->bind_param("si", $new_username, $user_id);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            // [AUDITORIA] Grava a tentativa frustrada
            $logger->log('USER_UPDATE_USERNAME_TAKEN', 'WARNING', ['user_id' => $user_id, 'new_data' => ['attempted_username' => $new_username]]);
            $_SESSION['profile_error'] = "Este @username já está em uso por outro jogador.";
        } else {
            // [AUDITORIA] Aplicação do carimbo de tempo irreversível (CURRENT_TIMESTAMP). Registra quando a identidade textual do usuário mudou, ativando as travas de Cooldown do sistema (limite de 30 dias).
            $stmt = $conn->prepare("UPDATE users SET username = ?, last_username_change = CURRENT_TIMESTAMP WHERE user_id = ?");
            $stmt->bind_param("si", $new_username, $user_id);
            if ($stmt->execute()) {
                // [AUDITORIA] Grava a troca bem-sucedida, registrando o estado anterior e o novo
                $logger->log('USER_UPDATE_USERNAME', 'INFO', [
                    'user_id' => $user_id, 
                    'entity_table' => 'users', 
                    'entity_id' => $user_id, 
                    'old_data' => ['username' => $curr_user_db['username']], 
                    'new_data' => ['username' => $new_username]
                ]);

                $_SESSION['username'] = $new_username;
                $_SESSION['profile_msg'] = "Nome de usuário alterado com sucesso! Você só poderá alterar novamente daqui a 30 dias.";
            }
        }
    }

    // --- 4. ATUALIZAR SENHA (COM CONTADOR) ---
    elseif ($action === 'update_password') {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        // [SEGURANÇA] Defesa em Profundidade. A mesma expressão regular usada no front-end é duplicada no backend. Isso garante a política de senhas fortes mesmo se o JavaScript do cliente falhar ou for desativado.
        $password_regex = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_]).{8,}$/';

        if ($new !== $confirm) {
            $_SESSION['profile_error'] = "As novas senhas não coincidem.";
        } elseif (!preg_match($password_regex, $new)) {
            $_SESSION['profile_error'] = "A nova senha deve ter no mínimo 8 caracteres, com letra maiúscula, minúscula, número e caractere especial.";
        } else {
            $stmt = $conn->prepare("SELECT password_hash, last_password_change, password_change_count FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user_db = $stmt->get_result()->fetch_assoc();

            // [SEGURANÇA] Reautenticação. Para alterar um dado sensível, a sessão ativa não basta. O usuário precisa provar criptograficamente que conhece a credencial de origem.
            if (password_verify($current, $user_db['password_hash'])) {
                
                $now = new DateTime();
                $last_change = !empty($user_db['last_password_change']) ? new DateTime($user_db['last_password_change']) : null;
                $diff_hours = $last_change ? ($now->getTimestamp() - $last_change->getTimestamp()) / 3600 : 25;

                $new_count = 1;
                $new_timestamp = date('Y-m-d H:i:s'); 

                // [AUDITORIA] Limitador Anti-Hijacking (Rate Limiting de Banco). O sistema conta os acessos. Após 3 mudanças em 24h, o acesso é barrado. Isso impede que atacantes travem o usuário original para fora de sua conta infinitamente.
                if ($diff_hours < 24) {
                    if ($user_db['password_change_count'] >= 3) {
                        $logger->log('USER_PASSWORD_RATE_LIMIT', 'WARNING', ['user_id' => $user_id]); // LOG INSERIDO
                        $_SESSION['profile_error'] = "Limite de alterações de senha excedido. Tente novamente mais tarde.";
                        header("Location: ../../public/pages/index.php");
                        exit();
                    }
                    $new_count = $user_db['password_change_count'] + 1;
                    $new_timestamp = $user_db['last_password_change']; 
                }

                $new_hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt_upd = $conn->prepare("UPDATE users SET password_hash = ?, last_password_change = ?, password_change_count = ? WHERE user_id = ?");
                $stmt_upd->bind_param("ssii", $new_hash, $new_timestamp, $new_count, $user_id);
                
                if ($stmt_upd->execute()) {
                    $logger->log('USER_UPDATE_PASSWORD', 'INFO', ['user_id' => $user_id, 'entity_table' => 'users', 'entity_id' => $user_id]); // LOG INSERIDO
                    $_SESSION['profile_msg'] = "Sua senha foi atualizada com segurança!";
                } else {
                    $_SESSION['profile_error'] = "Erro interno ao trocar a senha.";
                }
            } else {
                $logger->log('USER_UPDATE_PASSWORD_FAILED', 'WARNING', ['user_id' => $user_id, 'new_data' => ['reason' => 'invalid_current_password']]); // LOG INSERIDO
                $_SESSION['profile_error'] = "A Senha Atual digitada está incorreta.";
            }
        }
    }

    // --- 5. ZONA DE PERIGO: DESATIVAR CONTA COM SENHA ---
    elseif ($action === 'delete_account') {
        $password_attempt = $_POST['confirm_delete_password'];

        $stmt_check = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        $user_db = $stmt_check->get_result()->fetch_assoc();

        // [SEGURANÇA] Barreira crtítica. Exige validação ativa da senha antes de disparar rotinas destrutivas, prevenindo incidentes se o usuário esqueceu o terminal desbloqueado.
        if (password_verify($password_attempt, $user_db['password_hash'])) {
            
            // [AUDITORIA] Aplicação final do conceito de LGPD com Soft Delete. Em vez de apagar os dados do banco irreversivelmente (o que corromperia recibos e auditorias de terceiros), o sistema carimba o instante exato na coluna 'deleted_at' e suspende o perfil ('is_active = 0').
            $stmt = $conn->prepare("UPDATE users SET deleted_at = CURRENT_TIMESTAMP, is_active = 0 WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $logger->log('USER_DEACTIVATE_ACCOUNT', 'WARNING', ['user_id' => $user_id, 'entity_table' => 'users', 'entity_id' => $user_id]); // LOG INSERIDO
                
                // [LÓGICA] Como a conta foi desativada, não basta redirecionar, o sistema precisa eviscerar os tokens da sessão atual (session_destroy) para invalidar o acesso do terminal no mesmo instante.
                session_destroy();
                session_start();
                $_SESSION['login_error'] = "Sua conta foi desativada com sucesso. Caso queira recuperar o acesso, faça login novamente nos próximos 30 dias.";
                header("Location: ../../public/auth/login.php");
                exit();
            } else {
                $_SESSION['profile_error'] = "Erro crítico ao desativar a conta. Tente novamente.";
            }
        } else {
            $logger->log('USER_DEACTIVATE_FAILED', 'WARNING', ['user_id' => $user_id, 'new_data' => ['reason' => 'invalid_password']]); // LOG INSERIDO
            $_SESSION['profile_error'] = "Senha incorreta. A desativação da conta foi cancelada por segurança.";
        }
    }

    header("Location: ../../public/pages/index.php");
    exit();
}
?>