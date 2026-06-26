# Handoff — Plataforma WPAtomic (plugins + deploy + cadastro de produtos)

> Documento de transferência para um **novo agente/operador** assumir:
> **(1)** cadastrar produtos e **(2)** subir/atualizar um plugin customizado.
>
> **Regra de segurança deste documento:** ele **não contém valores de segredos**.
> Cada credencial aparece com **onde vive**, **como obter** e **como
> rodar/rotacionar**. Isso é proposital: segredo em arquivo versionado vaza e
> quebra quando você rotaciona. Copie cada valor da fonte indicada para o cofre
> do seu agente (variável de ambiente / secret manager) na hora de usar.
>
> Dono: Pablo Baptista (contato@pablobaptistadev.com.br)

---

## 1. Visão geral — o que existe

| # | Ativo | O que é | Onde roda | Repositório |
|---|-------|---------|-----------|-------------|
| 1 | **wpatomic-checkout** | Plugin WordPress/WooCommerce: templates de checkout (skins UV Mix, Minimal, Wizard, Dark, Stepper), página de obrigado, side cart, etc. `v1.13.4` | `uvmix.wpatomic.com.br` | `pablobaptistadev/checkout-plugin` |
| 2 | **wpatomic-side-cart** | Plugin WordPress/WooCommerce: carrinho lateral. `v2.1.0` | `uvmix.wpatomic.com.br` | `pablobaptistadev/checkout-plugin` (mesmo repo) |
| 3 | **wpatomic-deploy** | Plugin WordPress que expõe uma **API REST de deploy** (instala/atualiza plugins a partir de uma URL de .zip, com backup/restore). `v1.2.0` | `uvmix.wpatomic.com.br` | *(instalado no site; fora destes 2 repos)* |
| 4 | **produtos-scrap-odontojf-api** | **Cloudflare Worker** (TypeScript/Hono): faz scraping de produtos de um fornecedor, cruza com ERP e **publica no WooCommerce**. Pipeline com D1 + R2 + Queues. | `odontoapi.wpatomic.com.br` (Cloudflare) | `pablobaptistadev/produtos-scrap-odontojf-api` |

**Os dois objetivos do novo agente:**
- **Subir outro plugin customizado** → usa o ativo **#3 (wpatomic-deploy)**. Ver §4.
- **Cadastrar produtos** → usa o ativo **#4 (Worker odontojf)**. Ver §5.

---

## 2. Sites, domínios e integrações

| Papel | URL | Observação |
|-------|-----|------------|
| Loja UV Mix (WordPress) | `https://uvmix.wpatomic.com.br` | Onde os plugins #1, #2 e #3 estão instalados. |
| API de produtos (Worker) | `https://odontoapi.wpatomic.com.br` | Worker #4. Health público em `/health` e `/version`. |
| Mídia (R2 público) | `https://media.odontoapi.wpatomic.com.br` | Espelho de imagens/PDFs dos produtos. |
| Fonte do scraping | `https://dentalodontocirurgicajf.com.br` | Sitemap de produtos em `/api/sitemap/products`. |
| WooCommerce alvo (publicação) | runtime atual: `https://dentalodontocirurgica.com.br` | ⚠️ Diverge do `wrangler.jsonc` (`odonto.wpatomic.com.br`). O valor que **vale é o do `/version`** (deploy atual). |
| ERP ("SPACE") | `http://45.227.82.180:8082/ecommerceapi/v1` | Integração por token e/ou login+senha; `filialCodigo=1`. HTTP (não TLS). |

> **Estado atual do Worker (lido de `/version`):**
> `erp_token_set: false`, `woo_creds_set: false`.
> Ou seja: **a publicação no WooCommerce está desligada** até as credenciais
> Woo serem configuradas (ver §5.3). O `WORKER_API_KEY` **está** configurado
> (rotas protegidas retornam 401 sem chave).

---

## 3. Repositórios GitHub

| Repo | Branch de trabalho | Build/deploy |
|------|--------------------|--------------|
| `pablobaptistadev/checkout-plugin` | `claude/zealous-fermat-4eeqey` | zip → wpatomic-deploy (§4) |
| `pablobaptistadev/produtos-scrap-odontojf-api` | `claude/zealous-fermat-4eeqey` | `wrangler deploy` (§5.5) |

Acesso ao GitHub é com **a conta do dono** (`pablobaptistadev`). O novo agente
precisa de um token/credencial GitHub com escopo nesses repos — gere um **PAT**
em GitHub → Settings → Developer settings → Personal access tokens, com acesso
de `contents`/`pull_requests` aos dois repositórios.

---

## 4. Subir um plugin customizado — **wpatomic-deploy** (contrato completo)

Plugin instalado em `uvmix.wpatomic.com.br`. Expõe REST sob
`/wp-json/wpatomic-deploy/v1/`. Autentica por **header `X-Deploy-Token`**
(ou `?token=`).

