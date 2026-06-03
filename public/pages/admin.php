<?php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

// [SEGURANÇA] Proteção de Rota Crítica (RBAC)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// [SEGURANÇA TCC] Geração de Token Anti-CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// [AUDITORIA] Extração de métricas operacionais em tempo real para os contadores e abas
$stats = [
    'total_users' => $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0],
    'active_devs' => $conn->query("SELECT COUNT(*) FROM users WHERE role = 'dev'")->fetch_row()[0],
    'pending_req' => $conn->query("SELECT COUNT(*) FROM developers WHERE approval_status = 'pending'")->fetch_row()[0],
    'pending_games' => $conn->query("SELECT COUNT(*) FROM games WHERE status = 'pending'")->fetch_row()[0],
    'pending_withdrawals' => $conn->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetch_row()[0]
];

function resolveImageUrl($url) {
    if (empty($url)) return '';
    if (strpos($url, 'http') === 0) return htmlspecialchars($url);
    
    // Remove os ../ e a primeira barra para padronizar
    $clean_path = ltrim(str_replace('../', '/', $url), '/');
    return APP_URL . '/' . htmlspecialchars($clean_path);
}

// [ESCALABILIDADE TCC] Queries de Listagem com LIMIT para não sobrecarregar a memória
$users_query = $conn->query("SELECT user_id, display_name, username, email, role, is_active, deleted_at, created_at FROM users ORDER BY created_at DESC LIMIT 100");

