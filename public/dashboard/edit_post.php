<?php
// public/dashboard/edit_post.php
require_once("includes/dev_header.php"); 
require_once("../../core/config.php");
/** @var mysqli $conn */

$post_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$dev_id = $_SESSION['user_id'];

if ($post_id <= 0) {
    echo "Post inválido.";
    exit();
}

$stmt = $conn->prepare("SELECT * FROM community_posts WHERE post_id = ? AND developer_id = ?");
$stmt->bind_param("ii", $post_id, $dev_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo "<div style='padding: 40px; text-align: center; color: white;'><h2>Acesso Negado</h2><p>Esta postagem não existe ou você não é o autor dela.</p><a href='dashboard.php' style='color: #22c55e;'>Voltar ao painel</a></div>";
    exit();
}

$post = $res->fetch_assoc();

$error = $_SESSION['post_error'] ?? '';
unset($_SESSION['post_error']);
$success = $_SESSION['post_success'] ?? '';
unset($_SESSION['post_success']);
?>

<main class="dashboard-main">
    <header class="dash-header">
        <div class="dash-title">
            <h1>Editar Postagem ✏️</h1>
            <p>Corrija informações ou atualize a imagem de destaque.</p>
        </div>
        <div>
            <a href="../pages/community.php" class="btn-dash-outline">← Voltar à Comunidade</a>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="msg-subtle msg-error-subtle" style="max-width: 900px; margin: 0 auto 20px auto; color: #ef4444; background: rgba(239, 68, 68, 0.1); padding: 12px; border-radius: 8px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <section class="dev-form-card" style="max-width: 900px; margin: 0 auto; background: rgba(20, 25, 22, 0.6); padding: 30px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.08); backdrop-filter: blur(10px);">
        <form action="../../src/backend/process_edit_post.php" method="POST" enctype="multipart/form-data">
            
            <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">

            <div class="form-group" style="margin-bottom: 20px;">
                <label for="post_title" style="display: block; color: #94a3b8; margin-bottom: 8px;">Título da Postagem *</label>
                <input type="text" id="post_title" name="title" value="<?php echo htmlspecialchars($post['title']); ?>" required maxlength="150" style="width: 100%; padding: 12px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: #f8fafc; font-size: 15px; transition: border-color 0.2s;">
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label for="post_content" style="display: block; color: #94a3b8; margin-bottom: 8px;">Conteúdo *</label>
                <textarea id="post_content" name="content" required rows="8" style="width: 100%; padding: 12px; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: #f8fafc; font-size: 15px; resize: vertical; transition: border-color 0.2s;"><?php echo htmlspecialchars($post['content']); ?></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 30px;">
                <label style="display: block; color: #94a3b8; margin-bottom: 8px;">Imagem de Destaque (Deixe em branco para manter a atual)</label>
                <label class="file-drop-area" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 30px; border: 1px dashed rgba(255,255,255,0.2); border-radius: 8px; cursor: pointer; background: rgba(0,0,0,0.2); margin-bottom: 16px;">
                    <span id="file-name-display" style="color: #64748b; font-size: 14px;">Clique para escolher uma nova imagem...</span>
                    <input type="file" id="post_image" name="post_image" accept="image/jpeg, image/png, image/webp" onchange="previewImage(this)" style="display: none;">
                </label>
                
                <div id="image-preview-container" style="border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); background: #000; <?php echo empty($post['image_url']) ? 'display: none;' : ''; ?>">
                    <img id="image-preview" src="<?php echo htmlspecialchars($post['image_url']); ?>" alt="Preview" style="width: 100%; max-height: 400px; object-fit: contain; display: block;">
                </div>
            </div>

            <button type="submit" class="btn-submit-dev" style="background: #22c55e; color: #000; border: none; padding: 14px 24px; border-radius: 8px; font-weight: 700; font-size: 15px; cursor: pointer; width: 100%; transition: background 0.2s;">Salvar Alterações</button>

        </form>
    </section>
</main>

<script>
function previewImage(input) {
    const display = document.getElementById('file-name-display');
    const previewContainer = document.getElementById('image-preview-container');
    const previewImage = document.getElementById('image-preview');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        display.innerText = "Novo arquivo: " + file.name;
        display.style.color = "#22c55e";
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
        }
        reader.readAsDataURL(file);
    }
}
</script>
</body>
</html>