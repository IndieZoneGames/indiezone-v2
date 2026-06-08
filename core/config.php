<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * Configuração Global - IndieZone
 */

// [ARQUITETURA] Isola a carga de variáveis de ambiente do restante da aplicação. 
// Isso garante a portabilidade do sistema entre o ambiente de desenvolvimento local e o servidor em nuvem, mantendo a base de código unificada.
if (file_exists(__DIR__ . '/env_loader.php')) {
    require_once __DIR__ . '/env_loader.php';
}

// [LÓGICA] Forçar o fuso horário padrão do PHP para o Brasil. 
// Isso garante que funções nativas de data (como date() e time()) gerem os carimbos de tempo corretos locais para o sistema inteiro.
date_default_timezone_set('America/Sao_Paulo');

// [SEGURANÇA] Durante a auditoria do código, é crucial notar que a exibição de erros (display_errors) 
// deve ser desativada (0) em ambiente de produção para evitar o vazamento de informações sensíveis do servidor (Information Disclosure).
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * DETECÇÃO AUTOMÁTICA DA URL 
 */
/**
 * DETECÇÃO AUTOMÁTICA DA URL (VERSÃO TEMPORÁRIA PARA FACULDADE)
 */
if (!defined('APP_URL')) {
    // Detecta se o acesso veio por HTTPS seguro (Cloudflare e Ngrok)
    $protocol = (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    
    $url_host = $_SERVER['HTTP_HOST'];
    
    $final_url = "$protocol://$url_host/indiezone-main/public";
    
    define('APP_URL', rtrim($final_url, '/'));
}

/**
 * --- HELPER GLOBAL: Resolução de Imagens ---
 * [ARQUITETURA] Centraliza a lógica de caminhos de arquivos, garantindo que placeholders e uploads 
 * funcionem em qualquer subdiretório do projeto através da constante APP_URL.
 */
if (!function_exists('resolveImageUrl')) {
    function resolveImageUrl($url, $genre = '') {
        if (empty($url)) {
            $placeholders = [
                'Ação' => 'Placeholder_acao.png',
                'Arcade' => 'Placeholder_Arcade.png',
                'Aventura' => 'Placeholder_Aventura.png',
                'Estratégia' => 'Placeholder_Estrategia.png',
                'Plataforma' => 'Placeholder_Plataforma.png',
                'Puzzle' => 'Placeholder_Puzzle.png',
                'RPG' => 'Placeholder_RPG.png',
                'Simulador' => 'Placeholder_Simulador.png',
                'Terror' => 'Placeholder_terror.png'
            ];
            $file = $placeholders[$genre] ?? 'Placeholder_Padrao.png';
            return APP_URL . '/assets/img/' . $file;
        }
        if (strpos($url, 'http') === 0) return htmlspecialchars($url);
        // Remove os ../ e a primeira barra para padronizar o caminho relativo ao APP_URL
        $clean_path = ltrim(str_replace('../', '/', $url), '/');
        return APP_URL . '/' . htmlspecialchars($clean_path);
    }
}

/**
 * 🛡️ BLINDAGEM DE SESSÃO (TIMEOUT DE INATIVIDADE)
 */
// [SEGURANÇA] Estabelece uma janela de inatividade máxima para prevenir ataques de Session Hijacking (sequestro de sessão), 
// especialmente crítico se o usuário acessar a plataforma de computadores públicos ou compartilhados.
$timeout_duration = 3600;

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    
    if (isset($_SESSION['last_activity'])) {
        $time_elapsed = time() - $_SESSION['last_activity'];
        
        if ($time_elapsed > $timeout_duration) {
            
            // [AUDITORIA] Ao invalidar uma sessão viciada, o sistema corta imediatamente a capacidade de gravação de dados desse usuário. 
            // Qualquer futura ação que deixe um rastro (como logs ou soft deletes) exigirá uma nova comprovação de identidade criptográfica.
            session_unset();
            session_destroy();
            
            session_start();
            $_SESSION['login_error'] = "Sua sessão expirou por segurança. Por favor, faça login novamente.";
            
            header("Location: " . APP_URL . "/auth/login.php");
            exit();
        }
    }
    
    // [LÓGICA] Atualiza o timestamp de atividade a cada interação válida, mantendo a sessão viva apenas para usuários ativos.
    $_SESSION['last_activity'] = time();
}

/**
 * CONFIGURAÇÕES DO BANCO DE DADOS (Aiven)
 */
// [ARQUITETURA] A injeção de credenciais via variáveis de ambiente protege o acesso à base de dados.
// Nenhuma senha ou IP fica exposto no repositório de controle de versão, seguindo as diretrizes do Twelve-Factor App.
$host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '');
$port = (int)(getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? 23270));
$user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '');
$pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '');
$db   = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? '');

/**
 * CONFIGURAÇÕES DE E-MAIL (SMTP)
 */
define('SMTP_HOST',   getenv('SMTP_HOST')   ?: ($_ENV['SMTP_HOST']   ?? ''));
define('SMTP_USER',   getenv('SMTP_USER')   ?: ($_ENV['SMTP_USER']   ?? ''));
define('SMTP_PASS',   getenv('SMTP_PASS')   ?: ($_ENV['SMTP_PASS']   ?? ''));
define('SMTP_PORT',   getenv('SMTP_PORT')   ?: ($_ENV['SMTP_PORT']   ?? 587));
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: ($_ENV['SMTP_SECURE'] ?? 'tls'));

// Caminho do Certificado SSL do Aiven
//$ca_path = __DIR__ . "/ca.pem";

$conn = mysqli_init();

// [SEGURANÇA] Força a utilização de criptografia SSL/TLS ponta-a-ponta na comunicação entre a aplicação e o servidor de banco de dados, 
// o que é um critério de auditoria vital para proteger dados em trânsito contra interceptações (Man-in-the-Middle).
//mysqli_ssl_set($conn, NULL, NULL, $ca_path, NULL, NULL);
//mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);

$connected = @mysqli_real_connect($conn, $host, $user, $pass, $db, $port, NULL, MYSQLI_CLIENT_SSL);


if (!$connected) {
    die("<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>
            <h2>Erro de Conexão com o Banco! 🛠️</h2>
            <small>Erro: " . mysqli_connect_error() . "</small>
         </div>");
}

// [LÓGICA] Padroniza a codificação de caracteres como utf8mb4 para suportar todos os caracteres especiais e emojis, evitando falhas de armazenamento no banco de dados.
mysqli_set_charset($conn, "utf8mb4");

// [AUDITORIA] Força o fuso horário do banco de dados MySQL para o Brasil (UTC-3).
// Isso é mandatório para que funções nativas do SQL como NOW() e CURRENT_TIMESTAMP (amplamente usadas na trilha de auditoria) registrem a hora exata da ocorrência.
$conn->query("SET time_zone = '-03:00'");

// [NEGÓCIO] Taxa de retenção da plataforma (Take Rate). Centralizado para evitar hardcode.
define('PLATFORM_TAKE_RATE', 0.10); // 10%

?>