<?php
// public/dashboard/wallet.php

// [ARQUITETURA] Inicializa o painel importando a proteção de rota. Apenas contas validadas com a função (role) de 'dev' ou 'admin' conseguem acessar esta camada financeira do sistema.
require_once("includes/dev_header.php");
/** @var mysqli $conn */

$message = '';
$error = '';

// Taxa da plataforma (Exemplo: 10%)
// [LÓGICA] Definição centralizada da regra de negócio (Take Rate). Fixar a taxa no topo do controlador facilita manutenções futuras, sendo o primeiro passo antes de mover esse dado para uma tabela dinâmica de configurações do sistema.
$platform_fee_percentage = 0.10; 

// 1. PROCESSAR PEDIDO DE SAQUE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'withdraw') {
    
    // [SEGURANÇA] Tipagem rigorosa (Type Casting). Forçar a conversão do valor solicitado para 'floatval' elimina completamente o risco de injeção de strings ou caracteres nocivos em operações matemáticas críticas.
    $amount_requested = floatval($_POST['amount']);
    $payment_method = trim($_POST['payment_method']);
    
    // Buscar o saldo atual
    // [SEGURANÇA] Bloqueio contra manipulação de requisição. O sistema jamais confia no saldo enviado pelo frontend (HTML). Ele faz uma nova consulta no banco (usando o 'user_id' inalterável da sessão) no exato milissegundo do pedido.
    $stmt_balance = $conn->prepare("SELECT wallet_balance FROM users WHERE user_id = ?");
    $stmt_balance->bind_param("i", $user_id);
    $stmt_balance->execute();
    $current_balance = $stmt_balance->get_result()->fetch_assoc()['wallet_balance'] ?? 0.00;

    // [LÓGICA] Prevenção contra Overdraft (Saldo Devedor). Validações rigorosas garantem que o usuário obedeça ao piso de saque e não tente retirar fundos que não possui, poupando processamento de transações inválidas.
    if ($amount_requested < 50.00) {
        $error = "O valor mínimo para saque é de R$ 50,00.";
    } elseif ($amount_requested > $current_balance) {
        $error = "Saldo insuficiente. Você tentou sacar mais do que possui disponível.";
    } elseif (empty($payment_method)) {
        $error = "Por favor, informe a sua chave PIX ou e-mail do PayPal.";
    } else {
        
        // [AUDITORIA] Início da Transação Financeira (ACID). Esta é a proteção mais vital do arquivo. A dedução do saldo e a criação do recibo de saque precisam ocorrer como um bloco indivisível.
        $conn->begin_transaction();
        try {
            // Deduzir o valor da carteira do usuário
            $new_balance = $current_balance - $amount_requested;
            $stmt_update = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE user_id = ?");
            $stmt_update->bind_param("di", $new_balance, $user_id);
            $stmt_update->execute();

            // Registrar o pedido na tabela withdrawals
            // [AUDITORIA] O pedido nasce com status 'pending'. Isso cria um rastro documentado da intenção do usuário e congela o dinheiro, aguardando que a equipe de auditoria financeira aprove o repasse.
            $stmt_withdraw = $conn->prepare("INSERT INTO withdrawals (developer_id, amount, payment_details, status) VALUES (?, ?, ?, 'pending')");
            $stmt_withdraw->bind_param("ids", $user_id, $amount_requested, $payment_method);
            $stmt_withdraw->execute();

            // [AUDITORIA] Se ambas as queries rodaram sem erros, a transação é efetivada (Commit).
            $conn->commit();
            $message = "✅ Pedido de saque de R$ " . number_format($amount_requested, 2, ',', '.') . " realizado com sucesso! Aguarde o processamento.";
        } catch (Exception $e) {
            // [AUDITORIA] Se o servidor cair logo após deduzir o saldo, mas antes de gerar o recibo, o Rollback entra em ação. Ele cancela tudo, devolvendo o dinheiro ao usuário e evitando fraudes ou perdas não intencionais de fundos.
            $conn->rollback();
            $error = "Erro ao processar o saque. Tente novamente.";
        }
    }
}

