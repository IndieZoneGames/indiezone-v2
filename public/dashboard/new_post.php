<?php
// public/dashboard/new_post.php
require_once("includes/dev_header.php");

$error = $_SESSION['post_error'] ?? '';
unset($_SESSION['post_error']);

$success = $_SESSION['post_success'] ?? '';
unset($_SESSION['post_success']);
?>

<main class="dashboard-main">
    <header class="dash-header">
        <div class="dash-title">
            <h1>Novo Devlog / Atualização 📝</h1>
            <p>Compartilhe novidades do seu estúdio com a comunidade.</p>
        </div>
        <div>
            <a href="dashboard.php" class="btn-dash-outline">← Voltar ao Painel</a>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="msg-subtle msg-error-subtle" style="max-width: 900px; margin: 0 auto 20px auto;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="msg-subtle msg-success-subtle" style="max-width: 900px; margin: 0 auto 20px auto;">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <section class="dev-form-card">
        <h2 class="section-title">Detalhes da Postagem</h2>

        <form action="../../src/backend/process_new_post.php" method="POST" enctype="multipart/form-data">
            
            <div class="form-group">
                <label for="post_title">Título da Postagem *</label>
                <input type="text" id="post_title" name="title" placeholder="Ex: Atualização v1.2 - Novos Chefões!" required maxlength="150">
            </div>

            <div class="form-group">
                <label for="post_content">Conteúdo *</label>
                <textarea id="post_content" name="content" placeholder="Escreva os detalhes da sua atualização aqui..." required rows="8"></textarea>
            </div>

            <div class="form-group">
                <label>Imagem de Destaque (Opcional)</label>
                <label class="file-drop-area">
                    <span class="file-icon">🖼️</span>
                    <span class="file-msg" id="file-name-display">Clique ou arraste uma imagem (JPG, PNG, WEBP)</span>
                    <input type="file" id="post_image" name="post_image" accept="image/jpeg, image/png, image/webp" onchange="previewImage(this)">
                </label>
                
                <div id="image-preview-container" style="display: none; margin-top: 16px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); background: #000;">
                    <img id="image-preview" src="" alt="Preview" style="width: 100%; max-height: 400px; object-fit: contain; display: block;">
                </div>
            </div>

            <button type="submit" class="btn-submit-dev">Publicar na Comunidade</button>

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
        
        // Atualiza o texto
        display.innerText = "Arquivo selecionado: " + file.name;
        display.style.color = "var(--primary)";
        
        // Mágica do FileReader para mostrar a imagem na hora
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
        }
        reader.readAsDataURL(file);
    } else {
        display.innerText = "Clique ou arraste uma imagem (JPG, PNG, WEBP)";
        display.style.color = "";
        previewContainer.style.display = 'none';
        previewImage.src = "";
    }
}
</script>
</body>
</html>