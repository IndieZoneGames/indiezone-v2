<?php
// public/dashboard/manage_builds.php

// [ARQUITETURA] Inicializa as validações de rota (dev_header) para garantir que apenas o criador deste estúdio acesse a área.
require_once("includes/dev_header.php");
/** @var mysqli $conn */

// 1. CARREGAR DEPENDÊNCIAS (Composer e Dotenv)
require_once '../../vendor/autoload.php';

try {
    // [SEGURANÇA] Resolução do caminho absoluto para injeção do arquivo '.env'.
    $root_path = dirname(__DIR__, 2); 
    $dotenv = Dotenv\Dotenv::createImmutable($root_path);
    $dotenv->load();

    $client_id = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
    $client_secret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
    $refresh_token = $_ENV['GOOGLE_REFRESH_TOKEN'] ?? '';
    $drive_folder_id = $_ENV['GOOGLE_DRIVE_FOLDER_ID'] ?? '';

    if (empty($client_id) || empty($refresh_token)) {
        throw new Exception("Credenciais do Google não encontradas no ficheiro .env");
    }
} catch (Exception $e) {
    die("<div style='color:#ff4444; background:#1a1a1a; padding:20px; border-radius:8px; margin:20px; font-family:sans-serif;'>
            <strong>Erro de Configuração:</strong> " . $e->getMessage() . "
         </div>");
}

// 2. VERIFICAÇÃO DE SEGURANÇA
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: my_games.php");
    exit();
}

$game_id = intval($_GET['id']);

// --- SISTEMA ANTI-F5 (PRG) COM TRATAMENTO SILENCIOSO DE ERROS ---
$message = "";
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'upload_ok') {
        $message = "✅ Sucesso! Ficheiro enviado para a nuvem e salvo no banco de dados.";
    } elseif ($_GET['msg'] === 'upload_local') {
        $message = "✅ Sucesso! (Modo de Segurança). Ficheiro processado e salvo localmente no servidor.";
    } elseif ($_GET['msg'] === 'review_ok') {
        $message = "✅ Sucesso! O jogo foi enviado para a equipa de Moderação.";
    } elseif ($_GET['msg'] === 'err_local_perm') {
        $message = "❌ Falha (Plano B): O servidor bloqueou a criação da pasta. Verifique as permissões (chmod) da pasta 'uploads'.";
    } elseif ($_GET['msg'] === 'err_local_move') {
        $message = "❌ Falha (Plano B): Não foi possível mover o arquivo para o disco local.";
    } elseif ($_GET['msg'] === 'err_db') {
        $message = "❌ Erro Crítico: O ficheiro foi salvo, mas o banco de dados recusou o registro.";
    }
}

// --- FUNÇÃO PARA BUSCAR OU CRIAR PASTAS NO DRIVE ---
function getOrCreateDriveFolder($driveService, $folderName, $parentId) {
    $folderNameClean = str_replace("'", "\\'", $folderName);
    $query = "mimeType='application/vnd.google-apps.folder' and name='" . $folderNameClean . "' and '" . $parentId . "' in parents and trashed=false";
    
    $results = $driveService->files->listFiles([
        'q' => $query,
        'fields' => 'files(id, name)',
        'spaces' => 'drive'
    ]);

    $files = $results->getFiles();
    if (count($files) > 0) {
        return $files[0]->getId();
    } else {
        $folderMetadata = new \Google_Service_Drive_DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId]
        ]);
        $folder = $driveService->files->create($folderMetadata, ['fields' => 'id']);
        return $folder->id;
    }
}

// 3. DADOS DO JOGO
$stmt_check = $conn->prepare("SELECT title, cover_image_url, status FROM games WHERE game_id = ? AND developer_id = ?");
$stmt_check->bind_param("ii", $game_id, $user_id);
$stmt_check->execute();
$res_game = $stmt_check->get_result();

if ($res_game->num_rows === 0) {
    header("Location: dashboard.php");
    exit();
}
$game = $res_game->fetch_assoc();

// 4. AÇÃO: SUBMETER PARA REVISÃO DOS ADMINS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $check_builds = $conn->query("SELECT COUNT(*) FROM game_builds WHERE game_id = $game_id");
    if ($check_builds->fetch_row()[0] > 0) {
        $stmt_update = $conn->prepare("UPDATE games SET status = 'pending' WHERE game_id = ?");
        $stmt_update->bind_param("i", $game_id);
        if ($stmt_update->execute()) {
            echo "<script>window.location.href = 'manage_builds.php?id=" . $game_id . "&msg=review_ok';</script>";
            exit();
        }
    } else {
        $message = "❌ Erro: Você precisa fazer o upload de pelo menos um ficheiro antes de enviar para revisão.";
    }
}

