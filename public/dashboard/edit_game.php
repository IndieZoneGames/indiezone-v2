<?php
// public/dashboard/edit_game.php
// [ARQUITETURA] Inicializa a proteção de rota e os dados globais.
require_once("includes/dev_header.php");
require_once("../../src/backend/SystemLogger.php");
/** @var mysqli $conn */
/** @var int $user_id */

$logger = new SystemLogger($conn);

$message = '';
$error = '';

// 1. VERIFICAÇÃO DE SEGURANÇA E CAPTURA DO ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: my_games.php");
    exit();
}

$game_id = intval($_GET['id']);

// 2. PROCESSAR A ATUALIZAÇÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $short_description = trim($_POST['short_description']);
    $description = trim($_POST['description']);
    $release_stage = $_POST['release_stage'];
    $genre_id = intval($_POST['genre_id']); 
    $trailer_url = trim($_POST['trailer_url']);
    
    $monetization = $_POST['monetization']; 
    $price = 0.00;
    $min_price = 0.00;
    $is_pwyw = 0;

    $conn->begin_transaction();

    try {
        // [CORREÇÃO CRÍTICA]: Validação no Back-end (Não confiar no HTML para prevenir valores negativos)
        if ($monetization === 'paid') {
            $price = floatval($_POST['price']);
            if ($price < 1.00) throw new Exception("Preço de venda inválido. O valor mínimo é R$ 1,00.");
        } elseif ($monetization === 'pwyw') {
            $is_pwyw = 1;
            $min_price = floatval($_POST['min_price']);
            if ($min_price < 0) throw new Exception("O preço mínimo de doação não pode ser negativo.");
        }

        // Atualiza a capa APENAS se o dev tiver enviado uma nova
        $update_cover_sql = "";
        $params = [$title, $short_description, $description, $price, $min_price, $is_pwyw, $release_stage];
        $types = "sssdiis";

        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../public/uploads/covers/';
            // [CORREÇÃO CRÍTICA]: Permissão alterada de 0777 para 0755 (Segurança contra execução remota)
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
            $new_filename = uniqid('cover_') . '.' . $ext;
            
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_dir . $new_filename)) {
                $cover_image_url = '../uploads/covers/' . $new_filename;
                $update_cover_sql = ", cover_image_url = ?";
                $params[] = $cover_image_url;
                $types .= "s";
            }
        }

        $params[] = $game_id;
        $params[] = $user_id;
        $types .= "ii";

        $sql = "UPDATE games SET title = ?, short_description = ?, description = ?, price = ?, min_price = ?, is_pwyw = ?, release_stage = ? $update_cover_sql WHERE game_id = ? AND developer_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception("Erro ao atualizar os dados base do jogo.");
        }

        // Atualizar Gênero
        $conn->query("DELETE FROM game_genres WHERE game_id = $game_id");
        if ($genre_id > 0) {
            $stmt_genre = $conn->prepare("INSERT INTO game_genres (game_id, genre_id) VALUES (?, ?)");
            $stmt_genre->bind_param("ii", $game_id, $genre_id);
            $stmt_genre->execute();
        }

        // Atualizar Trailer
        $conn->query("DELETE FROM game_media WHERE game_id = $game_id AND media_type = 'trailer'");
        if (!empty($trailer_url)) {
            $stmt_trailer = $conn->prepare("INSERT INTO game_media (game_id, media_type, media_url, display_order) VALUES (?, 'trailer', ?, 0)");
            $stmt_trailer->bind_param("is", $game_id, $trailer_url);
            $stmt_trailer->execute();
        }

        // Processar Atualização de Screenshots
        if (isset($_FILES['screenshots']['name'][0]) && !empty($_FILES['screenshots']['name'][0])) {
            
            // [CORREÇÃO CRÍTICA]: Proteção contra Path Traversal e exclusão indevida de ficheiros
            $stmt_old_ss = $conn->prepare("SELECT media_url FROM game_media WHERE game_id = ? AND media_type = 'screenshot'");
            $stmt_old_ss->bind_param("i", $game_id);
            $stmt_old_ss->execute();
            $res_old = $stmt_old_ss->get_result();
            
            while ($row = $res_old->fetch_assoc()) {
                // basename() limpa caminhos maliciosos (../) e captura apenas o nome final do ficheiro
                $filename = basename($row['media_url']); 
                if (!empty($filename)) {
                    $file_path = __DIR__ . '/../../public/uploads/screenshots/' . $filename; 
                    if (file_exists($file_path) && is_file($file_path)) {
                        unlink($file_path);
                    }
                }
            }

            // Depois limpa do banco
            $conn->query("DELETE FROM game_media WHERE game_id = $game_id AND media_type = 'screenshot'");

            $upload_dir_ss = '../../public/uploads/screenshots/';
            // [CORREÇÃO CRÍTICA]: Permissão alterada de 0777 para 0755
            if (!is_dir($upload_dir_ss)) mkdir($upload_dir_ss, 0755, true);

            foreach ($_FILES['screenshots']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['screenshots']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['screenshots']['name'][$key], PATHINFO_EXTENSION));
                    $new_filename = uniqid('screen_') . '_' . $key . '.' . $ext;
                    
                    if (move_uploaded_file($tmp_name, $upload_dir_ss . $new_filename)) {
                        $media_url = '../uploads/screenshots/' . $new_filename;
                        $stmt_ins_ss = $conn->prepare("INSERT INTO game_media (game_id, media_type, media_url, display_order) VALUES (?, 'screenshot', ?, ?)");
                        $stmt_ins_ss->bind_param("isi", $game_id, $media_url, $key);
                        $stmt_ins_ss->execute();
                    }
                }
            }
        }

        $conn->commit();
        
        $logger->log('DEV_UPDATE_GAME', 'INFO', [
            'user_id' => $user_id,
            'entity_table' => 'games',
            'entity_id' => $game_id,
            'new_data' => [
                'title' => $title, 
                'price' => $price, 
                'monetization' => $monetization
            ]
        ]);

        $message = "✅ Loja atualizada com sucesso!";

    } catch (Exception $e) {
        $conn->rollback();
        
        $logger->log('DEV_UPDATE_GAME_ERROR', 'CRITICAL', [
            'user_id' => $user_id,
            'entity_table' => 'games',
            'entity_id' => $game_id,
            'new_data' => ['error_message' => $e->getMessage()]
        ]);

        $error = $e->getMessage();
    }
}