### 4.1 Onde está o token (NÃO está neste arquivo)
- O **token de deploy** é o segredo dessa API. Ele é exibido/gerado na **página
  de ajustes do plugin wpatomic-deploy no WP Admin** de `uvmix.wpatomic.com.br`.
- **Recomendado:** entre no WP Admin → ajustes do **wpatomic-deploy** →
  **regenere o token** e cole o valor novo direto no cofre do seu agente
  (ex.: `WPATOMIC_DEPLOY_TOKEN`). Regenerar invalida qualquer token antigo que
  já tenha circulado. Formato: string de ~48 caracteres.

### 4.2 Endpoints
| Método | Rota | Função |
|--------|------|--------|
| GET | `/ping` | Saúde + versão do plugin de deploy. |
| GET | `/plugins?managed=1` | Lista plugins (e versões) gerenciados. |
| GET | `/backups?slug=<slug>` | Lista snapshots de backup de um plugin. |
| POST | `/deploy` | Instala/atualiza a partir de `zip_url` (host externo). Cria backup automático. |
| POST | `/upload` | **Instala/atualiza enviando o `.zip` direto via multipart** — sem host externo. ⭐ recomendado p/ automação. |
| POST | `/restore` | Volta para um backup (rollback). |

> O `/upload` é fornecido pelo plugin companheiro **`wpatomic-deploy-uploader`**
> (fonte no repo `pablobaptistadev/checkout-plugin`). Ele registra a rota no
> **mesmo namespace** e reaproveita o **mesmo `X-Deploy-Token`** (valida
> despachando o `/ping` do `wpatomic-deploy` em processo). Mesma URL base,
> mesma chave, sem precisar hospedar o zip em lugar nenhum.
>
> **Backup automático:** o `/upload` (v1.1.0+) salva o zip em `uploads/` e
> **delega ao `/deploy` nativo** em processo — então cada upload cria o **mesmo
> backup versionado** que aparece em "Plugins gerenciados (deploy)" no admin e é
> restaurável por `/restore`. A resposta traz `"via":"deploy"` e o nome do
> `"backup"`. Se o site não conseguir baixar a própria URL, ele instala direto
> e devolve `"via":"direct"` + `"backup_warning"` (sem backup) — sinal pra cair
> no `/deploy` por URL (§4.3b).

### 4.3 Fluxo de deploy — **upload direto (recomendado)**
Um único passo, sem host externo. É o caminho ideal para o agente.
```bash
TOKEN="<cole do WP Admin / cofre>"          # nunca commitar
SITE="https://uvmix.wpatomic.com.br"
ZIP="meu-plugin-1.0.0.zip"

# 1) Empacotar (exclua dist/backups/node_modules):
zip -r -q "$ZIP" meu-plugin -x '*/dist/*' '*/backups/*' '*/node_modules/*'

# 2) Subir o zip DIRETO (multipart, campo "file"); ?activate=true ativa após instalar:
curl -s -X POST "$SITE/wp-json/wpatomic-deploy/v1/upload?activate=true" \
  -H "X-Deploy-Token: $TOKEN" \
  -F "file=@$ZIP"
# -> {"success":true,"plugin":"meu-plugin/meu-plugin.php","slug":"meu-plugin",
#     "version":"1.0.0","activated":true,"log":[...]}

# 3) Verificar:
curl -s -H "X-Deploy-Token: $TOKEN" "$SITE/wp-json/wpatomic-deploy/v1/plugins?managed=1"

# 4) Rollback (se quebrar):
curl -s -X POST "$SITE/wp-json/wpatomic-deploy/v1/restore" \
  -H "X-Deploy-Token: $TOKEN" -H "Content-Type: application/json" \
  -d '{"slug":"meu-plugin","activate":true}'   # sem version = backup anterior
```

> ⚠️ O `/upload` depende de `upload_max_filesize` / `post_max_size` do PHP do site
> comportarem o `.zip`. Para plugins grandes acima desse limite, caia no `/deploy`
> por URL (§4.3b).

### 4.3b Alternativa — deploy por URL (`/deploy`)
Quando o zip é grande demais para o upload do PHP, hospede num host temporário e
passe a URL:
```bash
URL=$(curl -s -F reqtype=fileupload -F time=1h -F fileToUpload=@"$ZIP" \
      https://litterbox.catbox.moe/resources/internals/api.php)
curl -s -X POST "$SITE/wp-json/wpatomic-deploy/v1/deploy" \
  -H "X-Deploy-Token: $TOKEN" -H "Content-Type: application/json" \
  -d "{\"zip_url\":\"$URL\",\"activate\":true}"
```

