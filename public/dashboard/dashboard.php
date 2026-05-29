<?php
// public/dashboard/dashboard.php

// [ARQUITETURA] A inclusão do 'dev_header.php' garante o RBAC antes da execução.
require_once("includes/dev_header.php");
/** @var mysqli $conn */

// 1. Buscar os jogos reais deste desenvolvedor
$stmt_games = $conn->prepare("SELECT game_id, title, status, is_pwyw, price, cover_image_url FROM games WHERE developer_id = ? ORDER BY created_at DESC");
$stmt_games->bind_param("i", $user_id);
$stmt_games->execute();
$result_games = $stmt_games->get_result();
$total_games = $result_games->num_rows;

// Variáveis Globais
$total_downloads = 0;
$receita_estimada = 0.00;

// Arrays base para o ApexCharts
$chart_games_labels = [];
$chart_games_downloads = [];
$chart_games_revenue = [];
$pricing_stats = ['paid' => 0, 'free' => 0, 'pwyw' => 0];

// Estruturas de Tempo Real (7 Dias)
$chart_traffic_labels = [];
$chart_traffic_data = [];
$seven_days_ago = date('Y-m-d', strtotime("-6 days"));
$today = date('Y-m-d');

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_traffic_labels[] = date('d/m', strtotime($date));
    $chart_traffic_data[$date] = 0; 
}

// Estrutura do Mapa de Calor Real
$dias_semana = [1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];
$heatmap_data = [];
foreach([1, 2, 3, 4, 5, 6, 7] as $d) {
    $horas = [];
    for($h = 0; $h < 24; $h += 4) { $horas[sprintf("%02dh", $h)] = 0; } // Agrupa de 4 em 4 horas
    $heatmap_data[$d] = ['name' => $dias_semana[$d], 'data' => $horas];
}

// Estrutura do Status do Catálogo Real
$status_counts = ['published' => 0, 'pending' => 0, 'draft' => 0];

if ($total_games > 0) {
    $game_ids = [];
    $games_array = [];
    while ($g = $result_games->fetch_assoc()) {
        $game_ids[] = $g['game_id'];
        $games_array[] = $g; 
        $chart_games_labels[] = $g['title'];
        
        // [MONETIZAÇÃO] Divisão real em 3 categorias separadas
        if ((int)$g['is_pwyw'] === 1) {
            $pricing_stats['pwyw']++;
        } elseif ((float)$g['price'] > 0) {
            $pricing_stats['paid']++;
        } else {
            $pricing_stats['free']++;
        }

        // Contagem real de status
        $s = $g['status'];
        if(isset($status_counts[$s])) $status_counts[$s]++;
        else $status_counts['draft']++;
    }

    $ids_string = implode(',', $game_ids);

    // [DADOS REAIS] Contagem global de Downloads
    $stmt_down = $conn->query("SELECT COUNT(*) as total FROM library WHERE game_id IN ($ids_string)");
    if ($stmt_down && $row = $stmt_down->fetch_assoc()) {
        $total_downloads = $row['total'];
    }

    // [CORREÇÃO CRÍTICA]: Fim do N+1 Queries. 
    // Em vez de rodar um SELECT no banco para CADA jogo no loop abaixo, mapeamos tudo em uma única query.
    $dls_map = [];
    $stmt_all_dls = $conn->query("SELECT game_id, COUNT(*) as dls FROM library WHERE game_id IN ($ids_string) GROUP BY game_id");
    if ($stmt_all_dls) {
        while ($row = $stmt_all_dls->fetch_assoc()) {
            $dls_map[$row['game_id']] = (int)$row['dls'];
        }
    }

    // [DADOS REAIS] Loop de Receita e Downloads Individuais otimizado (sem bater no banco repetidamente)
    foreach ($games_array as $game) {
        $dls = $dls_map[$game['game_id']] ?? 0;
        
        $receita_jogo = ($dls * $game['price']);
        $receita_estimada += $receita_jogo;
        
        $chart_games_downloads[] = (int)$dls;
        $chart_games_revenue[] = (float)$receita_jogo;
    }

    // [CORREÇÃO CRÍTICA]: Tráfego de Downloads dos Últimos 7 Dias (Usando acquired_at)
    // Otimizado com PHP dates ($seven_days_ago e $today) para prevenir desvio de fuso horário do MySQL
    $q_traf = "SELECT DATE(acquired_at) as dt, COUNT(*) as qtd FROM library WHERE game_id IN ($ids_string) AND DATE(acquired_at) >= '$seven_days_ago' AND DATE(acquired_at) <= '$today' GROUP BY DATE(acquired_at)";
    $res_traf = $conn->query($q_traf);
    if($res_traf) {
        while($r = $res_traf->fetch_assoc()) {
            if(isset($chart_traffic_data[$r['dt']])) { $chart_traffic_data[$r['dt']] = (int)$r['qtd']; }
        }
    }

    // [DADOS REAIS] Extração de Dia e Hora para o Heatmap (Usando acquired_at)
    $q_heat = "SELECT DAYOFWEEK(acquired_at) as dia, HOUR(acquired_at) as hora, COUNT(*) as qtd FROM library WHERE game_id IN ($ids_string) GROUP BY dia, hora";
    $res_heat = $conn->query($q_heat);
    if($res_heat) {
        while($r = $res_heat->fetch_assoc()) {
            $d = $r['dia'];
            $h_group = floor($r['hora']/4)*4;
            $h_label = sprintf("%02dh", $h_group);
            if(isset($heatmap_data[$d]['data'][$h_label])) {
                $heatmap_data[$d]['data'][$h_label] += (int)$r['qtd'];
            }
        }
    }
} else {
    // Fallback vazio caso seja o primeiro acesso do desenvolvedor
    $chart_games_labels = ['Sem Dados'];
    $chart_games_downloads = [0];
    $chart_games_revenue = [0];
}

