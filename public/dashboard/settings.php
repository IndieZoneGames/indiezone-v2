<?php
// public/dashboard/settings.php

// [ARQUITETURA] Importa o cabeçalho do desenvolvedor, delegando a proteção da rota (RBAC) e inicialização da sessão para o núcleo do sistema, mantendo este script focado apenas na regra de negócio do perfil.
require_once("includes/dev_header.php");
/** @var mysqli $conn */

$message = '';
$error = '';

// 1. PROCESSAR ATUALIZAÇÃO DO PERFIL DO ESTÚDIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_studio') {
    $studio_name = trim($_POST['studio_name']);
    $support_email = trim($_POST['support_email']);
    $bio = trim($_POST['bio']);
    $website_url = trim($_POST['website_url']);
    $twitter_url = trim($_POST['twitter_url']);
    $discord_url = trim($_POST['discord_url']);

    if (empty($studio_name) || empty($support_email)) {
        $error = "O Nome do Estúdio e o E-mail de Suporte são obrigatórios.";
    } else {
        // [SEGURANÇA] Atualização blindada por Prepared Statements. Evita que caracteres especiais em URLs ou textos livres (como a 'bio') quebrem a sintaxe SQL e causem injeções.
        // [AUDITORIA] O comando 'WHERE user_id = ?' utiliza exclusivamente a variável garantida pela sessão, impedindo Insecure Direct Object Reference (IDOR).
        $stmt_update = $conn->prepare("UPDATE developers SET studio_name = ?, support_email = ?, bio = ?, website_url = ?, twitter_url = ?, discord_url = ? WHERE user_id = ?");
        $stmt_update->bind_param("ssssssi", $studio_name, $support_email, $bio, $website_url, $twitter_url, $discord_url, $user_id);
        
        if ($stmt_update->execute()) {
            $message = "✅ Perfil do estúdio atualizado com sucesso!";
        } else {
            $error = "Erro ao atualizar. Verifique se o nome do estúdio já não está em uso.";
        }
    }
}

// 2. PROCESSAR ATUALIZAÇÃO DO ENDEREÇO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_address') {
    
    // [SEGURANÇA] Sanitização estrita utilizando Expressão Regular (Regex). O preg_replace expurga qualquer caractere que não seja de 0 a 9 (como o traço do CEP digitado no frontend), garantindo a normalização e integridade do dado antes de armazená-lo.
    $cep = preg_replace('/[^0-9]/', '', $_POST['cep']); 
    
    $logradouro = trim($_POST['logradouro']);
    $numero = trim($_POST['numero']);
    $complemento = trim($_POST['complemento']);
    $bairro = trim($_POST['bairro']);
    $cidade = trim($_POST['cidade']);
    
    // [LÓGICA] Padronização forçada. A função strtoupper garante que a sigla do estado sempre seja salva no padrão (ex: "sp" vira "SP"), facilitando relatórios e buscas futuras no banco.
    $estado = strtoupper(trim($_POST['estado'])); 

    if (empty($cep) || empty($logradouro) || empty($numero) || empty($cidade) || empty($estado)) {
        $error = "Por favor, preencha todos os campos obrigatórios do endereço.";
    } else {
        // [LÓGICA] Implementação de máquina de estados para inserção/atualização (Upsert). O sistema consulta primeiro a existência do endereço para decidir dinamicamente se fará um UPDATE ou um INSERT na tabela 'user_addresses'.
        $stmt_check = $conn->prepare("SELECT address_id FROM user_addresses WHERE user_id = ? AND address_type = 'commercial'");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        $res_addr = $stmt_check->get_result();

        if ($res_addr->num_rows > 0) {
            // Atualiza o existente
            $stmt_addr = $conn->prepare("UPDATE user_addresses SET cep = ?, logradouro = ?, numero = ?, complemento = ?, bairro = ?, cidade = ?, estado = ? WHERE user_id = ? AND address_type = 'commercial'");
            $stmt_addr->bind_param("sssssssi", $cep, $logradouro, $numero, $complemento, $bairro, $cidade, $estado, $user_id);
        } else {
            // Insere um novo
            $stmt_addr = $conn->prepare("INSERT INTO user_addresses (user_id, address_type, cep, logradouro, numero, complemento, bairro, cidade, estado) VALUES (?, 'commercial', ?, ?, ?, ?, ?, ?, ?)");
            $stmt_addr->bind_param("isssssss", $user_id, $cep, $logradouro, $numero, $complemento, $bairro, $cidade, $estado);
        }

        if ($stmt_addr->execute()) {
            $message = "✅ Endereço comercial atualizado com sucesso!";
        } else {
            $error = "Erro ao atualizar o endereço.";
        }
    }
}

