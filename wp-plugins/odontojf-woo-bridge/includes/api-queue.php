<?php
/**
 * FullBAI API Queue
 *
 * Origem: FullBAI API Queue v2.4.1 (portado verbatim)
 *
 * Intercepta as requests da API DEPOIS de toda validação (auth, seller, SKU,
 * ownership) e salva na fila APENAS a execução pesada.
 * Responde ao n8n exatamente como hoje — success: true, product_id, etc.
 *
 * O arquivo da API original NÃO muda. O sync v7 NÃO muda. O n8n NÃO muda.
 *
 * FLUXO:
 *   Request chega → rest_pre_dispatch intercepta
 *   → Roda TODA a validação (auth, seller, SKU duplicado, ownership)
 *   → Se qualquer validação falha: retorna erro igual hoje (401, 403, 404, 409)
 *   → Se tudo OK: INSERT na fila (~2ms) → responde success igual hoje
 *   → Worker em lote curto (GET_LOCK) processa vários itens por poucos segundos
 *   → Se ainda houver backlog, dispara continuação assíncrona imediata
 *   → Cron 1min fica só como fallback
 *
 * INSTALAR:
 *   Copiar para wp-content/mu-plugins/fullbai-api-queue.php
 *
 * (arquivo INCLUÍDO pelo odontojf-woo-bridge.php — não é um plugin próprio)
 * Origem: FullBAI API Queue v2.4.1
 */

if (!defined('ABSPATH')) exit;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

define('OJF_AQ_TABLE',          'ojf_api_queue');
define('OJF_AQ_LOCK_NAME',     'ojf_api_worker');
define('OJF_AQ_LOCK_TIMEOUT',  0);       // Non-blocking
define('OJF_AQ_CONCURRENCY',   3);       // Quantos produtos simultâneos
define('OJF_AQ_MAX_ATTEMPTS',  3);
define('OJF_AQ_STUCK_SEC',     300);     // 5 min
define('OJF_AQ_MAX_LOOP_SEC',  240);     // Legado: teto de execução do worker one-shot
define('OJF_AQ_DRAIN_SEC',     15);      // Worker drena backlog por até 15s antes de reencadear
define('OJF_AQ_CLEANUP_DAYS',  7);

/*
═══════════════════════════════════════════════════════════════════════════════
CHANGELOG OPERACIONAL — MAIS RECENTES PRIMEIRO
═══════════════════════════════════════════════════════════════════════════════

v2.4.1
- wp_option 'ojf_aq_concurrency' agora e autoload=yes — vem junto no
  wp_load_alloptions e fica em memoria pelo resto da request (zero query
  adicional). Antes era autoload=no (1 SQL extra por request quando o helper
  era chamado).
- Helper ojf_aq_get_concurrency() agora tem 3 camadas de cache:
  static in-request -> wp_cache (Redis) -> get_option (autoloaded).
- Setter limpa o object cache para refletir mudancas imediatamente.
- Impacto: 0 queries SQL no caminho de drain do worker (antes era 1 por drain).

v2.4.0
- Coluna `duration_ms` adicionada via migracao idempotente (ojf_aq_ensure_columns).
  Worker grava o execution_time_ms do response da API direto no banco — granularidade
  real de milissegundos no painel (antes: arredondado pra segundo via DATETIME diff).
- CONCURRENCY agora e configuravel via UI: input numerico no topo do painel +
  AJAX ojf_aq_set_concurrency. ojf_aq_get_concurrency() le wp_option
  'ojf_aq_concurrency' com fallback OJF_AQ_CONCURRENCY=3.
- Painel formata DURACAO: <1000ms => "Xms"; 1-60s => "X.XXXs" (3 casas); >60s => "Xm Ys".

v2.3.2
- Dashboard mostra "Last Run | Trigger GET | Cron" no rodape (mesmo padrao do sync v7)
- ojf_aq_become_worker grava timestamp em ojf_aq_last_worker_run no finally
- AJAX ojf_aq_stats retorna last_worker_run, last_trigger_get, cron_next

v2.3.1
- Trigger externo proprio em ?ojf_queue_trigger=1 com o MESMO token do sync v7
  (OJF_QUEUE_TRIGGER_TOKEN). Cada arquivo escuta o seu — sem dependencia
  entre filas. Roda em init priority 15 (antes do sync, priority 20).
- update_option('ojf_aq_last_trigger_get', ...) para o dashboard mostrar.
- Tudo idempotente: nao chama die() — sync continua respondendo o JSON unificado.

v2.3.0
- RE-ADICIONADO auto-trigger por page-load (rede de segurança independente do WP-Cron)
- Cada page-load (admin/front) checa com rate-limit de 50s via transient se há
  item pending > 60s e, se houver, agenda ojf_aq_become_worker no shutdown
  da própria request (não bloqueia entrega da página)
- Pula contextos com trigger próprio: wp_doing_cron() e REST_REQUEST
- Custo médio por request: 1 get_transient (Redis se houver object cache)
- Mantém OJF_AQ_CONCURRENCY=3 (do v2.2.1)

v2.2.1
- primeiro disparo da fila agora pré-aquece os outros slots de concorrência
- OJF_AQ_CONCURRENCY=3 passa a tentar abrir 3 workers logo no início da drenagem
- evita começar com apenas 1 worker ativo quando há backlog suficiente

v2.2.0
- worker agora drena vários itens por lote curto antes de encadear o próximo ciclo
- remove o gargalo de 1 produto por minuto quando existe backlog
- cron volta a ser apenas fallback; a fila se autoesvazia sozinha após ser disparada

v2.1.2
- worker one-shot agora encadeia o próximo item via loopback assíncrono
- remove espera de 1 minuto entre itens quando ainda existe backlog
- mantém 1 item por processo, sem voltar para worker longo

v2.1.1
- corrige bootstrap do plugin com `<?php` no topo do arquivo
- reprocess/retry/process-now agora tentam executar 1 item imediatamente
- evita item reprocessado ficar parado em `pending` quando houver slot livre

v2.1.0
- worker da API Queue agora é one-shot
- cada ciclo processa 1 item e morre
- remove loop longo que drenava múltiplos itens por request
- mantém concorrência por lock, retry, recovery e cron fallback

v2.0.0
- interceptor + fila + worker com locks

MANUTENÇÃO
- sempre adicionar mudanças novas aqui no topo
- ordem obrigatória: mais recente primeiro
═══════════════════════════════════════════════════════════════════════════════
*/

// ═══════════════════════════════════════════════════════════════════════════════
// TABELA
// ═══════════════════════════════════════════════════════════════════════════════

function ojf_aq_table() {
    global $wpdb;
    return $wpdb->prefix . OJF_AQ_TABLE;
}

