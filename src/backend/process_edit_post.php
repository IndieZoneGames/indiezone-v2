<?php
// src/backend/process_edit_post.php
session_start();
require_once("../../core/config.php");
require_once(__DIR__ . "/SystemLogger.php"); // [AUDITORIA] Inclusão do Logger
/** @var mysqli $conn */

// Verifica se o usuário é um desenvolvedor logado
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'developer') {
    header("Location: ../../public/auth/login.php");
    exit();
}

$logger = new SystemLogger($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_id = intval($_POST['post_id'] ?? 0);
    $title = trim(mysqli_real_escape_string($conn, $_POST['title'] ?? ''));
    $content = trim(mysqli_real_escape_string($conn, $_POST['content'] ?? ''));
    $dev_id = $_SESSION['user_id'];

    if ($post_id <= 0 || empty($title) || empty($content)) {
        $_SESSION['post_error'] = "Preencha todos os campos obrigatórios.";
        header("Location: ../../public/dashboard/edit_post.php?id=" . $post_id);
        exit();
    }

    // 1. Verifica se a postagem pertence a este dev
    $stmt_check = $conn->prepare("SELECT image_url, title FROM community_posts WHERE post_id = ? AND developer_id = ?");
    $stmt_check->bind_param("ii", $post_id, $dev_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();

    if ($res_check->num_rows === 0) {
        // [AUDITORIA] Registra tentativa de escalar privilégios (IDOR) tentando editar post de outro dev
        $logger->log('DEV_EDIT_POST_UNAUTHORIZED', 'WARNING', ['user_id' => $dev_id, 'new_data' => ['attempted_post_id' => $post_id]]);
        $_SESSION['post_error'] = "Você não tem permissão para editar esta postagem.";
        header("Location: ../../public/dashboard/dashboard.php");
        exit();
    }
    
    $post_data = $res_check->fetch_assoc();
    $current_image = $post_data['image_url'];
    $old_title = $post_data['title'];
    $new_image_url = $current_image; // Mantém a antiga por padrão

    // 2. Processa nova imagem se houver upload
    if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $file_tmp = $_FILES['post_image']['tmp_name'];
        $file_type = mime_content_type($file_tmp);

        if (in_array($file_type, $allowed_types)) {
            $upload_dir = "../../public/uploads/posts/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $file_ext = pathinfo($_FILES['post_image']['name'], PATHINFO_EXTENSION);
            $new_filename = "post_" . $dev_id . "_" . time() . "." . $file_ext;
            $destination = $upload_dir . $new_filename;

            if (move_uploaded_file($file_tmp, $destination)) {
                $new_image_url = "../uploads/posts/" . $new_filename;
                // Opcional: Apagar a imagem antiga do servidor para economizar espaço
                if (!empty($current_image) && file_exists("../../public/" . str_replace("../", "", $current_image))) {
                    unlink("../../public/" . str_replace("../", "", $current_image));
                }
            }
        } else {
            // [AUDITORIA] Registra tentativa de enviar arquivo com formato não permitido na edição
            $logger->log('DEV_EDIT_POST_INVALID_IMG', 'WARNING', ['user_id' => $dev_id, 'entity_id' => $post_id]);
        }
    }

    // 3. Atualiza no Banco de Dados
    $stmt_update = $conn->prepare("UPDATE community_posts SET title = ?, content = ?, image_url = ? WHERE post_id = ? AND developer_id = ?");
    $stmt_update->bind_param("sssii", $title, $content, $new_image_url, $post_id, $dev_id);

    if ($stmt_update->execute()) {
        // [AUDITORIA] Grava o sucesso da edição, armazenando como era o título antes para o histórico
        $logger->log('DEV_EDIT_POST', 'INFO', [
            'user_id' => $dev_id,
            'entity_table' => 'community_posts',
            'entity_id' => $post_id,
            'old_data' => ['title' => $old_title],
            'new_data' => ['title' => $title]
        ]);

        $_SESSION['post_success'] = "Postagem atualizada com sucesso!";
        // Redireciona de volta para a comunidade para ele ver como ficou
        header("Location: ../../public/pages/community.php?post_id=" . $post_id);
    } else {
        // [AUDITORIA] Falha no banco de dados
        $logger->log('DEV_EDIT_POST_ERROR', 'CRITICAL', ['user_id' => $dev_id, 'entity_id' => $post_id, 'new_data' => ['error' => $conn->error]]);
        $_SESSION['post_error'] = "Erro ao atualizar no banco de dados.";
        header("Location: ../../public/dashboard/edit_post.php?id=" . $post_id);
    }
    exit();
}
?>