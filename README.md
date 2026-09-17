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
1. **Formulário** — hoje envia por `mailto:` (abre o e-mail do visitante). Trocar por integração real (RD Station, Formspree, HubSpot ou webhook) antes de rodar mídia paga.
2. **Logos dos hospitais** — carregam de `sacihweb.com/wp-content/uploads/2024/03/`. Recomendado hospedar as imagens no mesmo servidor da LP.
3. **Depoimentos** — seção removida; retorna quando houver depoimentos novos e autorizados.
4. **Contadores de presença nacional** — sem números por decisão do cliente.

## Conteúdo da página
Hero · faixa de logos · o problema · objeção "já tenho prontuário eletrônico" · banner de impacto · 5 módulos SACIH (3i, Plus, Egressos, Mãos Limpas, NHE) · 3 frentes (CCIH/SCIH, NSP, NHE) · CTA intermediário · benefícios · empresa + stats · FAQ · CTA final com formulário · rodapé · WhatsApp flutuante.

---
Biobyte Sistemas — Rua Cristiano Moreira Sales, 150, sala 305, Buritis, Belo Horizonte/MG
relacionamento@biobytebrasil.com · (31) 98409-2974