function ojf_aq_create_table() {
    global $wpdb;
    $table   = ojf_aq_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id`           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `endpoint`     varchar(20)  NOT NULL COMMENT 'create | update | delete',
        `seller`       varchar(100) NOT NULL DEFAULT '',
        `api_key`      varchar(255) NOT NULL,
        `sku`          varchar(100) DEFAULT '',
        `product_id`   bigint(20) unsigned DEFAULT NULL COMMENT 'ID do produto (update/delete)',
        `payload`      longtext     NOT NULL,
        `status`       varchar(20)  NOT NULL DEFAULT 'pending',
        `result`       longtext     DEFAULT NULL,
        `error`        text         DEFAULT NULL,
        `attempts`     tinyint unsigned NOT NULL DEFAULT 0,
        `duration_ms`  int unsigned DEFAULT NULL COMMENT 'v2.4.0: tempo real do execute em ms',
        `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `started_at`   datetime     DEFAULT NULL,
        `completed_at` datetime     DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_status_id` (`status`, `id`),
        KEY `idx_seller` (`seller`),
        KEY `idx_sku` (`sku`)
    ) ENGINE=InnoDB {$charset};";

    $wpdb->query($sql);

    // v2.4.0: migracao idempotente — adiciona colunas em instalacoes antigas
    ojf_aq_ensure_columns();
}

// v2.4.0: garante colunas novas em tabelas pre-existentes (sem precisar de DROP)
function ojf_aq_ensure_columns() {
    global $wpdb;
    $table = ojf_aq_table();
    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) return;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
    $needed = [
        'duration_ms' => 'INT UNSIGNED DEFAULT NULL',
    ];
    foreach ($needed as $name => $def) {
        if (!in_array($name, $cols, true)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$def}");
        }
    }
}

// v2.4.1: concurrency configuravel via UI com 3 camadas de cache (static -> object -> autoload)
function ojf_aq_get_concurrency() {
    static $cached = null;
    if ($cached !== null) return $cached;

    // L1: object cache (Redis quando disponivel)
    $oc = wp_cache_get('ojf_aq_concurrency', 'ojf');
    if ($oc !== false) {
        $cached = (int) $oc;
        return $cached;
    }

    // L2: wp_options (autoloaded — vem junto no boot, sem SQL extra)
    $val = (int) get_option('ojf_aq_concurrency', 0);
    if ($val < 1 || $val > 10) $val = (int) OJF_AQ_CONCURRENCY;
    $cached = max(1, min(10, $val));
    wp_cache_set('ojf_aq_concurrency', $cached, 'ojf', 3600);
    return $cached;
}

// v2.4.1: setter unico que garante autoload=yes + invalida caches
function ojf_aq_set_concurrency_value($v) {
    $v = max(1, min(10, (int) $v));
    // Forca autoload=yes (vem junto no boot)
    if (get_option('ojf_aq_concurrency', null) === null) {
        add_option('ojf_aq_concurrency', $v, '', 'yes');
    } else {
        update_option('ojf_aq_concurrency', $v, 'yes');
    }
    wp_cache_delete('ojf_aq_concurrency', 'ojf');
    return $v;
}

add_action('init', function() {
    if (get_option('ojf_aq_version') !== '2.4.0') {
        ojf_aq_create_table();
        update_option('ojf_aq_version', '2.4.0', false);
    } else {
        // Bem sempre garante colunas (idempotente, no-op se ja existem)
        ojf_aq_ensure_columns();
    }
}, 5);

// ═══════════════════════════════════════════════════════════════════════════════
// INTERCEPTOR — Valida tudo, enfileira só a execução
// ═══════════════════════════════════════════════════════════════════════════════

add_filter('rest_pre_dispatch', 'ojf_aq_intercept', 10, 3);

function ojf_aq_intercept($result, $server, $request) {
    // Não interceptar quando o worker está executando os handlers
    if (!empty($GLOBALS['ojf_aq_worker_active'])) return $result;

    if ($request->get_method() !== 'POST') return $result;

    $route    = $request->get_route();
    $endpoints = [
        '/odontojf/v1/create-product' => 'create',
        '/odontojf/v1/update-product' => 'update',
        '/odontojf/v1/delete-product' => 'delete',
    ];
    if (!isset($endpoints[$route])) return $result;

    $endpoint = $endpoints[$route];

    global $wpdb;

    // ══════════════════════════════════════════════════════════════════════
    // VALIDAÇÃO COMPLETA — Idêntica ao handler original
    // Se falha aqui → retorna erro imediato igual hoje
    // ══════════════════════════════════════════════════════════════════════

    // 1. AUTH
    $auth = $request->get_header('Authorization');
    if (empty($auth)) {
        return ojf_aq_error('no_auth', 'API key not provided', 401);
    }
    $api_key = str_replace('Bearer ', '', $auth);

    // Single-tenant auth: valida o Bearer contra o segredo compartilhado (option
    // ojf_api_secret) em vez da tabela multi-seller sellers_meta. Mantém todo o
    // resto do fluxo validado intacto usando um $seller fixo.
    if (!ojf_validate_bearer($request)) {
        return ojf_aq_error('invalid_key', 'Invalid API key', 401);
    }
    $seller = 'odontojf';

    $data = $request->get_json_params();

    // ──────────────────────────────────────────────────────────────────────
    // CREATE
    // ──────────────────────────────────────────────────────────────────────
    if ($endpoint === 'create') {
        if (empty($data['sku']) || empty($data['name']) || empty($data['type'])) {
            return ojf_aq_error('missing_fields', 'Required fields: sku, name, type', 400);
        }

        if (function_exists('ojf_verificar_sku_duplicado')) {
            $exists = ojf_verificar_sku_duplicado($data['sku'], $seller);
            if ($exists) {
                return ojf_aq_error('sku_exists', 'You already have a product with this SKU: ' . $data['sku'], 409);
            }
        }

        // ✅ TUDO VALIDADO → enfileirar
        $queue_id = ojf_aq_enqueue($endpoint, $seller, $api_key, $data, null);
        $pending  = ojf_aq_pending_count();

        ojf_aq_trigger_worker();

        return new WP_REST_Response([
            'success'        => true,
            'queued'         => true,
            'queue_id'       => $queue_id,
            'queue_position' => $pending,
            'product_type'   => $data['type'],
            'seller'         => $seller,
            'sku'            => $data['sku'],
            'status'         => 'draft',
            'api_version'    => '5.1-queued',
        ], 200);
    }

    // ──────────────────────────────────────────────────────────────────────
    // UPDATE
    // ──────────────────────────────────────────────────────────────────────
    if ($endpoint === 'update') {
        if (empty($data['sku'])) {
            return ojf_aq_error('missing_sku', 'SKU is required', 400);
        }

        $product_id = function_exists('ojf_buscar_produto_por_sku')
            ? ojf_buscar_produto_por_sku($data['sku'], $seller)
            : null;

        if (!$product_id) {
            // O _sku do pai variável é sintético (OD-<código da 1ª variação>) e muda
            // quando a origem reordena os tamanhos. Recusar aqui bloqueava justamente
            // o re-chaveamento: o handler de update é um UPSERT e sabe achar o produto
            // pelo slug da origem. Tenta o slug antes de desistir.
            // Slug primeiro: é a URL canônica. O id de origem marca onde escrevemos
            // por último e, num par duplicado, isso é o gêmeo — ele entra só quando
            // o slug não acha ninguém.
            if (!empty($data['slug']) && function_exists('ojf_find_owned_product_by_slug')) {
                $product_id = ojf_find_owned_product_by_slug((string) $data['slug']);
            }
            if (!$product_id && function_exists('ojf_find_owned_product_by_origin_id')) {
                $product_id = ojf_find_owned_product_by_origin_id(ojf_payload_origin_id($data));
            }
            if (!$product_id && (empty($data['name']) || empty($data['type']))) {
                return ojf_aq_error('not_found', 'Product not found: ' . $data['sku'], 404);
            }
        }

        if ($product_id) {
            $product_seller = get_post_meta($product_id, '_seller', true);
            if ($product_seller !== $seller) {
                return ojf_aq_error('forbidden', 'No permission to update this product', 403);
            }
        }

        // ✅ TUDO VALIDADO → enfileirar
        $queue_id = ojf_aq_enqueue($endpoint, $seller, $api_key, $data, $product_id);
        $pending  = ojf_aq_pending_count();

        $product      = $product_id ? wc_get_product($product_id) : null;
        $product_type = $product ? $product->get_type() : (string) ($data['type'] ?? 'simple');

        ojf_aq_trigger_worker();

        return new WP_REST_Response([
            'success'        => true,
            'queued'         => true,
            'queue_id'       => $queue_id,
            'queue_position' => $pending,
            'product_id'     => $product_id,
            'product_type'   => $product_type,
            'seller'         => $seller,
            'sku'            => $data['sku'],
            'product_url'    => $product_id ? get_permalink($product_id) : null,
            'edit_url'       => $product_id ? admin_url('post.php?post=' . $product_id . '&action=edit') : null,
            'api_version'    => '5.1-queued',
        ], 200);
    }

    // ──────────────────────────────────────────────────────────────────────
    // DELETE
    // ──────────────────────────────────────────────────────────────────────
    if ($endpoint === 'delete') {
        if (empty($data['sku'])) {
            return ojf_aq_error('missing_sku', 'SKU is required', 400);
        }

        $product_id = function_exists('ojf_buscar_produto_por_sku')
            ? ojf_buscar_produto_por_sku($data['sku'], $seller)
            : null;

        if (!$product_id) {
            return ojf_aq_error('not_found', 'Product not found: ' . $data['sku'], 404);
        }

        $product_seller = get_post_meta($product_id, '_seller', true);
        if ($product_seller !== $seller) {
            return ojf_aq_error('forbidden', 'No permission to delete this product', 403);
        }

        // ✅ TUDO VALIDADO → enfileirar
        $queue_id = ojf_aq_enqueue($endpoint, $seller, $api_key, $data, $product_id);
        $pending  = ojf_aq_pending_count();

        $product      = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : '';
        $product_type = $product ? $product->get_type() : 'simple';

        ojf_aq_trigger_worker();

        return new WP_REST_Response([
            'success'        => true,
            'queued'         => true,
            'queue_id'       => $queue_id,
            'queue_position' => $pending,
            'product_id'     => $product_id,
            'product_name'   => $product_name,
            'product_type'   => $product_type,
            'seller'         => $seller,
            'sku'            => $data['sku'],
            'api_version'    => '5.1-queued',
        ], 200);
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

function ojf_aq_error($code, $message, $status) {
    return new WP_REST_Response([
        'code'    => $code,
        'message' => $message,
        'data'    => ['status' => $status],
    ], $status);
}

function ojf_aq_enqueue($endpoint, $seller, $api_key, $data, $product_id) {
    global $wpdb;
    $table = ojf_aq_table();

    $wpdb->insert($table, [
        'endpoint'   => $endpoint,
        'seller'     => $seller,
        'api_key'    => $api_key,
        'sku'        => $data['sku'] ?? '',
        'product_id' => $product_id,
        'payload'    => wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'status'     => 'pending',
        'created_at' => current_time('mysql'),
    ], ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);

    $id = (int) $wpdb->insert_id;

    // Safety: se INSERT falhou, tabela pode não existir
    if (!$id) {
        ojf_aq_create_table();
        $wpdb->insert($table, [
            'endpoint'   => $endpoint,
            'seller'     => $seller,
            'api_key'    => $api_key,
            'sku'        => $data['sku'] ?? '',
            'product_id' => $product_id,
            'payload'    => wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'     => 'pending',
            'created_at' => current_time('mysql'),
        ]);
        $id = (int) $wpdb->insert_id;
    }

    return $id;
}

function ojf_aq_pending_count() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `" . ojf_aq_table() . "` WHERE status = 'pending'"
    );
}

function ojf_aq_decode_json($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value) || is_object($value)) {
        return $value;
    }

    $decoded = json_decode($value, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
}

function ojf_aq_product_image_state($product_id) {
    $product_id = (int) $product_id;
    if ($product_id <= 0 || !get_post($product_id)) {
        return null;
    }

    $thumbnail_id = (int) get_post_meta($product_id, '_thumbnail_id', true);
    $gallery_raw  = get_post_meta($product_id, '_product_image_gallery', true);
    $gallery_ids  = $gallery_raw ? array_values(array_filter(array_map('intval', explode(',', $gallery_raw)))) : [];

    $gallery = [];
    foreach ($gallery_ids as $gallery_id) {
        $gallery[] = [
            'attachment_id' => $gallery_id,
            'url' => wp_get_attachment_url($gallery_id),
        ];
    }

    return [
        'product_id' => $product_id,
        'thumbnail_id' => $thumbnail_id,
        'thumbnail_url' => $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null,
        'gallery_ids' => $gallery_ids,
        'gallery' => $gallery,
        'seller_image_urls' => function_exists('ojf_get_seller_image_urls') ? ojf_get_seller_image_urls($product_id) : [],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// TRIGGER — Dispara worker one-shot no shutdown (após resposta HTTP)
// ═══════════════════════════════════════════════════════════════════════════════

function ojf_aq_trigger_worker() {
    if (!empty($GLOBALS['ojf_aq_trigger_set'])) return;
    $GLOBALS['ojf_aq_trigger_set'] = true;
    // NÃO-BLOQUEANTE: dispara o worker via loopback (admin-ajax, blocking=false)
    // no shutdown — a resposta da API volta NA HORA. Antes usava become_worker
    // no shutdown, que processa via fastcgi_finish_request; em servidores sem
    // esse suporte (ex: gooresultados) isso BLOQUEAVA a resposta até terminar o
    // lote → o Worker dava timeout ("operation was aborted"). O loopback resolve:
    // o processamento roda numa requisição separada, sem segurar a resposta.
    add_action('shutdown', 'ojf_aq_dispatch_async_worker', 5);
}

function ojf_aq_internal_worker_token() {
    return wp_hash('ojf_aq_internal_worker');
}

function ojf_aq_dispatch_async_worker() {
    if (ojf_aq_pending_count() <= 0) {
        return false;
    }

    $response = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => false,
        'body'      => [
            'action' => 'ojf_aq_internal_worker',
            'token'  => ojf_aq_internal_worker_token(),
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('[FullBAI Queue] Async worker dispatch failed: ' . $response->get_error_message());
        return false;
    }

    return true;
}

function ojf_aq_dispatch_async_workers($count = 1) {
    $count = max(0, (int) $count);
    $dispatched = 0;

    for ($i = 0; $i < $count; $i++) {
        if (ojf_aq_dispatch_async_worker()) {
            $dispatched++;
        }
    }

    return $dispatched;
}

function ojf_aq_is_internal_worker_request() {
    return wp_doing_ajax()
        && isset($_REQUEST['action'])
        && sanitize_key(wp_unslash($_REQUEST['action'])) === 'ojf_aq_internal_worker';
}

function ojf_aq_acquire_worker_lock($timeout = OJF_AQ_LOCK_TIMEOUT) {
    global $wpdb;

    $concurrency = ojf_aq_get_concurrency();
    for ($i = 1; $i <= $concurrency; $i++) {
        $lock_name = OJF_AQ_LOCK_NAME . '_' . $i;
        $got = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, %d)", $lock_name, (int) $timeout
        ));
        if ($got) {
            return $lock_name;
        }
    }

    return null;
}

function ojf_aq_release_worker_lock($lock_name) {
    global $wpdb;

    if (!$lock_name) {
        return;
    }

    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
}

function ojf_aq_run_one_worker_now($specific_id = 0, $lock_timeout = 5) {
    $lock_name = ojf_aq_acquire_worker_lock($lock_timeout);
    if (!$lock_name) {
        return [
            'processed' => false,
            'lock_acquired' => false,
            'remaining_pending' => ojf_aq_pending_count(),
        ];
    }

    $dispatch_next = false;
    $result = [
        'processed' => false,
        'remaining_pending' => ojf_aq_pending_count(),
        'processed_count' => 0,
        'processed_queue_ids' => [],
    ];

    try {
        ojf_aq_recover_stuck();
        $started = microtime(true);
        $next_specific_id = (int) $specific_id;

        while (true) {
            $step = ojf_aq_process_one($next_specific_id);
            $next_specific_id = 0;

            if (empty($step['processed'])) {
                $result['remaining_pending'] = $step['remaining_pending'] ?? ojf_aq_pending_count();
                break;
            }

            $result['processed'] = true;
            $result['processed_count']++;
            if (!empty($step['queue_id'])) {
                $result['processed_queue_ids'][] = (int) $step['queue_id'];
                $result['queue_id'] = (int) $step['queue_id'];
            }
            $result['remaining_pending'] = $step['remaining_pending'] ?? ojf_aq_pending_count();

            if ($result['remaining_pending'] <= 0) {
                break;
            }

            if ((microtime(true) - $started) >= OJF_AQ_DRAIN_SEC) {
                $dispatch_next = true;
                break;
            }
        }

        $result['lock_acquired'] = true;
        if (!$dispatch_next) {
            $dispatch_next = !empty($result['remaining_pending']);
        }
    } finally {
        ojf_aq_release_worker_lock($lock_name);
    }

    if ($dispatch_next) {
        // GENTIL: encadeia no MÁXIMO 1 sucessor (não 'concurrency'). Evita a
        // explosão exponencial de requests admin-ajax que travava o MariaDB.
        // A concorrência vem do lock (vários triggers/pushes em paralelo), não
        // de um worker semeando vários.
        $result['async_dispatched'] = ojf_aq_dispatch_async_workers(1);
    }

    return $result;
}

function ojf_aq_become_worker() {
    // Enviar resposta HTTP ao cliente ANTES de processar
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    ignore_user_abort(true);
    set_time_limit(OJF_AQ_MAX_LOOP_SEC + 30);

    // GENTIL: NÃO semeia workers extras por trigger. Servidores fracos (ex:
    // gooresultados, 3 cores) afogavam o MariaDB no GET_LOCK com a cascata.
    // A concorrência real é limitada pelo lock (OJF_AQ_CONCURRENCY) + o envio
    // pacote-a-pacote do lado do Worker. Cada worker encadeia no máximo 1
    // sucessor (ver run_one_worker_now).

    ojf_aq_run_one_worker_now();
}

// ═══════════════════════════════════════════════════════════════════════════════
// WORKER ONE-SHOT — Processa no máximo 1 item por ciclo
// ═══════════════════════════════════════════════════════════════════════════════

function ojf_aq_claim_pending_item($max_attempts = 5, $specific_id = 0) {
    global $wpdb;
    $table = ojf_aq_table();

    $specific_id = (int) $specific_id;
    if ($specific_id > 0) {
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE id = %d AND status = 'pending' LIMIT 1",
                $specific_id
            ));
            if (!$item) {
                return null;
            }

            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET status = 'processing', started_at = %s, attempts = attempts + 1
                 WHERE id = %d AND status = 'pending'",
                current_time('mysql'), $item->id
            ));

            if ($claimed) {
                return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $item->id));
            }
        }

        return null;
    }

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $item = $wpdb->get_row(
            "SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
        );
        if (!$item) {
            return null;
        }

        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = 'processing', started_at = %s, attempts = attempts + 1
             WHERE id = %d AND status = 'pending'",
            current_time('mysql'), $item->id
        ));

        if ($claimed) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $item->id));
        }
    }

    return null;
}

function ojf_aq_process_one($specific_id = 0) {
    global $wpdb;
    $table = ojf_aq_table();

    // Flag: desativa o interceptor enquanto worker roda handlers
    $GLOBALS['ojf_aq_worker_active'] = true;

    try {
        $item = ojf_aq_claim_pending_item(5, $specific_id);
        if (!$item) {
            return [
                'processed' => false,
                'remaining_pending' => ojf_aq_pending_count(),
            ];
        }

        error_log(sprintf(
            '[FullBAI Queue] #%d START: %s SKU=%s seller=%s',
            $item->id, $item->endpoint, $item->sku, $item->seller
        ));

        // ──────────────────────────────────────────────────────────────────
        // EXECUTAR HANDLER ORIGINAL
        // ──────────────────────────────────────────────────────────────────
        try {
            $result = ojf_aq_execute($item);

            if (is_wp_error($result)) {
                $msg  = $result->get_error_message();
                $data = $result->get_error_data();
                $code = isset($data['status']) ? (int) $data['status'] : 500;
                $err_code = $result->get_error_code();

                // SKU duplicado ou produto não encontrado = passed (retorno normal, não é falha)
                $passed_codes = ['sku_exists', 'not_found'];
                if (in_array($err_code, $passed_codes, true)) {
                    $wpdb->update($table, [
                        'status'       => 'passed',
                        'error'        => $msg,
                        'result'       => wp_json_encode($data),
                        'completed_at' => current_time('mysql'),
                    ], ['id' => $item->id]);
                    error_log(sprintf('[FullBAI Queue] #%d PASSED: %s', $item->id, $msg));
                // Retry apenas em 500 + tentativas sobrando
                } elseif ($code >= 500 && ($item->attempts + 1) < OJF_AQ_MAX_ATTEMPTS) {
                    $wpdb->update($table, [
                        'status'     => 'pending',
                        'started_at' => null,
                        'error'      => $msg . ' [attempt ' . ($item->attempts + 1) . '/' . OJF_AQ_MAX_ATTEMPTS . ']',
                    ], ['id' => $item->id]);
                    error_log(sprintf('[FullBAI Queue] #%d RETRY %d/%d: %s',
                        $item->id, $item->attempts + 1, OJF_AQ_MAX_ATTEMPTS, $msg));
                } else {
                    $wpdb->update($table, [
                        'status'       => 'failed',
                        'error'        => $msg,
                        'result'       => wp_json_encode($data),
                        'completed_at' => current_time('mysql'),
                    ], ['id' => $item->id]);
                    error_log(sprintf('[FullBAI Queue] #%d FAILED: %s', $item->id, $msg));
                }

            } else {
                // SUCESSO
                $resp = ($result instanceof WP_REST_Response) ? $result->get_data() : $result;

                // v2.4.0: extrai ms reais do response (handler ja calcula execution_time_ms)
                $dur_ms = null;
                if (is_array($resp) && isset($resp['execution_time_ms'])) {
                    $dur_ms = max(0, (int) $resp['execution_time_ms']);
                }

                $upd = [
                    'status'       => 'completed',
                    'result'       => wp_json_encode($resp, JSON_UNESCAPED_UNICODE),
                    'error'        => null,
                    'product_id'   => is_array($resp) ? ($resp['product_id'] ?? $item->product_id) : $item->product_id,
                    'completed_at' => current_time('mysql'),
                ];
                if ($dur_ms !== null) $upd['duration_ms'] = $dur_ms;
                $wpdb->update($table, $upd, ['id' => $item->id]);

                $pid = is_array($resp) ? ($resp['product_id'] ?? '?') : '?';
                $ms  = is_array($resp) ? ($resp['execution_time_ms'] ?? 0) : 0;
                error_log(sprintf('[FullBAI Queue] #%d OK: %s SKU=%s → #%s (%dms)',
                    $item->id, $item->endpoint, $item->sku, $pid, $ms));
            }

        } catch (Throwable $e) {
            $wpdb->update($table, [
                'status'       => 'failed',
                'error'        => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
                'completed_at' => current_time('mysql'),
            ], ['id' => $item->id]);
            error_log(sprintf('[FullBAI Queue] #%d EXCEPTION: %s', $item->id, $e->getMessage()));
        }

        $remaining_pending = ojf_aq_pending_count();
        error_log(sprintf(
            '[FullBAI Queue] Worker one-shot done: processed=1 remaining_pending=%d',
            $remaining_pending
        ));

        return [
            'processed' => true,
            'queue_id' => (int) $item->id,
            'remaining_pending' => $remaining_pending,
        ];
    } finally {
        $GLOBALS['ojf_aq_worker_active'] = false;
        update_option('ojf_aq_last_worker_run', current_time('mysql'));
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXECUTOR — Chama handler original com WP_REST_Request fake
// ═══════════════════════════════════════════════════════════════════════════════

function ojf_aq_execute($item) {
    $handlers = [
        'create' => 'ojf_create_product_handler',
        'update' => 'ojf_update_product_handler',
        'delete' => 'ojf_delete_product_handler',
    ];

    $handler = $handlers[$item->endpoint] ?? null;
    if (!$handler || !function_exists($handler)) {
        return new WP_Error('handler_missing', 'Handler not found: ' . ($handler ?? $item->endpoint));
    }

    $routes = [
        'create' => '/odontojf/v1/create-product',
        'update' => '/odontojf/v1/update-product',
        'delete' => '/odontojf/v1/delete-product',
    ];

    $request = new WP_REST_Request('POST', $routes[$item->endpoint]);
    $request->set_header('Authorization', 'Bearer ' . $item->api_key);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body($item->payload);

    return call_user_func($handler, $request);
}

// ═══════════════════════════════════════════════════════════════════════════════
// RECOVERY — Itens stuck
// ═══════════════════════════════════════════════════════════════════════════════

function ojf_aq_recover_stuck() {
    global $wpdb;
    $table  = ojf_aq_table();
    $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - OJF_AQ_STUCK_SEC);

    $stuck = $wpdb->get_results($wpdb->prepare(
        "SELECT id, attempts FROM `{$table}` WHERE status = 'processing' AND started_at < %s",
        $cutoff
    ));

    foreach ($stuck as $s) {
        if ($s->attempts >= OJF_AQ_MAX_ATTEMPTS) {
            $wpdb->update($table, [
                'status' => 'failed', 'error' => 'Stuck — max attempts',
                'completed_at' => current_time('mysql'),
            ], ['id' => $s->id]);
        } else {
            $wpdb->update($table, [
                'status' => 'pending', 'started_at' => null,
                'error' => 'Recovered from stuck',
            ], ['id' => $s->id]);
        }
    }

    if (!empty($stuck)) {
        error_log('[FullBAI Queue] Recovered ' . count($stuck) . ' stuck items');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CRON FALLBACK
// ═══════════════════════════════════════════════════════════════════════════════

add_filter('cron_schedules', function($s) {
    if (!isset($s['ojf_aq_1min'])) {
        $s['ojf_aq_1min'] = ['interval' => 60, 'display' => 'FullBAI Queue 1min'];
    }
    return $s;
});

add_action('init', function() {
    if (!wp_next_scheduled('ojf_aq_cron')) {
        wp_schedule_event(time() + 60, 'ojf_aq_1min', 'ojf_aq_cron');
    }
}, 30);

add_action('ojf_aq_cron', function() {
    if (ojf_aq_pending_count() === 0) return;
    error_log('[FullBAI Queue] Cron fallback: ' . ojf_aq_pending_count() . ' pending');
    ojf_aq_become_worker();
});

add_action('ojf_aq_kick', function() {
    if (ojf_aq_pending_count() === 0) return;
    ojf_aq_become_worker();
});

add_action('ojf_aq_cron', function() {
    if (get_transient('ojf_aq_cleaned')) return;
    set_transient('ojf_aq_cleaned', 1, HOUR_IN_SECONDS);
    global $wpdb;
    $del = $wpdb->query($wpdb->prepare(
        "DELETE FROM `" . ojf_aq_table() . "` WHERE status='completed' AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        OJF_AQ_CLEANUP_DAYS
    ));
    if ($del > 0) error_log('[FullBAI Queue] Cleanup: ' . $del . ' old items');
}, 20);

function ojf_aq_internal_worker_handler() {
    $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
    if (!$token || !hash_equals(ojf_aq_internal_worker_token(), $token)) {
        status_header(403);
        exit('forbidden');
    }

    ojf_aq_become_worker();
    exit('ok');
}

add_action('wp_ajax_ojf_aq_internal_worker', 'ojf_aq_internal_worker_handler');
add_action('wp_ajax_nopriv_ojf_aq_internal_worker', 'ojf_aq_internal_worker_handler');

// ═══════════════════════════════════════════════════════════════════════════════
// AUTO-TRIGGER POR PAGE-LOAD — rede de segurança independente do WP-Cron (v2.3.0)
// ═══════════════════════════════════════════════════════════════════════════════
// Cada page load (admin/front) checa, com rate-limit de 50s via transient,
// se existe item pending com mais de 60s. Se sim, agenda o worker para o
// shutdown da própria request (não bloqueia a entrega da página).
// Custo médio por request: 1 get_transient (Redis se houver object cache).
// Custo a cada 50s: + 1 SELECT 1 ... LIMIT 1 com índice em `status`.
// NÃO toca worker / lock / retry / recovery — só adiciona um gatilho extra.

add_action('init', function() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // Pular contextos que já tem trigger próprio
    if (wp_doing_cron()) return;                       // ojf_aq_cron já roda o worker
    if (defined('REST_REQUEST') && REST_REQUEST) return; // interceptor REST já dispara trigger

    if (get_transient('ojf_aq_autotrigger_lock')) return;
    set_transient('ojf_aq_autotrigger_lock', 1, 50);

    global $wpdb;
    $stale = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM `" . ojf_aq_table() . "`
         WHERE status='pending' AND created_at < %s LIMIT 1",
        gmdate('Y-m-d H:i:s', time() - 60)
    ));
    if ($stale) ojf_aq_trigger_worker();
}, 100);

// ═══════════════════════════════════════════════════════════════════════════════
// TRIGGER EXTERNO via ?ojf_queue_trigger=1 (v2.3.1)
// ═══════════════════════════════════════════════════════════════════════════════
// Mesmo token usado pelo sync v7 e pela image queue. Cada arquivo escuta o
// proprio handler — sem dependencia entre filas. Roda em init priority 15
// (antes do handler do sync que esta em priority 20).
// Nao chama die() — sync responde o JSON unificado.

if (!defined('OJF_QUEUE_TRIGGER_TOKEN')) {
    define('OJF_QUEUE_TRIGGER_TOKEN', 'fb_queue_3b5cfdd28d9a788c411334f9039a3d0a459f4524b806b96f');
}

function ojf_aq_validate_external_trigger() {
    static $valid = null;
    if ($valid !== null) return $valid;

    if (!isset($_GET['ojf_queue_trigger'])) { $valid = false; return $valid; }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $valid = false; return $valid; }

    if (!isset($GLOBALS['ojf_queue_trigger_body'])) {
        $GLOBALS['ojf_queue_trigger_body'] = file_get_contents('php://input');
    }
    $body = json_decode($GLOBALS['ojf_queue_trigger_body'], true);
    $valid = (is_array($body)
        && isset($body['token'])
        && hash_equals(OJF_QUEUE_TRIGGER_TOKEN, (string) $body['token']));
    return $valid;
}

add_action('init', function() {
    if (!ojf_aq_validate_external_trigger()) return;

    @ignore_user_abort(true);
    @set_time_limit(120);

    ojf_aq_become_worker();
    update_option('ojf_aq_last_trigger_get', current_time('mysql'));
}, 15);

// ═══════════════════════════════════════════════════════════════════════════════
// ENDPOINT DE STATUS
// ═══════════════════════════════════════════════════════════════════════════════

add_action('rest_api_init', function() {
    register_rest_route('odontojf/v1', '/queue-status', [
        'methods'  => 'GET',
        'callback' => function($request) {
            global $wpdb;
            $table = ojf_aq_table();

            // Busca por id único (usada pelo reconciler do Worker p/ puxar o
            // timing real + product_id de volta). Aditivo ao agregado original.
            $qid = (int) $request->get_param('queue_id');
            if ($qid) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status, duration_ms, product_id, error, attempts, sku FROM `{$table}` WHERE id=%d", $qid
                ));
                if (!$row) return rest_ensure_response(['error' => 'not found']);
                return rest_ensure_response([
                    'queue_id'    => (int) $row->id,
                    'status'      => $row->status,
                    'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                    'product_id'  => $row->product_id !== null ? (int) $row->product_id : null,
                    'error'       => $row->error,
                    'attempts'    => (int) $row->attempts,
                    'sku'         => $row->sku,
                ]);
            }

            $stats = [];
            foreach ($wpdb->get_results("SELECT status, COUNT(*) as c FROM `{$table}` GROUP BY status") as $r) {
                $stats[$r->status] = (int) $r->c;
            }
            $recent = $wpdb->get_results(
                "SELECT id, endpoint, seller, sku, product_id, status, error, 
                        created_at, started_at, completed_at
                 FROM `{$table}` ORDER BY id DESC LIMIT 20"
            );
            $lock_free = (int) $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", OJF_AQ_LOCK_NAME));
            $slots_busy = 0;
            $rest_concurrency = ojf_aq_get_concurrency();
            for ($i = 1; $i <= $rest_concurrency; $i++) {
                $f = (int) $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", OJF_AQ_LOCK_NAME . '_' . $i));
                if (!$f) $slots_busy++;
            }
            return rest_ensure_response(['counts' => $stats, 'slots_busy' => $slots_busy, 'slots_total' => $rest_concurrency, 'recent' => $recent]);
        },
        'permission_callback' => function($req) {
            // Bearer (Worker) OU admin (dashboard). Single-tenant: segredo único.
            if (ojf_validate_bearer($req)) return true;
            return current_user_can('manage_woocommerce');
        },
    ]);

    register_rest_route('odontojf/v1', '/queue-retry-failed', [
        'methods'  => 'POST',
        'callback' => function() {
            global $wpdb;
            $n = (int) $wpdb->query(
                "UPDATE `" . ojf_aq_table() . "` SET status='pending', started_at=NULL, error=NULL, attempts=0 WHERE status='failed'"
            );
            $run = ojf_aq_run_one_worker_now();
            return rest_ensure_response([
                'retried' => $n,
                'lock_acquired' => !empty($run['lock_acquired']),
                'remaining_pending' => $run['remaining_pending'] ?? ojf_aq_pending_count(),
            ]);
        },
        'permission_callback' => function() { return current_user_can('manage_woocommerce'); },
    ]);
});

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN UI — Menu no WooCommerce + AJAX + Painel
// ═══════════════════════════════════════════════════════════════════════════════

add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'API Queue',
        'API Queue',
        'manage_woocommerce',
        'fullbai-api-queue',
        'ojf_aq_render_page'
    );
});

// ── AJAX: Stats ──
add_action('wp_ajax_ojf_aq_stats', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    global $wpdb;
    $table = ojf_aq_table();

    $today = date('Y-m-d 00:00:00', current_time('timestamp'));

    $rows = $wpdb->get_results("SELECT status, COUNT(*) as c FROM `{$table}` GROUP BY status");
    $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'passed' => 0];
    foreach ($rows as $r) if (isset($counts[$r->status])) $counts[$r->status] = (int) $r->c;

    $completed_today = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE status IN ('completed','passed') AND completed_at >= %s", $today
    ));

    // Contar slots ocupados
    $slots_busy = 0;
    $aj_concurrency = ojf_aq_get_concurrency();
    for ($i = 1; $i <= $aj_concurrency; $i++) {
        $free = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT IS_FREE_LOCK(%s)", OJF_AQ_LOCK_NAME . '_' . $i
        ));
        if (!$free) $slots_busy++;
    }

    // v2.4.0: usa duration_ms quando existir (ms reais); fallback TIMESTAMPDIFF em segundos*1000
    $avg_ms = $wpdb->get_var($wpdb->prepare(
        "SELECT AVG(COALESCE(duration_ms, TIMESTAMPDIFF(SECOND, started_at, completed_at) * 1000))
         FROM `{$table}` WHERE status='completed' AND completed_at >= %s AND started_at IS NOT NULL", $today
    ));

    $next_scheduled = wp_next_scheduled('ojf_aq_cron');
    $last_worker_run = get_option('ojf_aq_last_worker_run', false);
    $last_trigger_get = get_option('ojf_aq_last_trigger_get', false);

    wp_send_json_success([
        'pending'         => $counts['pending'],
        'processing'      => $counts['processing'],
        'completed'       => $counts['completed'],
        'failed'          => $counts['failed'],
        'passed'          => $counts['passed'],
        'completed_today' => $completed_today,
        'slots_busy'      => $slots_busy,
        'slots_total'     => $aj_concurrency,
        'avg_ms'          => $avg_ms !== null ? (int) round((float) $avg_ms) : null,
        'concurrency'     => $aj_concurrency,
        'last_worker_run' => $last_worker_run ?: 'never',
        'last_trigger_get'=> $last_trigger_get ?: 'never',
        'cron_next'       => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : false,
    ]);
});

// v2.4.0/4.1: AJAX setter da concurrency (UI). Usa helper que forca autoload=yes.
add_action('wp_ajax_ojf_aq_set_concurrency', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('forbidden', 403);
    $v = isset($_POST['value']) ? (int) $_POST['value'] : 0;
    if ($v < 1 || $v > 10) wp_send_json_error('Valor deve estar entre 1 e 10');
    $saved = ojf_aq_set_concurrency_value($v);
    wp_send_json_success(['concurrency' => $saved]);
});

// ── AJAX: Itens recentes ──
add_action('wp_ajax_ojf_aq_recent', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    global $wpdb;
    $table = ojf_aq_table();
    $today = isset($_POST['today']) && $_POST['today'] === '1';
    $date  = isset($_POST['date']) ? preg_replace('/[^0-9\-]/', '', (string) $_POST['date']) : '';
    $limit = isset($_POST['limit']) ? max(1, min(20000, (int) $_POST['limit'])) : 1500;

    // today=1 → só hoje | date=YYYY-MM-DD → aquele dia | senão → recentes (LIMIT).
    if ($today) {
        $where = 'WHERE DATE(created_at) = CURDATE()'; $lim = '';
    } elseif ($date !== '') {
        $where = $wpdb->prepare('WHERE DATE(created_at) = %s', $date); $lim = '';
    } else {
        $where = ''; $lim = 'LIMIT ' . (int) $limit;
    }
    $items = $wpdb->get_results(
        "SELECT id, endpoint, seller, sku, product_id, status, error, attempts,
                duration_ms, created_at, started_at, completed_at
         FROM `{$table}` {$where}
         ORDER BY FIELD(status, 'processing', 'pending', 'failed', 'completed', 'passed'), id DESC {$lim}"
    );
    // Títulos em LOTE (1 query). Thumbnail/preço (wc_get_product) só p/ conjuntos
    // pequenos (≤500) — senão N+1 travava o histórico de milhares de linhas.
    $pids = array_values(array_unique(array_filter(array_map(function ($it) { return (int) $it->product_id; }, (array) $items))));
    $titles = [];
    if ($pids) {
        $in = implode(',', array_map('intval', $pids));
        foreach ($wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($in)") as $r) {
            $titles[(int) $r->ID] = $r->post_title;
        }
    }
    $enrich = count((array) $items) <= 500;
    foreach ((array) $items as $it) {
        $it->image_url = ''; $it->stock_quantity = null; $it->price = null; $it->sale_price = null; $it->stock_status = null;
        $pid = (int) $it->product_id;
        $it->product_name = isset($titles[$pid]) && $titles[$pid] !== '' ? $titles[$pid] : '';
        if ($enrich && $pid && ($p = wc_get_product($pid))) {
            $img = $p->get_image_id() ? wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') : '';
            $it->image_url = $img ?: '';
            $it->price = $p->get_price();
            $it->stock_status = $p->get_stock_status();
        }
        if (!$it->product_name) $it->product_name = $it->sku;
    }
    wp_send_json_success(['items' => $items ?: [], 'total' => count((array) $items)]);
});

// ── AJAX: Detalhe/log de um item ──
add_action('wp_ajax_ojf_aq_item_log', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    $id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    if ($id <= 0) wp_send_json_error('ID inválido');

    global $wpdb;
    $table = ojf_aq_table();

    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT id, endpoint, seller, sku, product_id, status, error, attempts, payload, result, duration_ms, created_at, started_at, completed_at
         FROM `{$table}` WHERE id = %d LIMIT 1",
        $id
    ), ARRAY_A);

    if (!$item) wp_send_json_error('Item não encontrado');

    $payload = ojf_aq_decode_json($item['payload']);
    $result  = ojf_aq_decode_json($item['result']);

    wp_send_json_success([
        'item' => [
            'id' => (int) $item['id'],
            'endpoint' => $item['endpoint'],
            'seller' => $item['seller'],
            'sku' => $item['sku'],
            'product_id' => $item['product_id'] ? (int) $item['product_id'] : null,
            'status' => $item['status'],
            'error' => $item['error'],
            'attempts' => (int) $item['attempts'],
            'created_at' => $item['created_at'],
            'started_at' => $item['started_at'],
            'completed_at' => $item['completed_at'],
        ],
        'payload' => $payload,
        'payload_raw' => $item['payload'],
        'result' => $result,
        'result_raw' => $item['result'],
        'current_product_images' => ojf_aq_product_image_state($item['product_id']),
    ]);
});

// ── AJAX: Process Now ──
add_action('wp_ajax_ojf_aq_process_now', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    $before = ojf_aq_pending_count();
    $run = ojf_aq_run_one_worker_now();

    $after = ojf_aq_pending_count();

    wp_send_json_success([
        'processed'      => $before - $after,
        'pending_before' => $before,
        'pending_after'  => $after,
        'lock_acquired'  => !empty($run['lock_acquired']),
    ]);
});

// ── AJAX: Retry Failed ──
add_action('wp_ajax_ojf_aq_retry', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    global $wpdb;
    $n = (int) $wpdb->query(
        "UPDATE `" . ojf_aq_table() . "` SET status='pending', started_at=NULL, error=NULL, attempts=0 WHERE status='failed'"
    );
    $run = ojf_aq_run_one_worker_now();
    wp_send_json_success([
        'retried' => $n,
        'lock_acquired' => !empty($run['lock_acquired']),
        'remaining_pending' => $run['remaining_pending'] ?? ojf_aq_pending_count(),
    ]);
});

// ── AJAX: Clear Completed ──
add_action('wp_ajax_ojf_aq_clear', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    global $wpdb;
    $n = (int) $wpdb->query("DELETE FROM `" . ojf_aq_table() . "` WHERE status IN ('completed','passed')");
    wp_send_json_success(['cleared' => $n]);
});

// ── AJAX: Reprocess Single Item ──
add_action('wp_ajax_ojf_aq_reprocess', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    $id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    if ($id <= 0) wp_send_json_error('ID inválido');

    global $wpdb;
    $table = ojf_aq_table();

    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id));
    if (!$item) wp_send_json_error('Item não encontrado');

    // Reset para pending independente do status atual
    $wpdb->update($table, [
        'status'       => 'pending',
        'started_at'   => null,
        'completed_at' => null,
        'error'        => null,
        'attempts'     => 0,
        'result'       => null,
    ], ['id' => $id]);

    $run = ojf_aq_run_one_worker_now($id);

    wp_send_json_success([
        'reprocessed' => $id,
        'product_id' => $item->product_id,
        'processed_now' => !empty($run['processed']),
        'lock_acquired' => !empty($run['lock_acquired']),
        'remaining_pending' => $run['remaining_pending'] ?? ojf_aq_pending_count(),
    ]);
});

// ── AJAX: Clear Failed ──
add_action('wp_ajax_ojf_aq_clear_failed', function() {
    check_ajax_referer('ojf_aq_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('No permission');

    global $wpdb;
    $table = ojf_aq_table();
    // Primeiro converter failed com mensagens conhecidas para passed
    $converted = (int) $wpdb->query(
        "UPDATE `{$table}` SET status='passed' WHERE status='failed' AND (error LIKE '%already have a product with this SKU%' OR error LIKE '%Product not found%')"
    );
    // Depois deletar os failed reais
    $deleted = (int) $wpdb->query("DELETE FROM `{$table}` WHERE status='failed'");
    wp_send_json_success(['converted' => $converted, 'deleted' => $deleted]);
});

// ── RENDER PAGE ──
function ojf_aq_render_page() {
    $nonce = wp_create_nonce('ojf_aq_nonce');
    $ajax  = admin_url('admin-ajax.php');
    ?>
    <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#000;color:#f5f5f7;padding:40px 24px;margin-left:-20px;min-height:100vh;">
    <div style="max-width:1200px;margin:0 auto;">

        <h1 style="font-size:40px;font-weight:700;letter-spacing:-0.5px;margin:0 0 6px;">API Queue</h1>
        <p style="color:#86868b;font-size:17px;margin:0 0 16px;">Fila de execução da API de produtos — até <span id="hdr-concurrency"><?php echo (int) ojf_aq_get_concurrency(); ?></span> simultâneos</p>

        <!-- v2.4.0: Configurar workers (concurrency) -->
        <div style="display:flex;align-items:center;gap:10px;margin:0 0 28px;padding:12px 16px;background:#1d1d1f;border:1px solid #2d2d2d;border-radius:10px;font-size:13px;color:#86868b;">
            <span>Workers concorrentes:</span>
            <input id="cfg-concurrency" type="number" min="1" max="10" step="1" value="<?php echo (int) ojf_aq_get_concurrency(); ?>" style="background:#000;border:1px solid #3a3a3c;color:#f5f5f7;padding:6px 10px;border-radius:8px;width:70px;font-size:13px;outline:none;">
            <button onclick="saveConcurrency()" style="background:#0a84ff;border:none;color:#fff;padding:7px 16px;border-radius:980px;cursor:pointer;font-weight:600;font-size:13px;">Salvar</button>
            <span id="cfg-msg" style="color:#86868b;font-size:12px;"></span>
            <span style="margin-left:auto;color:#48484a;font-size:11px;">Valor de 1 a 10. Aplica nos próximos drains do worker.</span>
        </div>

        <!-- STATS -->
        <div id="stats" style="display:grid;grid-template-columns:repeat(7,1fr);gap:14px;margin-bottom:28px;">
            <div class="stat-card" data-filter="pending" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Pending</div>
                <div id="s-pending" style="font-size:36px;font-weight:700;color:#ff9f0a;">0</div>
            </div>
            <div class="stat-card" data-filter="processing" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Processing</div>
                <div id="s-processing" style="font-size:36px;font-weight:700;color:#0a84ff;">0</div>
            </div>
            <div class="stat-card" data-filter="completed" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Hoje</div>
                <div id="s-today" style="font-size:36px;font-weight:700;color:#30d158;">0</div>
            </div>
            <div class="stat-card" data-filter="failed" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Failed</div>
                <div id="s-failed" style="font-size:36px;font-weight:700;color:#ff453a;">0</div>
            </div>
            <div class="stat-card" data-filter="passed" style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;cursor:pointer;transition:border-color .2s;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Passed</div>
                <div id="s-passed" style="font-size:36px;font-weight:700;color:#ff9500;">0</div>
            </div>
            <div style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Tempo medio</div>
                <div id="s-avg" style="font-size:36px;font-weight:700;">—</div>
            </div>
            <div style="background:#1d1d1f;padding:20px;border-radius:14px;border:1px solid #2d2d2d;">
                <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;">Workers</div>
                <div id="s-worker" style="font-size:24px;font-weight:700;margin-top:6px;">—</div>
            </div>
        </div>

        <!-- BOTOES -->
        <div style="display:flex;gap:10px;margin-bottom:28px;flex-wrap:wrap;align-items:center;">
            <button onclick="processNow()" style="background:#0a84ff;border:none;color:#fff;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg> Process Now</button>
            <button onclick="retryFailed()" style="background:#ff9f0a;border:none;color:#000;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> Retry Failed</button>
            <button onclick="clearCompleted()" style="background:transparent;border:1px solid #3a3a3c;color:#f5f5f7;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> Clear Completed</button>
            <button onclick="clearFailed()" style="background:transparent;border:1px solid #ff453a;color:#ff453a;padding:10px 22px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;display:inline-flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> Clear Failed</button>
            <span id="filter-label" style="display:none;background:#2d2d2d;color:#f5f5f7;padding:6px 14px;border-radius:980px;font-size:13px;font-weight:600;cursor:pointer;" onclick="clearFilter()"></span>

            <span style="display:inline-flex;align-items:center;gap:8px;margin-left:auto;">
                <select id="seller-filter" onchange="setSellerFilter(this.value)" style="background:#1d1d1f;border:1px solid #3a3a3c;color:#f5f5f7;padding:5px 10px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;outline:none;">
                    <option value="">Todos sellers</option>
                </select>
                <button id="date-filter-btn" onclick="toggleDateFilter()" title="Filtrar por data" style="background:transparent;border:1px solid #3a3a3c;color:#86868b;padding:5px 8px;border-radius:980px;cursor:pointer;display:inline-flex;align-items:center;transition:all .2s;" onmouseover="this.style.borderColor='#0a84ff';this.style.color='#0a84ff'" onmouseout="if(!window.__dateFilterActive){this.style.borderColor='#3a3a3c';this.style.color='#86868b'}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </button>
                <input id="date-filter-input" type="date" onchange="setDateFilter(this.value)" style="display:none;background:#1d1d1f;border:1px solid #3a3a3c;color:#f5f5f7;padding:5px 10px;border-radius:980px;font-size:12px;outline:none;">
                <span style="color:#86868b;font-size:12px;margin-left:4px;margin-right:4px;">Mostrar:</span>
                <button class="limit-btn active-limit" data-limit="100" onclick="setLimit(100)" style="background:#2d2d2d;border:1px solid #48484a;color:#f5f5f7;padding:5px 12px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">100</button>
                <button class="limit-btn" data-limit="500" onclick="setLimit(500)" style="background:transparent;border:1px solid #3a3a3c;color:#86868b;padding:5px 12px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">500</button>
                <button class="limit-btn" data-limit="1500" onclick="setLimit(1500)" style="background:transparent;border:1px solid #3a3a3c;color:#86868b;padding:5px 12px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">1500</button>
                <button class="limit-btn" data-limit="today" id="btn-today" onclick="loadToday()" style="background:transparent;border:1px solid #30d158;color:#30d158;padding:5px 14px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">Today</button>
                <button id="btn-history" onclick="loadHistory(5000)" title="Carrega todo o histórico (até 5000 mais recentes)" style="background:transparent;border:1px solid #bf5af2;color:#bf5af2;padding:5px 14px;border-radius:980px;cursor:pointer;font-weight:600;font-size:12px;">📜 Ver todo histórico</button>
            </span>
            <span id="msg" style="display:inline-flex;align-items:center;color:#86868b;font-size:13px;margin-left:8px;"></span>
        </div>

        <!-- TABELA -->
        <div style="background:#1d1d1f;border-radius:14px;border:1px solid #2d2d2d;overflow:hidden;">
            <div style="overflow-x:auto;max-height:800px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #2d2d2d;position:sticky;top:0;background:#1d1d1f;z-index:1;">
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Photo</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Product</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Endpoint</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Status</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Duração</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Tries</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Criado</th>
                            <th style="padding:12px 10px;text-align:left;color:#86868b;font-size:11px;text-transform:uppercase;">Concluído</th>
                            <th style="padding:12px 10px;text-align:center;color:#86868b;font-size:11px;text-transform:uppercase;">Ação</th>
                        </tr>
                    </thead>
                    <tbody id="tbl">
                        <tr><td colspan="9" style="text-align:center;color:#86868b;padding:40px;">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="margin-top:16px;color:#48484a;font-size:12px;">
            Auto-refresh: stats 5s · tabela 10s · <span id="last-refresh">—</span>
        </div>

        <div style="margin-top:10px;padding:10px 14px;background:#1d1d1f;border:1px solid #2d2d2d;border-radius:10px;color:#86868b;font-size:12px;font-family:monospace;">
            <span id="aq-info-line">Last Run: — | Trigger GET: — | Cron: —</span>
        </div>

    </div>
    </div>

    <div id="aq-log-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:99999;align-items:center;justify-content:center;padding:24px;">
        <div style="width:min(1100px,96vw);max-height:92vh;background:#111214;border:1px solid #2d2d2d;border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.55);overflow:hidden;display:flex;flex-direction:column;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #2d2d2d;">
                <div>
                    <div style="font-size:18px;font-weight:700;color:#f5f5f7;">Log da transação</div>
                    <div id="aq-log-subtitle" style="font-size:12px;color:#86868b;margin-top:4px;">Carregando...</div>
                </div>
                <button onclick="closeLogModal()" style="background:transparent;border:1px solid #3a3a3c;color:#f5f5f7;padding:8px 10px;border-radius:10px;cursor:pointer;font-weight:600;">Fechar</button>
            </div>
            <div id="aq-log-body" style="overflow:auto;padding:20px 22px;display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                <div style="grid-column:1/-1;color:#86868b;">Carregando...</div>
            </div>
        </div>
    </div>

    <script>
    const N = '<?php echo $nonce; ?>';
    const U = '<?php echo $ajax; ?>';

    // Parser resiliente: se a resposta vier vazia ou truncada (ex.: timeout do nginx,
    // fastcgi_finish_request prematuro), NÃO quebra o auto-refresh — devolve
    // {success:false, data:'mensagem'} e a UI segue rodando.
    const post = (action, extra = {}) => {
        const p = new URLSearchParams({action, nonce: N, ...extra});
        return fetch(U, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: p})
            .then(r => r.text().then(t => ({status: r.status, text: t})))
            .then(({status, text}) => {
                if (!text) return {success: false, data: 'Resposta vazia (HTTP ' + status + ' — possível timeout)'};
                try { return JSON.parse(text); }
                catch(e) { return {success: false, data: 'Resposta inválida (HTTP ' + status + '): ' + text.substring(0, 120)}; }
            })
            .catch(e => ({success: false, data: 'Erro de rede: ' + (e && e.message ? e.message : e)}));
    };

    const statusColor = {completed:'#30d158', failed:'#ff453a', pending:'#ff9f0a', processing:'#0a84ff', passed:'#ff9500'};
    const svgCreate = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#30d158" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    const svgUpdate = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0a84ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>';
    const svgDelete = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ff453a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
    const endpointSvg = {create: svgCreate, update: svgUpdate, delete: svgDelete};
    const svgReprocess = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>';
    const svgLog = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v.01"/><path d="M12 7a4 4 0 0 1 0 8"/><circle cx="12" cy="12" r="10"/></svg>';

    function fmtDateTime(dateStr) {
        if (!dateStr) return '\u2014';
        const d = new Date(dateStr.replace(' ', 'T'));
        const dd = String(d.getDate()).padStart(2,'0');
        const mm = String(d.getMonth()+1).padStart(2,'0');
        const hh = String(d.getHours()).padStart(2,'0');
        const mi = String(d.getMinutes()).padStart(2,'0');
        const ss = String(d.getSeconds()).padStart(2,'0');
        return dd+'/'+mm+' '+hh+':'+mi+':'+ss;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function prettyJson(value) {
        if (value === null || value === undefined || value === '') return '<span style="color:#48484a;">vazio</span>';
        if (typeof value === 'string') return '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;">'+escapeHtml(value)+'</pre>';
        return '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;">'+escapeHtml(JSON.stringify(value, null, 2))+'</pre>';
    }

    function renderLogCard(title, content, fullWidth = false) {
        return '<section style="background:#17181c;border:1px solid #2d2d2d;border-radius:14px;padding:16px;'+(fullWidth?'grid-column:1/-1;':'')+'">'
            + '<div style="font-size:12px;font-weight:700;color:#f5f5f7;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;">'+escapeHtml(title)+'</div>'
            + '<div style="font-size:12px;color:#c7c7cc;line-height:1.5;">'+content+'</div>'
            + '</section>';
    }

    function renderKeyValueRows(rows) {
        return rows.map(row => '<div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #222;"><div style="width:140px;color:#86868b;">'+escapeHtml(row.label)+'</div><div style="flex:1;color:#f5f5f7;">'+row.value+'</div></div>').join('');
    }

    function closeLogModal() {
        document.getElementById('aq-log-modal').style.display = 'none';
    }

    async function openLogModal(id) {
        const modal = document.getElementById('aq-log-modal');
        document.getElementById('aq-log-subtitle').textContent = 'Item #' + id;
        document.getElementById('aq-log-body').innerHTML = '<div style="grid-column:1/-1;color:#86868b;">Carregando item #' + id + '...</div>';
        modal.style.display = 'flex';

        const j = await post('ojf_aq_item_log', {item_id: id});
        if (!j.success) {
            document.getElementById('aq-log-body').innerHTML = '<div style="grid-column:1/-1;color:#ff453a;">Erro ao carregar o log.</div>';
            return;
        }

        const item = j.data.item || {};
        const payload = j.data.payload;
        const result = j.data.result;
        const currentImages = j.data.current_product_images;

        document.getElementById('aq-log-subtitle').textContent = 'Item #' + item.id + ' · ' + (item.endpoint || '') + ' · ' + (item.status || '');

        const summaryRows = renderKeyValueRows([
            {label: 'Produto', value: escapeHtml(item.product_id || '\u2014')},
            {label: 'SKU', value: escapeHtml(item.sku || '\u2014')},
            {label: 'Seller', value: escapeHtml(item.seller || '\u2014')},
            {label: 'Tentativas', value: escapeHtml(item.attempts || 0)},
            {label: 'Criado', value: escapeHtml(fmtDateTime(item.created_at))},
            {label: 'Concluído', value: escapeHtml(fmtDateTime(item.completed_at))},
            {label: 'Erro', value: item.error ? '<span style="color:#ff453a;">'+escapeHtml(item.error)+'</span>' : '<span style="color:#30d158;">sem erro</span>'},
        ]);

        const payloadImages = payload && Array.isArray(payload.images)
            ? payload.images.map((img, idx) => {
                const url = (img && typeof img === 'object') ? img.src : img;
                return '<div style="padding:5px 0;border-bottom:1px solid #222;">#'+(idx+1)+' · '+escapeHtml(url || '')+'</div>';
            }).join('')
            : '<span style="color:#48484a;">sem images no payload</span>';

        let currentImageRows = '<span style="color:#48484a;">produto não encontrado</span>';
        if (currentImages) {
            const galleryRows = (currentImages.gallery || []).map((img, idx) =>
                '<div style="padding:5px 0;border-bottom:1px solid #222;">#'+(idx+1)+' · att:'+escapeHtml(img.attachment_id)+' · '+escapeHtml(img.url || '')+'</div>'
            ).join('') || '<span style="color:#48484a;">galeria vazia</span>';

            currentImageRows =
                renderKeyValueRows([
                    {label: 'Thumbnail ID', value: escapeHtml(currentImages.thumbnail_id || '\u2014')},
                    {label: 'Thumbnail URL', value: escapeHtml(currentImages.thumbnail_url || '\u2014')},
                    {label: 'Seller URLs', value: currentImages.seller_image_urls && currentImages.seller_image_urls.length ? '<pre style="margin:0;white-space:pre-wrap;">'+escapeHtml(JSON.stringify(currentImages.seller_image_urls, null, 2))+'</pre>' : '<span style="color:#48484a;">vazio</span>'},
                ])
                + '<div style="margin-top:10px;color:#86868b;">Galeria atual</div>'
                + '<div style="margin-top:6px;">'+galleryRows+'</div>';
        }

        document.getElementById('aq-log-body').innerHTML =
            renderLogCard('Resumo', summaryRows)
            + renderLogCard('Images do payload', payloadImages)
            + renderLogCard('Estado atual do produto', currentImageRows, true)
            + renderLogCard('Payload JSON', prettyJson(payload), true)
            + renderLogCard('Result JSON', prettyJson(result), true)
            + renderLogCard('Payload bruto', prettyJson(j.data.payload_raw), true)
            + renderLogCard('Result bruto', prettyJson(j.data.result_raw), true);
    }

    // ── Filtro por limite e status ──
    let activeFilter = null;
    let allItems = [];
    let displayLimit = 100;
    let todayMode = false;
    let sellerFilter = '';
    let dateFilter = '';
    window.__dateFilterActive = false;

    function setLimit(n) {
        // RE-BUSCA no backend (antes só mudava o display do que já estava carregado).
        loadHistory(n);
    }

    async function loadHistory(limit) {
        todayMode = false;
        dateFilter = '';
        window.__dateFilterActive = false;
        var di = document.getElementById('date-filter-input'); if (di) di.value = '';
        var db = document.getElementById('date-filter-btn'); if (db) { db.style.borderColor = '#3a3a3c'; db.style.color = '#86868b'; }
        displayLimit = limit || 1500;
        updateLimitButtons();
        document.getElementById('msg').textContent = 'Carregando histórico...';
        const j = await post('ojf_aq_recent', { limit: String(limit || 1500) });
        if (j.success) {
            allItems = j.data.items || [];
            refreshSellerOptions();
            document.getElementById('msg').textContent = allItems.length + ' itens (histórico completo)';
            renderItems();
            document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString();
        }
    }

    function setSellerFilter(v) {
        sellerFilter = v || '';
        renderItems();
    }

    function toggleDateFilter() {
        const inp = document.getElementById('date-filter-input');
        if (inp.style.display === 'none') {
            inp.style.display = 'inline-flex';
            inp.focus();
            try { inp.showPicker && inp.showPicker(); } catch(e) {}
        } else if (!inp.value) {
            inp.style.display = 'none';
        }
    }

    async function setDateFilter(v) {
        dateFilter = v || '';
        const btn = document.getElementById('date-filter-btn');
        if (dateFilter) {
            window.__dateFilterActive = true;
            btn.style.borderColor = '#0a84ff';
            btn.style.color = '#0a84ff';
            todayMode = false;
            displayLimit = 99999;
            document.getElementById('msg').textContent = 'Carregando ' + dateFilter + '...';
            const j = await post('ojf_aq_recent', { date: dateFilter });
            if (j.success) {
                allItems = j.data.items || [];
                refreshSellerOptions();
                document.getElementById('msg').textContent = allItems.length + ' itens em ' + dateFilter;
                renderItems();
            }
        } else {
            window.__dateFilterActive = false;
            btn.style.borderColor = '#3a3a3c';
            btn.style.color = '#86868b';
            document.getElementById('date-filter-input').style.display = 'none';
            loadHistory(displayLimit > 5000 ? 1500 : displayLimit);
        }
    }

    function refreshSellerOptions() {
        const sel = document.getElementById('seller-filter');
        if (!sel) return;
        const sellers = Array.from(new Set(allItems.map(i => i.seller).filter(Boolean))).sort();
        const current = sel.value;
        sel.innerHTML = '<option value="">Todos sellers</option>' +
            sellers.map(s => '<option value="'+escapeHtml(s)+'"'+(s===current?' selected':'')+'>'+escapeHtml(s)+'</option>').join('');
        if (current && !sellers.includes(current)) {
            sellerFilter = '';
        }
    }

    async function loadToday() {
        todayMode = true;
        displayLimit = 99999;
        updateLimitButtons();
        document.getElementById('msg').textContent = 'Carregando todos de hoje...';
        const j = await post('ojf_aq_recent', {today: '1'});
        if (j.success) {
            allItems = j.data.items || [];
            refreshSellerOptions();
            document.getElementById('msg').textContent = allItems.length + ' itens de hoje';
            renderItems();
            document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString();
        }
    }

    function updateLimitButtons() {
        document.querySelectorAll('.limit-btn').forEach(btn => {
            const isActive = todayMode ? btn.dataset.limit === 'today' : parseInt(btn.dataset.limit) === displayLimit;
            btn.style.background = isActive ? '#2d2d2d' : 'transparent';
            btn.style.color = btn.dataset.limit === 'today' ? '#30d158' : (isActive ? '#f5f5f7' : '#86868b');
            btn.style.borderColor = btn.dataset.limit === 'today' ? '#30d158' : (isActive ? '#48484a' : '#3a3a3c');
        });
    }

    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', function() {
            const f = this.dataset.filter;
            if (activeFilter === f) { clearFilter(); return; }
            activeFilter = f;
            document.querySelectorAll('.stat-card').forEach(c => c.style.borderColor = '#2d2d2d');
            this.style.borderColor = statusColor[f] || '#86868b';
            const label = document.getElementById('filter-label');
            label.style.display = 'inline-flex';
            label.innerHTML = f.toUpperCase() + ' <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" style="margin-left:6px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            label.style.color = statusColor[f] || '#f5f5f7';
            renderItems();
        });
    });

    function clearFilter() {
        activeFilter = null;
        document.querySelectorAll('.stat-card').forEach(c => c.style.borderColor = '#2d2d2d');
        document.getElementById('filter-label').style.display = 'none';
        renderItems();
    }

    async function loadStats() {
        const j = await post('ojf_aq_stats');
        if (!j.success) return;
        const d = j.data;
        document.getElementById('s-pending').textContent = d.pending;
        document.getElementById('s-processing').textContent = d.processing;
        document.getElementById('s-today').textContent = d.completed_today;
        document.getElementById('s-failed').textContent = d.failed;
        document.getElementById('s-passed').textContent = d.passed || 0;
        // v2.4.0: tempo medio em ms reais (vem do duration_ms gravado pelo worker)
        if (d.avg_ms != null) {
            const f = fmtMs(d.avg_ms);
            const el = document.getElementById('s-avg');
            el.textContent = f.text;
            el.style.color = f.color;
        } else {
            document.getElementById('s-avg').textContent = '\u2014';
        }

        const lr = d.last_worker_run && d.last_worker_run !== 'never' ? d.last_worker_run : 'never';
        const lt = d.last_trigger_get && d.last_trigger_get !== 'never' ? d.last_trigger_get : 'never';
        const cn = d.cron_next ? d.cron_next : 'OFF';
        document.getElementById('aq-info-line').textContent = 'Last Run: ' + lr + ' | Trigger GET: ' + lt + ' | Cron: ' + cn;

        const w = document.getElementById('s-worker');
        const svgBolt = '<svg width="18" height="18" viewBox="0 0 24 24" fill="#0a84ff"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10"/></svg>';
        const svgWait = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ff9f0a" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        const svgCheck = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#30d158" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        if (d.slots_busy > 0) {
            w.innerHTML = svgBolt + ' ' + d.slots_busy + '/' + d.slots_total;
            w.style.color = '#0a84ff';
        } else if (d.pending > 0) {
            w.innerHTML = svgWait + ' 0/' + d.slots_total;
            w.style.color = '#ff9f0a';
        } else {
            w.innerHTML = svgCheck + ' 0/' + d.slots_total;
            w.style.color = '#30d158';
        }
    }

    // v2.4.0: aceita item completo. Prioriza duration_ms gravado pelo worker (ms reais
    // do execution_time_ms do response). Fallback: diff start/end (segundos arredondados).
    function fmtMs(ms) {
        let text, color;
        if (ms < 1000)      { text = Math.round(ms) + 'ms';        color = '#30d158'; }
        else if (ms < 5000) { text = (ms/1000).toFixed(3) + 's';   color = '#30d158'; }
        else if (ms < 15000){ text = (ms/1000).toFixed(2) + 's';   color = '#ff9f0a'; }
        else if (ms < 60000){ text = (ms/1000).toFixed(1) + 's';   color = '#ff453a'; }
        else {
            const sec = ms / 1000;
            text = Math.floor(sec/60) + 'm ' + Math.round(sec%60) + 's';
            color = '#ff453a';
        }
        return {text, color};
    }
    function fmtDuration(startedOrItem, completed) {
        // forma nova: fmtDuration(item)
        if (typeof startedOrItem === 'object' && startedOrItem !== null) {
            const it = startedOrItem;
            if (it.duration_ms !== null && it.duration_ms !== undefined && it.duration_ms !== '') {
                return fmtMs(parseInt(it.duration_ms, 10));
            }
            if (!it.started_at || !it.completed_at) return {text: '\u2014', color: '#48484a'};
            return fmtMs(new Date(it.completed_at.replace(' ','T')) - new Date(it.started_at.replace(' ','T')));
        }
        // forma antiga: fmtDuration(started, completed)
        const started = startedOrItem;
        if (!started || !completed) return {text: '\u2014', color: '#48484a'};
        return fmtMs(new Date(completed.replace(' ','T')) - new Date(started.replace(' ','T')));
    }

    async function saveConcurrency() {
        const v = parseInt(document.getElementById('cfg-concurrency').value, 10);
        if (!v || v < 1 || v > 10) {
            document.getElementById('cfg-msg').textContent = 'Valor invalido (1-10)';
            document.getElementById('cfg-msg').style.color = '#ff453a';
            return;
        }
        const r = await post('ojf_aq_set_concurrency', { value: v });
        if (r.success) {
            document.getElementById('cfg-msg').textContent = 'Salvo: ' + r.data.concurrency + ' workers';
            document.getElementById('cfg-msg').style.color = '#30d158';
            document.getElementById('hdr-concurrency').textContent = r.data.concurrency;
            setTimeout(() => { document.getElementById('cfg-msg').textContent = ''; }, 4000);
        } else {
            document.getElementById('cfg-msg').textContent = 'Erro: ' + (r.data || '?');
            document.getElementById('cfg-msg').style.color = '#ff453a';
        }
    }

    const svgTimer = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#0a84ff" stroke-width="2"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3L2 6"/><path d="M22 6l-3-3"/></svg>';

    function renderItems() {
        const el = document.getElementById('tbl');
        let items = allItems;
        if (activeFilter) items = items.filter(i => i.status === activeFilter);
        if (sellerFilter) items = items.filter(i => (i.seller || '') === sellerFilter);
        if (dateFilter) items = items.filter(i => (i.created_at || '').substring(0,10) === dateFilter);
        const totalFiltered = items.length;
        items = items.slice(0, displayLimit);
        if (!items || items.length === 0) {
            const reasons = [];
            if (activeFilter) reasons.push('status '+activeFilter);
            if (sellerFilter) reasons.push('seller '+sellerFilter);
            if (dateFilter) reasons.push('data '+dateFilter);
            el.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#48484a;padding:40px;">'+(reasons.length ? 'Nenhum item com '+reasons.join(' + ') : 'Fila vazia')+'</td></tr>';
            return;
        }

        el.innerHTML = items.map(i => {
            const sc = statusColor[i.status] || '#86868b';
            const icon = endpointSvg[i.endpoint] || '';
            const errColor = i.status === 'passed' ? '#ff9500' : '#ff453a';
            const err = i.error ? '<div style="color:'+errColor+';font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+i.error.replace(/"/g,'&quot;')+'">'+i.error.substring(0,80)+'</div>' : '';
            const dur = fmtDuration(i);
            const durRunning = (i.status === 'processing' && i.started_at) ? fmtDuration(i.started_at, new Date().toISOString().replace('T',' ').substring(0,19)) : null;
            const durCell = durRunning
                ? '<span style="color:#0a84ff;font-weight:600;">'+svgTimer+' '+durRunning.text+'</span>'
                : '<span style="color:'+dur.color+';font-weight:600;">'+dur.text+'</span>';

            // Photo
            const img = i.image_url
                ? '<img src="'+i.image_url+'" style="width:44px;height:44px;object-fit:contain;border-radius:6px;background:#1a1a1a;">'
                : '<div style="width:44px;height:44px;background:#2c2c2e;border-radius:6px;display:flex;align-items:center;justify-content:center;">'+icon+'</div>';

            // Product card
            const name = i.product_name ? i.product_name.substring(0, 45) : (i.endpoint === 'create' ? '<span style="color:#48484a;font-style:italic;">novo produto</span>' : '');
            const pid = i.product_id ? '<a href="post.php?post='+i.product_id+'&action=edit" style="color:#0a84ff;text-decoration:none;">'+i.product_id+'</a>' : '';
            const sku = i.sku || '';
            const stock = i.stock_quantity !== null && i.stock_quantity !== undefined ? i.stock_quantity : '';
            const price = i.price ? '$' + parseFloat(i.price).toFixed(2) : '';
            const sale = (i.sale_price && parseFloat(i.sale_price) > 0) ? ' \u2192 $' + parseFloat(i.sale_price).toFixed(2) : '';
            const meta = [pid, sku].filter(Boolean).join(' \u00B7 ');
            const stockPrice = [stock !== '' ? 'Stock: ' + stock : '', price + sale].filter(Boolean).join(' \u00B7 ');

            return '<tr style="border-bottom:1px solid #2d2d2d;">'
                + '<td style="padding:8px 10px;">'+img+'</td>'
                + '<td style="padding:8px 10px;min-width:200px;"><div style="font-weight:600;font-size:12px;color:#f5f5f7;line-height:1.3;">'+name+'</div><div style="color:#86868b;font-size:11px;">'+meta+'</div>'+(stockPrice?'<div style="color:#48484a;font-size:11px;">'+stockPrice+'</div>':'')+'</td>'
                + '<td style="padding:8px 10px;"><span style="font-size:12px;display:inline-flex;align-items:center;gap:4px;">'+icon+' '+i.endpoint+'</span><div style="color:#48484a;font-size:10px;">'+(i.seller||'')+'</div></td>'
                + '<td style="padding:8px 10px;"><span style="color:'+sc+';font-weight:600;">'+i.status+'</span>'+err+'</td>'
                + '<td style="padding:8px 10px;text-align:center;">'+durCell+'</td>'
                + '<td style="padding:8px 10px;text-align:center;color:#86868b;">'+i.attempts+'</td>'
                + '<td style="padding:8px 10px;color:#48484a;font-size:11px;">'+fmtDateTime(i.created_at)+'</td>'
                + '<td style="padding:8px 10px;color:#48484a;font-size:11px;">'+fmtDateTime(i.completed_at)+'</td>'
                + '<td style="padding:8px 10px;text-align:center;"><div style="display:flex;align-items:center;justify-content:center;gap:6px;"><button onclick="reprocessItem('+i.id+')" title="Reprocessar este item — volta para pending e executa novamente" style="background:transparent;border:1px solid #3a3a3c;color:#ff9f0a;padding:6px 8px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;transition:all .2s;" onmouseover="this.style.background=\'#2d2d2d\';this.style.borderColor=\'#ff9f0a\'" onmouseout="this.style.background=\'transparent\';this.style.borderColor=\'#3a3a3c\'">'+svgReprocess+'</button><button onclick="openLogModal('+i.id+')" title="Ver payload, result e estado atual do produto" style="background:transparent;border:1px solid #3a3a3c;color:#0a84ff;padding:6px 8px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;transition:all .2s;" onmouseover="this.style.background=\'#2d2d2d\';this.style.borderColor=\'#0a84ff\'" onmouseout="this.style.background=\'transparent\';this.style.borderColor=\'#3a3a3c\'">'+svgLog+'</button></div></td>'
                + '</tr>';
        }).join('');

        // Mostrar contador se há mais itens do que o exibido
        if (totalFiltered > items.length) {
            el.innerHTML += '<tr><td colspan="9" style="text-align:center;color:#86868b;padding:10px;font-size:12px;">Mostrando '+items.length+' de '+totalFiltered+' itens</td></tr>';
        }
    }

    async function loadRecent() {
        if (todayMode) return; // Não recarregar no auto-refresh quando está em modo Today
        const j = await post('ojf_aq_recent');
        if (!j.success) return;
        allItems = j.data.items || [];
        refreshSellerOptions();
        renderItems();
        document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString();
    }

    async function processNow() {
        document.getElementById('msg').textContent = 'Processando...';
        const j = await post('ojf_aq_process_now');
        if (j.success) {
            document.getElementById('msg').textContent = j.data.processed + ' processados (' + j.data.pending_before + ' \u2192 ' + j.data.pending_after + ')';
        } else {
            document.getElementById('msg').textContent = 'Erro';
        }
        loadStats(); loadRecent();
    }

    async function retryFailed() {
        const j = await post('ojf_aq_retry');
        if (j.success) {
            document.getElementById('msg').textContent = j.data.retried + ' re-enfileirados';
        }
        loadStats(); loadRecent();
    }

    async function clearCompleted() {
        const j = await post('ojf_aq_clear');
        if (j.success) {
            document.getElementById('msg').textContent = j.data.cleared + ' removidos';
        }
        loadStats(); loadRecent();
    }

    async function reprocessItem(id) {
        if (!confirm('Reprocessar item #' + id + '?')) return;
        document.getElementById('msg').textContent = 'Reprocessando #' + id + '...';
        const j = await post('ojf_aq_reprocess', {item_id: id});
        if (j.success) {
            document.getElementById('msg').textContent = 'Item #' + id + ' re-enfileirado com sucesso';
        } else {
            document.getElementById('msg').textContent = 'Erro: ' + (j.data || 'falha ao reprocessar');
        }
        loadStats(); loadRecent();
    }

    async function clearFailed() {
        const j = await post('ojf_aq_clear_failed');
        if (j.success) {
            const parts = [];
            if (j.data.converted > 0) parts.push(j.data.converted + ' convertidos para passed');
            if (j.data.deleted > 0) parts.push(j.data.deleted + ' failed removidos');
            document.getElementById('msg').textContent = parts.join(', ') || 'Nenhum failed encontrado';
        }
        loadStats(); loadRecent();
    }

    // Init + auto-refresh
    loadStats();
    loadRecent();
    setInterval(loadStats, 5000);
    setInterval(loadRecent, 10000);
    document.getElementById('aq-log-modal').addEventListener('click', function(e) {
        if (e.target === this) closeLogModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogModal();
    });
    </script>
    <?php
}
