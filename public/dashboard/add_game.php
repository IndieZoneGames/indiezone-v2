<?php
// public/dashboard/add_game.php

// [ARQUITETURA] Utiliza o cabeçalho padronizado do dashboard de desenvolvedores.
require_once("includes/dev_header.php");
require_once("../../src/backend/SystemLogger.php"); // [AUDITORIA] Inclusão do Logger
/** @var mysqli $conn */
/** @var int $user_id */ // Definido no dev_header.php

$logger = new SystemLogger($conn);
$error = '';

// [LÓGICA] Função para criar slugs amigáveis para SEO e URLs de jogos.
function createSlug($string) {
    $string = iconv('UTF-8', 'ASCII//TRANSLIT', $string);
    $string = preg_replace('/[^A-Za-z0-9-]+/', '-', $string);
    return strtolower(trim($string, '-'));
}

// [SEGURANÇA] Validação estrita de tipo MIME utilizando a extensão 'finfo' do PHP.
function isImageSafe($tmp_name) {
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp_name);
    finfo_close($finfo);
    return in_array($mime, $allowed_mime_types);
}

// Busca os géneros disponíveis para popular o formulário
$res_genres = mysqli_query($conn, "SELECT genre_id, name FROM genres ORDER BY name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SEGURANÇA] Higienização de entrada e tipagem forçada.
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

    // [CORREÇÃO CRÍTICA]: Validação no Back-end (Não confiar no HTML para prevenir valores negativos)
    if ($monetization === 'paid') {
        $price = floatval($_POST['price']);
        if ($price < 1.00) throw new Exception("Preço de venda inválido. O valor mínimo é R$ 1,00.");
    } elseif ($monetization === 'pwyw') {
        $is_pwyw = 1;
        $min_price = floatval($_POST['min_price']);
        if ($min_price < 0) throw new Exception("O preço mínimo de doação não pode ser negativo.");
    }

    $slug = createSlug($title);
    
    // [AUDITORIA] Inicia explicitamente uma Transação de Banco de Dados.
    $conn->begin_transaction();

    try {
        $cover_image_url = '';
        
        // 1. Processamento da Capa Principal
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            if (!isImageSafe($_FILES['cover_image']['tmp_name'])) {
                // [AUDITORIA] Registra a tentativa de upload inválido/malicioso
                $logger->log('DEV_CREATE_GAME_INVALID_IMG', 'WARNING', ['user_id' => $user_id]);
                throw new Exception("A imagem de capa não é num formato válido (apenas JPG, PNG, WEBP).");
            }
            $upload_dir = '../../public/uploads/covers/';
            
            // [CORREÇÃO CRÍTICA]: Permissão alterada de 0777 para 0755 (Segurança contra execução remota)
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            // [LÓGICA] Geração de nome único usando 'uniqid()'.
            $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
            $new_filename = uniqid('cover_') . '.' . $ext;
            
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_dir . $new_filename)) {
                $cover_image_url = '../uploads/covers/' . $new_filename;
            } else {
                throw new Exception("Falha ao guardar a imagem de capa.");
            }
        }

        // 2. Inserção do Jogo
        $stmt = $conn->prepare("INSERT INTO games (developer_id, title, slug, short_description, description, price, min_price, is_pwyw, release_stage, cover_image_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
        $stmt->bind_param("issssdiiss", $user_id, $title, $slug, $short_description, $description, $price, $min_price, $is_pwyw, $release_stage, $cover_image_url);

        if (!$stmt->execute()) {
            throw new Exception("Erro ao cadastrar. O título já existe ou os dados são inválidos.");
        }
        
        $new_game_id = $conn->insert_id;

        // 3. Inserção do Género na tabela de junção 'game_genres'
        if ($genre_id > 0) {
            $stmt_genre = $conn->prepare("INSERT INTO game_genres (game_id, genre_id) VALUES (?, ?)");
            $stmt_genre->bind_param("ii", $new_game_id, $genre_id);
            if (!$stmt_genre->execute()) {
                 throw new Exception("Erro ao vincular o género ao jogo.");
            }
        }

        // 4. Inserção do Trailer na tabela 'game_media' 
        if (!empty($trailer_url)) {
            $stmt_trailer = $conn->prepare("INSERT INTO game_media (game_id, media_type, media_url, display_order) VALUES (?, 'trailer', ?, 0)");
            $stmt_trailer->bind_param("is", $new_game_id, $trailer_url);
            if (!$stmt_trailer->execute()) {
                throw new Exception("Erro ao salvar o link do trailer.");
            }
        }

        // 5. Processamento da Galeria (Screenshots)
        if (!empty($_FILES['screenshots']['name'][0])) {
            $screen_dir = '../../public/uploads/screenshots/';
            
            // [CORREÇÃO CRÍTICA]: Permissão alterada de 0777 para 0755
            if (!is_dir($screen_dir)) mkdir($screen_dir, 0755, true);

            foreach ($_FILES['screenshots']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['screenshots']['error'][$key] === UPLOAD_ERR_OK) {
                    if (!isImageSafe($tmp_name)) continue;

                    $s_ext = strtolower(pathinfo($_FILES['screenshots']['name'][$key], PATHINFO_EXTENSION));
                    $s_filename = uniqid('screen_') . '_' . $key . '.' . $s_ext;
                    
                    if (move_uploaded_file($tmp_name, $screen_dir . $s_filename)) {
                        $media_url = '../uploads/screenshots/' . $s_filename;
                        $display_order = $key + 1; 
                        
                        $stmt_m = $conn->prepare("INSERT INTO game_media (game_id, media_type, media_url, display_order) VALUES (?, 'screenshot', ?, ?)");
                        $stmt_m->bind_param("isi", $new_game_id, $media_url, $display_order);
                        $stmt_m->execute();
                    }
                }
            }
        }

        // [AUDITORIA] Confirmação da Transação.
        $conn->commit();
        
        $logger->log('DEV_CREATE_GAME', 'INFO', [
            'user_id' => $user_id,
            'entity_table' => 'games',
            'entity_id' => $new_game_id,
            'new_data' => [
                'title' => $title, 
                'price' => $price,
                'release_stage' => $release_stage
            ]
        ]);

        header("Location: manage_builds.php?id=" . $new_game_id . "&status=success");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        
        $logger->log('DEV_CREATE_GAME_ERROR', 'CRITICAL', [
            'user_id' => $user_id,
            'new_data' => ['error_message' => $e->getMessage()]
        ]);
        
        $error = $e->getMessage();
    }
}
?>

