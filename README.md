# Biobyte / SACIH — Landing Page

Landing page de captação para o SACIH (Biobyte Sistemas).

## Arquivos
- `index.html` — página final, arquivo único (imagens em base64). É o que vai para produção.
- `template.html` — versão mestre com placeholders `{{...}}` no lugar das imagens. Use para editar conteúdo antes de gerar o `index.html`.
- `robots.txt` / `.nojekyll` — apoio à publicação.

## Publicar
### GitHub Pages
1. Suba esta pasta na raiz do repositório.
2. Settings → Pages → Source: branch `main`, pasta `/ (root)`.
3. A página fica em `https://<usuario>.github.io/<repo>/`.

### Servidor próprio / Hostinger / cPanel
Basta enviar `index.html` para a pasta pública (`public_html`).

## Rastreamento já configurado
- Google Tag Manager: `GTM-K39SZ677`
- Meta Pixel: `432105726399169`

(Mesmos IDs usados em sacihweb.com.)

## Pendências conhecidas
1. **Formulário** — integrado ao Kommo via `api/kommo-lead.php` (ver seção abaixo). Falta preencher `api/kommo-config.php` no servidor. Enquanto não estiver configurado, o formulário continua caindo no `mailto:` como antes.
2. **Logos dos hospitais** — carregam de `sacihweb.com/wp-content/uploads/2024/03/`. Recomendado hospedar as imagens no mesmo servidor da LP.
3. **Depoimentos** — seção removida; retorna quando houver depoimentos novos e autorizados.
4. **Contadores de presença nacional** — sem números por decisão do cliente.

## Conteúdo da página
Hero · faixa de logos · o problema · objeção "já tenho prontuário eletrônico" · banner de impacto · 5 módulos SACIH (3i, Plus, Egressos, Mãos Limpas, NHE) · 3 frentes (CCIH/SCIH, NSP, NHE) · CTA intermediário · benefícios · empresa + stats · FAQ · CTA final com formulário · rodapé · WhatsApp flutuante.

---
Biobyte Sistemas — Rua Cristiano Moreira Sales, 150, sala 305, Buritis, Belo Horizonte/MG
relacionamento@biobytebrasil.com · (31) 98409-2974

## Integração do formulário com o Kommo

Fluxo: formulário (`index.html`) → `api/kommo-lead.php` (no servidor, com PHP) → API v4 do Kommo.

- Envia nome, hospital, e-mail, telefone, cargo, UTMs (`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`), `gclid`, `fbclid`, página e referência.
- No Kommo cria: **lead** (funil/etapa configurados, UTMs nos campos de rastreamento, tag), **contato** (nome, telefone, e-mail, cargo), **empresa** (hospital) e uma **nota** com todos os dados.
- O token fica só no servidor. Proteções: honeypot, validação, limite de 5 envios/10 min por IP, checagem de origem, `.htaccess` bloqueando config e dados.
- Se o Kommo falhar, o lead é salvo em `api/_dados/` (pasta bloqueada) e o visitante cai no `mailto:` — nenhum lead se perde.

### Onde está no ar
- **Página:** GitHub Pages — https://nexxus-lp-s.github.io/Biobyte---LP/ (publica sozinho a cada commit na `main`).
- **Endpoint:** servidor do sacihweb.com (HostGator, cPanel) — `public_html/lp-sacih-api/` → https://sacihweb.com/lp-sacih-api/kommo-lead.php
  - Arquivos lá: `kommo-lead.php`, `kommo-config.php` (com o token — só no servidor), `.htaccess` e `_dados/.htaccess`.
  - O `index.html` aponta para esse endereço em `LEAD_ENDPOINT`.
- **Kommo:** conta `relacionamentobiobytebrasilcom`, Funil de vendas → etapa Oportunidade, responsável "Biobyte", tag "LP SACIH". Token de longa duração válido até 25/11/2031.

### Manutenção
- Diagnóstico: https://sacihweb.com/lp-sacih-api/kommo-lead.php?diagnostico — deve mostrar `"configurado":true` e `"kommo_conexao":"ok"`.
- Trocar token/funil/etapa: editar `public_html/lp-sacih-api/kommo-config.php` no cPanel (modelo em `api/kommo-config.example.php`). **Nunca** subir o arquivo com token para o GitHub (o repositório é público).
- Leads que o Kommo recusar ficam em `public_html/lp-sacih-api/_dados/leads-nao-enviados-AAAA-MM.log`.
- Novo domínio para a LP: incluir em `allowed_origins` no `kommo-config.php`.
