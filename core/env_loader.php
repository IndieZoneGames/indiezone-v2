<?php
/**
 * Função simples para carregar variáveis de ambiente de um arquivo .env
 * @param string $path Caminho completo para o arquivo .env
 */
// [ARQUITETURA] Isola a responsabilidade de gerenciar as credenciais do sistema em um módulo independente, garantindo que o núcleo da aplicação não dependa de bibliotecas externas complexas para uma tarefa estrutural.
function loadEnv($path)
{
    // [SEGURANÇA] Validação preventiva de infraestrutura. Evita que o PHP tente ler arquivos inexistentes, o que poderia gerar exceções (Fatal Errors) e acabar vazando o caminho da estrutura de diretórios do servidor para o usuário final.
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // [LÓGICA] Permite a inclusão de documentação interna no arquivo de configuração, ignorando as linhas de comentário para que não sejam acidentalmente processadas como chaves de acesso.
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Dividir chave=valor
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        
        // Primeiro removemos espaços em branco nas pontas
        $value = trim($value);
        
        // [CORREÇÃO] Remove as aspas simples ou duplas que envolvem a string, mantendo os espaços internos intactos.
        $value = trim($value, "\"'");

        // [AUDITORIA] Aplica a regra de precedência de ambiente. Se a variável já foi injetada diretamente pelo servidor, o sistema jamais a sobrescreve com dados do arquivo local. 
        // Isso garante a integridade da configuração de produção e impede que credenciais de teste afetem o ambiente real.
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// [ARQUITETURA] Posiciona a busca do arquivo na raiz do projeto, propositalmente fora de pastas de acesso web (como a /public). 
// Isso blinda o arquivo contendo senhas e tokens contra leituras acidentais via navegador.
loadEnv(__DIR__ . '/../.env');