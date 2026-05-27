<?php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

// [SEGURANÇA] Proteção de Rota Crítica (RBAC)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// [AUDITORIA] Extração de métricas operacionais em tempo real para os contadores e abas
$stats = [
    'total_users' => $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0],
    'active_devs' => $conn->query("SELECT COUNT(*) FROM users WHERE role = 'dev'")->fetch_row()[0],
    'pending_req' => $conn->query("SELECT COUNT(*) FROM developers WHERE approval_status = 'pending'")->fetch_row()[0],
    'pending_games' => $conn->query("SELECT COUNT(*) FROM games WHERE status = 'pending'")->fetch_row()[0],
    'pending_withdrawals' => $conn->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetch_row()[0]
];

// QUERIES DE LISTAGEM ORIGINAIS COMPLETAS
$users_query = $conn->query("SELECT user_id, display_name, username, email, role, is_active, deleted_at, created_at FROM users ORDER BY created_at DESC");

$devs_query = $conn->query("SELECT d.user_id, d.studio_name, d.document_number, d.portfolio_url, d.first_game_pitch, d.created_at, u.display_name, u.email 
                            FROM developers d JOIN users u ON d.user_id = u.user_id 
                            WHERE d.approval_status = 'pending' ORDER BY d.created_at ASC");

$games_query = $conn->query("SELECT g.game_id, g.title, g.release_stage, g.price, g.created_at, g.cover_image_url, d.studio_name 
                             FROM games g JOIN developers d ON g.developer_id = d.user_id 
                             WHERE g.status = 'pending' ORDER BY g.created_at ASC");

$withdrawals_query = $conn->query("SELECT w.id, w.amount, w.payment_details, w.created_at, u.display_name, u.email 
                                   FROM withdrawals w JOIN users u ON w.developer_id = u.user_id 
                                   WHERE w.status = 'pending' ORDER BY w.created_at ASC");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Comando - IndieZone</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        /* CSS das Listagens e Tabelas */
        .search-container { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.2); }
        .search-input { width: 100%; max-width: 400px; padding: 12px 16px 12px 40px; border-radius: 8px; background: rgba(0,0,0,0.4) url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%2394a3b8" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>') no-repeat 14px center; border: 1px solid rgba(34, 197, 94, 0.2); color: #fff; font-family: inherit; font-size: 14px; transition: all 0.2s; }
        .search-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.15); }
        .details-box summary { cursor: pointer; color: #22c55e; font-weight: 600; font-size: 12px; margin-top: 8px; outline: none;}
        .details-box p { background: rgba(0,0,0,0.4); padding: 10px; border-radius: 6px; font-size: 12px; color: #94a3b8; margin-top: 5px; border: 1px solid rgba(255,255,255,0.1); max-height: 80px; overflow-y: auto;}

        /* CSS do Painel de Controle e Elementos Analíticos */
        .dashboard-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; flex-wrap: wrap; gap: 15px; }
        .controls-group { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .select-modern { background: #050e08; color: #e2e8f0; border: 1px solid #1a3a24; padding: 10px 14px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; outline: none; cursor: pointer; transition: 0.2s;}
        .select-modern:focus { border-color: #22c55e; }
        
        /* Botões Estritamente Customizados em Tons de Verde */
        .btn-refresh { background: rgba(34, 197, 94, 0.1); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); padding: 10px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; transition: 0.2s; }
        .btn-refresh:hover { background: #22c55e; color: #000; }
        .btn-pdf { background: #22c55e; color: #050e08; border: 1px solid #16a34a; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 700; transition: 0.2s; }
        .btn-pdf:hover { background: #4ade80; }

        .last-updated { font-size: 12px; color: #64748b; font-weight: 500;}
        
        #alert-container { display: flex; flex-direction: column; gap: 10px; width: 100%; margin-bottom: 10px;}
        .sys-alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 10px; animation: fadeIn 0.4s; }
        .alert-danger { background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #f87171; }
        .alert-warning { background: rgba(250, 204, 21, 0.1); border: 1px solid #facc15; color: #fde047; }

        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: rgba(10, 25, 15, 0.8); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 12px; padding: 24px; position: relative; }
        .kpi-title { color: #94a3b8; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; border-bottom: 1px dashed #22c55e; display: inline-block; cursor: help; }
        .kpi-value { color: #fff; font-size: 30px; font-weight: 800; line-height: 1; margin-bottom: 8px; }
        .trend-up { color: #4ade80; font-size: 13px; font-weight: 600; }
        .trend-down { color: #f87171; font-size: 13px; font-weight: 600; }

        .charts-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; padding: 24px; background: rgba(0,0,0,0.2); border-radius: 12px;}
        .chart-box { background: rgba(5, 14, 8, 0.8); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 12px; padding: 24px; position: relative; display: block; }
        .chart-full { grid-column: 1 / -1; }
        .chart-header h4 { margin: 0; color: #e2e8f0; font-size: 15px; font-weight: 700; }
        .chart-header p { margin: 4px 0 15px 0; color: #64748b; font-size: 12px; }
        
        .chart-loader { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(5, 14, 8, 0.8); display: flex; justify-content: center; align-items: center; border-radius: 12px; z-index: 5; color: #22c55e; font-weight: 600; opacity: 0; pointer-events: none; transition: 0.3s; }
        .chart-loader.active { opacity: 1; pointer-events: all; }

        /* =========================================================
           ESTILOS EXCLUSIVOS DE IMPRESSÃO (FOTOCÓPIA EM PDF)
           ========================================================= */
        @media print {
            @page { size: landscape; margin: 10mm; } /* Força a página deitada para o gráfico caber inteiro */
            body { background: #050e08 !important; color: #e2e8f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .top-navbar, .admin-tabs, .dashboard-header, .search-container, .btn-action, form { display: none !important; }
            .admin-container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .table-container { display: none !important; }
            #tab-overview { display: block !important; background: transparent !important; border: none !important; width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .kpi-grid { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 15px !important; }
            .charts-grid { display: grid !important; grid-template-columns: repeat(2, 1fr) !important; gap: 20px !important; background: transparent !important; }
            .chart-box { background: rgba(5, 14, 8, 0.9) !important; border: 1px solid rgba(34, 197, 94, 0.2) !important; page-break-inside: avoid !important; width: 100% !important; }
            .chart-full { grid-column: 1 / -1 !important; }
        }

        @media (max-width: 1024px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } .charts-grid { grid-template-columns: 1fr; } .chart-full { grid-column: 1; } }
        @media (max-width: 600px) { .kpi-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <div class="top-navbar" style="background: rgba(5, 14, 8, 0.95); padding: 16px 40px; border-bottom: 1px solid rgba(34, 197, 94, 0.3); display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <span style="font-size: 24px;">🛡️</span>
            <h1 style="color: #22c55e; font-size: 24px; font-weight: 800; margin: 0;">Centro de Comando</h1>
        </div>
        <div style="display: flex; align-items: center; gap: 20px;">
            <a href="admin_logs.php" style="background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.4); padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px;">🔎 Logs NOC</a>
            <a href="index.php" style="color: #e2e8f0; text-decoration: none; font-weight: 500;">Voltar ao Sistema</a>
        </div>
    </div>

    <div class="admin-container">

        <div class="admin-tabs">
            <button class="tab-btn active" onclick="openAdminTab('tab-overview', this)">Visão Geral Analítica</button>
            <button class="tab-btn" onclick="openAdminTab('tab-users', this)">Gerenciar Usuários</button>
            <button class="tab-btn" onclick="openAdminTab('tab-devs', this)">Estúdios <?php if($stats['pending_req'] > 0) echo "<span style='background: #ef4444; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;'>{$stats['pending_req']}</span>"; ?></button>
            <button class="tab-btn" onclick="openAdminTab('tab-games', this)">Catálogo <?php if($stats['pending_games'] > 0) echo "<span style='background: #facc15; color: black; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px; font-weight: bold;'>{$stats['pending_games']}</span>"; ?></button>
            <button class="tab-btn" onclick="openAdminTab('tab-finance', this)">Financeiro <?php if($stats['pending_withdrawals'] > 0) echo "<span style='background: #4ade80; color: black; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px; font-weight: bold;'>{$stats['pending_withdrawals']}</span>"; ?></button>
        </div>

        <div id="tab-overview" class="table-container active" style="background: transparent; border: none; overflow: visible; padding: 0;">
            
            <div class="dashboard-header">
                <div id="alert-container"></div>
                <div class="controls-group" style="width: 100%; justify-content: flex-end;">
                    <span style="color:#22c55e; font-size:13px; font-weight:600;">Filtrar Período:</span>
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
                        <div class="kpi-value"><span style="font-size: 16px;">R$</span> <span id="kpi-gmv">0.00</span></div>
                        <div style="font-size: 12px; color: #94a3b8;">Total transacionado</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title" title="Receita retida da plataforma">Receita Real (Take Rate)</div>
                        <div class="kpi-value" style="color:#4ade80;"><span style="font-size: 16px;">R$</span> <span id="kpi-take">0.00</span></div>
                        <div style="font-size: 12px; color: #94a3b8;">Lucro bruto do período</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title" title="Gasto médio por conta ativa pagante">Ticket Médio (ARPU)</div>
                        <div class="kpi-value"><span style="font-size: 16px;">R$</span> <span id="kpi-arpu">0.00</span></div>
                        <div style="font-size: 12px; color: #94a3b8;">Por usuário</div>
                    </div>
                    <div class="kpi-card" style="border-color: rgba(250, 204, 21, 0.3);">
                        <div class="kpi-title" style="border-color: #facc15;" title="Soma total de requisições aguardando moderação">Fila SLA</div>
                        <div class="kpi-value" style="color: #facc15;"><?php echo $stats['pending_games'] + $stats['pending_req']; ?></div>
                        <div style="font-size: 12px; color: #facc15;">Pendências aguardando</div>
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
                        <div id="chart-seguranca" style="display:flex; justify-content:center;"></div>
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
                        <th style="width: 60px;">ID</th><th>Usuário</th><th>Email</th><th>Cargo</th><th>Status</th><th>Cadastro</th><th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $users_query->fetch_assoc()): ?>
                        <tr>
                            <td style="color: #94a3b8; font-weight: 600;">#<?php echo $row['user_id']; ?></td>
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
                                    if ($row['role'] === 'admin') echo '<span class="badge badge-admin" style="color:#22c55e; border-color:#22c55e;">Admin</span>';
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
                                    <form action="../../src/backend/admin_process.php" method="POST" style="display:inline;">
                                        <input type="hidden" name="target_user_id" value="<?php echo $row['user_id']; ?>">
                                        <?php if ($row['is_active'] == 1): ?>
                                            <button type="submit" name="action" value="ban_user" class="btn-action btn-reject" onclick="return confirm('Banir este usuário imediatamente?')">Banir</button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="reactivate_user" class="btn-action btn-approve">Reativar</button>
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #64748b; font-size: 12px; font-style: italic;">Intocável</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div id="tab-devs" class="table-container" style="display: none;">
            <?php if ($stats['pending_req'] == 0): ?>
                <div style="padding: 40px; text-align: center; color: #94a3b8;">Nenhum estúdio aguardando aprovação no momento.</div>
            <?php else: ?>
                <div class="search-container">
                    <input type="text" id="searchDevs" class="search-input" onkeyup="filterTable('searchDevs', 'devsTable')" placeholder="Buscar estúdio...">
                </div>
                <table id="devsTable">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th><th>Solicitante</th><th>Estúdio Requerido</th><th style="width: 300px;">Provas (Curadoria)</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($req = $devs_query->fetch_assoc()): ?>
                            <tr>
                                <td style="color: #94a3b8; font-weight: 600;">#<?php echo $req['user_id']; ?></td>
                                <td><div class="user-cell"><div><span class="user-name"><?php echo htmlspecialchars($req['display_name']); ?></span><span class="user-nick"><?php echo htmlspecialchars($req['email']); ?></span></div></div></td>
                                <td><strong style="color: #facc15; font-size: 16px;"><?php echo htmlspecialchars($req['studio_name']); ?></strong><br><span style="font-family: monospace; font-size: 12px; color: #94a3b8;">Doc: <?php echo htmlspecialchars($req['document_number']); ?></span></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($req['portfolio_url']); ?>" target="_blank" style="color: #22c55e; font-size: 12px; font-weight: 600;">🔗 Acessar Portfólio</a>
                                    <details class="details-box"><summary style="color:#22c55e;">Ler Pitch do Jogo</summary><p><?php echo nl2br(htmlspecialchars($req['first_game_pitch'])); ?></p></details>
                                </td>
                                <td>
                                    <form action="../../src/backend/admin_process.php" method="POST" class="actions" style="flex-direction: column;">
                                        <input type="hidden" name="target_user_id" value="<?php echo $req['user_id']; ?>">
                                        <button type="submit" name="action" value="approve_dev" class="btn-action btn-approve" style="width: 100%;">Aprovar</button>
                                        <button type="submit" name="action" value="reject_dev" class="btn-action btn-reject" style="width: 100%; margin-top: 5px;">Rejeitar</button>
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
                <div style="padding: 40px; text-align: center; color: #94a3b8;">Fila de jogos limpa. Nenhum jogo aguardando aprovação.</div>
            <?php else: ?>
                <div class="search-container">
                    <input type="text" id="searchGames" class="search-input" onkeyup="filterTable('searchGames', 'gamesTable')" placeholder="Buscar por título ou estúdio...">
                </div>
                <table id="gamesTable">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th><th>Jogo</th><th>Estúdio</th><th>Preço</th><th>Envio</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($game = $games_query->fetch_assoc()): ?>
                            <tr>
                                <td style="color: #94a3b8; font-weight: 600;">#<?php echo $game['game_id']; ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img src="<?php echo htmlspecialchars($game['cover_image_url'] ?: '../assets/img/placeholder-game.png'); ?>" style="width: 60px; height: 35px; border-radius: 4px; object-fit: cover;">
                                        <div>
                                            <strong style="color: #fff; font-size: 14px;"><?php echo htmlspecialchars($game['title']); ?></strong>
                                            <span style="display: block; font-size: 11px; color: #94a3b8; text-transform: uppercase;"><?php echo $game['release_stage']; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: #cbd5e1;"><?php echo htmlspecialchars($game['studio_name']); ?></td>
                                <td style="color: #4ade80; font-weight: bold;"><?php echo $game['price'] > 0 ? "R$ " . number_format($game['price'], 2, ',', '.') : "Grátis / PWYW"; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($game['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 8px;">
                                        <form action="../../src/backend/admin_process.php" method="POST" style="margin: 0;">
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
                <div style="padding: 40px; text-align: center; color: #94a3b8;">Nenhum pedido de saque pendente.</div>
            <?php else: ?>
                <div class="search-container">
                    <input type="text" id="searchWith" class="search-input" onkeyup="filterTable('searchWith', 'withTable')" placeholder="Buscar por dev ou chave...">
                </div>
                <table id="withTable">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th><th>Estúdio / Dev</th><th>Chave de Pagamento</th><th>Valor a Pagar</th><th>Pedido</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($with = $withdrawals_query->fetch_assoc()): ?>
                            <tr>
                                <td style="color: #94a3b8; font-weight: 600;">#<?php echo $with['id']; ?></td>
                                <td><span style="color: #fff; font-weight: 600;"><?php echo htmlspecialchars($with['display_name']); ?></span><span style="display: block; font-size: 12px; color: #94a3b8;"><?php echo htmlspecialchars($with['email']); ?></span></td>
                                <td><div style="background: rgba(255,255,255,0.05); padding: 8px 12px; border-radius: 6px; border: 1px dashed rgba(255,255,255,0.2); font-family: monospace; font-size: 14px; color: #facc15; display: inline-block;"><?php echo htmlspecialchars($with['payment_details']); ?></div></td>
                                <td style="color: #4ade80; font-size: 18px; font-weight: bold;">R$ <?php echo number_format($with['amount'], 2, ',', '.'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($with['created_at'])); ?></td>
                                <td>
                                    <form action="../../src/backend/admin_process.php" method="POST" style="display: flex; flex-direction: column; gap: 5px;">
                                        <input type="hidden" name="target_with_id" value="<?php echo $with['id']; ?>">
                                        <button type="submit" name="action" value="complete_withdrawal" class="btn-action btn-approve" onclick="return confirm('Já realizou o pagamento no banco?')">💸 Marcar Pago</button>
                                        <button type="submit" name="action" value="reject_withdrawal" class="btn-action btn-reject" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;" onclick="return confirm('Cancelar saque?')">Cancelar Saque</button>
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
        
        // Base desabilitada de toolbar individual em favor da impressão macro via PDF
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

                    // 1. Módulo: Liquidez
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

                    // 2. Módulo: Heatmap
                    if(!charts.heat) {
                        charts.heat = new ApexCharts(document.querySelector("#chart-heatmap"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'heatmap', height: 400 },
                            series: data?.heatmap || [],
                            plotOptions: { heatmap: { colorScale: { ranges: [{ from: 0, to: 0, color: 'rgba(255,255,255,0.02)' }, { from: 1, to: 5, color: '#14532d' }, { from: 6, to: 15, color: '#16a34a' }, { from: 16, to: 9999, color: '#4ade80' }]}}},
                            dataLabels: { enabled: false }
                        });
                        charts.heat.render();
                    } else { charts.heat.updateOptions({ series: data.heatmap }); }

                    // 3. Módulo: Usuários (Verde e Roxo)
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

                    // 4. Módulo: Gêneros (Ciano Claro)
                    const sGen = [{ name: 'Aquisições', data: data?.generos?.valores || [] }];
                    if(!charts.gen) {
                        charts.gen = new ApexCharts(document.querySelector("#chart-genres"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'bar', height: 350 },
                            plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#06b6d4'],
                            series: sGen, xaxis: { categories: data?.generos?.labels || [] }
                        });
                        charts.gen.render();
                    } else { charts.gen.updateOptions({ series: sGen, xaxis: { categories: data.generos.labels } }); }

                    // 5. Módulo: Top Jogos (Amarelo)
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

                    // 6. Módulo: Pricing (Cores Distintas: Laranja, Esmeralda, Roxo)
                    if(!charts.pri) {
                        charts.pri = new ApexCharts(document.querySelector("#chart-pricing"), {
                            ...baseOpts, chart: { ...baseOpts.chart, type: 'pie', height: 350 },
                            series: hasData(data?.pricing?.valores) ? data.pricing.valores : [1], 
                            labels: hasData(data?.pricing?.labels) ? data.pricing.labels : ['Sem dados'],
                            colors: hasData(data?.pricing?.labels) ? ['#f59e0b', '#10b981', '#8b5cf6'] : ['#1a3a24'], stroke: { show: false }, legend: { position: 'bottom' }
                        });
                        charts.pri.render();
                    } else { charts.pri.updateOptions({ series: data.pricing.valores, labels: data.pricing.labels }); }

                    // 7. Módulo: OS (Sem Monochrome - Cores Variadas)
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

                    // 8. Módulo: NOC Logs (Status do Sistema)
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
                .catch(err => { alert("Erro de Conexão: " + err.message); })
                .finally(() => { document.querySelectorAll('.chart-loader').forEach(el => el.classList.remove('active')); });
        }

        document.addEventListener("DOMContentLoaded", () => fetchDashboardData(false));
    </script>
</body>
</html>