// 2. BUSCAR DADOS PARA A TELA
// Saldo Atual
$stmt_balance = $conn->prepare("SELECT wallet_balance FROM users WHERE user_id = ?");
$stmt_balance->bind_param("i", $user_id);
$stmt_balance->execute();
$current_balance = $stmt_balance->get_result()->fetch_assoc()['wallet_balance'] ?? 0.00;

// Histórico de Saques Pendentes (Soma)
$pending_withdrawals = 0.00;
$stmt_pending = $conn->prepare("SELECT SUM(amount) as total_pending FROM withdrawals WHERE developer_id = ? AND status = 'pending'");
$stmt_pending->bind_param("i", $user_id);
$stmt_pending->execute();
$res_pending = $stmt_pending->get_result()->fetch_assoc();
if ($res_pending && $res_pending['total_pending']) {
    $pending_withdrawals = $res_pending['total_pending'];
}

// Total Arrecadado (Vendas brutas totais do dev)
// [SEGURANÇA] Proteção IDOR em queries complexas. Ao usar JOIN, amarramos a tabela de transações aos jogos que comprovadamente pertencem ao desenvolvedor ativo (g.developer_id = ?). Impede a leitura de dados financeiros de outros estúdios.
$total_earned_gross = 0.00;
$stmt_total = $conn->prepare("SELECT SUM(t.amount) as total_gross FROM transactions t JOIN games g ON t.game_id = g.game_id WHERE g.developer_id = ? AND t.status = 'completed'");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$res_total = $stmt_total->get_result()->fetch_assoc();
if ($res_total && $res_total['total_gross']) {
    $total_earned_gross = $res_total['total_gross'];
}

// Histórico de Vendas (Últimas 10) usando a tabela transactions
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

// Histórico de Saques (Últimos 10) da tabela withdrawals
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
                    <!-- [LÓGICA] Trava de interface baseada em dados reais. O atributo 'max' limita o input com base na variável $current_balance, criando uma primeira barreira no lado do cliente (UX), antes mesmo da validação estrita que já implementamos no servidor (PHP). -->
                    <input type="number" name="amount" step="0.01" min="50.00" max="<?php echo $current_balance; ?>" required placeholder="Ex: 150.00" style="font-size: 18px; font-weight: bold; color: var(--primary);">
                </div>
                
                <div class="form-group">
                    <label>Dados para Recebimento (PIX ou PayPal)</label>
                    <input type="text" name="payment_method" required placeholder="Digite sua chave PIX ou e-mail...">
                </div>
                
                <!-- [LÓGICA] Componente reativo simples: se o usuário não atingiu o limite mínimo, o botão é inativado na View, evitando falsas esperanças e submissões incorretas. -->
                <button type="submit" class="btn-submit-dev" <?php echo ($current_balance < 50) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
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
                                    // [AUDITORIA] O faturamento é auditável por exibir transparência ponta-a-ponta (Valor Bruto -> Desconto da Taxa -> Valor Líquido), permitindo que o estúdio cruze esses dados com o saldo da carteira com precisão matemática.
                                    $gross = $sale['gross_amount'];
                                    $fee = $gross * $platform_fee_percentage;
                                    $net = $gross - $fee;
                                ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <!-- [SEGURANÇA] Como nomes de jogos podem ser manipulados, o output passa por 'htmlspecialchars' para impedir a execução de códigos maliciosos inseridos no banco (XSS). -->
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
                                        <!-- [SEGURANÇA] Dados preenchidos livremente pelo usuário (como PIX ou E-mail) são neutralizados via 'htmlspecialchars' na renderização do histórico. -->
                                        <td style="padding: 12px; opacity: 0.8;"><?php echo htmlspecialchars($with['payment_details']); ?></td>
                                        <td style="padding: 12px; font-weight: bold;">R$ <?php echo number_format($with['amount'], 2, ',', '.'); ?></td>
                                        <td style="padding: 12px;">
                                            <?php 
                                                // [LÓGICA] Mapeamento direto do status do banco (pendente, concluído, rejeitado) para identificadores visuais que traduzem a movimentação financeira para o dono do estúdio.
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