$devs_query = $conn->query("SELECT d.user_id, d.studio_name, d.document_number, d.portfolio_url, d.first_game_pitch, d.created_at, u.display_name, u.email 
                            FROM developers d JOIN users u ON d.user_id = u.user_id 
                            WHERE d.approval_status = 'pending' ORDER BY d.created_at ASC LIMIT 50");

$games_query = $conn->query("SELECT g.game_id, g.title, g.release_stage, g.price, g.created_at, g.cover_image_url, d.studio_name 
                             FROM games g JOIN developers d ON g.developer_id = d.user_id 
                             WHERE g.status = 'pending' ORDER BY g.created_at ASC LIMIT 50");

$withdrawals_query = $conn->query("SELECT w.id, w.amount, w.payment_details, w.created_at, u.display_name, u.email 
                                   FROM withdrawals w JOIN users u ON w.developer_id = u.user_id 
                                   WHERE w.status = 'pending' ORDER BY w.created_at ASC LIMIT 50");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Comando - IndieZone</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

    <div class="top-navbar">
        <div class="navbar-brand">
            <span class="navbar-brand-icon">🛡️</span>
            <h1 class="navbar-title">Centro de Comando</h1>
        </div>
        <div class="navbar-actions">
            <a href="admin_logs.php" class="btn-noc">🔎 Logs NOC</a>
            <a href="index.php" class="btn-back-sys">Voltar ao Sistema</a>
        </div>
    </div>

    <div class="admin-container">

        <!-- [UX TCC] Feedback visual para a banca quando uma ação é concluída -->
        <?php if (isset($_SESSION['admin_msg'])): ?>
            <div class="flash-message">
                <span>✅</span> <?php echo htmlspecialchars($_SESSION['admin_msg']); unset($_SESSION['admin_msg']); ?>
            </div>
        <?php endif; ?>

        <div class="admin-tabs">
            <button class="tab-btn active" onclick="openAdminTab('tab-overview', this)">Visão Geral Analítica</button>
            <button class="tab-btn" onclick="openAdminTab('tab-users', this)">Gerenciar Usuários</button>
            <button class="tab-btn" onclick="openAdminTab('tab-devs', this)">Estúdios <?php if($stats['pending_req'] > 0) echo "<span class='tab-badge tab-badge-danger'>{$stats['pending_req']}</span>"; ?></button>
            <button class="tab-btn" onclick="openAdminTab('tab-games', this)">Catálogo <?php if($stats['pending_games'] > 0) echo "<span class='tab-badge tab-badge-warning'>{$stats['pending_games']}</span>"; ?></button>
            <button class="tab-btn" onclick="openAdminTab('tab-finance', this)">Financeiro <?php if($stats['pending_withdrawals'] > 0) echo "<span class='tab-badge tab-badge-success'>{$stats['pending_withdrawals']}</span>"; ?></button>
        </div>

        <div id="tab-overview" class="table-container tab-overview-container active">
            
            <div class="dashboard-header">
                <div id="alert-container"></div>
                <div class="controls-group controls-group-right">
                    <span class="filter-label">Filtrar Período:</span>
                    <select id="periodSelector" class="select-modern" onchange="fetchDashboardData(true)">
                        <option value="7">Últimos 7 dias</option>
                        <option value="30" selected>Últimos 30 dias</option>
                        <option value="90">Últimos 90 dias</option>
                        <option value="180">Últimos 6 meses</option>
                    </select>
                    
                    <button class="btn-refresh" onclick="fetchDashboardData(true)">Atualizar Dados</button>
                    <button class="btn-pdf" onclick="window.print()">Exportar PDF</button>
                    <span id="lastUpdated" class="last-updated">Atualizado em: --:--</span>
                </div>
            </div>

            <div id="bi-content">
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-title" title="Gross Merchandise Value: Volume total transacionado">Volume Bruto (GMV)</div>
                        <div class="kpi-value"><span class="kpi-currency">R$</span> <span id="kpi-gmv">0.00</span></div>
                        <div class="kpi-desc">Total transacionado</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title" title="Receita retida da plataforma">Receita Real (Take Rate)</div>
                        <div class="kpi-value kpi-value-success"><span class="kpi-currency">R$</span> <span id="kpi-take">0.00</span></div>
                        <div class="kpi-desc">Lucro bruto do período</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title" title="Gasto médio por conta ativa pagante">Ticket Médio (ARPU)</div>
                        <div class="kpi-value"><span class="kpi-currency">R$</span> <span id="kpi-arpu">0.00</span></div>
                        <div class="kpi-desc">Por usuário</div>
                    </div>
                    <div class="kpi-card kpi-card-warning">
                        <div class="kpi-title kpi-title-warning" title="Soma total de requisições aguardando moderação">Fila SLA</div>
                        <div class="kpi-value kpi-value-warning"><?php echo $stats['pending_games'] + $stats['pending_req']; ?></div>
                        <div class="kpi-desc kpi-desc-warning">Pendências aguardando</div>
                    </div>
                </div>

                <div class="charts-grid">
                    <div class="chart-box chart-full">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header">
                            <h4>Fluxo Financeiro e Vendas Diárias</h4>
                            <p>Análise cronológica de entradas brutas confirmadas no sistema.</p>
                        </div>
                        <div id="chart-liquidez"></div>
                    </div>

                    <div class="chart-box chart-full">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header"><h4>Mapa de Calor Transacional</h4><p>Densidade de compras segmentadas por dia de semana e faixa de horário.</p></div>
                        <div id="chart-heatmap"></div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header"><h4>Aquisição de Usuários (Growth)</h4><p>Mapeamento de novos registros de Jogadores e Desenvolvedores.</p></div>
                        <div id="chart-users"></div>
                    </div>
                    
                    <div class="chart-box">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header"><h4>Top 5 Gêneros Mais Adquiridos</h4><p>Segmentação dos estilos de jogos preferidos na biblioteca.</p></div>
                        <div id="chart-genres"></div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header"><h4>Top 5 Jogos em Faturamento</h4><p>Títulos com maior representação financeira no marketplace.</p></div>
                        <div id="chart-topgames"></div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header"><h4>Precificação do Catálogo Ativo</h4><p>Modelos comerciais mais publicados na plataforma.</p></div>
                        <div id="chart-pricing"></div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header"><h4>Distribuição de Compilações (Sistemas)</h4><p>Sistemas operacionais alvo das builds ativas.</p></div>
                        <div id="chart-os"></div>
                    </div>

                    <div class="chart-box">
                        <div class="chart-loader">Processando...</div>
                        <div class="chart-header"><h4>NOC: Alertas de Auditoria (7 dias)</h4><p>Volumetria de severidade capturada pela trilha de logs.</p></div>
                        <div id="chart-seguranca" class="d-flex-center"></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-users" class="table-container" style="display: none;">
            <div class="search-container">
                <input type="text" id="searchUsers" class="search-input" onkeyup="filterTable('searchUsers', 'usersTable')" placeholder="Buscar por ID, nome, @usuario ou email...">
            </div>
            <table id="usersTable">
                <thead>
                    <tr>
                        <th class="col-id">ID</th><th>Usuário</th><th>Email</th><th>Cargo</th><th>Status</th><th>Cadastro</th><th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $users_query->fetch_assoc()): ?>
                        <tr>
                            <td class="cell-id">#<?php echo $row['user_id']; ?></td>
                            <td>
                                <div class="user-cell">
                                    <img src="https://api.dicebear.com/7.x/pixel-art/svg?seed=<?php echo urlencode($row['username']); ?>" class="user-avatar">
                                    <div>
                                        <span class="user-name"><?php echo htmlspecialchars($row['display_name']); ?></span>
                                        <span class="user-nick">@<?php echo htmlspecialchars($row['username']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td>
                                <?php 
                                    if ($row['role'] === 'admin') echo '<span class="badge badge-admin">Admin</span>';
                                    elseif ($row['role'] === 'dev') echo '<span class="badge badge-dev">Dev</span>';
                                    else echo '<span class="badge badge-player">Player</span>';
                                ?>
                            </td>
                            <td>
                                <?php if ($row['is_active'] == 1): ?>
                                    <span class="badge badge-active">Ativa</span>
                                <?php elseif ($row['deleted_at'] !== null): ?>
                                    <span class="badge badge-inactive">Banida</span>
                                <?php else: ?>
                                    <span class="badge badge-pending">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <?php if ($row['role'] !== 'admin'): ?>
                                    <form action="../../src/backend/admin_process.php" method="POST" class="form-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="target_user_id" value="<?php echo $row['user_id']; ?>">
                                        <?php if ($row['is_active'] == 1): ?>
                                            <button type="submit" name="action" value="ban_user" class="btn-action btn-reject" onclick="return confirm('Banir este usuário imediatamente?')">Banir</button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="reactivate_user" class="btn-action btn-approve">Reativar</button>
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span class="text-untouchable">Intocável</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div id="tab-devs" class="table-container" style="display: none;">
            <?php if ($stats['pending_req'] == 0): ?>
                <div class="empty-state">Nenhum estúdio aguardando aprovação no momento.</div>
            <?php else: ?>
                <div class="search-container">
                    <input type="text" id="searchDevs" class="search-input" onkeyup="filterTable('searchDevs', 'devsTable')" placeholder="Buscar estúdio...">
                </div>
                <table id="devsTable">
                    <thead>
                        <tr>
                            <th class="col-id">ID</th><th>Solicitante</th><th>Estúdio Requerido</th><th>Provas (Curadoria)</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($req = $devs_query->fetch_assoc()): ?>
                            <tr>
                                <td class="cell-id">#<?php echo $req['user_id']; ?></td>
                                <td>
                                    <div class="user-cell">
                                        <div>
                                            <span class="user-name"><?php echo htmlspecialchars($req['display_name']); ?></span>
                                            <span class="user-nick"><?php echo htmlspecialchars($req['email']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong class="studio-name-highlight"><?php echo htmlspecialchars($req['studio_name']); ?></strong><br>
                                    <span class="doc-number">Doc: <?php echo htmlspecialchars($req['document_number']); ?></span>
                                </td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($req['portfolio_url']); ?>" target="_blank" class="portfolio-link">🔗 Acessar Portfólio</a>
                                    <details class="details-box">
                                        <summary>Ler Pitch do Jogo</summary>
                                        <p><?php echo nl2br(htmlspecialchars($req['first_game_pitch'])); ?></p>
                                    </details>
                                </td>
                                <td>
                                    <form action="../../src/backend/admin_process.php" method="POST" class="actions-col">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="target_user_id" value="<?php echo $req['user_id']; ?>">
                                        <button type="submit" name="action" value="approve_dev" class="btn-action btn-approve w-100">Aprovar</button>
                                        <button type="submit" name="action" value="reject_dev" class="btn-action btn-reject mt-5 w-100">Rejeitar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div id="tab-games" class="table-container" style="display: none;">
            <?php if ($stats['pending_games'] == 0): ?>
                <div class="empty-state">Fila de jogos limpa. Nenhum jogo aguardando aprovação.</div>
            <?php else: ?>
                <div class="search-container">
                    <input type="text" id="searchGames" class="search-input" onkeyup="filterTable('searchGames', 'gamesTable')" placeholder="Buscar por título ou estúdio...">
                </div>
                <table id="gamesTable">
                    <thead>
                        <tr>
                            <th class="col-id">ID</th><th>Jogo</th><th>Estúdio</th><th>Preço</th><th>Envio</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($game = $games_query->fetch_assoc()): ?>
                            <tr>
                                <td class="cell-id">#<?php echo $game['game_id']; ?></td>
                                <td>
                                    <div class="game-info-wrapper">
                                        <img src="<?php echo resolveImageUrl($game['cover_image_url'] ?: '../assets/img/placeholder-game.png'); ?>" class="game-cover-mini">
                                        <div>
                                            <strong class="game-title-main"><?php echo htmlspecialchars($game['title']); ?></strong>
                                            <span class="game-stage-sub"><?php echo $game['release_stage']; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="studio-text"><?php echo htmlspecialchars($game['studio_name']); ?></td>
                                <td class="price-highlight"><?php echo $game['price'] > 0 ? "R$ " . number_format($game['price'], 2, ',', '.') : "Grátis / PWYW"; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($game['created_at'])); ?></td>
                                <td>
                                    <div class="actions">
                                        <form action="../../src/backend/admin_process.php" method="POST" class="form-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="target_game_id" value="<?php echo $game['game_id']; ?>">
                                            <button type="submit" name="action" value="approve_game" class="btn-action btn-approve" onclick="return confirm('Deseja PUBLICAR este jogo?')">Aprovar</button>
                                            <button type="submit" name="action" value="reject_game" class="btn-action btn-reject" onclick="return confirm('Devolver para Rascunho?')">Rejeitar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div id="tab-finance" class="table-container" style="display: none;">
            <?php if ($stats['pending_withdrawals'] == 0): ?>
                <div class="empty-state">Nenhum pedido de saque pendente.</div>
            <?php else: ?>
                <div class="search-container">
                    <input type="text" id="searchWith" class="search-input" onkeyup="filterTable('searchWith', 'withTable')" placeholder="Buscar por dev ou chave...">
                </div>
                <table id="withTable">
                    <thead>
                        <tr>
                            <th class="col-id">ID</th><th>Estúdio / Dev</th><th>Chave de Pagamento</th><th>Valor a Pagar</th><th>Pedido</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($with = $withdrawals_query->fetch_assoc()): ?>
                            <tr>
                                <td class="cell-id">#<?php echo $with['id']; ?></td>
                                <td>
                                    <span class="user-name"><?php echo htmlspecialchars($with['display_name']); ?></span>
                                    <span class="user-nick"><?php echo htmlspecialchars($with['email']); ?></span>
                                </td>
                                <td><div class="pay-key-box"><?php echo htmlspecialchars($with['payment_details']); ?></div></td>
                                <td class="withdraw-amount">R$ <?php echo number_format($with['amount'], 2, ',', '.'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($with['created_at'])); ?></td>
                                <td>
                                    <form action="../../src/backend/admin_process.php" method="POST" class="actions-col">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="target_with_id" value="<?php echo $with['id']; ?>">
                                        <button type="submit" name="action" value="complete_withdrawal" class="btn-action btn-approve" onclick="return confirm('Já realizou o pagamento no banco?')">💸 Marcar Pago</button>
                                        <button type="submit" name="action" value="reject_withdrawal" class="btn-action btn-reject-alt" onclick="return confirm('Cancelar saque?')">Cancelar Saque</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        function openAdminTab(tabId, element) {
            document.querySelectorAll('.table-container').forEach(tab => { tab.style.display = 'none'; tab.classList.remove('active'); });
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            const target = document.getElementById(tabId); target.style.display = 'block';
            setTimeout(() => target.classList.add('active'), 10);
            element.classList.add('active'); window.dispatchEvent(new Event('resize'));
        }

        function filterTable(inputId, tableId) {
            let input = document.getElementById(inputId), filter = input.value.toLowerCase(), table = document.getElementById(tableId), tr = table.getElementsByTagName("tr");
            for (let i = 1; i < tr.length; i++) { 
                let tdArray = tr[i].getElementsByTagName("td"), rowContainsFilter = false;
                for (let j = 0; j < tdArray.length; j++) {
                    if (tdArray[j] && (tdArray[j].textContent || tdArray[j].innerText).toLowerCase().indexOf(filter) > -1) { rowContainsFilter = true; break; }
                }
                tr[i].style.display = rowContainsFilter ? "" : "none";
            }
        }

        let charts = {}; 

        const formatMoney = (val) => val.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const hasData = (arr) => arr && arr.length > 0;
        
        const baseOpts = { 
            theme: { mode: 'dark' }, 
            chart: { background: 'transparent', fontFamily: "'Inter', sans-serif", toolbar: { show: false } }, 
            grid: { borderColor: 'rgba(255,255,255,0.05)', strokeDashArray: 4 }
        };

        function fetchDashboardData(forceRefresh = false) {
            const period = document.getElementById('periodSelector').value;
            document.querySelectorAll('.chart-loader').forEach(el => el.classList.add('active'));

            fetch(`../../src/backend/ajax_admin_dashboard.php?period=${period}&refresh=${forceRefresh}`)
                .then(res => { 
                    if(res.status === 429) throw new Error("Aguarde um momento antes de atualizar.");
                    if(!res.ok) throw new Error("Erro na rede: " + res.status); 
                    return res.json(); 
                })
                .then(data => {
                    if(data.error) throw new Error(data.error);

                    const now = new Date();
                    document.getElementById('lastUpdated').innerText = `Atualizado em: ${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}`;

                    const alertContainer = document.getElementById('alert-container');
                    alertContainer.innerHTML = '';
                    if (hasData(data.alerts)) {
                        data.alerts.forEach(al => { alertContainer.innerHTML += `<div class="sys-alert alert-${al.type}">⚠️ ${al.msg}</div>`; });
                    }

                    document.getElementById('kpi-gmv').innerText = formatMoney(data.kpis.gmv);
                    document.getElementById('kpi-take').innerText = formatMoney(data.kpis.take_rate);
                    document.getElementById('kpi-arpu').innerText = formatMoney(data.kpis.arpu);

                    const sLiq = [{ name: 'Vendas (R$)', data: data?.liquidez?.vendas || [] }];
                    if(!charts.liq) {
                        charts.liq = new ApexCharts(document.querySelector("#chart-liquidez"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'area', height: 350 },
                            stroke: { curve: 'smooth', width: 3 }, colors: ['#22c55e'],
                            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
                            series: sLiq, xaxis: { categories: data?.liquidez?.labels || [] }
                        });
                        charts.liq.render();
                    } else { charts.liq.updateOptions({ series: sLiq, xaxis: { categories: data.liquidez.labels } }); }

                    if(!charts.heat) {
                        charts.heat = new ApexCharts(document.querySelector("#chart-heatmap"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'heatmap', height: 400 },
                            series: data?.heatmap || [],
                            plotOptions: { heatmap: { colorScale: { ranges: [{ from: 0, to: 0, color: 'rgba(255,255,255,0.02)' }, { from: 1, to: 5, color: '#14532d' }, { from: 6, to: 15, color: '#16a34a' }, { from: 16, to: 9999, color: '#4ade80' }]}}},
                            dataLabels: { enabled: false }
                        });
                        charts.heat.render();
                    } else { charts.heat.updateOptions({ series: data.heatmap }); }

                    const sUsr = [
                        { name: 'Jogadores', data: data?.crescimento?.players || [] },
                        { name: 'Estúdios', data: data?.crescimento?.devs || [] }
                    ];
                    if(!charts.usr) {
                        charts.usr = new ApexCharts(document.querySelector("#chart-users"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'bar', stacked: true, height: 350 },
                            colors: ['#22c55e', '#8b5cf6'], series: sUsr, xaxis: { categories: data?.crescimento?.labels || [] }
                        });
                        charts.usr.render();
                    } else { charts.usr.updateOptions({ series: sUsr, xaxis: { categories: data.crescimento.labels } }); }

                    const sGen = [{ name: 'Aquisições', data: data?.generos?.valores || [] }];
                    if(!charts.gen) {
                        charts.gen = new ApexCharts(document.querySelector("#chart-genres"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'bar', height: 350 },
                            plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#06b6d4'],
                            series: sGen, xaxis: { categories: data?.generos?.labels || [] }
                        });
                        charts.gen.render();
                    } else { charts.gen.updateOptions({ series: sGen, xaxis: { categories: data.generos.labels } }); }

                    const sTop = [{ name: 'Receita', data: data?.top_games?.valores || [] }];
                    if(!charts.top) {
                        charts.top = new ApexCharts(document.querySelector("#chart-topgames"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'bar', height: 350 },
                            plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#facc15'],
                            series: sTop, xaxis: { categories: data?.top_games?.labels || [] },
                            dataLabels: { enabled: true, formatter: (val) => "R$ " + val }
                        });
                        charts.top.render();
                    } else { charts.top.updateOptions({ series: sTop, xaxis: { categories: data.top_games.labels } }); }

                    if(!charts.pri) {
                        charts.pri = new ApexCharts(document.querySelector("#chart-pricing"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'pie', height: 350 },
                            series: hasData(data?.pricing?.valores) ? data.pricing.valores : [1], 
                            labels: hasData(data?.pricing?.labels) ? data.pricing.labels : ['Sem dados'],
                            colors: hasData(data?.pricing?.labels) ? ['#f59e0b', '#10b981', '#8b5cf6'] : ['#1a3a24'], stroke: { show: false }, legend: { position: 'bottom' }
                        });
                        charts.pri.render();
                    } else { charts.pri.updateOptions({ series: data.pricing.valores, labels: data.pricing.labels }); }

                    if(!charts.os) {
                        charts.os = new ApexCharts(document.querySelector("#chart-os"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'polarArea', height: 350 },
                            series: hasData(data?.os?.valores) ? data.os.valores : [1], 
                            labels: hasData(data?.os?.labels) ? data.os.labels : ['N/A'],
                            colors: hasData(data?.os?.labels) ? ['#38bdf8', '#facc15', '#ef4444', '#a78bfa', '#22c55e'] : ['#1a3a24'],
                            stroke: { colors: ['#050e08'] }, fill: { opacity: 0.8 }, legend: { position: 'bottom' }
                        });
                        charts.os.render();
                    } else { charts.os.updateOptions({ series: data.os.valores, labels: data.os.labels }); }

                    if(!charts.noc) {
                        charts.noc = new ApexCharts(document.querySelector("#chart-seguranca"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'donut', height: 350 },
                            series: hasData(data?.logs?.valores) ? data.logs.valores : [1], 
                            labels: hasData(data?.logs?.labels) ? data.logs.labels : ['Saudável'],
                            colors: hasData(data?.logs?.labels) ? ['#22c55e', '#facc15', '#ef4444'] : ['#22c55e'], stroke: { show: false }, legend: { position: 'bottom' }
                        });
                        charts.noc.render();
                    } else { charts.noc.updateOptions({ series: data.logs.valores, labels: data.logs.labels }); }

                })
                .catch(err => { 
                    // [UX TCC] Tratamento elegante de erro: em vez do alert, adicionamos a falha direto no container visual.
                    const alertContainer = document.getElementById('alert-container');
                    alertContainer.innerHTML += `<div class="sys-alert alert-danger">❌ Falha ao carregar gráficos: ${err.message}</div>`; 
                })
                .finally(() => { document.querySelectorAll('.chart-loader').forEach(el => el.classList.remove('active')); });
        }

        document.addEventListener("DOMContentLoaded", () => {
            fetchDashboardData(false);
            setTimeout(() => {
                let flash = document.querySelector('.flash-message');
                if(flash) flash.style.display = 'none';
            }, 5000); 
        });

        // [UX TCC] Previne múltiplos envios de formulário (Double Submit Protection)
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                // Impede clique duplo desabilitando visualmente os botões
                const buttons = this.querySelectorAll('button[type="submit"]');
                buttons.forEach(b => {
                    b.style.pointerEvents = 'none';
                    b.style.opacity = '0.7';
                });
            });
        });
    </script>
</body>
</html>