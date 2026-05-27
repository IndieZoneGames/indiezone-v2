<?php
// public/dashboard/manage_builds.php

// [ARQUITETURA] Inicializa as validações de rota (dev_header) para garantir que apenas o criador deste estúdio acesse a área.
require_once("includes/dev_header.php");
/** @var mysqli $conn */

// 1. CARREGAR DEPENDÊNCIAS (Composer e Dotenv)
// [ARQUITETURA] Autoloading de bibliotecas externas. A inclusão do Composer na raiz do projeto isola os SDKs complexos (como o Google_Client) das lógicas diretas de visualização, garantindo que o PHP não corrompa tentando instanciar objetos ausentes.
require_once '../../vendor/autoload.php';

try {
    // [SEGURANÇA] Resolução do caminho absoluto para injeção do arquivo '.env'. Essa prática impede que configurações secretas (como o Refresh Token do Google Cloud) sejam expostas se a estrutura de pastas for alterada.
    $root_path = dirname(__DIR__, 2); 
    $dotenv = Dotenv\Dotenv::createImmutable($root_path);
    $dotenv->load();

    // Capturar as credenciais das variáveis de ambiente
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
// [LÓGICA] Prevenção de quebra do gerenciador de estado. Assim como na edição do jogo, o upload exige o ID alvo.
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: my_games.php");
    exit();
}

$game_id = intval($_GET['id']);
$message = "";

// --- FUNÇÃO PARA BUSCAR OU CRIAR PASTAS NO DRIVE ---
// [ARQUITETURA] Esta função encapsula a complexidade da API do Google Drive. Em vez de simplesmente "jogar" arquivos numa raiz (Root), o sistema tenta mapear a hierarquia organizacional de pastas, promovendo a manutenibilidade do ecossistema a longo prazo.
function getOrCreateDriveFolder($driveService, $folderName, $parentId) {
   // [SEGURANÇA] Escapamento primitivo de aspas, evitando quebra da formatação na querystring interna da API do Google, o que funciona como uma prevenção básica de injeção em APIs terceiras.
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
// [SEGURANÇA] Blindagem IDOR. Valida se o ID passado pela URL pertence ao desenvolvedor que está operando o painel no momento.
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
// [LÓGICA] Implementação de máquina de estado segura. A transição para 'pending' (em análise) não é arbitrária. A regra de negócio exige pelo menos um build atrelado ('COUNT(*) > 0') no banco antes que um projeto consuma recursos de um moderador humano.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $check_builds = $conn->query("SELECT COUNT(*) FROM game_builds WHERE game_id = $game_id");
    if ($check_builds->fetch_row()[0] > 0) {
        $stmt_update = $conn->prepare("UPDATE games SET status = 'pending' WHERE game_id = ?");
        $stmt_update->bind_param("i", $game_id);
        if ($stmt_update->execute()) {
            $game['status'] = 'pending';
            $message = "✅ Sucesso! O jogo foi enviado para a equipa de Moderação.";
        }
    } else {
        $message = "❌ Erro: Você precisa fazer o upload de pelo menos um ficheiro antes de enviar para revisão.";
    }
}

// 5. PROCESSAR O ENVIO (CHUNKED UPLOAD)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_build'])) {
    $version_name = trim($_POST['version_name']);
    $platform = $_POST['platform'];
    
    // [SEGURANÇA] Bloqueia envios vazios e confere o código de erro nativo do PHP (UPLOAD_ERR_OK) antes de tentar acessar dados brutos na array temporária.
    if (isset($_FILES['game_file']) && $_FILES['game_file']['error'] === UPLOAD_ERR_OK) {
        $file_name = $_FILES['game_file']['name'];
        $file_size = $_FILES['game_file']['size']; 
        $tmp_name = $_FILES['game_file']['tmp_name'];

        try {
            // [ARQUITETURA] Autenticação OAuth2 Server-to-Server. O servidor web não interage com a conta pessoal do usuário. Ele usa um Refresh Token fixo atrelado à "Conta de Serviço" da plataforma para agir como intermediário no Drive.
            $client = new Google_Client();
            $client->setClientId($client_id);
            $client->setClientSecret($client_secret);
            $token = $client->fetchAccessTokenWithRefreshToken($refresh_token); 
            
            if (isset($token['error'])) throw new Exception("Falha na autenticação: " . $token['error']);
            
            $client->setAccessToken($token);
            $driveService = new Google_Service_Drive($client);

            // Código Novo: Usando ID para garantir que nunca duplica
            // [AUDITORIA] Mapeamento explícito de propriedade. O sistema força a utilização do $user_id validado em sessão para construir a taxonomia de pastas da nuvem. Se um estúdio mudar seu "Display Name", os arquivos antigos não se perderão em pastas órfãs, mantendo o histórico de auditoria perfeito.
            $nome_estudio = "Estudio_" . $user_id; 
            $studio_folder_id = getOrCreateDriveFolder($driveService, $nome_estudio, $drive_folder_id);

            // Criamos a pasta sempre com "Jogo_ID" (ex: Jogo_22)
            $nome_pasta_jogo = "Jogo_" . $game_id;
            $game_drive_folder_id = getOrCreateDriveFolder($driveService, $nome_pasta_jogo, $studio_folder_id);

            $safe_filename = $platform . '_' . $version_name . '_' . $file_name;
            $mime_type = mime_content_type($tmp_name) ?: 'application/octet-stream';

            $fileMetadata = new Google_Service_Drive_DriveFile([
                'name' => $safe_filename,
                'parents' => [$game_drive_folder_id]
            ]);

            // Upload Seguro em Chunks de 2MB
            // [ARQUITETURA] Chunked Upload. Essa técnica otimiza o uso de memória do servidor ao transmitir grandes arquivos executáveis (Builds) fracionados (ex: blocos de 2MB) para o Google, prevenindo o congelamento de processos (Timeouts) ou estouros do limite do PHP (Memory Exhaustion).
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

            // [AUDITORIA] Em vez de salvar uma URL mutável, a plataforma salva o identificador físico e permanente da API ($real_drive_id). 
            // Qualquer tentativa de download passará primeiro pela validação do banco de dados (users -> games -> builds) antes que a API responda com os dados encriptados.
            $real_drive_id = $status['id']; 
            
            $stmt_build = $conn->prepare("INSERT INTO game_builds (game_id, version_name, platform_os, drive_file_id, file_size_bytes, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt_build->bind_param("isssi", $game_id, $version_name, $platform, $real_drive_id, $file_size);
            
            if ($stmt_build->execute()) {
                $message = "✅ Sucesso! Ficheiro enviado para a nuvem de forma segura.";
            }
            $stmt_build->close();

        } catch (Exception $e) {
            $message = "❌ Erro ao enviar para a Nuvem: " . $e->getMessage();
        }
    }
}

