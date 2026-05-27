<?php
// public/dashboard/my_games.php

// [ARQUITETURA] A inclusão do cabeçalho consolida a verificação de sessão e o controle de acesso (RBAC). 
// Qualquer requisição sem privilégios de desenvolvedor é interceptada e expulsa antes mesmo desta página iniciar consultas no banco de dados.
require_once("includes/dev_header.php");
/** @var mysqli $conn */
/** @var mysqli $user_id */


// Buscar todos os jogos do desenvolvedor
// [SEGURANÇA] Mitigação de IDOR (Insecure Direct Object Reference) e SQL Injection (via Prepared Statements). A consulta filtra o catálogo exigindo que o 'developer_id' corresponda exatamente ao '$user_id' validado em sessão, impedindo que um estúdio liste os jogos de outro.
// [AUDITORIA] A ordenação por 'created_at DESC' gera uma linha do tempo natural. O sistema prioriza os registros mais recentes, construindo um rastro cronológico fiel das atividades de criação do estúdio.
$stmt_games = $conn->prepare("SELECT * FROM games WHERE developer_id = ? ORDER BY created_at DESC");
$stmt_games->bind_param("i", $user_id);
$stmt_games->execute();
$result_games = $stmt_games->get_result();
$total_games = $result_games->num_rows;
?>