// [DADOS REAIS] Consultas Isoladas para o Radar de Engajamento (Usando user_follows)
$total_followers = 0;
$res_foll = $conn->query("SELECT COUNT(*) as tot FROM user_follows WHERE developer_id = $user_id");
if($res_foll) $total_followers = $res_foll->fetch_assoc()['tot'];

$total_reviews = 0;
if ($total_games > 0) {
    $res_rev = $conn->query("SELECT COUNT(*) as tot FROM reviews WHERE game_id IN ($ids_string)");
    if($res_rev) $total_reviews = $res_rev->fetch_assoc()['tot'];
}

// Preparação Final dos Arrays para o JavaScript
$real_traffic_values = array_values($chart_traffic_data);
$real_heatmap = [];
foreach($heatmap_data as $info) {
    $data_arr = [];
    foreach($info['data'] as $x => $y) { $data_arr[] = ['x' => $x, 'y' => $y]; }
    $real_heatmap[] = ['name' => $info['name'], 'data' => $data_arr];
}
$real_status_labels = ['Publicados', 'Em Análise', 'Rascunhos'];
$real_status_values = [$status_counts['published'], $status_counts['pending'], $status_counts['draft']];
$real_radar_labels = ['Downloads Totais', 'Seguidores', 'Avaliações', 'Jogos Lançados'];
$real_radar_values = [$total_downloads, (int)$total_followers, (int)$total_reviews, $total_games];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <style>
        .charts-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; padding: 24px; background: rgba(0,0,0,0.25); border-radius: 16px; margin-bottom: 40px; border: 1px solid rgba(34, 197, 94, 0.08); }
        .chart-box { background: rgba(5, 14, 8, 0.85); border: 1px solid rgba(255, 255, 255, 0.04); border-radius: 12px; padding: 24px; position: relative; display: block; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
        .chart-full { grid-column: 1 / -1; }
        .chart-header h4 { margin: 0; color: #e2e8f0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .chart-header p { margin: 4px 0 15px 0; color: #64748b; font-size: 12px; }
        .chart-loader { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 14, 8, 0.9); display: flex; justify-content: center; align-items: center; border-radius: 12px; z-index: 5; color: #22c55e; font-weight: 600; opacity: 0; pointer-events: none; transition: 0.3s; }
        .chart-loader.active { opacity: 1; pointer-events: all; }
        @media (max-width: 1024px) { .charts-grid { grid-template-columns: 1fr; } .chart-full { grid-column: 1; } }
    </style>
</head>
<body>
<main class="dashboard-main">

    <header class="dash-header">
        <div class="dash-title">
            <h1>Bem-vindo de volta, Dev! 🚀</h1>
            <p>Seus dados operacionais reais em tempo real.</p>
        </div>
        <div style="display: flex; gap: 12px;">
            <a href="new_post.php" class="btn-dash-outline">📝 Novo Post</a>
            <a href="add_game.php" class="btn-upload">+ Novo Jogo</a>
        </div>
    </header>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-title">Total de Downloads</div>
            <div class="stat-value"><?php echo number_format($total_downloads, 0, ',', '.'); ?></div>
            <div class="stat-trend <?php echo $total_downloads > 0 ? 'positive' : ''; ?>">
                <?php echo $total_downloads > 0 ? 'Crescimento constante' : 'Aguardando aquisições'; ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-title">Receita Estimada (Base)</div>
            <div class="stat-value">R$ <?php echo number_format($receita_estimada, 2, ',', '.'); ?></div>
            <div class="stat-trend <?php echo $receita_estimada > 0 ? 'positive' : ''; ?>">
                <?php echo $receita_estimada > 0 ? 'Ganhos acumulados' : 'Aguardando vendas'; ?>
            </div>
        </div>
        <div class="stat-card" style="border-color: rgba(139, 92, 246, 0.3);">
            <div class="stat-title" style="color: #a78bfa;">Seguidores do Estúdio</div>
            <div class="stat-value" style="color: #a78bfa;"><?php echo $total_followers; ?></div>
            <div class="stat-trend">Comunidade engajada</div>
        </div>
    </div>

    <div class="charts-grid">
        
        <div class="chart-box chart-full">
            <div class="chart-loader">Processando...</div>
            <div class="chart-header">
                <h4>📈 Aquisições Diárias (Últimos 7 Dias)</h4>
                <p>Volume exato de jogadores que adicionaram seus jogos à biblioteca recentemente.</p>
            </div>
            <div id="chart-traffic-flow"></div>
        </div>

        <div class="chart-box">
            <div class="chart-loader">Processando...</div>
            <div class="chart-header">
                <h4>📊 Volume de Downloads por Título</h4>
                <p>Distribuição absoluta do sucesso do seu catálogo.</p>
            </div>
            <div id="chart-downloads"></div>
        </div>

        <div class="chart-box">
            <div class="chart-loader">Processando...</div>
            <div class="chart-header">
                <h4>💰 Faturamento Bruto Estimado</h4>
                <p>Projeção baseada em valor de tabela vs. bibliotecas vinculadas.</p>
            </div>
            <div id="chart-revenue"></div>
        </div>

        <div class="chart-box chart-full">
            <div class="chart-loader">Processando...</div>
            <div class="chart-header">
                <h4>🔥 Matriz de Horários de Aquisição</h4>
                <p>Identifica os picos reais de interesse nos seus jogos (Dias da Semana vs. Horários).</p>
            </div>
            <div id="chart-heatmap-activity"></div>
        </div>

        <div class="chart-box">
            <div class="chart-loader">Processando...</div>
            <div class="chart-header">
                <h4>💎 Estrutura de Monetização</h4>
                <p>Proporção real de títulos Premium, Gratuitos e modelos dinâmicos Pay What You Want.</p>
            </div>
            <div id="chart-pricing"></div>
        </div>

        <div class="chart-box">
            <div class="chart-loader">Processando...</div>
            <div class="chart-header">
                <h4>📋 Status do Catálogo</h4>
                <p>Situação operacional atual dos seus projetos na IndieZone.</p>
            </div>
            <div id="chart-status-polar"></div>
        </div>

        <div class="chart-box chart-full">
            <div class="chart-loader">Processando...</div>
            <div class="chart-header">
                <h4>🕸️ Radar de Engajamento Global</h4>
                <p>Mapeamento das interações reais da comunidade com a sua marca.</p>
            </div>
            <div id="chart-radar-health"></div>
        </div>

    </div>

    <section class="content-section">
        <h2>Meus Jogos Recentes</h2>

        <?php if ($total_games === 0): ?>
            <div class="empty-state">
                <p>Você ainda não publicou nenhum jogo na IndieZone.</p>
                <a href="add_game.php" class="btn-upload" style="display: inline-block;">Começar meu Primeiro Upload</a>
            </div>
        <?php else: ?>
            <div class="games-grid">
                <?php foreach ($games_array as $game): ?>
                    <div class="game-card">
                        <img src="<?php echo htmlspecialchars($game['cover_image_url'] ?: '../assets/img/placeholder-game.png'); ?>" alt="Capa">
                        <h3><?php echo htmlspecialchars($game['title']); ?></h3>
                        <p>
                            <?php
                            if ($game['status'] == 'published') echo '<span style="color: #4ade80;">● Público</span>';
                            elseif ($game['status'] == 'pending') echo '<span style="color: #facc15;">● Em Análise</span>';
                            else echo '<span style="color: #94a3b8;">○ Rascunho</span>';
                            ?>
                        </p>

                        <div class="game-card-actions">
                            <a href="edit_game.php?id=<?php echo $game['game_id']; ?>">Editar</a>
                            <a href="manage_builds.php?id=<?php echo $game['game_id']; ?>" style="background: rgba(34, 197, 94, 0.2); color: #22c55e;">Arquivos</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('.chart-loader').forEach(el => el.classList.add('active'));

        const baseOpts = { 
            theme: { mode: 'dark' }, 
            chart: { background: 'transparent', fontFamily: "'Inter', sans-serif", toolbar: { show: false } }, 
            grid: { borderColor: 'rgba(255,255,255,0.05)', strokeDashArray: 4 }
        };

        // INJEÇÃO SEGURA DE DADOS REAIS DO PHP PARA O JS
        const gameLabels = <?php echo json_encode($chart_games_labels); ?>;
        const gameDownloads = <?php echo json_encode($chart_games_downloads); ?>;
        const gameRevenue = <?php echo json_encode($chart_games_revenue); ?>;
        
        // Estrutura atualizada com as 3 divisões reais solicitadas
        const pricingData = [
            <?php echo $pricing_stats['paid']; ?>, 
            <?php echo $pricing_stats['free']; ?>, 
            <?php echo $pricing_stats['pwyw']; ?>
        ];
        
        const trafficLabels = <?php echo json_encode($chart_traffic_labels); ?>;
        const trafficData = <?php echo json_encode($real_traffic_values); ?>;
        const heatmapData = <?php echo json_encode($real_heatmap); ?>;
        const statusLabels = <?php echo json_encode($real_status_labels); ?>;
        const statusData = <?php echo json_encode($real_status_values); ?>;
        const radarLabels = <?php echo json_encode($real_radar_labels); ?>;
        const radarData = <?php echo json_encode($real_radar_values); ?>;

        setTimeout(() => {
            
            // 1. Aquisições Diárias
            new ApexCharts(document.querySelector("#chart-traffic-flow"), {
                ...baseOpts,
                chart: { ...baseOpts.chart, type: 'area', height: 320 },
                stroke: { curve: 'smooth', width: 3 },
                colors: ['#22c55e'],
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02 } },
                series: [{ name: 'Downloads Realizados', data: trafficData }],
                xaxis: { categories: trafficLabels }
            }).render();

            // 2. Downloads por Título
            new ApexCharts(document.querySelector("#chart-downloads"), {
                ...baseOpts,
                chart: { ...baseOpts.chart, type: 'bar', height: 280 },
                colors: ['#22c55e'],
                series: [{ name: 'Downloads', data: gameDownloads }],
                xaxis: { categories: gameLabels },
                plotOptions: { bar: { borderRadius: 4, columnWidth: '50%' } }
            }).render();

            // 3. Faturamento
            new ApexCharts(document.querySelector("#chart-revenue"), {
                ...baseOpts,
                chart: { ...baseOpts.chart, type: 'bar', height: 280 },
                colors: ['#facc15'],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '50%' } },
                series: [{ name: 'Receita Bruta (R$)', data: gameRevenue }],
                xaxis: { categories: gameLabels },
                dataLabels: { enabled: true, formatter: (val) => "R$ " + val }
            }).render();

            // 4. Heatmap Real
            new ApexCharts(document.querySelector("#chart-heatmap-activity"), {
                ...baseOpts,
                chart: { ...baseOpts.chart, type: 'heatmap', height: 260 },
                series: heatmapData,
                plotOptions: { heatmap: { colorScale: { ranges: [
                    { from: 0, to: 0, color: 'rgba(255,255,255,0.02)' },
                    { from: 1, to: 5, color: '#14532d' },
                    { from: 6, to: 20, color: '#16a34a' },
                    { from: 21, to: 9999, color: '#4ade80' }
                ]}}}
            }).render();

            // 5. Monetização (Atualizado para 3 Divisões com cores premium)
            new ApexCharts(document.querySelector("#chart-pricing"), {
                ...baseOpts,
                chart: { ...baseOpts.chart, type: 'donut', height: 280 },
                colors: ['#f59e0b', '#10b981', '#3b82f6'], // Laranja (Pago), Esmeralda (Grátis), Azul (PWYW)
                series: pricingData,
                labels: ['Jogos Pagos', 'Jogos Gratuitos', 'Pay What You Want (PWYW)'],
                stroke: { show: false },
                legend: { position: 'bottom' }
            }).render();

            // 6. Status do Catálogo (Polar Area Real)
            new ApexCharts(document.querySelector("#chart-status-polar"), {
                ...baseOpts,
                chart: { ...baseOpts.chart, type: 'polarArea', height: 280 },
                series: statusData,
                labels: statusLabels,
                colors: ['#22c55e', '#facc15', '#94a3b8'],
                stroke: { colors: ['#050e08'] },
                fill: { opacity: 0.85 },
                legend: { position: 'bottom' }
            }).render();

            // 7. Radar de Engajamento Real
            new ApexCharts(document.querySelector("#chart-radar-health"), {
                ...baseOpts,
                chart: { ...baseOpts.chart, type: 'radar', height: 320 },
                colors: ['#8b5cf6'],
                series: [{ name: 'Total Absoluto', data: radarData }],
                labels: radarLabels,
                stroke: { width: 2 },
                fill: { opacity: 0.2 },
                yaxis: { show: false }
            }).render();

            document.querySelectorAll('.chart-loader').forEach(el => el.classList.remove('active'));
        }, 500);
    });
</script>
</body>
</html>