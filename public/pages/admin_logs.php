<?php
// public/pages/admin_logs.php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

// Proteção da Rota: Somente Administradores
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Acesso negado. Nível de privilégio insuficiente.");
}

// 1. BUSCA DINÂMICA DE AÇÕES PARA O DROPDOWN (SELECT)
$actions_query = $conn->query("SELECT DISTINCT action FROM system_logs ORDER BY action ASC");
$available_actions = [];
if ($actions_query) {
    while ($row = $actions_query->fetch_assoc()) {
        $available_actions[] = $row['action'];
    }
}

// 2. CAPTURA DOS FILTROS
// A data inicializa automaticamente com o dia de hoje
$filter_date     = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
$filter_severity = isset($_GET['severity']) ? $_GET['severity'] : '';
$filter_action   = isset($_GET['action']) ? $_GET['action'] : '';
$search_text     = trim($_GET['search'] ?? '');

// 3. MONTAGEM DA QUERY DINÂMICA
$sql = "SELECT l.*, u.display_name, u.avatar_url, u.username 
        FROM system_logs l 
        LEFT JOIN users u ON l.user_id = u.user_id 
        WHERE 1=1";

$params = [];
$types = "";

if (!empty($filter_date)) {
    $sql .= " AND DATE(l.created_at) = ?";
    $params[] = $filter_date;
    $types .= "s";
}

if (!empty($filter_severity)) {
    $sql .= " AND l.severity = ?";
    $params[] = $filter_severity;
    $types .= "s";
}

if (!empty($filter_action)) {
    $sql .= " AND l.action = ?";
    $params[] = $filter_action;
    $types .= "s";
}

if (!empty($search_text)) {
    $sql .= " AND (
        u.username LIKE ? OR 
        u.display_name LIKE ? OR 
        l.entity_table LIKE ? OR 
        l.entity_id = ? OR 
        l.ip_address LIKE ?
    )";
    $like = "%" . $search_text . "%";
    $search_id = intval($search_text);
    array_push($params, $like, $like, $like, $search_id, $like);
    $types .= "sssis";
}