<main class="dashboard-main" style="padding-bottom: 80px;">
    
    <header class="dash-header" style="margin-bottom: 40px;">
        <div class="dash-title">
            <h1 style="font-size: 32px; font-weight: 800; color: #fff;">Meus Projetos</h1>
            <p>Gerencie seu catálogo de jogos, atualize vitrines e envie novas builds.</p>
        </div>
        <a href="add_game.php" class="btn-upload" style="padding: 14px 24px; font-size: 15px;">+ Novo Jogo</a>
    </header>

    <div class="dev-form-card" style="max-width: 100%; padding: 30px;">
        
        <?php if ($total_games === 0): ?>
            <!-- [LÓGICA] Padrão de "Empty State" (Estado Vazio). Em vez de mostrar uma tabela quebrada ou sem dados, o sistema oferece um atalho direto para a criação de um novo jogo (Call to Action), melhorando o fluxo de navegação do desenvolvedor recém-cadastrado. -->
            <div style="text-align: center; padding: 60px 20px; border: 1px dashed rgba(255,255,255,0.1); border-radius: 12px; background: rgba(0,0,0,0.2);">
                <span style="font-size: 48px; opacity: 0.5;">🎮</span>
                <h3 style="color: #fff; margin: 16px 0 8px 0;">Sua biblioteca está vazia</h3>
                <p style="color: var(--text-muted); margin-bottom: 24px;">Comece a compartilhar seu universo com a comunidade.</p>
                <a href="add_game.php" class="btn-upload" style="display: inline-block;">Publicar Primeiro Jogo</a>
            </div>
        <?php else: ?>
            
            <div style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr style="background: rgba(0,0,0,0.4);">
                            <th style="padding: 16px 20px; font-size: 12px; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.05);">Projeto</th>
                            <th style="padding: 16px 20px; font-size: 12px; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.05);">Fase</th>
                            <th style="padding: 16px 20px; font-size: 12px; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.05);">Status</th>
                            <th style="padding: 16px 20px; font-size: 12px; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.05);">Preço</th>
                            <th style="padding: 16px 20px; font-size: 12px; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($game = $result_games->fetch_assoc()): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.02); transition: background 0.2s;">
                                <td style="padding: 16px 20px; display: flex; align-items: center; gap: 16px;">
                                    <!-- [SEGURANÇA] Sanitização mandatória contra Cross-Site Scripting (Stored XSS). Como a imagem e o título são inputs anteriores do banco de dados, o 'htmlspecialchars' impede a renderização e execução de scripts maliciosos injetados previamente. -->
                                    <img src="<?php echo htmlspecialchars($game['cover_image_url'] ?: '../assets/img/placeholder-game.png'); ?>" alt="Capa" style="width: 80px; height: 45px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1);">
                                    <div>
                                        <strong style="color: #fff; font-size: 15px; display: block;"><?php echo htmlspecialchars($game['title']); ?></strong>
                                        <span style="color: var(--text-muted); font-size: 12px;">Criado em <?php echo date('d/m/Y', strtotime($game['created_at'])); ?></span>
                                    </div>
                                </td>
                                
                                <td style="padding: 16px 20px; color: #cbd5e1; font-size: 13px;">
                                    <!-- [LÓGICA] Tradução de Enums do banco. Transforma as chaves curtas e técnicas armazenadas no banco em rótulos compreensíveis para o usuário final, utilizando arrays de mapeamento. -->
                                    <?php 
                                        $fases = [
                                            'alpha' => 'Alpha', 
                                            'beta' => 'Beta', 
                                            'early_access' => 'Acesso Antecipado', 
                                            'full_release' => 'Lançamento Final'
                                        ];
                                        echo $fases[$game['release_stage']] ?? 'Não definido';
                                    ?>
                                </td>

                                <td style="padding: 16px 20px;">
                                    <!-- [LÓGICA] Mapeamento visual das flags do banco para badges na interface. Isso ajuda o estúdio a auditar rapidamente a situação do catálogo (saber o que está trancado em moderação, o que já foi publicado ou o que ainda é um rascunho). -->
                                    <?php if ($game['status'] == 'draft'): ?>
                                        <span style="background: rgba(148, 163, 184, 0.1); color: #94a3b8; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; border: 1px solid rgba(148, 163, 184, 0.2);">Rascunho</span>
                                    <?php elseif ($game['status'] == 'pending'): ?>
                                        <span style="background: rgba(250, 204, 21, 0.1); color: #facc15; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; border: 1px solid rgba(250, 204, 21, 0.2);">Em Análise</span>
                                    <?php elseif ($game['status'] == 'published'): ?>
                                        <span style="background: rgba(34, 197, 94, 0.15); color: #4ade80; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; border: 1px solid rgba(34, 197, 94, 0.3);">Publicado</span>
                                    <?php elseif ($game['status'] == 'archived'): ?>
                                        <span style="background: rgba(239, 68, 68, 0.1); color: #f87171; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; border: 1px solid rgba(239, 68, 68, 0.2);">Arquivado</span>
                                    <?php endif; ?>
                                </td>

                                <td style="padding: 16px 20px; font-size: 14px; font-weight: 600; color: #fff;">
                                    <!-- [LÓGICA] Resolução da árvore de decisão de monetização. Combina a checagem dupla do modelo de "Pague o que Quiser" (PWYW) e do valor estrito para imprimir a formatação correta de preço na tela. -->
                                    <?php 
                                        if ($game['price'] == 0 && $game['is_pwyw'] == 0) {
                                            echo '<span style="color: var(--primary);">Grátis</span>';
                                        } elseif ($game['is_pwyw'] == 1) {
                                            echo '<span style="color: #60a5fa;">Apoio (Mín. R$ ' . number_format($game['min_price'], 2, ',', '.') . ')</span>';
                                        } else {
                                            echo 'R$ ' . number_format($game['price'], 2, ',', '.');
                                        }
                                    ?>
                                </td>

                                <td style="padding: 16px 20px; text-align: right; display: flex; gap: 8px; justify-content: flex-end;">
                                    <a href="edit_game.php?id=<?php echo $game['game_id']; ?>" style="padding: 8px 16px; background: rgba(255,255,255,0.05); color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; transition: 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                                        Editar
                                    </a>
                                    <a href="manage_builds.php?id=<?php echo $game['game_id']; ?>" style="padding: 8px 16px; background: rgba(34, 197, 94, 0.15); color: #4ade80; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; transition: 0.2s;" onmouseover="this.style.background='rgba(34, 197, 94, 0.25)'" onmouseout="this.style.background='rgba(34, 197, 94, 0.15)'">
                                        Arquivos
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
        <?php endif; ?>
    </div>
</main>

</body>
</html>