// 3. BUSCAR DADOS ATUAIS PARA PREENCHER OS CAMPOS
// Dados do Estúdio
$stmt_dev = $conn->prepare("SELECT * FROM developers WHERE user_id = ?");
$stmt_dev->bind_param("i", $user_id);
$stmt_dev->execute();
$dev_data = $stmt_dev->get_result()->fetch_assoc();

// Dados do Endereço
$stmt_address = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? AND address_type = 'commercial'");
$stmt_address->bind_param("i", $user_id);
$stmt_address->execute();
$addr_data = $stmt_address->get_result()->fetch_assoc();
?>

<main class="dashboard-main" style="padding-bottom: 80px;">
    
    <header class="dash-header">
        <div class="dash-title">
            <h1 style="font-size: 32px; font-weight: 800; color: #fff;">Configurações do Estúdio ⚙️</h1>
            <p>Faça a gestão da identidade pública da sua empresa e dos dados de faturamento.</p>
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

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">
        
        <div class="dev-form-card" style="margin: 0;">
            <h3 class="section-title" style="margin-top: 0;">Identidade Pública</h3>
            
            <form method="POST">
                <!-- [ARQUITETURA] Campo oculto 'action' atua como um roteador de requisições. Permite o encapsulamento de múltiplos formulários na mesma página, informando ao PHP exatamente qual bloco condicional processar. -->
                <input type="hidden" name="action" value="update_studio">
                
                <div class="form-group">
                    <label>Nome do Estúdio <span style="color: var(--primary);">*</span></label>
                    <!-- [SEGURANÇA] Refletir dados do banco na tela sempre exige 'htmlspecialchars' (Prevenção de Stored XSS), garantindo que nomes de estúdio ou links não executem código nocivo no navegador. -->
                    <input type="text" name="studio_name" required value="<?php echo htmlspecialchars($dev_data['studio_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>E-mail de Suporte <span style="color: var(--primary);">*</span></label>
                    <input type="email" name="support_email" required value="<?php echo htmlspecialchars($dev_data['support_email'] ?? ''); ?>">
                    <span style="font-size: 12px; color: var(--text-muted);">E-mail que os jogadores usarão para entrar em contato.</span>
                </div>

                <div class="form-group">
                    <label>Biografia / Sobre o Estúdio</label>
                    <textarea name="bio" rows="4" placeholder="Conte a história do seu estúdio..."><?php echo htmlspecialchars($dev_data['bio'] ?? ''); ?></textarea>
                </div>

                <h4 style="color: #fff; margin: 20px 0 10px 0; font-size: 15px;">Redes Sociais e Links</h4>

                <div class="form-group">
                    <label>Website Oficial</label>
                    <input type="url" name="website_url" placeholder="https://..." value="<?php echo htmlspecialchars($dev_data['website_url'] ?? ''); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group form-col">
                        <label>Twitter / X</label>
                        <input type="url" name="twitter_url" placeholder="Link do perfil..." value="<?php echo htmlspecialchars($dev_data['twitter_url'] ?? ''); ?>">
                    </div>
                    <div class="form-group form-col">
                        <label>Servidor do Discord</label>
                        <input type="url" name="discord_url" placeholder="Link de convite..." value="<?php echo htmlspecialchars($dev_data['discord_url'] ?? ''); ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn-submit-dev">Salvar Perfil</button>
            </form>
        </div>

        <div class="dev-form-card" style="margin: 0;">
            <h3 class="section-title" style="margin-top: 0;">Dados de Faturamento</h3>
            <!-- [AUDITORIA] Tratamento de dados sensíveis inalteráveis. O CPF/CNPJ atua como âncora fiscal. O sistema apenas exibe a informação renderizada do banco, obrigando que alterações de natureza tributária gerem um ticket manual no suporte (garantindo rastreio humano). -->
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                <strong>Documento:</strong> <?php echo htmlspecialchars($dev_data['document_number'] ?? 'Não informado'); ?><br>
                <span style="opacity: 0.7;">Para alterar o documento (CPF/CNPJ) entre em contato com o suporte.</span>
            </p>

            <form method="POST">
                <input type="hidden" name="action" value="update_address">

                <div class="form-row">
                    <div class="form-group" style="flex: 0.8;">
                        <label>CEP <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="cep" id="cep" maxlength="9" placeholder="00000-000" required value="<?php echo htmlspecialchars($addr_data['cep'] ?? ''); ?>">
                    </div>
                    <div class="form-group form-col" style="flex: 2;">
                        <label>Rua / Avenida <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="logradouro" id="logradouro" required value="<?php echo htmlspecialchars($addr_data['logradouro'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 0.8;">
                        <label>Número <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="numero" id="numero" required value="<?php echo htmlspecialchars($addr_data['numero'] ?? ''); ?>">
                    </div>
                    <div class="form-group form-col">
                        <label>Complemento</label>
                        <input type="text" name="complemento" id="complemento" placeholder="Apto, Sala, Bloco..." value="<?php echo htmlspecialchars($addr_data['complemento'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group form-col">
                        <label>Bairro <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="bairro" id="bairro" required value="<?php echo htmlspecialchars($addr_data['bairro'] ?? ''); ?>">
                    </div>
                    <div class="form-group form-col">
                        <label>Cidade <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="cidade" id="cidade" required value="<?php echo htmlspecialchars($addr_data['cidade'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="flex: 0.4;">
                        <label>UF <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="estado" id="estado" maxlength="2" placeholder="SP" required value="<?php echo htmlspecialchars($addr_data['estado'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="btn-submit-dev" style="background: rgba(255,255,255,0.1); color: #fff;">Salvar Endereço</button>
            </form>
        </div>

    </div>