// 3. BUSCAR OS DADOS ATUAIS DO JOGO
$stmt_game = $conn->prepare("SELECT * FROM games WHERE game_id = ? AND developer_id = ?");
$stmt_game->bind_param("ii", $game_id, $user_id);
$stmt_game->execute();
$res_game = $stmt_game->get_result();

if ($res_game->num_rows === 0) {
    header("Location: my_games.php");
    exit();
}
$game = $res_game->fetch_assoc();

$current_genre_id = 0;
$res_curr_genre = $conn->query("SELECT genre_id FROM game_genres WHERE game_id = $game_id LIMIT 1");
if ($res_curr_genre && $row = $res_curr_genre->fetch_assoc()) {
    $current_genre_id = $row['genre_id'];
}

$current_trailer = "";
$res_curr_trailer = $conn->query("SELECT media_url FROM game_media WHERE game_id = $game_id AND media_type = 'trailer' LIMIT 1");
if ($res_curr_trailer && $row = $res_curr_trailer->fetch_assoc()) {
    $current_trailer = $row['media_url'];
}

$current_screenshots = [];
$res_ss = $conn->query("SELECT media_url FROM game_media WHERE game_id = $game_id AND media_type = 'screenshot' ORDER BY display_order ASC");
if ($res_ss) {
    while ($row = $res_ss->fetch_assoc()) {
        $current_screenshots[] = $row['media_url'];
    }
}

$res_genres = mysqli_query($conn, "SELECT genre_id, name FROM genres ORDER BY name ASC");

$current_monetization = 'free';
if ($game['price'] > 0) $current_monetization = 'paid';
elseif ($game['is_pwyw'] == 1) $current_monetization = 'pwyw';
?>