### 4.4 Para deployar em OUTRO site
O `wpatomic-deploy` só existe hoje em `uvmix`. Para usar o mesmo fluxo em outro
WordPress: instale o plugin `wpatomic-deploy` nesse site, pegue o token **dele**
(cada site tem o seu) e troque `$SITE`/`$TOKEN`. (Em teste, `odonto.wpatomic.com.br`
não respondeu — não tem o plugin / host indisponível.)

### 4.5 Regras de versionamento (obrigatórias a cada entrega)
A versão aparece em **3 lugares** + nome do zip:
1. Header do plugin: `* Version: X.Y.Z`
2. Constante: `define( 'WPATOMIC_CHECKOUT_VERSION', 'X.Y.Z' );` (equivalente no seu plugin)
3. `readme.txt`: `Stable tag: X.Y.Z` + entrada no `== Changelog ==`
4. Zip versionado `meu-plugin-X.Y.Z.zip`, arquivado em `dist/` (histórico, nunca apagar) + um `meu-plugin.zip` (link estável) na raiz.

---

## 5. Cadastrar produtos — **Worker odontojf** (`odontoapi.wpatomic.com.br`)

Pipeline (Cloudflare Worker, Hono): **scrape → erp → merge → media → push**.
Persiste em **D1** (catálogo/estado), espelha mídia em **R2**, orquestra com
**Queues** e roda um **cron a cada minuto** para drenar a fila.

### 5.1 Autenticação da API do Worker
- Todas as rotas (exceto `/`, `/health`, `/version`) exigem **`WORKER_API_KEY`**.
- Envie em **`x-api-key: <chave>`** (também aceita `?api_key=`, `?key=` ou
  `Authorization: Bearer <chave>`).
- **Onde vive:** é um *secret* do Cloudflare Worker (não fica no repo e **não é
  legível de volta**). Para obter/rotacionar:
  ```bash
  cd produtos-scrap-odontojf-api
  npx wrangler secret list                      # mostra os NOMES dos secrets
  npx wrangler secret put WORKER_API_KEY        # define/rotaciona (digite o valor)
  ```
  Se você não tem o valor atual, **rotacione** (`secret put`) e use o novo.

### 5.2 Endpoints principais
| Método | Rota | Função |
|--------|------|--------|
| GET | `/health`, `/version` | Saúde / config efetiva (público). |
| GET | `/dashboard` | Painel web (HTML) do pipeline. *(exige api key)* |
| GET | `/products`, `/products/:sku` | Catálogo no D1. |
| GET | `/queue`, `/queue/:id` · POST `/queue/:id/retry` · POST `/queue/cleanup` | Fila de sync. |
| POST | `/sync/rebuild` | Reconstrói a lista a partir do sitemap. |
| POST | `/sync/sku/:sku?stage=<scrape\|erp\|merge\|media\|push>` | Roda 1 SKU numa etapa. |
| POST | `/admin/start-scrape` | Dispara o scraping em massa. |
| POST | `/admin/import-urls` | Importa URLs de produto manualmente. |
| POST | `/admin/advance` | Avança o lote para a próxima etapa (quando os "gates" estão desligados). |
| POST | `/admin/mirror-scraped` | Força espelhamento de mídia p/ R2. |
| POST | `/admin/wipe` | ⚠️ Limpa dados (cuidado). |
| GET | `/monitor`, `/events`, `/debug/*` | Observabilidade / depuração. |

### 5.3 Ligar a publicação no WooCommerce (hoje está OFF)
`/version` mostra `woo_creds_set: false` → o push está desligado. Para cadastrar
produtos no Woo:
1. No WooCommerce alvo: **WooCommerce → Configurações → Avançado → REST API →
   Adicionar chave** (permissão *Leitura/Escrita*). Guarde `Consumer key` (`ck_…`)
   e `Consumer secret` (`cs_…`).
2. Configure como secrets do Worker:
   ```bash
   npx wrangler secret put WOO_CONSUMER_KEY     # cole o ck_...
   npx wrangler secret put WOO_CONSUMER_SECRET  # cole o cs_...
   ```
3. Confirme/ajuste o destino: `WOO_BASE_URL` (em `wrangler.jsonc → vars`, ou como
   secret). Garanta que aponta para o Woo certo (o `/version` mostra o efetivo).
4. Habilite o estágio de push: `WOO_PUSH_ENABLED=1` (e os gates
   `AUTO_ENQUEUE_ERP`/`AUTO_ENQUEUE_MERGE` conforme o rollout desejado).
5. Faça `npx wrangler deploy` e dispare um SKU de teste:
   `POST /sync/sku/<sku>?stage=push` com `x-api-key`.

### 5.4 Integração ERP (opcional, também OFF hoje)
`/version`: `erp_token_set: false`. Para ligar, defina **um** dos caminhos:
```bash
npx wrangler secret put ERP_API_TOKEN     # token pré-emitido (tem precedência)
# OU login+senha:
npx wrangler secret put ERP_LOGIN
npx wrangler secret put ERP_SENHA
```
`ERP_BASE_URL` e `ERP_FILIAL_CODIGO` já estão em `wrangler.jsonc → vars`.