$sql .= " ORDER BY l.created_at DESC LIMIT 500";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria do Sistema - IndieZone</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    
    <style>
        .filter-bar {
            background: rgba(10, 25, 15, 0.6);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-label { color: #94a3b8; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .input-base {
            background: #050e08; border: 1px solid rgba(34, 197, 94, 0.3);
            color: #fff; padding: 12px 16px; border-radius: 8px; font-size: 14px; outline: none; height: 46px;
        }
        .input-base:focus { border-color: #22c55e; }
        select.input-base {
            appearance: none; padding-right: 30px;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2322c55e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat; background-position: right 10px center; background-size: 16px; cursor: pointer;
        }
        .logs-table th { white-space: nowrap; }
        
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(4px);
            display: none; justify-content: center; align-items: center; z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #050e08; border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 12px; width: 90%; max-width: 900px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.8);
        }
        .modal-header {
            background: rgba(10, 25, 15, 0.8); padding: 20px 24px; border-bottom: 1px solid rgba(34, 197, 94, 0.2);
            display: flex; justify-content: space-between; align-items: center;
        }
        .modal-title { margin: 0; color: #22c55e; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;}
        .btn-close { background: none; border: none; color: #94a3b8; font-size: 28px; cursor: pointer; line-height: 1;}
        .btn-close:hover { color: #ef4444; }
        .modal-body { padding: 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; max-height: 65vh; overflow-y: auto; }
        .json-panel h4 { margin: 0 0 10px 0; color: #94a3b8; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;}
        .json-viewer {
            background: rgba(10, 25, 15, 0.8); padding: 20px; border-radius: 8px;
            border: 1px solid rgba(34, 197, 94, 0.1); font-family: 'Courier New', Courier, monospace; font-size: 13px; color: #e2e8f0;
            white-space: pre-wrap; margin: 0; word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        
        <header class="admin-header">
            <h1 class="admin-title">Auditoria de Sistema</h1>
            <a href="admin.php" class="btn-action btn-neutral" style="text-decoration: none; padding: 10px 20px; display: flex; align-items: center; gap: 8px;">
                ← Voltar ao Dashboard
            </a>
        </header>

        <form method="GET" action="admin_logs.php" class="filter-bar">
            <div class="filter-group">
                <label class="filter-label">Data</label>
                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>" class="input-base" style="width: 140px;">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">Nível</label>
                <select name="severity" class="input-base" style="width: 140px;">
                    <option value="">Todos</option>
                    <option value="INFO" <?php echo $filter_severity === 'INFO' ? 'selected' : ''; ?>>INFO</option>
                    <option value="WARNING" <?php echo $filter_severity === 'WARNING' ? 'selected' : ''; ?>>WARNING</option>
                    <option value="CRITICAL" <?php echo $filter_severity === 'CRITICAL' ? 'selected' : ''; ?>>CRITICAL</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tipo de Ação</label>
                <select name="action" class="input-base" style="min-width: 180px;">
                    <option value="">Todas as Ações</option>
                    <?php foreach ($available_actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $filter_action === $act ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($act); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group" style="flex: 1; min-width: 250px;">
                <label class="filter-label">Pesquisa (Usuário, Entidade, IP)</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_text); ?>" placeholder="Ex: joao_indie, 192.168.1.1, games..." class="input-base" style="width: 100%;">
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-action btn-approve" style="padding: 0 24px; height: 46px; font-size: 14px;">Buscar</button>
                <a href="admin_logs.php" class="btn-action btn-neutral" style="padding: 0 24px; height: 46px; text-decoration: none; display: flex; align-items: center; font-size: 14px;">Limpar</a>
            </div>
        </form>

        <div class="table-container active">
            <table class="logs-table">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Nível</th>
                        <th>Usuário</th>
                        <th>Ação</th>
                        <th>Entidade Afetada</th>
                        <th style="text-align: center;">Dados (JSON)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs->num_rows > 0): ?>
                        <?php while ($log = $logs->fetch_assoc()): ?>
                            <tr>
                                <td style="color: #64748b; font-family: monospace; font-size: 13px; white-space: nowrap;">
                                    <?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                                </td>
                                
                                <td>
                                    <?php 
                                        $badge = 'badge-player'; // Fallback
                                        if ($log['severity'] === 'INFO') $badge = 'badge-active';     
                                        if ($log['severity'] === 'WARNING') $badge = 'badge-pending'; 
                                        if ($log['severity'] === 'CRITICAL') $badge = 'badge-inactive';
                                    ?>
                                    <span class="badge <?php echo $badge; ?>">
                                        <?php echo htmlspecialchars($log['severity']); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <div class="user-cell">
                                        <?php if ($log['user_id']): ?>
                                            <img src="<?php echo htmlspecialchars($log['avatar_url'] ?: 'https://api.dicebear.com/7.x/pixel-art/svg?seed=Anon'); ?>" alt="Avatar" class="user-avatar">
                                            <div>
                                                <span class="user-name"><?php echo htmlspecialchars($log['display_name']); ?></span>
                                                <span class="user-nick">@<?php echo htmlspecialchars($log['username']); ?> • IP: <?php echo htmlspecialchars($log['ip_address'] ?: '?'); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div class="user-avatar" style="display:flex; align-items:center; justify-content:center; border-color: #64748b; background: transparent;">🤖</div>
                                            <div>
                                                <span class="user-name" style="color: #94a3b8;">Sistema / Visitante</span>
                                                <span class="user-nick">IP: <?php echo htmlspecialchars($log['ip_address'] ?: '?'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td>
                                    <span style="font-weight: 600; color: #fff; font-size: 13px; font-family: monospace;">
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <?php if ($log['entity_table']): ?>
                                        Tb: <?php echo htmlspecialchars($log['entity_table']); ?><br>
                                        <span style="color: #64748b; font-size: 12px;">ID: #<?php echo htmlspecialchars($log['entity_id'] ?: 'N/A'); ?></span>
                                    <?php else: ?>
                                        <span style="opacity: 0.3;">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td style="text-align: center;">
                                    <?php 
                                        $hasData = (!empty($log['old_data']) || !empty($log['new_data']));
                                        if ($hasData): 
                                    ?>
                                        <button type="button" class="btn-action btn-neutral" 
                                            data-old="<?php echo htmlspecialchars($log['old_data'] ?: '{}', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-new="<?php echo htmlspecialchars($log['new_data'] ?: '{}', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-action="<?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="openPayloadModal(this)" 
                                            style="padding: 6px 12px;">
                                            Ver Dados
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 12px;">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 60px 20px; color: #64748b;">
                                Nenhum log encontrado para os filtros selecionados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal-overlay" id="payloadModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">🔍 Detalhes do Payload: <span id="modalActionName" style="color: #fff; font-family: monospace; font-size: 16px; margin-left: 8px;"></span></h3>
                <button class="btn-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="json-panel">
                    <h4>Estado Anterior (Old Data)</h4>
                    <pre class="json-viewer" id="modalOldData" style="border-left: 3px solid #ef4444;"></pre>
                </div>
                <div class="json-panel">
                    <h4>Estado Atual (New Data)</h4>
                    <pre class="json-viewer" id="modalNewData" style="border-left: 3px solid #22c55e;"></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openPayloadModal(btn) {
            // Puxa os dados com segurança absoluta direto do HTML
            const oldStr = btn.getAttribute('data-old');
            const newStr = btn.getAttribute('data-new');
            const actionName = btn.getAttribute('data-action');
            
            document.getElementById('modalActionName').innerText = actionName;
            
            const formatJSON = (str) => {
                try {
                    if (!str || str === '{}') return 'Nenhum dado registrado.';
                    return JSON.stringify(JSON.parse(str), null, 4);
                } catch (e) {
                    // Se falhar (ex: aspas soltas no erro do SQL), mostra o texto cru
                    return 'Dado não formatável:\n\n' + str;
                }
            };

            document.getElementById('modalOldData').innerText = formatJSON(oldStr);
            document.getElementById('modalNewData').innerText = formatJSON(newStr);
            
            document.getElementById('payloadModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('payloadModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        document.getElementById('payloadModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>