<main class="dashboard-main" style="padding-bottom: 80px;">
    
    <header class="dash-header" style="justify-content: center; text-align: center; margin-bottom: 50px;">
        <div class="dash-title">
            <h1 style="font-size: 36px; font-weight: 800; color: #fff;">Criar Novo Projeto</h1>
            <p>Configure a vitrine do seu jogo antes de subir os arquivos executáveis.</p>
        </div>
    </header>

    <div class="dev-form-card">
        
        <?php if (!empty($error)): ?>
            <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px;">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="add_game.php" method="POST" enctype="multipart/form-data">
            
            <h3 class="section-title">
                <span style="font-size: 20px;">📄</span> Ficha Técnica
            </h3>
            
            <div class="form-row">
                <div class="form-group form-col" style="flex: 2;">
                    <label>Título do Jogo <span style="color: var(--primary);">*</span></label>
                    <input type="text" name="title" required placeholder="Nome do seu projeto épico..." value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                </div>
                <div class="form-group form-col" style="flex: 1;">
                    <label>Expectativa (Fase) <span style="color: var(--primary);">*</span></label>
                    <select name="release_stage" required>
                        <option value="full_release" <?php echo (isset($_POST['release_stage']) && $_POST['release_stage'] == 'full_release') ? 'selected' : ''; ?>>Versão Final</option>
                        <option value="early_access" <?php echo (isset($_POST['release_stage']) && $_POST['release_stage'] == 'early_access') ? 'selected' : ''; ?>>Acesso Antecipado</option>
                        <option value="beta" <?php echo (isset($_POST['release_stage']) && $_POST['release_stage'] == 'beta') ? 'selected' : ''; ?>>Beta</option>
                        <option value="alpha" <?php echo (isset($_POST['release_stage']) && $_POST['release_stage'] == 'alpha') ? 'selected' : ''; ?>>Alpha</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group form-col">
                    <label>Gênero Principal <span style="color: var(--primary);">*</span></label>
                    <select name="genre_id" required>
                        <option value="">Selecione...</option>
                        <?php if($res_genres): while($g = mysqli_fetch_assoc($res_genres)): ?>
                            <option value="<?php echo $g['genre_id']; ?>" <?php echo (isset($_POST['genre_id']) && $_POST['genre_id'] == $g['genre_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g['name']); ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                
                <div class="form-group form-col">
                    <label>Link do Trailer (YouTube) <span style="font-weight: 400; opacity: 0.5;">(Opcional)</span></label>
                    <div class="input-with-prefix">
                        <span class="prefix-badge">▶</span>
                        <input type="url" name="trailer_url" placeholder="https://youtube.com/watch?v=..." value="<?php echo isset($_POST['trailer_url']) ? htmlspecialchars($_POST['trailer_url']) : ''; ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Sinopse Curta <span style="color: var(--primary);">*</span> <span style="font-weight: 400; opacity: 0.5; font-size: 12px; margin-left: 5px;">Máx. 150 caracteres</span></label>
                <input type="text" name="short_description" maxlength="150" required placeholder="Resumo rápido para os cards e buscas da loja..." value="<?php echo isset($_POST['short_description']) ? htmlspecialchars($_POST['short_description']) : ''; ?>">
            </div>

            <div class="form-group">
                <label>Sobre o Jogo <span style="color: var(--primary);">*</span> <span style="font-weight: 400; opacity: 0.5; font-size: 12px; margin-left: 5px;">Suporta formatação básica</span></label>
                <textarea name="description" required rows="5" placeholder="Explique a história, as mecânicas principais e o que torna seu jogo único na IndieZone..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            </div>

            <h3 class="section-title">
                <span style="font-size: 20px;">🎨</span> Materiais de Divulgação
            </h3>

            <div class="form-row">
                <div class="form-col">
                    <label class="form-label" style="font-weight: 600; margin-bottom: 8px; display: block;">Capa Principal (Banner) <span style="color: var(--primary);">*</span></label>
                    <label class="file-drop-area" id="cover-drop">
                        <span class="file-icon" style="font-size: 28px; opacity: 0.8;">🖼️</span>
                        <span class="file-msg" id="cover-msg" style="margin-top: 10px;">Clique para anexar a capa</span>
                        <!-- [CORREÇÃO UX]: Adicionado onchange para preview da imagem -->
                        <input type="file" name="cover_image" id="cover-input" accept=".jpg, .jpeg, .png, .webp" required onchange="previewCoverImage(this)">
                    </label>
                    
                    <!-- [CORREÇÃO UX]: Container para o preview visual da imagem -->
                    <div id="cover-preview-container" style="display: none; margin-top: 16px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); background: #000;">
                        <img id="cover-preview" src="" alt="Preview da Capa" style="width: 100%; max-height: 200px; object-fit: cover; display: block;">
                    </div>
                </div>
                
                <div class="form-col">
                    <label class="form-label" style="font-weight: 600; margin-bottom: 8px; display: block;">Galeria (Screenshots) <span style="color: var(--primary);">*</span></label>
                    <label class="file-drop-area">
                        <span class="file-icon" style="font-size: 28px; opacity: 0.8;">📸</span>
                        <span class="file-msg" id="screens-msg" style="margin-top: 10px;">Subir múltiplos arquivos</span>
                        <input type="file" name="screenshots[]" id="screens-input" multiple accept=".jpg, .jpeg, .png, .webp" required>
                    </label>
                </div>
            </div>

            <h3 class="section-title">
                <span style="font-size: 20px;">💰</span> Preço & Monetização
            </h3>

            <div class="form-group">
                <div class="monetization-cards">
                    <label class="m-card">
                        <input type="radio" name="monetization" value="free" checked onclick="togglePriceFields('free')">
                        <span class="card-title">Totalmente Grátis</span>
                        <span class="card-desc">Download livre</span>
                    </label>
                    <label class="m-card">
                        <input type="radio" name="monetization" value="paid" onclick="togglePriceFields('paid')" <?php echo (isset($_POST['monetization']) && $_POST['monetization'] == 'paid') ? 'checked' : ''; ?>>
                        <span class="card-title">Venda Direta</span>
                        <span class="card-desc">Preço fixo</span>
                    </label>
                    <label class="m-card">
                        <input type="radio" name="monetization" value="pwyw" onclick="togglePriceFields('pwyw')" <?php echo (isset($_POST['monetization']) && $_POST['monetization'] == 'pwyw') ? 'checked' : ''; ?>>
                        <span class="card-title">Apoio (PWYW)</span>
                        <span class="card-desc">Pague o que puder</span>
                    </label>
                </div>

                <div id="field-paid" class="price-panel <?php echo (isset($_POST['monetization']) && $_POST['monetization'] == 'paid') ? 'active' : ''; ?>">
                    <div class="form-row">
                        <div class="form-col" style="max-width: 250px;">
                            <label>Valor da Venda (R$)</label>
                            <input type="number" name="price" step="0.01" min="1.00" placeholder="0.00" value="<?php echo isset($_POST['price']) ? htmlspecialchars($_POST['price']) : ''; ?>">
                        </div>
                        <div class="form-col" style="display: flex; align-items: center; color: var(--text-muted); font-size: 13px;">
                            <p>O jogador deve pagar este valor exato para adquirir o jogo na loja.</p>
                        </div>
                    </div>
                </div>

                <div id="field-pwyw" class="price-panel <?php echo (isset($_POST['monetization']) && $_POST['monetization'] == 'pwyw') ? 'active' : ''; ?>">
                    <div class="form-row">
                        <div class="form-col" style="max-width: 250px;">
                            <label>Mínimo para Doação (R$)</label>
                            <input type="number" name="min_price" step="0.01" min="0.00" placeholder="0.00" value="<?php echo isset($_POST['min_price']) ? htmlspecialchars($_POST['min_price']) : ''; ?>">
                        </div>
                        <div class="form-col" style="display: flex; align-items: center; color: var(--text-muted); font-size: 13px;">
                            <p>Defina 0.00 para permitir downloads gratuitos com opção de o jogador deixar uma gorjeta.</p>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit-dev">Salvar Dados e Configurar Ficheiros ➔</button>
            
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

    // [CORREÇÃO UX]: Função para exibir o preview visual da capa instantaneamente
    function previewCoverImage(input) {
        const display = document.getElementById('cover-msg');
        const previewContainer = document.getElementById('cover-preview-container');
        const previewImage = document.getElementById('cover-preview');

        if (input.files && input.files[0]) {
            const file = input.files[0];
            display.innerText = "Capa: " + file.name;
            display.style.color = "var(--primary)";
            
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result;
                previewContainer.style.display = 'block';
            }
            reader.readAsDataURL(file);
        } else {
            display.innerText = "Clique para anexar a capa";
            display.style.color = "";
            previewContainer.style.display = 'none';
            previewImage.src = "";
        }
    }

    const setupFileFeedback = (inputId, msgId) => {
        const input = document.getElementById(inputId);
        const msg = document.getElementById(msgId);
        input.addEventListener('change', () => {
            const count = input.files.length;
            msg.textContent = count > 1 ? `${count} arquivos selecionados` : (input.files[0] ? input.files[0].name : 'Nenhum ficheiro');
            msg.style.color = 'var(--primary)';
        });
    };

    setupFileFeedback('screens-input', 'screens-msg');
</script>

</body>
</html>