</main>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const cepInput = document.getElementById('cep');

        // [ARQUITETURA] Integração via Frontend (AJAX/Fetch) com a API pública do ViaCEP. Isso transfere a carga de processamento de geolocalização para o navegador do cliente, mantendo o backend focado apenas na persistência final do dado.
        cepInput.addEventListener('blur', function() {
            let cep = this.value.replace(/\D/g, '');

            if (cep.length === 8) {
                document.getElementById('logradouro').value = "Buscando...";
                
                fetch(`https://viacep.com.br/ws/${cep}/json/`)
                    .then(response => response.json())
                    .then(data => {
                        if (!data.erro) {
                            // [LÓGICA] Preenchimento automático para otimização de UX (User Experience). Reduz o tempo de cadastro e mitiga erros de digitação de nomes de ruas ou cidades.
                            document.getElementById('logradouro').value = data.logradouro;
                            document.getElementById('bairro').value = data.bairro;
                            document.getElementById('cidade').value = data.localidade;
                            document.getElementById('estado').value = data.uf;
                            
                            document.getElementById('numero').focus();
                        } else {
                            alert("CEP não encontrado. Por favor, digite manualmente.");
                            document.getElementById('logradouro').value = "";
                        }
                    })
                    .catch(error => {
                        console.error("Erro ao buscar o CEP:", error);
                        alert("Não foi possível buscar o endereço automaticamente.");
                        document.getElementById('logradouro').value = "";
                    });
            }
        });

        // [LÓGICA] Aplicação de máscara visual reativa (Regex no JavaScript). Formata o texto como "00000-000" em tempo real durante a digitação para guiar o usuário visualmente.
        cepInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 5) {
                value = value.substring(0,5) + '-' + value.substring(5,8);
            }
            e.target.value = value;
        });
    });
</script>

</body>
</html>