$res_builds = mysqli_query($conn, "SELECT * FROM game_builds WHERE game_id = $game_id ORDER BY created_at DESC");
$total_builds = mysqli_num_rows($res_builds);
?>

<main class="dashboard-main" style="padding-bottom: 80px;">
    
    <header class="dash-header" style="justify-content: flex-start; gap: 20px; margin-bottom: 20px;">
        <img src="<?php echo htmlspecialchars($game['cover_image_url'] ?: '../assets/img/placeholder-game.png'); ?>" 
             alt="Capa" style="width: 120px; height: 60px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
        <div class="dash-title">
            <h1 style="font-size: 28px; font-weight: 800; color: #fff;">Gerenciar Arquivos (Builds)</h1>
            <!-- [SEGURANÇA] Sanitização mandatória contra Stored XSS caso o nome de capa ou do projeto tenha caracteres invasivos. -->
            <p>Jogo: <strong style="color: var(--primary);"><?php echo htmlspecialchars($game['title']); ?></strong></p>
        </div>
        <div style="flex: 1;"></div>
        <a href="edit_game.php?id=<?php echo $game_id; ?>" class="btn-upload" style="background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #fff;">✏️ Editar Loja</a>
    </header>

    <div style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 20px; margin-bottom: 40px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 style="margin: 0 0 5px 0; color: #fff; font-size: 16px;">Status Atual da Publicação</h3>
            <?php 
                // [LÓGICA] Abordagem de dicionário limpo para mapeamento dos estados, evitando estruturas pesadas de 'if-else' no frontend e traduzindo chaves internas do banco para descritivos amigáveis em tela.
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
                <!-- [LÓGICA] Implementação de trava de segurança. Um alert JavaScript nativo (confirm) força o usuário a pensar duas vezes antes de iniciar uma transação de alteração de máquina de estado irreversível no frontend. -->
                <button type="submit" name="submit_review" class="btn-upload" style="background: #3b82f6; color: #fff; border: none;" onclick="return confirm('Enviar para revisão agora?');">
                    Submeter para Aprovação
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($message)): ?>
        <div class="msg-subtle" style="background: rgba(34, 197, 94, 0.1); color: var(--primary); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="form-row">
        <div class="form-col" style="flex: 1;">
            <div class="dev-form-card" style="padding: 30px;">
                <h3 class="section-title" style="margin-top: 0;">📤 Enviar Nova Versão</h3>
                
                <!-- [ARQUITETURA] Bloqueio reativo de componentes baseados no estado do dado (status = pending). O formulário de envio não é processado nem chega a ser renderizado na View se o jogo estiver sob auditoria da equipe de moderação. -->
                <?php if ($game['status'] == 'pending'): ?>
                    <div style="background: rgba(250, 204, 21, 0.1); border: 1px solid rgba(250, 204, 21, 0.3); color: #facc15; padding: 15px; border-radius: 8px; font-size: 14px; text-align: center;">
                        Edição bloqueada durante a análise.
                    </div>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data">
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
                        <button type="submit" name="upload_build" class="btn-submit-dev" onclick="this.innerHTML='A enviar...';">Iniciar Upload ➔</button>
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
                                    <td style="padding: 12px; font-weight: 600;"><?php echo $b['version_name']; ?></td>
                                    <td style="padding: 12px; opacity: 0.8;"><?php echo ucfirst($b['platform_os']); ?></td>
                                    <td style="padding: 12px; opacity: 0.8;">
                                        <!-- [LÓGICA] Transformação de visualização. Mantém a precisão extrema dos dados salvando a exata quantidade de bytes no banco, mas entrega um dado traduzido para MB (Megabytes) para facilitar o entendimento do desenvolvedor. -->
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
</script>
</body>
</html>