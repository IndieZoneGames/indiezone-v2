<?php
// src/backend/ajax_admin_dashboard.php
ob_start();
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    ob_clean();
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Acesso negado.']);
    exit();
}

// Rate Limiting
$time_now = time();
if (isset($_SESSION['last_dashboard_request']) && ($time_now - $_SESSION['last_dashboard_request']) < 2) {
    ob_clean();
    header('HTTP/1.1 429 Too Many Requests');
    echo json_encode(['error' => 'Muitas requisições. Aguarde antes de atualizar.']);
    exit();
}
$_SESSION['last_dashboard_request'] = $time_now;

header('Content-Type: application/json; charset=utf-8');

try {
    $period_days = isset($_GET['period']) ? (int)$_GET['period'] : 30;
    if (!in_array($period_days, [7, 30, 90, 180])) $period_days = 30; 
    
    $force_refresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
    $take_rate = defined('PLATFORM_TAKE_RATE') ? PLATFORM_TAKE_RATE : 0.10;
    
    $cache_file = sys_get_temp_dir() . '/indiezone_dash_cache_p' . $period_days . '.json';
    if (!$force_refresh && file_exists($cache_file) && (time() - filemtime($cache_file)) < 300) {
        ob_clean(); echo file_get_contents($cache_file); exit();
    }

    $response = [];
    $alerts = [];
    $date_limit = date('Y-m-d H:i:s', strtotime("-$period_days days"));
    $date_limit_previous = date('Y-m-d H:i:s', strtotime("-" . ($period_days * 2) . " days"));

    // 1. KPIs
    $stmt_gmv = $conn->prepare("SELECT SUM(amount) as gmv FROM transactions WHERE status = 'completed' AND created_at >= ?");
    $stmt_gmv->bind_param("s", $date_limit);
    $stmt_gmv->execute();
    $gmv_atual = (float)($stmt_gmv->get_result()->fetch_assoc()['gmv'] ?? 0);

    $stmt_gmv_prev = $conn->prepare("SELECT SUM(amount) as gmv FROM transactions WHERE status = 'completed' AND created_at >= ? AND created_at < ?");
    $stmt_gmv_prev->bind_param("ss", $date_limit_previous, $date_limit);
    $stmt_gmv_prev->execute();
    $gmv_anterior = (float)($stmt_gmv_prev->get_result()->fetch_assoc()['gmv'] ?? 0);
    $gmv_growth = $gmv_anterior > 0 ? (($gmv_atual - $gmv_anterior) / $gmv_anterior) * 100 : 0;

    $stmt_arpu = $conn->prepare("SELECT SUM(amount) as total, COUNT(DISTINCT user_id) as users FROM transactions WHERE status = 'completed' AND created_at >= ?");
    $stmt_arpu->bind_param("s", $date_limit);
    $stmt_arpu->execute();
    $arpu_data = $stmt_arpu->get_result()->fetch_assoc();
    $arpu_atual = ($arpu_data && (int)$arpu_data['users'] > 0) ? ((float)$arpu_data['total'] / (int)$arpu_data['users']) : 0;

    $response['kpis'] = [
        'gmv' => round($gmv_atual, 2), 'gmv_growth' => round($gmv_growth, 1),
        'take_rate' => round($gmv_atual * $take_rate, 2), 'arpu' => round($arpu_atual, 2), 'period' => $period_days
    ];

    if ($gmv_growth < -15) $alerts[] = ['type' => 'danger', 'msg' => "Alerta: Queda de " . abs(round($gmv_growth,1)) . "% na receita vs período anterior."];
    
    $fila = $conn->query("SELECT (SELECT COUNT(*) FROM games WHERE status='pending') + (SELECT COUNT(*) FROM developers WHERE approval_status='pending')")->fetch_row()[0];
    if ($fila > 10) $alerts[] = ['type' => 'warning', 'msg' => "Atenção: SLA de moderação acumulado ($fila itens na fila)."];
    $response['alerts'] = $alerts;

    // 2. LIQUIDEZ (Dinâmico)
    $stmt_liq = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as dia, SUM(amount) as total FROM transactions WHERE status = 'completed' AND created_at >= ? GROUP BY dia ORDER BY dia ASC");
    $stmt_liq->bind_param("s", $date_limit); $stmt_liq->execute();
    $res_liq = $stmt_liq->get_result();
    $liq_labels = []; $liq_vendas = [];
    while($row = $res_liq->fetch_assoc()) { $liq_labels[] = date('d/m', strtotime($row['dia'])); $liq_vendas[] = (float)$row['total']; }
    $response['liquidez'] = ['labels' => $liq_labels, 'vendas' => $liq_vendas];

    // 3. HEATMAP
    $dias = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    $heat_data = []; foreach ($dias as $dia) { $horas = []; for ($h = 0; $h < 24; $h++) { $horas[str_pad($h, 2, '0', STR_PAD_LEFT).':00'] = 0; } $heat_data[$dia] = $horas; }
    $stmt_heat = $conn->prepare("SELECT DAYOFWEEK(created_at) as dw, HOUR(created_at) as hr, COUNT(*) as qtd FROM transactions WHERE status='completed' AND created_at >= ? GROUP BY dw, hr");
    $stmt_heat->bind_param("s", $date_limit); $stmt_heat->execute();
    $res_heat = $stmt_heat->get_result();
    while($r = $res_heat->fetch_assoc()) { $heat_data[$dias[(int)$r['dw'] - 1]][str_pad($r['hr'], 2, '0', STR_PAD_LEFT).':00'] = (int)$r['qtd']; }
    $heat_series = []; foreach(array_reverse($dias) as $dia) { $pts = []; foreach($heat_data[$dia] as $hora => $val) { $pts[] = ['x' => $hora, 'y' => $val]; } $heat_series[] = ['name' => $dia, 'data' => $pts]; }
    $response['heatmap'] = $heat_series;

    // 4. CRESCIMENTO (Usuários)
    $stmt_usr = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as dia, role, COUNT(*) as qtd FROM users WHERE created_at >= ? AND role IN ('player','dev') GROUP BY dia, role ORDER BY dia ASC");
    $stmt_usr->bind_param("s", $date_limit); $stmt_usr->execute();
    $res_usr = $stmt_usr->get_result();
    $usr_labels = []; $players = []; $devs = [];
    while($r = $res_usr->fetch_assoc()) { 
        $d = date('d/m', strtotime($r['dia']));
        if(!in_array($d, $usr_labels)) { $usr_labels[] = $d; $players[$d] = 0; $devs[$d] = 0; }
        if($r['role'] == 'player') $players[$d] += (int)$r['qtd'];
        if($r['role'] == 'dev') $devs[$d] += (int)$r['qtd'];
    }
    $response['crescimento'] = ['labels' => $usr_labels, 'players' => array_values($players), 'devs' => array_values($devs)];

    // 5. GÊNEROS
    $stmt_gen = $conn->prepare("SELECT gen.name, COUNT(l.game_id) as qtd FROM library l JOIN game_genres gg ON l.game_id = gg.game_id JOIN genres gen ON gg.genre_id = gen.genre_id WHERE l.acquired_at >= ? GROUP BY gen.name ORDER BY qtd DESC LIMIT 5");
    $stmt_gen->bind_param("s", $date_limit); $stmt_gen->execute();
    $res_gen = $stmt_gen->get_result();
    $glabels = []; $gdata = [];
    while($r = $res_gen->fetch_assoc()) { $glabels[] = $r['name']; $gdata[] = (int)$r['qtd']; }
    $response['generos'] = ['labels' => $glabels, 'valores' => $gdata];

    // 6. TOP JOGOS
    $stmt_top = $conn->prepare("SELECT g.title, SUM(t.amount) as receita FROM transactions t JOIN games g ON t.game_id = g.game_id WHERE t.status = 'completed' AND t.created_at >= ? GROUP BY g.game_id ORDER BY receita DESC LIMIT 5");
    $stmt_top->bind_param("s", $date_limit); $stmt_top->execute();
    $res_top = $stmt_top->get_result();
    $tlabels = []; $tdata = [];
    while($r = $res_top->fetch_assoc()) { $tlabels[] = $r['title']; $tdata[] = (float)$r['receita']; }
    $response['top_games'] = ['labels' => $tlabels, 'valores' => $tdata];

    // 7. PRICING
    $q_pricing = $conn->query("SELECT CASE WHEN price = 0 AND is_pwyw = 0 THEN 'Grátis' WHEN price > 0 AND is_pwyw = 0 THEN 'Pago' WHEN is_pwyw = 1 THEN 'PWYW' END as modelo, COUNT(*) as qtd FROM games WHERE status = 'published' GROUP BY modelo");
    $plabels = []; $pdata = [];
    while($r = $q_pricing->fetch_assoc()) { if($r['modelo']){ $plabels[] = $r['modelo']; $pdata[] = (int)$r['qtd']; } }
    $response['pricing'] = ['labels' => $plabels, 'valores' => $pdata];

    // 8. OS
    $q_os = $conn->query("SELECT platform_os, COUNT(*) as qtd FROM game_builds WHERE is_active = 1 GROUP BY platform_os");
    $oslabels = []; $osdata = [];
    while($r = $q_os->fetch_assoc()) { $oslabels[] = strtoupper($r['platform_os']); $osdata[] = (int)$r['qtd']; }
    $response['os'] = ['labels' => $oslabels, 'valores' => $osdata];

    // 9. LOGS
    $stmt_logs = $conn->prepare("SELECT severity, COUNT(*) as qtd FROM system_logs WHERE created_at >= ? GROUP BY severity");
    $stmt_logs->bind_param("s", $date_limit); $stmt_logs->execute();
    $res_logs = $stmt_logs->get_result();
    $llabels = []; $ldata = [];
    while($r = $res_logs->fetch_assoc()) { $llabels[] = $r['severity']; $ldata[] = (int)$r['qtd']; }
    $response['logs'] = ['labels' => $llabels, 'valores' => $ldata];

    ob_clean();
    $json_output = json_encode($response);
    file_put_contents($cache_file, $json_output);
    echo $json_output;

} catch (Exception $e) {
    ob_clean();
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Falha no processamento.']);
}
?>