<?php
// [ARQUITETURA] Separação de Responsabilidades (Backend). Este script opera de forma invisível, sem renderizar interfaces. Sua localização fora das pastas públicas blinda a lógica de negócio contra acessos indevidos e varreduras diretas no servidor.
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

// Proteção: só entra aqui se passou pelo login corretamente e recebeu a flag
// [SEGURANÇA] Controle de Estado Transitório. O sistema não aceita que essa URL seja acessada diretamente (GET). Ele exige uma requisição POST e a existência da chave 'reactivation_user_id', garantindo que o usuário já provou sua identidade na tela de login antes de chegar aqui.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['reactivation_user_id'])) {
    
    $target_id = $_SESSION['reactivation_user_id'];

    // Religando a conta: is_active = 1 e limpamos a data de exclusão
    // [AUDITORIA] Reversão de Soft Delete. É aqui que o sistema prova sua consistência de dados. A conta volta à vida apenas limpando a flag 'deleted_at' e ativando o status. O 'user_id' permanece idêntico, preservando intacto todo o rastro de compras e histórico na biblioteca.
    // [SEGURANÇA] O uso de Prepared Statements assegura que a variável em memória transite para o banco de forma protegida contra injeções SQL.
    $stmt = $conn->prepare("UPDATE users SET is_active = 1, deleted_at = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $target_id);
    
    if ($stmt->execute()) {
        // Reativação concluída! Busca os dados para criar a sessão de login
        // [LÓGICA] Renovação de Dados. Após reativar a conta, o sistema faz uma nova consulta limpa para garantir que a sessão receberá os dados atualizados direto do banco (Single Source of Truth).
        $stmt_login = $conn->prepare("SELECT user_id, display_name, username, role FROM users WHERE user_id = ?");
        $stmt_login->bind_param("i", $target_id);
        $stmt_login->execute();
        $user = $stmt_login->get_result()->fetch_assoc();

        // Limpa a flag temporária e constrói a sessão oficial
        // [AUDITORIA] Elevação de Privilégio. A chave temporária ('reactivation_user_id') é destruída para evitar reusos (estado limpo) e os tokens definitivos de acesso são gravados, concedendo autoridade completa ao usuário sobre seu próprio painel.
        unset($_SESSION['reactivation_user_id']);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        // Redireciona para a vitrine da loja
        header("Location: ../../public/pages/store.php");
        exit();
    } else {
        // Falha no banco de dados
        // [LÓGICA] Falha Segura (Fail-Safe). Se o banco oscilar e o UPDATE falhar, a credencial temporária é apagada da memória para evitar que a sessão fique "suja" ou num estado zumbi.
        unset($_SESSION['reactivation_user_id']);
        $_SESSION['login_error'] = "Erro ao tentar reativar a conta. Contate o suporte.";
        header("Location: ../../public/auth/login.php");
        exit();
    }
} else {
    // Acesso negado direto pela URL
    // [ARQUITETURA] Interceptação de acessos diretos ou requisições GET inválidas redirecionando o fluxo de volta à porta de entrada oficial da aplicação.
    header("Location: ../../public/auth/login.php");
    exit();
}
?>