<?php
// src/backend/process_new_post.php
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php"); // [AUDITORIA] Inclusão do Logger
/** @var mysqli $conn */

// 1. Barreira de Segurança: Verifica se é um POST válido de um Dev
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_SESSION['user_id']) || $_SESSION['role'] !== 'dev') {
    header("Location: ../../public/auth/login.php");
    exit();
}

$logger = new SystemLogger($conn);
$developer_id = $_SESSION['user_id'];
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');

// 2. Validação Básica
if (empty($title) || empty($content)) {
    $_SESSION['post_error'] = "Título e conteúdo são obrigatórios.";
    header("Location: ../../public/dashboard/new_post.php");
    exit();
}

$image_url = null;

// 3. Lógica de Upload de Imagem (Segurança Crítica)
if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] === UPLOAD_ERR_OK) {
    
    $file_tmp = $_FILES['post_image']['tmp_name'];
    $file_name = $_FILES['post_image']['name'];
    $file_size = $_FILES['post_image']['size'];
    
    // Extrai a extensão e valida
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
    
    // Valida o MIME type real (impede que um .php seja renomeado para .jpg)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_tmp);
    finfo_close($finfo);
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

    if (!in_array($file_ext, $allowed_exts) || !in_array($mime_type, $allowed_mimes)) {
        // [AUDITORIA] Registra a tentativa de enviar arquivo malicioso ou incorreto
        $logger->log('DEV_CREATE_POST_INVALID_IMAGE', 'WARNING', ['user_id' => $developer_id, 'new_data' => ['attempted_mime' => $mime_type, 'attempted_ext' => $file_ext]]); 
        $_SESSION['post_error'] = "Formato de imagem inválido. Use JPG, PNG ou WEBP.";
        header("Location: ../../public/dashboard/new_post.php");
        exit();
    }

    if ($file_size > 2 * 1024 * 1024) { // Limite de 2MB
        $_SESSION['post_error'] = "A imagem deve ter no máximo 2MB.";
        header("Location: ../../public/dashboard/new_post.php");
        exit();
    }

    // Gera um nome único para evitar colisão e travessia de diretório
    $new_filename = 'post_' . uniqid() . '_' . time() . '.' . $file_ext;
    
    // Define o diretório de destino (relativo a este script)
    $upload_dir = '../../public/uploads/posts/';
    
    // Cria a pasta automaticamente se não existir
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $destination = $upload_dir . $new_filename;

    if (move_uploaded_file($file_tmp, $destination)) {
        // Salva o caminho relativo público no banco para fácil renderização
        $image_url = '../uploads/posts/' . $new_filename;
    } else {
        $logger->log('DEV_CREATE_POST_UPLOAD_FAIL', 'CRITICAL', ['user_id' => $developer_id]); // LOG INSERIDO
        $_SESSION['post_error'] = "Falha ao salvar a imagem no servidor.";
        header("Location: ../../public/dashboard/new_post.php");
        exit();
    }
}

// 4. Inserção no Banco de Dados (Prepared Statement)
$stmt = $conn->prepare("INSERT INTO community_posts (developer_id, title, content, image_url) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $developer_id, $title, $content, $image_url);

if ($stmt->execute()) {
    // [AUDITORIA] Usa o $conn->insert_id para pegar o ID gerado pelo auto_increment e guardar no log
    $post_id = $conn->insert_id;
    $logger->log('DEV_CREATE_POST', 'INFO', [
        'user_id' => $developer_id,
        'entity_table' => 'community_posts',
        'entity_id' => $post_id,
        'new_data' => ['title' => $title] // Salvamos o título para facilitar a busca rápida na tela de auditoria
    ]);

    $_SESSION['post_success'] = "Postagem publicada com sucesso na comunidade!";
    // Redireciona para um formulário limpo para evitar reenvio (F5)
    header("Location: ../../public/dashboard/new_post.php");
    exit();
} else {
    // [AUDITORIA] Erro na inserção no banco
    $logger->log('DEV_CREATE_POST_DB_ERROR', 'CRITICAL', ['user_id' => $developer_id, 'new_data' => ['error' => $conn->error]]);
    
    $_SESSION['post_error'] = "Erro interno no banco de dados. Tente novamente mais tarde.";
    header("Location: ../../public/dashboard/new_post.php");
    exit();
}
?>