### 5.5 Deploy do Worker
```bash
cd produtos-scrap-odontojf-api
npm install
npm run typecheck && npm test     # vitest
npx wrangler deploy               # publica em odontoapi.wpatomic.com.br
npx wrangler tail                 # logs ao vivo
```

---

## 6. Infra Cloudflare (não-segredo — já versionado em `wrangler.jsonc`)

| Recurso | Valor |
|---------|-------|
| Account (Cloudflare) | **Contato@pablobaptistadev's Account** — `9a9f4e5b0f7015bab8ca874ecd887339` |
| Worker name | `produtos-scrap-odontojf-api` |
| Domínio custom | `odontoapi.wpatomic.com.br` |
| D1 database | `odontojf-products-db` — id `48a12199-a327-40da-9def-1f1f993cf00b` (binding `DB`) |
| R2 bucket | `odontojf-products-media` (binding `MEDIA`) · público em `media.odontoapi.wpatomic.com.br` |
| Queues | `odontojf-sync-queue` (binding `SYNC_QUEUE`) + DLQ `odontojf-sync-dlq` (`SYNC_DLQ`) |
| Cron | `* * * * *` (a cada minuto, drena a fila) |

Acesso Cloudflare: a conta do dono. O agente precisa de um **Cloudflare API
Token** (escopo Workers/D1/R2/Queues nessa account) para rodar `wrangler`.

---

## 7. Tabela-resumo de credenciais (onde cada segredo vive)

| Segredo | Usado por | Onde vive / como obter | Como rotacionar |
|---------|-----------|------------------------|-----------------|
| **Token de deploy** (`X-Deploy-Token`) | wpatomic-deploy (§4) | WP Admin de `uvmix` → ajustes do plugin **wpatomic-deploy** | Regenerar na própria página |
| **WORKER_API_KEY** | API do Worker (§5.1) | Cloudflare Worker *secret* | `wrangler secret put WORKER_API_KEY` |
| **WOO_CONSUMER_KEY / _SECRET** | Push p/ WooCommerce (§5.3) | WooCommerce alvo → REST API; depois vira Worker *secret* | Recriar chave no Woo + `wrangler secret put` |
| **ERP_API_TOKEN** ou **ERP_LOGIN/ERP_SENHA** | Integração ERP (§5.4) | Fornecidos pelo ERP "SPACE"; viram Worker *secret* | `wrangler secret put …` |
| **GitHub PAT** | Push/PR nos 2 repos (§3) | GitHub → Developer settings → PAT | Revogar + gerar novo |
| **Cloudflare API Token** | `wrangler` (§6) | Cloudflare → My Profile → API Tokens | Roll/Delete no painel |

> Nenhum desses valores está neste arquivo de propósito. Para um valor que
> ninguém tem mais em texto, o caminho seguro é **rotacionar** na fonte e plugar
> o novo direto no cofre do agente.

---

## 8. Checklist do novo agente

**Para subir um plugin customizado:**
1. [ ] Pegar/rotacionar o **token de deploy** no WP Admin do `uvmix`.
2. [ ] Versionar o plugin (header + constante + readme + zip em `dist/`) — §4.5.
3. [ ] `zip` → upload litterbox → `POST /deploy` → verificar `/plugins` — §4.3.
4. [ ] Em caso de erro, `POST /restore`.

**Para cadastrar produtos:**
1. [ ] Ter o **WORKER_API_KEY** (rotacionar se não tiver) — §5.1.
2. [ ] Criar chaves **REST do WooCommerce** alvo e setar `WOO_CONSUMER_KEY/SECRET`
       + `WOO_PUSH_ENABLED=1` no Worker — §5.3.
3. [ ] (Opcional) Ligar ERP — §5.4.
4. [ ] `wrangler deploy`, depois `POST /admin/start-scrape` (massa) ou
       `POST /sync/sku/<sku>?stage=push` (1 produto) — §5.2/5.5.
5. [ ] Acompanhar por `/dashboard`, `/monitor` e `wrangler tail`.

---

## 9. Segurança (importante)

- **Nunca** comite segredos (token de deploy, api keys, ck_/cs_, ERP) em git/PR/log.
  Os repos têm `.gitignore` cobrindo `.wrangler/`, `node_modules`, `dist/`.
- Cloudflare *secrets* não são legíveis de volta — se perdeu o valor, **rotacione**.
- Rotacione o **token de deploy** periodicamente e sempre que ele circular por
  automação.
- Dê ao novo agente o **menor escopo possível** em cada credencial (PAT só nos 2
  repos; token Woo só Read/Write; CF token só nos recursos do Worker).