$max_post_size = ini_get('post_max_size');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && $_SERVER['CONTENT_LENGTH'] > 0) {
    $message = "❌ Erro Fatal: O arquivo enviado é maior que o limite máximo permitido pelo servidor (" . $max_post_size . ").";
}

// 5. PROCESSAR O ENVIO (CHUNKED UPLOAD)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_build'])) {
    
    if (isset($_FILES['game_file'])) {
        if ($_FILES['game_file']['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE   => "O arquivo excede o limite de upload do PHP.",
                UPLOAD_ERR_FORM_SIZE  => "O arquivo excede o limite especificado no formulário HTML.",
                UPLOAD_ERR_PARTIAL    => "O upload do arquivo foi feito apenas parcialmente (conexão caiu).",
                UPLOAD_ERR_NO_FILE    => "Nenhum arquivo foi enviado.",
                UPLOAD_ERR_NO_TMP_DIR => "Pasta temporária ausente no servidor.",
                UPLOAD_ERR_CANT_WRITE => "Falha ao escrever o arquivo em disco.",
                UPLOAD_ERR_EXTENSION  => "Uma extensão do PHP interrompeu o upload do arquivo."
            ];
            $err_code = $_FILES['game_file']['error'];
            $message = "❌ Erro no Upload: " . ($upload_errors[$err_code] ?? "Erro desconhecido ($err_code).");
        } else {
            
            $version_name = trim($_POST['version_name']);
            $platform = $_POST['platform'];
            $file_name = $_FILES['game_file']['name'];
            $file_size = $_FILES['game_file']['size']; 
            $tmp_name = $_FILES['game_file']['tmp_name'];

            set_time_limit(0);

            try {
                // ==========================================
                // PLANO A: TENTAR UPLOAD PARA O GOOGLE DRIVE
                // ==========================================
                $client = new Google_Client();
                $client->setClientId($client_id);
                $client->setClientSecret($client_secret);
                $token = $client->fetchAccessTokenWithRefreshToken($refresh_token); 
                
                if (isset($token['error'])) {
                    throw new Exception("Falha na autenticação do token do Google.");
                }
                
                $client->setAccessToken($token);
                $driveService = new Google_Service_Drive($client);

                $nome_estudio = "Estudio_" . $user_id; 
                $studio_folder_id = getOrCreateDriveFolder($driveService, $nome_estudio, $drive_folder_id);

                $nome_pasta_jogo = "Jogo_" . $game_id;
                $game_drive_folder_id = getOrCreateDriveFolder($driveService, $nome_pasta_jogo, $studio_folder_id);

                $safe_filename = $platform . '_' . $version_name . '_' . $file_name;
                $mime_type = mime_content_type($tmp_name) ?: 'application/octet-stream';

                $fileMetadata = new Google_Service_Drive_DriveFile([
                    'name' => $safe_filename,
                    'parents' => [$game_drive_folder_id]
                ]);

                $client->setDefer(true);
                $request = $driveService->files->create($fileMetadata);
                $media = new Google_Http_MediaFileUpload($client, $request, $mime_type, null, true, 2 * 1024 * 1024);
                $media->setFileSize($file_size);

                $status = false;
                $handle = fopen($tmp_name, "rb");
                while (!$status && !feof($handle)) {
                    $chunk = fread($handle, 2 * 1024 * 1024);
                    $status = $media->nextChunk($chunk);
                }
                fclose($handle);
                $client->setDefer(false);

                if (!$status) {
                    throw new Exception("Drive interrompido.");
                }

                $real_id = is_object($status) ? $status->getId() : $status['id']; 
                
                $stmt_build = $conn->prepare("INSERT INTO game_builds (game_id, version_name, platform_os, drive_file_id, file_size_bytes, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                
                if ($stmt_build) {
                    $stmt_build->bind_param("isssi", $game_id, $version_name, $platform, $real_id, $file_size);
                    if ($stmt_build->execute()) {
                        echo "<script>window.location.href = 'manage_builds.php?id=" . $game_id . "&msg=upload_ok';</script>";
                        exit();
                    } else {
                        echo "<script>window.location.href = 'manage_builds.php?id=" . $game_id . "&msg=err_db';</script>";
                        exit();
                    }
                } else {
                    throw new Exception("Erro de SQL.");
                }

            } catch (Exception $e) {
                // ==========================================
                // PLANO B: ROTA DE FUGA (FALLBACK LOCAL)
                // ==========================================
                
                $fallback_dir = dirname(__DIR__, 2) . '/public/uploads/builds/';
                
                // O operador '@' suprime o Warning feio do PHP caso a permissão seja negada
                if (!is_dir($fallback_dir)) {
                    @mkdir($fallback_dir, 0777, true);
                }
                
                // Verifica se a pasta realmente não existe ou não tem permissão
                if (!is_dir($fallback_dir) || !is_writable($fallback_dir)) {
                    echo "<script>window.location.href = 'manage_builds.php?id=" . $game_id . "&msg=err_local_perm';</script>";
                    exit();
                }
                
                $fallback_filename = $platform . '_' . time() . '_' . basename($file_name);
                $fallback_path = $fallback_dir . $fallback_filename;
                
                // Tenta mover o arquivo silenciosamente com '@'
                if (@move_uploaded_file($tmp_name, $fallback_path)) {
                    $local_id = "LOCAL:" . $fallback_filename;
                    $stmt_fallback = $conn->prepare("INSERT INTO game_builds (game_id, version_name, platform_os, drive_file_id, file_size_bytes, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                    
                    if ($stmt_fallback) {
                        $stmt_fallback->bind_param("isssi", $game_id, $version_name, $platform, $local_id, $file_size);
                        if ($stmt_fallback->execute()) {
                            echo "<script>window.location.href = 'manage_builds.php?id=" . $game_id . "&msg=upload_local';</script>";
                            exit();
                        } else {
                            echo "<script>window.location.href = 'manage_builds.php?id=" . $game_id . "&msg=err_db';</script>";
                            exit();
                        }
                    }
                } else {
                    echo "<script>window.location.href = 'manage_builds.php?id=" . $game_id . "&msg=err_local_move';</script>";
                    exit();
                }
            }
        }
    }
}

$res_builds = mysqli_query($conn, "SELECT * FROM game_builds WHERE game_id = $game_id ORDER BY created_at DESC");
$total_builds = mysqli_num_rows($res_builds);
?>

<style>
    #upload-loading-overlay {
        display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(5, 14, 8, 0.95); z-index: 9999;
        flex-direction: column; justify-content: center; align-items: center; text-align: center;
        backdrop-filter: blur(5px);
    }
    .spinner-ring {
        width: 60px; height: 60px; border: 4px solid rgba(34, 197, 94, 0.2);
        border-top: 4px solid var(--primary); border-radius: 50%;
        animation: spin 1s linear infinite; margin-bottom: 20px;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<div id="upload-loading-overlay">
    <div class="spinner-ring"></div>
    <h2 style="color: #fff; margin-bottom: 10px;">Enviando arquivo para a nuvem...</h2>
    <p style="color: var(--text-muted); font-size: 14px;">Isso pode demorar alguns minutos dependendo do tamanho do jogo.<br>Por favor, não feche nem atualize esta página.</p>
</div>
<main class="dashboard-main" style="padding-bottom: 80px;">
    
    <header class="dash-header" style="justify-content: flex-start; gap: 20px; margin-bottom: 20px;">
        <img src="<?php echo htmlspecialchars($game['cover_image_url'] ?: '../assets/img/placeholder-game.png'); ?>" 
             alt="Capa" style="width: 120px; height: 60px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
        <div class="dash-title">
            <h1 style="font-size: 28px; font-weight: 800; color: #fff;">Gerenciar Arquivos (Builds)</h1>
            <p>Jogo: <strong style="color: var(--primary);"><?php echo htmlspecialchars($game['title']); ?></strong></p>
        </div>
        <div style="flex: 1;"></div>
        <a href="edit_game.php?id=<?php echo $game_id; ?>" class="btn-upload" style="background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #fff;">✏️ Editar Loja</a>
    </header>

    <div style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 20px; margin-bottom: 40px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 style="margin: 0 0 5px 0; color: #fff; font-size: 16px;">Status Atual da Publicação</h3>
            <?php 
                $status_info = [
                    'draft' => ['🛠️ Rascunho', 'O jogo ainda não foi enviado para análise.'],
                    'pending' => ['⏳ Em Análise', 'A nossa equipa está a validar os ficheiros.'],
                    'published' => ['✅ Público', 'O jogo já está disponível na IndieZone!']
                ];
                $s = $status_info[$game['status']];
            ?>
            <p style="margin: 0; color: #94a3b8; font-size: 14px;"><strong style="color:#fff;"><?php echo $s[0]; ?></strong> — <?php echo $s[1]; ?></p>
        </div>
        
        <?php if ($game['status'] == 'draft'): ?>
            <form method="POST">
                <button type="submit" name="submit_review" class="btn-upload" style="background: #3b82f6; color: #fff; border: none;" onclick="return confirm('Enviar para revisão agora?');">
                    Submeter para Aprovação
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($message)): ?>
        <?php $is_error = strpos($message, '❌') !== false; ?>
        <div class="msg-subtle" style="background: <?php echo $is_error ? 'rgba(239, 68, 68, 0.1)' : 'rgba(34, 197, 94, 0.1)'; ?>; 
                                       border: 1px solid <?php echo $is_error ? 'rgba(239, 68, 68, 0.3)' : 'rgba(34, 197, 94, 0.3)'; ?>; 
                                       color: <?php echo $is_error ? '#ef4444' : '#4ade80'; ?>; 
                                       padding: 16px; border-radius: 8px; margin-bottom: 24px;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="form-row">
        <div class="form-col" style="flex: 1;">
            <div class="dev-form-card" style="padding: 30px;">
                <h3 class="section-title" style="margin-top: 0;">📤 Enviar Nova Versão</h3>
                
                <?php if ($game['status'] == 'pending'): ?>
                    <div style="background: rgba(250, 204, 21, 0.1); border: 1px solid rgba(250, 204, 21, 0.3); color: #facc15; padding: 15px; border-radius: 8px; font-size: 14px; text-align: center;">
                        Edição bloqueada durante a análise.
                    </div>
                <?php else: ?>
                    <form id="form-upload-build" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="upload_build" value="1">
                        
                        <div class="form-group">
                            <label>Plataforma</label>
                            <select name="platform" required>
                                <option value="windows">🖥️ Windows</option>
                                <option value="mac">🍎 macOS</option>
                                <option value="linux">🐧 Linux</option>
                                <option value="android">📱 Android</option>
                                <option value="web">🌐 Web</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Versão</label>
                            <input type="text" name="version_name" required placeholder="v1.0.0">
                        </div>
                        <div class="form-group">
                            <label>Ficheiro</label>
                            <label class="file-drop-area" id="build-drop" style="min-height: 100px;">
                                <span class="file-msg" id="build-msg">Anexar arquivo</span>
                                <input type="file" name="game_file" id="build-input" required>
                            </label>
                        </div>
                        <button type="submit" class="btn-submit-dev">Iniciar Upload ➔</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-col" style="flex: 2;">
            <div class="dev-form-card" style="padding: 30px; height: 100%;">
                <h3 class="section-title" style="margin-top: 0;">🗂️ Arquivos Disponíveis</h3>
                <?php if ($total_builds > 0): ?>
                    <table style="width: 100%; border-collapse: collapse; color: #fff;">
                        <thead>
                            <tr style="text-align: left; opacity: 0.5; font-size: 12px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                                <th style="padding: 10px;">Versão</th>
                                <th style="padding: 10px;">Plataforma</th>
                                <th style="padding: 10px;">Tamanho</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($b = mysqli_fetch_assoc($res_builds)): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                                    <td style="padding: 12px; font-weight: 600;"><?php echo htmlspecialchars($b['version_name']); ?></td>
                                    <td style="padding: 12px; opacity: 0.8;"><?php echo htmlspecialchars(ucfirst($b['platform_os'])); ?></td>
                                    <td style="padding: 12px; opacity: 0.8;">
                                        <?php 
                                            $tamanho_mb = round($b['file_size_bytes'] / 1048576, 2);
                                            echo ($tamanho_mb < 0.01) ? '< 0.01 MB' : $tamanho_mb . ' MB';
                                        ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 40px;">Nenhum ficheiro enviado.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
    const fileInput = document.getElementById('build-input');
    const fileMsg = document.getElementById('build-msg');
    
    if(fileInput) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                fileMsg.textContent = fileInput.files[0].name;
                fileMsg.style.color = 'var(--primary)';
            }
        });
    }

    const uploadForm = document.getElementById('form-upload-build');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function() {
            document.getElementById('upload-loading-overlay').style.display = 'flex';
            const btn = this.querySelector('button[type="submit"]');
            
            setTimeout(() => {
                btn.disabled = true;
                btn.innerHTML = 'Enviando para a nuvem...';
            }, 50); 
        });
    }
</script>
</body>
</html>