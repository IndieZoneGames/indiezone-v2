<?php
session_start();
require_once("../../core/config.php");
/** @var mysqli $conn */

// [SEGURANÇA] Controle de Acesso Estrito (RBAC). O sistema protege a rota barrando usuários não autenticados.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// [ARQUITETURA] Proteção de fluxo de negócio. Se o usuário já possui o cargo (role) de 'dev' ou 'admin', a aplicação entende que ele já passou por este processo e o redireciona para o dashboard, evitando requisições duplicadas ou concorrência de dados.
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'player') {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Resgata os erros e dados antigos da sessão
// [LÓGICA] Padrão de "Flash Messages" e Manutenção de Estado. O sistema intercepta erros gerados pelo backend (process_become_dev.php) e recupera os dados previamente digitados pelo usuário ($old) para evitar a frustração de ter que preencher tudo de novo em caso de falha.
$error_field = $_SESSION['form_error'] ?? null;
$error_msg = $_SESSION['form_error_msg'] ?? null;
$profile_msg = $_SESSION['profile_msg'] ?? null;
$old = $_SESSION['form_data'] ?? [];

// Limpa as variáveis da sessão
// [LÓGICA] O comando 'unset' age imediatamente após o resgate das variáveis. Isso garante que as mensagens de erro ou os dados do formulário sobrevivam a apenas um único carregamento de página, limpando a memória do servidor.
unset($_SESSION['form_error'], $_SESSION['form_error_msg'], $_SESSION['form_data'], $_SESSION['profile_msg']);

// Função auxiliar para manter os valores digitados
function getOld($field, $oldArray) {
    // [SEGURANÇA] Blindagem contra Reflected XSS. Ao repopular o formulário com dados antigos, o 'htmlspecialchars' garante que qualquer tentativa de injeção de script (HTML/JS malicioso) seja renderizada apenas como texto inofensivo.
    return isset($oldArray[$field]) ? htmlspecialchars($oldArray[$field]) : '';
}

// Verifica o status do pedido na tabela 'developers'
// [SEGURANÇA] Prevenção contra IDOR. A consulta de status do estúdio é feita usando exclusivamente a credencial inviolável da sessão ($_SESSION['user_id']) através de Prepared Statements, ignorando qualquer parâmetro que pudesse vir manipulado via GET.
$stmt = $conn->prepare("SELECT approval_status, created_at FROM developers WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$is_pending = false;
$request_date = '';

// [AUDITORIA] Rastreabilidade de Elevação de Privilégios. O sistema detecta se existe um pedido de estúdio em análise ('pending'). Isso trava a interface, cria uma linha do tempo clara exibindo o 'created_at' e evita que o usuário faça spam de solicitações no banco de dados.
if ($result->num_rows > 0) {
    $dev_req = $result->fetch_assoc();
    if ($dev_req['approval_status'] === 'pending') {
        $is_pending = true;
        $request_date = isset($dev_req['created_at']) ? date('d/m/Y', strtotime($dev_req['created_at'])) : 'recentemente';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tornar-se Desenvolvedor - IndieZone</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link rel="stylesheet" href="../assets/css/become_dev.css">
</head>
<body data-theme="dark">

    <div class="form-wrap">
        <div class="form-card">
            
            <?php if ($profile_msg): ?>
                <div style="padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; text-align: center; background: rgba(34, 197, 94, 0.1); border: 1px solid #22c55e; color: #4ade80;">
                    <?php echo $profile_msg; ?>
                </div>
            <?php endif; ?>

            <?php if ($is_pending): ?>
                
                <!-- [ARQUITETURA] Interface Reativa ao Estado. O PHP altera completamente o DOM com base no 'approval_status' do banco, bloqueando o formulário e entregando feedback transparente sobre a auditoria do perfil. -->
                <div style="text-align: center; padding: 20px 0;">
                    <div style="font-size: 48px; margin-bottom: 16px;">⏳</div>
                    <h2 style="color: #facc15; font-size: 24px; margin-bottom: 15px;">Solicitação em Análise</h2>
                    <p style="color: #e2e8f0; margin-top: 15px; line-height: 1.6;">
                        Sua solicitação enviada <strong><?php echo $request_date; ?></strong> está na fila de avaliação da nossa equipe.<br><br>
                        <span style="color: #94a3b8; font-size: 14px;">Aguarde nosso retorno. Nós avisaremos assim que seu painel de estúdio for liberado!</span>
                    </p>
                    <a href="index.php" style="display: inline-block; background: transparent; color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); text-decoration: none; margin-top: 30px; padding: 12px 24px; border-radius: 8px; font-weight: 600; transition: 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='transparent'">Voltar ao Perfil</a>
                </div>

            <?php else: ?>

                <div class="form-header">
                    <h2>Eleve seu Jogo! 🚀</h2>
                    <p>Cadastre seu estúdio e comece a publicar na IndieZone.</p>
                </div>

                <?php if ($error_field === 'global'): ?>
                    <div class="global-error"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <!-- [ARQUITETURA] Separação de Responsabilidades (MVC). O formulário dispara a requisição diretamente para a pasta 'backend', mantendo este arquivo responsável unicamente por renderização e apresentação (View). -->
                <form action="../../src/backend/process_become_dev.php" method="POST" id="devForm">
                    
                    <h3 class="section-title">Dados do Estúdio</h3>
                    
                    <div class="form-group">
                        <label for="studio_name">Nome do Estúdio</label>
                        <input type="text" id="studio_name" name="studio_name" required placeholder="Ex: Pixel Forge Games" value="<?php echo getOld('studio_name', $old); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group form-col">
                            <label for="support_email">E-mail de Suporte</label>
                            <input type="email" id="support_email" name="support_email" required placeholder="contato@seustudio.com" value="<?php echo getOld('support_email', $old); ?>" class="<?php echo ($error_field == 'support_email') ? 'input-error' : ''; ?>">
                            <?php if($error_field == 'support_email') echo "<span class='error-msg'>$error_msg</span>"; ?>
                        </div>
                        
                        <div class="form-group form-col">
                            <label for="document_number">CPF ou CNPJ</label>
                            <!-- [AUDITORIA] Identificação Fiscal. O CPF/CNPJ é tratado de forma rigorosa pois servirá de âncora para os processos financeiros e contratos de repasse ('withdrawals') da plataforma no futuro. -->
                            <input type="text" id="document_number" name="document_number" maxlength="18" required placeholder="Apenas números" value="<?php echo getOld('document_number', $old); ?>" class="<?php echo ($error_field == 'document_number') ? 'input-error' : ''; ?>">
                            <?php if($error_field == 'document_number') echo "<span class='error-msg'>$error_msg</span>"; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="bio">Biografia / Sobre o Estúdio</label>
                        <textarea id="bio" name="bio" rows="3"><?php echo getOld('bio', $old); ?></textarea>
                    </div>

                    <h3 class="section-title">Validação do Estúdio (Curadoria)</h3>
                    
                    <div class="form-group">
                        <label for="portfolio_url">Link do Portfólio (Obrigatório)</label>
                        <input type="url" id="portfolio_url" name="portfolio_url" required placeholder="https://seu-itch-io.com ou GitHub" value="<?php echo getOld('portfolio_url', $old); ?>">
                        <span class="info-text" style="font-size: 12px; color: #64748b; margin-top: 5px; display: block;">Nossa equipe de moderação avaliará seus trabalhos anteriores através deste link.</span>
                    </div>

                    <div class="form-group">
                        <label for="first_game_pitch">Pitch: O que você pretende publicar primeiro?</label>
                        <textarea id="first_game_pitch" name="first_game_pitch" rows="3" required placeholder="Ex: Um jogo de plataforma 2D no estilo Metroidvania feito na Godot Engine..."><?php echo getOld('first_game_pitch', $old); ?></textarea>
                        <span class="info-text" style="font-size: 12px; color: #64748b; margin-top: 5px; display: block;">Explique o conceito do seu jogo. Essa informação é vital para a aprovação.</span>
                    </div>

                    <h3 class="section-title">Endereço Comercial / Faturamento</h3>
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 0.8; position: relative;">
                            <label for="cep">CEP</label>
                            <input type="text" id="cep" name="cep" maxlength="9" placeholder="00000-000" required value="<?php echo getOld('cep', $old); ?>">
                            <span id="cep_error" class="error-msg" style="display:none;"></span>
                        </div>
                        <div class="form-group form-col" style="flex: 2;">
                            <label for="logradouro">Rua / Avenida</label>
                            <input type="text" id="logradouro" name="logradouro" required readonly value="<?php echo getOld('logradouro', $old); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex: 0.8;">
                            <label for="numero">Número</label>
                            <input type="text" id="numero" name="numero" required value="<?php echo getOld('numero', $old); ?>">
                        </div>
                        <div class="form-group form-col">
                            <label for="complemento">Complemento (Opcional)</label>
                            <input type="text" id="complemento" name="complemento" value="<?php echo getOld('complemento', $old); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-col">
                            <label for="bairro">Bairro</label>
                            <input type="text" id="bairro" name="bairro" required readonly value="<?php echo getOld('bairro', $old); ?>">
                        </div>
                        <div class="form-group form-col">
                            <label for="cidade">Cidade</label>
                            <input type="text" id="cidade" name="cidade" required readonly value="<?php echo getOld('cidade', $old); ?>">
                        </div>
                        <div class="form-group" style="flex: 0.4;">
                            <label for="estado">UF</label>
                            <input type="text" id="estado" name="estado" maxlength="2" required readonly value="<?php echo getOld('estado', $old); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">Submeter para Análise</button>
                    <a href="index.php" class="back-link">← Voltar para o Painel</a>
                </form>

            <?php endif; ?>

        </div>
    </div>

    <script>
        // 1. MÁSCARA CPF/CNPJ
        // [LÓGICA] Sanitização de Entrada via Regex. O JavaScript atua como primeira linha de defesa, expurgando caracteres não-numéricos (\D) em tempo real e aplicando a formatação correta de CPF ou CNPJ baseada no tamanho da string digitada.
        document.getElementById('document_number')?.addEventListener('input', function (e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                value = value.replace(/^(\d{2})(\d)/, '$1.$2');
                value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            }
            e.target.value = value;
            
            this.classList.remove('input-error');
            const errorSpan = this.nextElementSibling;
            if(errorSpan && errorSpan.classList.contains('error-msg')) errorSpan.style.display = 'none';
        });

        // 2. VIA CEP ROBUSTO
        const cepInput = document.getElementById('cep');
        const cepError = document.getElementById('cep_error');
        const submitBtn = document.getElementById('submitBtn');
        const form = document.getElementById('devForm');
        let isFetchingCep = false;

        if (cepInput) {
            cepInput.addEventListener('input', function (e) {
                let value = e.target.value.replace(/\D/g, '');
                value = value.replace(/^(\d{5})(\d)/, '$1-$2');
                e.target.value = value;
                cepInput.classList.remove('input-error');
                cepError.style.display = 'none';
            });

            function handleCepFetch() {
                let cep = cepInput.value.replace(/\D/g, '');
                if (cep.length !== 8) return; 

                // [ARQUITETURA] Consumo de API Externa no Cliente (Client-Side). Evita sobrecarga de geolocalização no servidor. Ao buscar o endereço via ViaCEP, o formulário é preenchido automaticamente, padronizando os dados de endereço que irão para o banco.
                isFetchingCep = true;
                document.getElementById('logradouro').value = "Buscando...";
                submitBtn.disabled = true; 
                submitBtn.textContent = "Validando endereço...";

                fetch(`https://viacep.com.br/ws/${cep}/json/`)
                    .then(response => response.json())
                    .then(data => {
                        if (!("erro" in data)) {
                            document.getElementById('logradouro').value = data.logradouro;
                            document.getElementById('bairro').value = data.bairro;
                            document.getElementById('cidade').value = data.localidade;
                            document.getElementById('estado').value = data.uf;
                            document.getElementById('numero').focus();
                            cepInput.classList.remove('input-error');
                            cepError.style.display = 'none';
                        } else {
                            showCepError("CEP não encontrado.");
                        }
                    })
                    .catch(() => showCepError("Erro ao conectar aos Correios."))
                    .finally(() => {
                        // [LÓGICA] Trava de consistência. Impede que o usuário submeta o formulário enquanto o endereço não estiver totalmente validado pela API.
                        isFetchingCep = false;
                        submitBtn.disabled = false;
                        submitBtn.textContent = "Submeter para Análise";
                    });
            }

            function showCepError(msg) {
                limpaFormularioCEP();
                cepInput.classList.add('input-error');
                cepError.textContent = msg;
                cepError.style.display = 'block';
            }

            function limpaFormularioCEP() {
                document.getElementById('logradouro').value = "";
                document.getElementById('bairro').value = "";
                document.getElementById('cidade').value = "";
                document.getElementById('estado').value = "";
            }

            cepInput.addEventListener('blur', handleCepFetch);

            form.addEventListener('submit', function(e) {
                const logradouro = document.getElementById('logradouro').value;
                if (isFetchingCep || logradouro === 'Buscando...') {
                    e.preventDefault();
                    return;
                }
                if (logradouro === '') {
                    e.preventDefault();
                    showCepError("Insira um CEP válido primeiro.");
                    cepInput.focus();
                }
            });
        }
    </script>
</body>
</html>