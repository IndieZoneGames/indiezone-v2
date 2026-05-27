<?php
session_start();
// Caminho corrigido para o padrão do projeto
require_once("../../core/config.php");
/** @var mysqli $conn */

$message = "";

if (isset($_POST['login'])) {

  $email = trim($_POST['email']);
  $password = $_POST['password'];

  // 1. USO DE PREPARE PARA EVITAR SQL INJECTION
  $stmt = $conn->prepare("SELECT user_id, display_name, password_hash FROM users WHERE email = ? AND (role = 'dev' OR role = 'admin') LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();

    // 2. Verifica a password (usando a coluna correta do teu DB: password_hash)
    if (password_verify($password, $user['password_hash'])) {
      $_SESSION['user_id'] = $user['user_id'];
      // Define a role para garantir que o header reconhece
      $_SESSION['role'] = 'dev'; 

      header("Location: dashboard.php");
      exit();
    } else {
      $message = "Senha incorreta.";
    }
  } else {
    $message = "Conta de desenvolvedor não encontrada.";
  }
  $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Desenvolvedor - IndieZone</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/base.css">
  <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body data-theme="dark">

  <div class="auth-screen">
    <div class="auth-box">
      <div class="brand">
        <h1>Área Dev</h1>
        <p>Publique e gerencie seus jogos</p>
      </div>

      <?php if ($message != ""): ?>
        <div class="msg-subtle msg-error-subtle">
            <?php echo htmlspecialchars($message); ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="input-group">
          <label>Email do Estúdio</label>
          <input type="email" name="email" placeholder="contato@estudio.com" class="input-base" required>
        </div>
        
        <div class="input-group">
          <label>Senha</label>
          <input type="password" name="password" placeholder="••••••••" class="input-base" required>
        </div>
        
        <button type="submit" name="login" class="btn" style="background: #8b5cf6; color: #fff;">Entrar no Dashboard</button>
      </form>

      <div class="auth-footer">
        <p>Não é desenvolvedor? <a href="../auth/login.php">Login de Jogador</a></p>
      </div>
    </div>
  </div>

</body>
</html>