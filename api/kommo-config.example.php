<?php
/**
 * MODELO de configuração da integração com o Kommo.
 *
 * COMO USAR (no servidor da Hostinger, NUNCA no GitHub):
 *   1. Copie este arquivo para "kommo-config.php" na mesma pasta (/api).
 *   2. Preencha os valores abaixo.
 *   3. O arquivo "kommo-config.php" está no .gitignore e bloqueado no .htaccess:
 *      ele não deve ser enviado ao repositório (o repositório é público).
 */
defined('KOMMO_LEAD_ENDPOINT') or exit;

return [
    // Subdomínio da conta Kommo. Ex.: se a URL é https://biobyte.kommo.com → 'biobyte'
    'subdomain' => 'relacionamentobiobytebrasilcom',

    // Token de longa duração da integração privada do Kommo
    // (Configurações → Integrações → sua integração → aba "Chaves e escopos" → "Token de longa duração").
    'access_token' => '',

    // ID do funil e da etapa de entrada dos leads.
    // Não use a etapa "Leads de entrada" (111590627): a API do Kommo não cria leads direto nela.
    'pipeline_id' => 14446659,   // Funil de vendas
    'status_id'   => 111590631,  // Oportunidade

    // (Opcional) ID do usuário responsável pelos leads. 0 = padrão da conta.
    // Usuários: Biobyte = 15844167 | Gabriel Isidoro = 12596707
    'responsible_user_id' => 15844167, // usuário "Biobyte"

    // (Opcional) Tags aplicadas ao lead.
    'tags' => ['LP SACIH'],

    // (Opcional) Domínios autorizados a enviar o formulário. Vazio = apenas o próprio domínio do servidor.
    // Ex.: ['https://lp.sacihweb.com', 'https://www.sacihweb.com']
    'allowed_origins' => ['https://nexxus-lp-s.github.io', 'https://sacihweb.com', 'https://www.sacihweb.com'],

    // (Opcional) E-mail que recebe um aviso caso o Kommo esteja fora do ar. Vazio = não envia.
    'fallback_email' => '',
];
