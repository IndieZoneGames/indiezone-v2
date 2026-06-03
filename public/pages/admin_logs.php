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
    <link rel="stylesheet" href="../assets/css/admin_logs.css">
</head>
<body>
    <div class="admin-container">
        
        <header class="admin-header">
            <h1 class="admin-title">Auditoria de Sistema</h1>
            <a href="admin.php" class="btn-action btn-neutral btn-back">
                ← Voltar ao Dashboard
            </a>
        </header>

        <form method="GET" action="admin_logs.php" class="filter-bar">
            <div class="filter-group">
                <label class="filter-label">Data</label>
                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>" class="input-base w-140">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">Nível</label>
                <select name="severity" class="input-base w-140">
                    <option value="">Todos</option>
                    <option value="INFO" <?php echo $filter_severity === 'INFO' ? 'selected' : ''; ?>>INFO</option>
                    <option value="WARNING" <?php echo $filter_severity === 'WARNING' ? 'selected' : ''; ?>>WARNING</option>
                    <option value="CRITICAL" <?php echo $filter_severity === 'CRITICAL' ? 'selected' : ''; ?>>CRITICAL</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tipo de Ação</label>
                <select name="action" class="input-base w-180">
                    <option value="">Todas as Ações</option>
                    <?php foreach ($available_actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $filter_action === $act ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($act); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group filter-group-fluid">
                <label class="filter-label">Pesquisa (Usuário, Entidade, IP)</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_text); ?>" placeholder="Ex: joao_indie, 192.168.1.1, games..." class="input-base">
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn-action btn-approve btn-search">Buscar</button>
                <a href="admin_logs.php" class="btn-action btn-neutral btn-clear">Limpar</a>
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
                        <th class="text-center">Dados (JSON)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs->num_rows > 0): ?>
                        <?php while ($log = $logs->fetch_assoc()): ?>
                            <tr>
                                <td class="cell-time">
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
                                            <div class="user-avatar user-avatar-bot">🤖</div>
                                            <div>
                                                <span class="user-name-sys">Sistema / Visitante</span>
                                                <span class="user-nick">IP: <?php echo htmlspecialchars($log['ip_address'] ?: '?'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td class="cell-action">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </td>
                                
                                <td>
                                    <?php if ($log['entity_table']): ?>
                                        Tb: <?php echo htmlspecialchars($log['entity_table']); ?><br>
                                        <span class="entity-id">ID: #<?php echo htmlspecialchars($log['entity_id'] ?: 'N/A'); ?></span>
                                    <?php else: ?>
                                        <span class="entity-null">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center">
                                    <?php 
                                        $hasData = (!empty($log['old_data']) || !empty($log['new_data']));
                                        if ($hasData): 
                                    ?>
                                        <button type="button" class="btn-action btn-neutral" 
                                            data-old="<?php echo htmlspecialchars($log['old_data'] ?: '{}', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-new="<?php echo htmlspecialchars($log['new_data'] ?: '{}', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-action="<?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="openPayloadModal(this)">
                                            Ver Dados
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted-sm">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-table">
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
                <h3 class="modal-title">
                    🔍 Detalhes do Payload: 
                    <span id="modalActionName" class="modal-action-name"></span>
                </h3>
                <button class="btn-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="json-panel">
                    <h4>Estado Anterior (Old Data)</h4>
                    <pre class="json-viewer json-old" id="modalOldData"></pre>
                </div>
                <div class="json-panel">
                    <h4>Estado Atual (New Data)</h4>
                    <pre class="json-viewer json-new" id="modalNewData"></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openPayloadModal(btn) {
            const oldStr = btn.getAttribute('data-old');
            const newStr = btn.getAttribute('data-new');
            const actionName = btn.getAttribute('data-action');
            
            document.getElementById('modalActionName').innerText = actionName;
            
            const formatJSON = (str) => {
                try {
                    if (!str || str === '{}') return 'Nenhum dado registrado.';
                    return JSON.stringify(JSON.parse(str), null, 4);
                } catch (e) {
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