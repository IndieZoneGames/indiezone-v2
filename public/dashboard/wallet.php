<?php
// public/dashboard/wallet.php

// [ARQUITETURA] Inicializa o painel importando a proteção de rota.
require_once("includes/dev_header.php");
/** @var mysqli $conn */

$message = '';
$error = '';

// Taxa da plataforma (Exemplo: 10%)
$platform_fee_percentage = 0.10; 

// 1. PROCESSAR PEDIDO DE SAQUE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'withdraw') {
    
    $amount_requested = floatval($_POST['amount']);
    $payment_method = trim($_POST['payment_method']);
    
    // [CORREÇÃO CRÍTICA]: A Transação começa ANTES do SELECT para usar o FOR UPDATE.
    $conn->begin_transaction();
    try {
        // [CORREÇÃO CRÍTICA]: Pessimistic Locking. O 'FOR UPDATE' tranca a linha do usuário no banco. 
        // Se houver 2 requisições simultâneas de saque, a segunda aguarda a primeira terminar. Previne saques duplicados.
        $stmt_balance = $conn->prepare("SELECT wallet_balance FROM users WHERE user_id = ? FOR UPDATE");
        $stmt_balance->bind_param("i", $user_id);
        $stmt_balance->execute();
        $current_balance = $stmt_balance->get_result()->fetch_assoc()['wallet_balance'] ?? 0.00;

        // [CORREÇÃO CRÍTICA]: Tratamento de Ponto Flutuante (IEEE 754).
        // Convertendo para centavos inteiros para evitar bloqueios onde 50.00 é lido como 49.999999
        $amount_cents = intval(round($amount_requested * 100));
        $balance_cents = intval(round($current_balance * 100));

        if ($amount_cents < 5000) {
            throw new Exception("O valor mínimo para saque é de R$ 50,00.");
        } elseif ($amount_cents > $balance_cents) {
            throw new Exception("Saldo insuficiente. Tentou sacar mais do que tem disponível.");
        } elseif (empty($payment_method)) {
            throw new Exception("Por favor, informe a sua chave PIX ou e-mail do PayPal.");
        }
        
        // [CORREÇÃO CRÍTICA]: Dedução de saldo segura relativa no próprio banco.
        // O WHERE wallet_balance >= ? adiciona uma camada final anti-overdraft.
        $stmt_update = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE user_id = ? AND wallet_balance >= ?");
        $stmt_update->bind_param("did", $amount_requested, $user_id, $amount_requested);
        $stmt_update->execute();
        
        if ($stmt_update->affected_rows === 0) {
            throw new Exception("Erro de concorrência. A transação foi abortada por segurança.");
        }

        // Registrar o pedido na tabela withdrawals
        $stmt_withdraw = $conn->prepare("INSERT INTO withdrawals (developer_id, amount, payment_details, status) VALUES (?, ?, ?, 'pending')");
        $stmt_withdraw->bind_param("ids", $user_id, $amount_requested, $payment_method);
        $stmt_withdraw->execute();

        $conn->commit();
        $message = "✅ Pedido de saque de R$ " . number_format($amount_requested, 2, ',', '.') . " realizado com sucesso! Aguarde o processamento.";
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

// 2. BUSCAR DADOS PARA A TELA
$stmt_balance = $conn->prepare("SELECT wallet_balance FROM users WHERE user_id = ?");
$stmt_balance->bind_param("i", $user_id);
$stmt_balance->execute();
$current_balance = $stmt_balance->get_result()->fetch_assoc()['wallet_balance'] ?? 0.00;

$pending_withdrawals = 0.00;
$stmt_pending = $conn->prepare("SELECT SUM(amount) as total_pending FROM withdrawals WHERE developer_id = ? AND status = 'pending'");
$stmt_pending->bind_param("i", $user_id);
$stmt_pending->execute();
$res_pending = $stmt_pending->get_result()->fetch_assoc();
if ($res_pending && $res_pending['total_pending']) {
    $pending_withdrawals = $res_pending['total_pending'];
}

$total_earned_gross = 0.00;
$stmt_total = $conn->prepare("SELECT SUM(t.amount) as total_gross FROM transactions t JOIN games g ON t.game_id = g.game_id WHERE g.developer_id = ? AND t.status = 'completed'");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$res_total = $stmt_total->get_result()->fetch_assoc();
if ($res_total && $res_total['total_gross']) {
    $total_earned_gross = $res_total['total_gross'];
}

$recent_sales = [];
$stmt_sales = $conn->prepare("
    SELECT t.amount as gross_amount, t.created_at, g.title 
    FROM transactions t
    JOIN games g ON t.game_id = g.game_id
    WHERE g.developer_id = ? AND t.status = 'completed'
    ORDER BY t.created_at DESC LIMIT 10
");
if ($stmt_sales) {
    $stmt_sales->bind_param("i", $user_id);
    $stmt_sales->execute();
    $result_sales = $stmt_sales->get_result();
    while ($row = $result_sales->fetch_assoc()) {
        $recent_sales[] = $row;
    }
}

$recent_withdrawals = [];
$stmt_with_hist = $conn->prepare("SELECT amount, payment_details, status, created_at FROM withdrawals WHERE developer_id = ? ORDER BY created_at DESC LIMIT 10");
if ($stmt_with_hist) {
    $stmt_with_hist->bind_param("i", $user_id);
    $stmt_with_hist->execute();
    $result_with = $stmt_with_hist->get_result();
    while ($row = $result_with->fetch_assoc()) {
        $recent_withdrawals[] = $row;
    }
}

// [CORREÇÃO UX]: Lógica para ativar ou desativar os campos de formulário baseada no saldo.
$can_withdraw = ($current_balance >= 50);
?>

<main class="dashboard-main" style="padding-bottom: 80px;">
    
    <header class="dash-header">
        <div class="dash-title">
            <h1>Financeiro & Vendas 💰</h1>
            <p>Acompanhe o desempenho dos seus jogos, saldo disponível e histórico de repasses.</p>
        </div>
    </header>

    <?php if (!empty($message)): ?>
        <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); color: #4ade80; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
        <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
            ⚠️ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); margin-bottom: 40px;">
        <div class="stat-card" style="background: linear-gradient(145deg, #1e293b, #0f172a); border-color: var(--primary);">
            <div class="stat-title" style="color: #fff;">Saldo Disponível (Líquido)</div>
            <div class="stat-value" style="color: var(--primary);">R$ <?php echo number_format($current_balance, 2, ',', '.'); ?></div>
            <div class="stat-trend positive">Liberado para saque</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-title">Saques em Processamento</div>
            <div class="stat-value">R$ <?php echo number_format($pending_withdrawals, 2, ',', '.'); ?></div>
            <div class="stat-trend" style="color: #facc15;">Aguardando equipe financeira</div>
        </div>

        <div class="stat-card" style="opacity: 0.8;">
            <div class="stat-title">Total Arrecadado (Bruto)</div>
            <div class="stat-value" style="font-size: 24px;">R$ <?php echo number_format($total_earned_gross, 2, ',', '.'); ?></div>
            <div class="stat-trend">Soma de todas as vendas geradas</div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px; align-items: start;">
        
        <div class="dev-form-card" style="margin: 0; position: sticky; top: 20px;">
            <h3 class="section-title">Solicitar Saque</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                O valor mínimo para saque é de R$ 50,00. Os repasses são processados na conta informada em até 5 dias úteis.
            </p>
            
            <form method="POST">
                <input type="hidden" name="action" value="withdraw">
                
                <div class="form-group">
                    <label>Valor a Sacar (R$)</label>
                    <!-- [CORREÇÃO UX]: Só insere o max="" e o required se puder sacar. Caso contrário, desativa o campo. -->
                    <input type="number" name="amount" step="0.01" min="50.00" 
                           <?php echo $can_withdraw ? 'max="' . $current_balance . '" required' : 'disabled'; ?>
                           placeholder="Ex: 150.00" style="font-size: 18px; font-weight: bold; color: var(--primary);">
                </div>
                
                <div class="form-group">
                    <label>Dados para Recebimento (PIX ou PayPal)</label>
                    <input type="text" name="payment_method" 
                           <?php echo $can_withdraw ? 'required' : 'disabled'; ?> 
                           placeholder="Digite sua chave PIX ou e-mail...">
                </div>
                
                <button type="submit" class="btn-submit-dev" <?php echo !$can_withdraw ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                    💸 Confirmar Saque
                </button>
            </form>
        </div>

        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <div class="content-section" style="margin: 0;">
                <h2 style="margin-top: 0; font-size: 20px;">Transações Recentes</h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">A taxa atual de serviço da IndieZone é de <?php echo ($platform_fee_percentage * 100); ?>% sobre as vendas.</p>
                
                <div class="table-container">
                    <table class="builds-table" style="width: 100%; text-align: left; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); font-size: 13px;">
                                <th style="padding: 12px; color: var(--text-muted);">Jogo</th>
                                <th style="padding: 12px; color: var(--text-muted);">Data</th>
                                <th style="padding: 12px; color: var(--text-muted);">Venda (Bruto)</th>
                                <th style="padding: 12px; color: var(--text-muted);">Taxa</th>
                                <th style="padding: 12px; color: var(--text-muted);">Seu Repasse</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 14px;">
                            <?php if (empty($recent_sales)): ?>
                                <tr>
                                    <td colspan="5" style="padding: 20px; text-align: center; color: var(--text-muted);">Ainda não há vendas registradas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_sales as $sale): 
                                    $gross = $sale['gross_amount'];
                                    $fee = $gross * $platform_fee_percentage;
                                    $net = $gross - $fee;
                                ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 12px; font-weight: bold; color: #fff;"><?php echo htmlspecialchars($sale['title']); ?></td>
                                        <td style="padding: 12px; opacity: 0.8;"><?php echo date('d/m/Y H:i', strtotime($sale['created_at'])); ?></td>
                                        <td style="padding: 12px;">R$ <?php echo number_format($gross, 2, ',', '.'); ?></td>
                                        <td style="padding: 12px; color: #ef4444;">- R$ <?php echo number_format($fee, 2, ',', '.'); ?></td>
                                        <td style="padding: 12px; color: #4ade80; font-weight: bold;">+ R$ <?php echo number_format($net, 2, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="content-section" style="margin: 0;">
                <h2 style="margin-top: 0; font-size: 20px;">Histórico de Saques</h2>
                
                <div class="table-container">
                    <table class="builds-table" style="width: 100%; text-align: left; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); font-size: 13px;">
                                <th style="padding: 12px; color: var(--text-muted);">Data da Solicitação</th>
                                <th style="padding: 12px; color: var(--text-muted);">Destino</th>
                                <th style="padding: 12px; color: var(--text-muted);">Valor</th>
                                <th style="padding: 12px; color: var(--text-muted);">Status</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 14px;">
                            <?php if (empty($recent_withdrawals)): ?>
                                <tr>
                                    <td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">Nenhum pedido de saque realizado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_withdrawals as $with): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 12px; opacity: 0.8;"><?php echo date('d/m/Y H:i', strtotime($with['created_at'])); ?></td>
                                        <td style="padding: 12px; opacity: 0.8;"><?php echo htmlspecialchars($with['payment_details']); ?></td>
                                        <td style="padding: 12px; font-weight: bold;">R$ <?php echo number_format($with['amount'], 2, ',', '.'); ?></td>
                                        <td style="padding: 12px;">
                                            <?php 
                                                if ($with['status'] == 'completed') echo '<span style="color: #4ade80; background: rgba(74, 222, 128, 0.1); padding: 4px 8px; border-radius: 4px;">Concluído</span>';
                                                elseif ($with['status'] == 'pending') echo '<span style="color: #facc15; background: rgba(250, 204, 21, 0.1); padding: 4px 8px; border-radius: 4px;">Processando</span>';
                                                else echo '<span style="color: #ef4444; background: rgba(239, 68, 68, 0.1); padding: 4px 8px; border-radius: 4px;">Rejeitado</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</main>
</body>
</html>