<main class="dashboard-main" style="padding-bottom: 80px;">
    
    <header class="dash-header" style="justify-content: flex-start; gap: 20px; margin-bottom: 40px;">
        <img src="<?php echo htmlspecialchars($game['cover_image_url'] ?: '../assets/img/placeholder-game.png'); ?>" 
             alt="Capa" style="width: 120px; height: 60px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
        <div class="dash-title">
            <h1 style="font-size: 28px; font-weight: 800; color: #fff;">Editar Loja</h1>
            <p>Projeto: <strong style="color: var(--primary);"><?php echo htmlspecialchars($game['title']); ?></strong></p>
        </div>
        <div style="flex: 1;"></div>
        <a href="manage_builds.php?id=<?php echo $game_id; ?>" class="btn-upload" style="background: rgba(34, 197, 94, 0.2); color: #22c55e;">📦 Gerenciar Ficheiros</a>
    </header>

    <div class="dev-form-card">
        
        <?php if (!empty($message)): ?>
            <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); color: #4ade80; padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px;">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            
            <h3 class="section-title">
                <span style="font-size: 20px;">📄</span> Ficha Técnica
            </h3>
            
            <div class="form-row">
                <div class="form-group form-col" style="flex: 2;">
                    <label>Título do Jogo <span style="color: var(--primary);">*</span></label>
                    <input type="text" name="title" required value="<?php echo htmlspecialchars($game['title']); ?>">
                </div>
                <div class="form-group form-col" style="flex: 1;">
                    <label>Expectativa (Fase) <span style="color: var(--primary);">*</span></label>
                    <select name="release_stage" required>
                        <option value="full_release" <?php echo ($game['release_stage'] == 'full_release') ? 'selected' : ''; ?>>Versão Final</option>
                        <option value="early_access" <?php echo ($game['release_stage'] == 'early_access') ? 'selected' : ''; ?>>Acesso Antecipado</option>
                        <option value="beta" <?php echo ($game['release_stage'] == 'beta') ? 'selected' : ''; ?>>Beta</option>
                        <option value="alpha" <?php echo ($game['release_stage'] == 'alpha') ? 'selected' : ''; ?>>Alpha</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group form-col">
                    <label>Gênero Principal <span style="color: var(--primary);">*</span></label>
                    <select name="genre_id" required>
                        <option value="">Selecione...</option>
                        <?php if($res_genres): while($g = mysqli_fetch_assoc($res_genres)): ?>
                            <option value="<?php echo $g['genre_id']; ?>" <?php echo ($current_genre_id == $g['genre_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g['name']); ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                
                <div class="form-group form-col">
                    <label>Link do Trailer (YouTube) <span style="font-weight: 400; opacity: 0.5;">(Opcional)</span></label>
                    <div class="input-with-prefix">
                        <span class="prefix-badge">▶</span>
                        <input type="url" name="trailer_url" value="<?php echo htmlspecialchars($current_trailer); ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Sinopse Curta <span style="color: var(--primary);">*</span></label>
                <input type="text" name="short_description" maxlength="150" required value="<?php echo htmlspecialchars($game['short_description']); ?>">
            </div>

            <div class="form-group">
                <label>Sobre o Jogo <span style="color: var(--primary);">*</span></label>
                <textarea name="description" required rows="5"><?php echo htmlspecialchars($game['description']); ?></textarea>
            </div>

            <h3 class="section-title">
                <span style="font-size: 20px;">🎨</span> Mídia do Jogo
            </h3>
            
            <div class="form-group" style="margin-bottom: 30px;">
                <label>Atualizar Capa Principal</label>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 12px;">Deixe este campo em branco para manter a capa atual.</p>
                <label class="file-drop-area" id="cover-drop" style="min-height: 120px;">
                    <span class="file-icon" style="font-size: 24px; opacity: 0.8;">🖼️</span>
                    <span class="file-msg" id="cover-msg" style="margin-top: 5px; font-size: 13px;">Clique para escolher uma nova capa</span>
                    <input type="file" name="cover_image" id="cover-input" accept=".jpg, .jpeg, .png, .webp">
                </label>
            </div>

            <div class="form-group">
                <label>Atualizar Screenshots da Loja</label>
                
                <?php if (count($current_screenshots) > 0): ?>
                    <div style="margin-bottom: 8px; font-size: 13px; color: #fff;">
                        O jogo possui <strong><?php echo count($current_screenshots); ?></strong> screenshot(s) ativa(s).
                    </div>
                <?php endif; ?>
                
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 12px;">Nota: O envio de novas imagens substituirá todas as suas screenshots atuais. Se quiser manter alguma, você deve enviá-la novamente junto com as novas.</p>
                
                <label class="file-drop-area" id="ss-drop" style="min-height: 120px;">
                    <span class="file-icon" style="font-size: 24px; opacity: 0.8;">📸</span>
                    <span class="file-msg" id="ss-msg" style="margin-top: 5px; font-size: 13px;">Clique para selecionar múltiplas imagens</span>
                    <input type="file" name="screenshots[]" id="ss-input" multiple accept=".jpg, .jpeg, .png, .webp">
                </label>
            </div>

            <h3 class="section-title">
                <span style="font-size: 20px;">💰</span> Preço & Monetização
            </h3>

            <div class="form-group">
                <div class="monetization-cards">
                    <label class="m-card">
                        <input type="radio" name="monetization" value="free" onclick="togglePriceFields('free')" <?php echo ($current_monetization == 'free') ? 'checked' : ''; ?>>
                        <span class="card-title">Totalmente Grátis</span>
                        <span class="card-desc">Download livre</span>
                    </label>
                    <label class="m-card">
                        <input type="radio" name="monetization" value="paid" onclick="togglePriceFields('paid')" <?php echo ($current_monetization == 'paid') ? 'checked' : ''; ?>>
                        <span class="card-title">Venda Direta</span>
                        <span class="card-desc">Preço fixo</span>
                    </label>
                    <label class="m-card">
                        <input type="radio" name="monetization" value="pwyw" onclick="togglePriceFields('pwyw')" <?php echo ($current_monetization == 'pwyw') ? 'checked' : ''; ?>>
                        <span class="card-title">Apoio (PWYW)</span>
                        <span class="card-desc">Pague o que puder</span>
                    </label>
                </div>

                <div id="field-paid" class="price-panel <?php echo ($current_monetization == 'paid') ? 'active' : ''; ?>">
                    <div class="form-row">
                        <div class="form-col" style="max-width: 250px;">
                            <label>Valor da Venda (R$)</label>
                            <input type="number" name="price" step="0.01" min="1.00" value="<?php echo $game['price'] > 0 ? htmlspecialchars($game['price']) : ''; ?>">
                        </div>
                    </div>
                </div>

                <div id="field-pwyw" class="price-panel <?php echo ($current_monetization == 'pwyw') ? 'active' : ''; ?>">
                    <div class="form-row">
                        <div class="form-col" style="max-width: 250px;">
                            <label>Mínimo para Doação (R$)</label>
                            <input type="number" name="min_price" step="0.01" min="0.00" value="<?php echo htmlspecialchars($game['min_price']); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit-dev">Guardar Alterações ➔</button>
            
        </form>
    </div>
</main>

<script>
    function togglePriceFields(type) {
        document.getElementById('field-paid').classList.remove('active');
        document.getElementById('field-pwyw').classList.remove('active');
        if (type === 'paid') document.getElementById('field-paid').classList.add('active');
        if (type === 'pwyw') document.getElementById('field-pwyw').classList.add('active');
    }

    const fileInput = document.getElementById('cover-input');
    const fileMsg = document.getElementById('cover-msg');
    if (fileInput) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                fileMsg.textContent = fileInput.files[0].name;
                fileMsg.style.color = 'var(--primary)';
            }
        });
    }

    const ssInput = document.getElementById('ss-input');
    const ssMsg = document.getElementById('ss-msg');
    if (ssInput) {
        ssInput.addEventListener('change', () => {
            if (ssInput.files.length > 0) {
                ssMsg.textContent = ssInput.files.length + " arquivo(s) selecionado(s)";
                ssMsg.style.color = 'var(--primary)';
            } else {
                ssMsg.textContent = "Clique para selecionar múltiplas imagens";
                ssMsg.style.color = '';
            }
        });
    }
</script>

</body>
</html>