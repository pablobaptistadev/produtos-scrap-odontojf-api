<?php
/**
 * FullBAI CDN Image Handler — Fluent Snippet
 *
 * Version: 6.10
 *
 * Sobe imagens para Cloudflare R2, cria stub attachments com metadata completa.
 * Gera todos os sizes (thumbnail, woocommerce_thumbnail, etc.) com URLs CDN.
 *
 * COMO FUNCIONA:
 * 1. ojf_upload_image() baixa do seller → captura dimensões → sobe pro R2 → stub attachment
 * 2. Stub attachment gera sizes array completo com Cloudflare Image Resizing URLs
 * 3. Filtros reescrevem wp_get_attachment_url() para retornar URL do CDN
 * 4. URL original do seller salva como fallback no meta _ojf_seller_url
 * 5. Frontend usa onerror no <img> para fallback automático
 * 6. Hook delete_attachment limpa imagem do R2 automaticamente
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// FALLBACK: Se debug snippet não carregou ainda, criar stubs para não dar fatal
// ============================================================================
if (!function_exists('ojf_debug_cdn')) {
    function ojf_debug_cdn($message, $level = 'info', $data = null) {
        error_log('[FullBAI CDN] ' . $message . ($data ? ' | ' . wp_json_encode($data) : ''));
    }
}

// ============================================================================
// CONFIGURAÇÃO R2
// ============================================================================

// R2 config vem de includes/config.php (conta NOVA ce2d7a097…). Os defines
// abaixo são só fallback caso config.php não tenha carregado — NÃO contêm
// credenciais (o secret real fica em wp-config.php via OJF_R2_SECRET_KEY).
if (!defined('OJF_R2_ACCESS_KEY'))  define('OJF_R2_ACCESS_KEY',  '8f290cbe293b3e8619d23a8ee022e5dd');
// SECRET fica em wp-config.php: define('OJF_R2_SECRET_KEY', '...');
if (!defined('OJF_R2_SECRET_KEY'))  define('OJF_R2_SECRET_KEY',  '');
if (!defined('OJF_R2_BUCKET'))      define('OJF_R2_BUCKET',      'odonto-loja');
if (!defined('OJF_R2_ACCOUNT_ID'))  define('OJF_R2_ACCOUNT_ID',  'ce2d7a097ff927538d0a6d8da21c1d4d');
if (!defined('OJF_R2_ENDPOINT'))    define('OJF_R2_ENDPOINT',    'https://ce2d7a097ff927538d0a6d8da21c1d4d.r2.cloudflarestorage.com');
if (!defined('OJF_CDN_BASE_URL'))   define('OJF_CDN_BASE_URL',   'https://arquivos.dentalodontocirurgica.com.br');
if (!defined('OJF_CDN_ENABLED'))    define('OJF_CDN_ENABLED',    true);
if (!defined('OJF_CDN_QUEUE_ENABLED')) define('OJF_CDN_QUEUE_ENABLED', true);

/*
===============================================================================
CHANGELOG OPERACIONAL — MAIS RECENTES PRIMEIRO
===============================================================================

v6.10
- ojf_iq_become_worker grava timestamp em ojf_iq_last_worker_run no fim
- Dashboard (queue-dashboard.php) mostra "Last Run | Trigger GET | Cron" no rodape

v6.9
- Trigger externo proprio em ?ojf_queue_trigger=1 com o MESMO token do sync v7
  (OJF_QUEUE_TRIGGER_TOKEN). Cada arquivo escuta o seu — sem dependencia
  entre filas. Roda em init priority 15 (antes do sync, priority 20).
- update_option('ojf_iq_last_trigger_get', ...) para o dashboard mostrar.
- Tudo idempotente: nao chama die() — sync continua respondendo o JSON unificado.

v6.8
- RE-ADICIONADO auto-trigger por page-load (rede de segurança independente do WP-Cron)
- Cada page-load (admin/front) checa com rate-limit de 50s via transient se há
  imagem pending > 60s e, se houver, agenda ojf_iq_become_worker no shutdown
  da própria request (não bloqueia entrega da página)
- Pula contextos com trigger próprio: wp_doing_cron() e REST_REQUEST
- Custo médio por request: 1 get_transient (Redis se houver object cache)
- Mantém OJF_IQ_CONCURRENCY=2

v6.7
- URLs pre-signed S3/AWS agora são baixadas exatamente como vieram, sem ojf_dl e sem header extra
- attachments temporários da Image Queue não usam URL assinada/longa no guid para evitar falha no wp_insert_attachment

v6.6
- object keys do R2 agora usam slug ASCII puro, sem percent-encoding (%xx)
- evita falha de assinatura/upload quando o título do produto tem caracteres especiais
- logs de falha do R2 agora incluem object_key e endpoint


v6.5
- adiciona suporte a reprocessamento imediato de item específico da Image Queue
- prepara o core para a coluna de ação/log do dashboard sem alterar o pipeline padrão

v6.4
- downloader agora tenta primeiro um transporte cURL nativo alinhado com o curl do terminal
- adiciona assinatura previsível de download via query param e header customizado
- melhora observabilidade por tentativa sem alterar o restante do pipeline da fila

v6.3
- worker agora drena várias imagens por lote curto antes de encadear o próximo ciclo
- remove o gargalo de 1 imagem por minuto quando existe backlog
- cron volta a ser apenas fallback; a fila de imagem se autoesvazia sozinha

v6.2
- worker one-shot agora encadeia a próxima imagem via loopback assíncrono
- remove espera de 1 minuto entre imagens quando ainda existe backlog
- mantém 1 imagem por processo, sem voltar para worker longo

v6.1
- Image Queue agora usa worker one-shot
- cada ciclo processa 1 imagem e morre
- remove loop longo que drenava múltiplas imagens por request

v6.0
- Image Queue assíncrona para download/optimize/upload R2

MANUTENÇÃO
- sempre adicionar mudanças novas aqui no topo
- ordem obrigatória: mais recente primeiro
===============================================================================
*/

// ============================================================================
// IMAGE QUEUE — CONFIGURAÇÃO
// ============================================================================

define('OJF_IQ_TABLE',          'ojf_cdn_image_queue');
define('OJF_IQ_LOCK_NAME',      'ojf_img_worker');
define('OJF_IQ_LOCK_TIMEOUT',   0);
define('OJF_IQ_CONCURRENCY',    2);
define('OJF_IQ_MAX_ATTEMPTS',   3);
define('OJF_IQ_STUCK_SEC',      300);
define('OJF_IQ_MAX_LOOP_SEC',   240);    // Legado: teto de execução do worker one-shot
define('OJF_IQ_DRAIN_SEC',      20);     // Worker drena backlog por até 20s antes de reencadear
define('OJF_IQ_CLEANUP_DAYS',   7);

// ============================================================================
// DOWNLOAD ROBUSTO — ASSINATURA E TRANSPORTE
// ============================================================================

if (!defined('OJF_DL_QUERY_ENABLED')) define('OJF_DL_QUERY_ENABLED', true);
if (!defined('OJF_DL_QUERY_PARAM'))   define('OJF_DL_QUERY_PARAM', 'ojf_dl');
if (!defined('OJF_DL_QUERY_VALUE'))   define('OJF_DL_QUERY_VALUE', '1');
if (!defined('OJF_DL_HEADER_ENABLED')) define('OJF_DL_HEADER_ENABLED', true);
if (!defined('OJF_DL_HEADER_NAME'))    define('OJF_DL_HEADER_NAME', 'X-Fullbai-Download');
if (!defined('OJF_DL_HEADER_VALUE'))   define('OJF_DL_HEADER_VALUE', '1');
if (!defined('OJF_DL_USER_AGENT'))     define('OJF_DL_USER_AGENT', 'Mozilla/5.0');

// ============================================================================
// HELPER: GERAR SLUG A PARTIR DO TÍTULO DO PRODUTO
// ============================================================================

/**
 * Gera slug SEO-friendly base a partir do título do produto.
 * Ex: "iPhone 128G Branco" → "iphone-128g-branco"
 *
 * @param int $post_id ID do produto
 * @param int $img_index Parâmetro legado, mantido por compatibilidade
 * @return string Slug base do produto
 */
function ojf_cdn_image_slug($post_id, $img_index = 0) {
    $title = get_the_title($post_id);
    if (empty($title)) {
        return 'img-' . $post_id;
    }

    // R2/S3 assina o path. Portanto o object key precisa ser ASCII puro,
    // sem percent-encoding (%c2%a8), espaços ou caracteres especiais.
    $slug = remove_accents($title);
    $slug = strtolower($slug);
    $slug = rawurldecode($slug);
    $slug = preg_replace('/%[0-9a-f]{2}/i', '-', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = substr($slug, 0, 80);
    $slug = trim($slug, '-');

    if ($slug === '') {
        return 'img-' . $post_id;
    }

    return $slug;
}

function ojf_dl_is_presigned_url($url) {
    if (!is_string($url) || $url === '') {
        return false;
    }

    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return false;
    }

    return (bool) preg_match('/(?:^|&)(?:amp;)?x-amz-(signature|credential|algorithm|expires|signedheaders)=/i', $query);
}

function ojf_dl_request_signature($url) {
    $request_url = $url;
    $is_presigned = ojf_dl_is_presigned_url($url);
    $query_applied = false;
    $header_applied = false;

    if (!$is_presigned && OJF_DL_QUERY_ENABLED && OJF_DL_QUERY_PARAM !== '') {
        $request_url = add_query_arg(OJF_DL_QUERY_PARAM, OJF_DL_QUERY_VALUE, $request_url);
        $query_applied = true;
    }

    $headers = [];
    if (!$is_presigned && OJF_DL_HEADER_ENABLED && OJF_DL_HEADER_NAME !== '') {
        $headers[OJF_DL_HEADER_NAME] = OJF_DL_HEADER_VALUE;
        $header_applied = true;
    }

    return [
        'original_url'    => $url,
        'request_url'     => $request_url,
        'headers'         => $headers,
        'query_applied'   => $query_applied,
        'header_applied'  => $header_applied,
        'presigned_url'   => $is_presigned,
        'user_agent'      => OJF_DL_USER_AGENT,
    ];
}

function ojf_dl_header_lines($headers) {
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    return $lines;
}

function ojf_dl_write_temp_file($source_url, $body) {
    if (!is_string($body) || strlen($body) <= 100) {
        return new WP_Error('download_body_invalid', 'Body vazio ou muito pequeno');
    }

    $tmp = wp_tempnam($source_url);
    if (!$tmp) {
        return new WP_Error('download_temp_failed', 'wp_tempnam falhou');
    }

    if (file_put_contents($tmp, $body) === false) {
        @unlink($tmp);
        return new WP_Error('download_temp_failed', 'Falha ao gravar arquivo temporário');
    }

    return $tmp;
}

/**
 * Gera object key estável por attachment para evitar colisão de cache no CDN.
 * Formato: products/{product_id}/{slug}-{attachment_id}.webp
 *
 * @param int $post_id ID do produto
 * @param int $attachment_id ID do attachment
 * @param string $ext Extensão final do arquivo
 * @return string
 */
function ojf_cdn_build_object_key($post_id, $attachment_id, $ext = 'webp') {
    $post_id = (int) $post_id;
    $attachment_id = (int) $attachment_id;
    $ext = strtolower((string) $ext);
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    if ($ext === '') {
        $ext = 'webp';
    }

    return sprintf(
        'products/%d/%s-%d.%s',
        $post_id,
        ojf_cdn_image_slug($post_id),
        $attachment_id,
        $ext
    );
}

// ============================================================================
// DOWNLOAD ROBUSTO DE IMAGEM (User-Agent, retry, fallback)
// ============================================================================

/**
 * Download robusto de URL de imagem.
 * Tenta primeiro cURL nativo alinhado com o curl do terminal e mantém os fallbacks.
 *
 * @param string $url URL da imagem
 * @param int $timeout Timeout em segundos
 * @return string|WP_Error Caminho do arquivo temporário ou WP_Error
 */
function ojf_robust_download($url, $timeout = 60) {
    $signature   = ojf_dl_request_signature($url);
    $request_url = $signature['request_url'];
    $headers     = $signature['headers'];
    $user_agent  = $signature['user_agent'];
    $errors      = [];

    // Tentativa 1: cURL nativo, alinhado com curl -L -A "Mozilla/5.0"
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $request_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(15, max(5, (int) floor($timeout / 3))),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => $user_agent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ojf_dl_header_lines($headers),
        ]);

        $body         = curl_exec($ch);
        $curl_errno   = curl_errno($ch);
        $curl_error   = curl_error($ch);
        $http_code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective_url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($curl_errno === 0 && $http_code >= 200 && $http_code < 400 && is_string($body) && strlen($body) > 100) {
            $tmp = ojf_dl_write_temp_file($request_url, $body);
            if (!is_wp_error($tmp)) {
                ojf_debug_cdn('Robust download OK (curl_native)', 'debug', [
                    'url'            => $url,
                    'request_url'    => $request_url,
                    'transport'      => 'curl_native',
                    'http_code'      => $http_code,
                    'effective_url'  => $effective_url,
                    'content_type'   => $content_type,
                    'body_len'       => strlen($body),
                    'query_applied'  => $signature['query_applied'],
                    'header_applied' => $signature['header_applied'],
                    'presigned_url'  => $signature['presigned_url'],
                ]);
                return $tmp;
            }

            $errors[] = 'curl_native temp file: ' . $tmp->get_error_message();
            ojf_debug_cdn('curl_native falhou ao persistir temp file', 'warn', [
                'url'            => $url,
                'request_url'    => $request_url,
                'transport'      => 'curl_native',
                'http_code'      => $http_code,
                'effective_url'  => $effective_url,
                'body_len'       => strlen($body),
                'erro'           => $tmp->get_error_message(),
                'query_applied'  => $signature['query_applied'],
                'header_applied' => $signature['header_applied'],
                'presigned_url'  => $signature['presigned_url'],
            ]);
        } else {
            $reason = $curl_errno ? ('cURL error ' . $curl_errno . ': ' . $curl_error) : ('HTTP ' . $http_code . ' ou body inválido');
            $errors[] = 'curl_native: ' . $reason;
            ojf_debug_cdn('curl_native falhou', 'warn', [
                'url'            => $url,
                'request_url'    => $request_url,
                'transport'      => 'curl_native',
                'http_code'      => $http_code,
                'effective_url'  => $effective_url,
                'content_type'   => $content_type,
                'body_len'       => is_string($body) ? strlen($body) : 0,
                'curl_errno'     => $curl_errno,
                'curl_error'     => $curl_error,
                'query_applied'  => $signature['query_applied'],
                'header_applied' => $signature['header_applied'],
                'presigned_url'  => $signature['presigned_url'],
            ]);
        }
    } else {
        $errors[] = 'curl_native indisponível';
        ojf_debug_cdn('curl_native indisponível no PHP', 'warn', [
            'url'            => $url,
            'request_url'    => $request_url,
            'transport'      => 'curl_native',
            'query_applied'  => $signature['query_applied'],
            'header_applied' => $signature['header_applied'],
            'presigned_url'  => $signature['presigned_url'],
        ]);
    }

    // Tentativa 2: wp_remote_get com assinatura e sem Accept-Encoding suspeito
    $wp_headers = array_merge([
        'Accept' => '*/*',
    ], $headers);

    $response = wp_remote_get($request_url, [
        'timeout'     => $timeout,
        'user-agent'  => $user_agent,
        'sslverify'   => false,
        'headers'     => $wp_headers,
        'redirection' => 5,
    ]);

    if (!is_wp_error($response)) {
        $code         = wp_remote_retrieve_response_code($response);
        $body         = wp_remote_retrieve_body($response);
        $effective_url = $request_url;
        if (is_array($response) && isset($response['http_response']) && is_object($response['http_response']) && method_exists($response['http_response'], 'get_response_object')) {
            $response_object = $response['http_response']->get_response_object();
            if (is_object($response_object) && isset($response_object->url)) {
                $effective_url = (string) $response_object->url;
            }
        }
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        if ($code >= 200 && $code < 400 && strlen($body) > 100) {
            $tmp = ojf_dl_write_temp_file($request_url, $body);
            if (!is_wp_error($tmp)) {
                ojf_debug_cdn('Robust download OK (wp_remote_get)', 'debug', [
                    'url'            => $url,
                    'request_url'    => $request_url,
                    'transport'      => 'wp_remote_get',
                    'http_code'      => $code,
                    'effective_url'  => $effective_url,
                    'content_type'   => $content_type,
                    'body_len'       => strlen($body),
                    'query_applied'  => $signature['query_applied'],
                    'header_applied' => $signature['header_applied'],
                    'presigned_url'  => $signature['presigned_url'],
                ]);
                return $tmp;
            }

            $errors[] = 'wp_remote_get temp file: ' . $tmp->get_error_message();
        }

        $errors[] = 'wp_remote_get: HTTP ' . $code . ' / body ' . strlen($body);
        ojf_debug_cdn('wp_remote_get retornou código inválido ou body vazio', 'warn', [
            'url'            => $url,
            'request_url'    => $request_url,
            'transport'      => 'wp_remote_get',
            'http_code'      => $code,
            'effective_url'  => $effective_url,
            'content_type'   => $content_type,
            'body_len'       => strlen($body),
            'query_applied'  => $signature['query_applied'],
            'header_applied' => $signature['header_applied'],
            'presigned_url'  => $signature['presigned_url'],
        ]);
    } else {
        $errors[] = 'wp_remote_get: ' . $response->get_error_message();
        ojf_debug_cdn('wp_remote_get falhou', 'warn', [
            'url'            => $url,
            'request_url'    => $request_url,
            'transport'      => 'wp_remote_get',
            'erro'           => $response->get_error_message(),
            'query_applied'  => $signature['query_applied'],
            'header_applied' => $signature['header_applied'],
            'presigned_url'  => $signature['presigned_url'],
        ]);
    }

    // Tentativa 3: download_url nativo do WordPress
    $temp = download_url($request_url, $timeout);
    if (!is_wp_error($temp) && file_exists($temp) && filesize($temp) > 100) {
        ojf_debug_cdn('Robust download OK (download_url fallback)', 'debug', [
            'url'            => $url,
            'request_url'    => $request_url,
            'transport'      => 'download_url',
            'body_len'       => filesize($temp),
            'query_applied'  => $signature['query_applied'],
            'header_applied' => false,
            'presigned_url'  => $signature['presigned_url'],
        ]);
        return $temp;
    }
    if (!is_wp_error($temp) && file_exists($temp)) {
        $errors[] = 'download_url: body ' . filesize($temp);
        @unlink($temp);
    } elseif (is_wp_error($temp)) {
        $errors[] = 'download_url: ' . $temp->get_error_message();
    }

    // Tentativa 4: file_get_contents com contexto HTTP
    $stream_headers = "User-Agent: {$user_agent}\r\nAccept: */*\r\n";
    foreach ($headers as $name => $value) {
        $stream_headers .= $name . ': ' . $value . "\r\n";
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => $stream_headers,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($request_url, false, $ctx);
    $http_code = 0;
    $content_type = '';
    $response_headers = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : [];
    if (!empty($response_headers) && is_array($response_headers)) {
        foreach ($response_headers as $header_line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header_line, $m)) {
                $http_code = (int) $m[1];
            } elseif (stripos($header_line, 'Content-Type:') === 0) {
                $content_type = trim(substr($header_line, 13));
            }
        }
    }

    if ($body !== false && strlen($body) > 100 && $http_code >= 200 && $http_code < 400) {
        $tmp = ojf_dl_write_temp_file($request_url, $body);
        if (!is_wp_error($tmp)) {
            ojf_debug_cdn('Robust download OK (file_get_contents fallback)', 'debug', [
                'url'            => $url,
                'request_url'    => $request_url,
                'transport'      => 'file_get_contents',
                'http_code'      => $http_code,
                'content_type'   => $content_type,
                'body_len'       => strlen($body),
                'query_applied'  => $signature['query_applied'],
                'header_applied' => $signature['header_applied'],
                'presigned_url'  => $signature['presigned_url'],
            ]);
            return $tmp;
        }

        $errors[] = 'file_get_contents temp file: ' . $tmp->get_error_message();
    }

    $errors[] = 'file_get_contents: HTTP ' . $http_code . ' / body ' . ($body !== false ? strlen($body) : 0);
    ojf_debug_cdn('file_get_contents falhou', 'warn', [
        'url'            => $url,
        'request_url'    => $request_url,
        'transport'      => 'file_get_contents',
        'http_code'      => $http_code,
        'content_type'   => $content_type,
        'body_len'       => $body !== false ? strlen($body) : 0,
        'query_applied'  => $signature['query_applied'],
        'header_applied' => $signature['header_applied'],
        'presigned_url'  => $signature['presigned_url'],
    ]);

    $err_msg = !empty($errors) ? implode(' | ', $errors) : 'Todas as tentativas de download falharam';
    ojf_debug_cdn('Robust download FALHOU TOTAL', 'error', [
        'url'            => $url,
        'request_url'    => $request_url,
        'erro'           => $err_msg,
        'query_applied'  => $signature['query_applied'],
        'header_applied' => $signature['header_applied'],
        'presigned_url'  => $signature['presigned_url'],
    ]);
    return new WP_Error('download_failed', $err_msg . ' | URL: ' . $url);
}

// ============================================================================
// FUNÇÃO PRINCIPAL: UPLOAD DE IMAGEM COM CDN
// ============================================================================

/**
 * Substitui a ojf_upload_image() original.
 * Fluxo: download do seller → upload pro R2 → stub attachment no WP
 *
 * @param string $url URL da imagem (do seller)
 * @param int $post_id ID do produto
 * @param int $max_attempts Tentativas máximas
 * @return int|false Attachment ID ou false
 */
/**
 * Processa as imagens do CORPO do produto (post_content): baixa cada <img>
 * externa, otimiza (WebP/1200px), sobe pro R2 da LOJA e reescreve o src. Resolve
 * as imagens que apontavam pro media.odontoapi (hotlink bloqueado → erro 1011).
 * Síncrono (o corpo tem poucas imgs). Dedup por meta (_ojf_body_img_<hash>) p/
 * re-update não re-subir. Vai direto pro R2 (sem criar attachment na biblioteca).
 */
function ojf_process_body_images($html, $product_id) {
    $html = (string) $html;
    $product_id = (int) $product_id;
    if (!defined('OJF_CDN_ENABLED') || !OJF_CDN_ENABLED || empty(OJF_R2_ACCESS_KEY)) return $html;
    if (!function_exists('ojf_robust_download') || !function_exists('ojf_r2_upload')) return $html;
    // Description sem <img> → limpa eventuais imagens de corpo antigas (saíram todas).
    if ($html === '' || stripos($html, '<img') === false) {
        if ($product_id) ojf_body_cleanup_stale($product_id, '');
        return $html;
    }
    $cdn_base = rtrim(OJF_CDN_BASE_URL, '/');
    $idx = 0;
    $out = preg_replace_callback('/<img\b[^>]*?\bsrc\s*=\s*("|\')(.*?)\1[^>]*>/i', function ($m) use ($product_id, $cdn_base, &$idx) {
        $tag = $m[0];
        $src = trim(html_entity_decode($m[2], ENT_QUOTES));
        if ($src === '' || !preg_match('#^https?://#i', $src)) return $tag;
        if (strpos($src, $cdn_base) !== false) return $tag; // já está no nosso R2
        $meta_key = '_ojf_body_img_' . md5($src);
        $cached = $product_id ? (string) get_post_meta($product_id, $meta_key, true) : '';
        if ($cached !== '') return str_replace($m[2], esc_url($cached), $tag);
        $tmp = ojf_robust_download($src, 60);
        if (is_wp_error($tmp) || !$tmp || !file_exists($tmp)) return $tag;
        $opt  = function_exists('ojf_cdn_optimize_image') ? ojf_cdn_optimize_image($tmp) : ['file' => $tmp, 'mime' => 'image/jpeg', 'ext' => 'jpg'];
        $file = !empty($opt['file']) ? $opt['file'] : $tmp;
        $ext  = !empty($opt['ext'])  ? $opt['ext']  : 'webp';
        $mime = !empty($opt['mime']) ? $opt['mime'] : 'image/webp';
        $key  = 'products/' . $product_id . '/body/' . (++$idx) . '-' . substr(md5($src), 0, 10) . '.' . $ext;
        $cdn  = ojf_r2_upload($file, $key, $mime);
        @unlink($tmp);
        if (!empty($opt['file']) && $opt['file'] !== $tmp) @unlink($opt['file']);
        if ($cdn) {
            if ($product_id) update_post_meta($product_id, $meta_key, $cdn);
            return str_replace($m[2], esc_url($cdn), $tag);
        }
        return $tag;
    }, $html);
    // Limpeza no UPDATE: apaga do R2 as imagens de corpo que SAÍRAM da description.
    if ($product_id) ojf_body_cleanup_stale($product_id, $out);
    return $out;
}

/** Apaga do R2 as imagens de corpo cuja URL não está mais no HTML final + remove
 *  o meta. Fecha a brecha de "lixo" quando a description é atualizada/encurtada. */
function ojf_body_cleanup_stale($product_id, $final_html) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
         WHERE post_id = %d AND meta_key LIKE %s",
        (int) $product_id, $wpdb->esc_like('_ojf_body_img_') . '%'
    ));
    if (empty($rows)) return;
    $cdn = rtrim(OJF_CDN_BASE_URL, '/') . '/';
    foreach ((array) $rows as $r) {
        $url = (string) $r->meta_value;
        if ($url === '' || strpos((string) $final_html, $url) !== false) continue; // ainda em uso
        if (strpos($url, $cdn) === 0 && function_exists('ojf_r2_delete')) {
            ojf_r2_delete(substr($url, strlen($cdn)));
        }
        $wpdb->delete($wpdb->postmeta, ['meta_id' => (int) $r->meta_id]);
    }
}

if (!function_exists('ojf_upload_image')) {
function ojf_upload_image($url, $post_id = 0, $max_attempts = 3) {
    ojf_debug_cdn('=== INÍCIO upload_image ===', 'info', ['url' => $url, 'post_id' => $post_id]);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        ojf_debug_cdn('URL inválida, abortando', 'warn', ['url' => $url]);
        return false;
    }

    // Garantir que funções admin estejam disponíveis (REST API não carrega automaticamente)
    if (!function_exists('download_url')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        ojf_debug_cdn('Carregou file.php (download_url)', 'debug');
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
    }
    if (!function_exists('media_handle_sideload')) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
    }

    // Se CDN desabilitado, usar fluxo original do WordPress
    if (!OJF_CDN_ENABLED || empty(OJF_R2_ACCESS_KEY)) {
        ojf_debug_cdn('CDN desabilitado, usando upload local', 'warn');
        return ojf_upload_image_local($url, $post_id, $max_attempts);
    }

    // ═══════════════════════════════════════════════
    // MODO FILA: criar attachment seller + enfileirar
    // ═══════════════════════════════════════════════
    if (OJF_CDN_QUEUE_ENABLED) {
        // DEDUP: reusa attachment existente (mesmo produto+URL) sem re-enfileirar.
        $reused = ojf_iq_find_existing_attachment($url, $post_id);
        if ($reused) { ojf_debug_cdn('Image reused (dedup)', 'info', ['attach_id' => $reused]); return $reused; }
        $attach_id = ojf_iq_create_seller_attachment($url, $post_id);
        if ($attach_id) {
            $queue_id = ojf_iq_enqueue($attach_id, $url, $post_id);
            ojf_iq_trigger_worker();
            ojf_debug_cdn('Image queued', 'info', ['attach_id' => $attach_id, 'queue_id' => $queue_id]);
        }
        return $attach_id;
    }

    // ═══════════════════════════════════════════════
    // MODO INLINE: download + otimizar + R2
    // ═══════════════════════════════════════════════
    $reused = ojf_iq_find_existing_attachment($url, $post_id);
    if ($reused) return $reused;
    $inline_attach_id = ojf_iq_create_seller_attachment($url, $post_id);
    if (!$inline_attach_id) {
        ojf_debug_cdn('Falha ao criar attachment inline antes do upload', 'error', ['url' => $url, 'post_id' => $post_id]);
        return false;
    }

    for ($i = 0; $i < $max_attempts; $i++) {
        ojf_debug_cdn('Tentativa ' . ($i + 1) . '/' . $max_attempts . ' - robust_download', 'debug');
        $temp_file = ojf_robust_download($url, 60);

        if (!is_wp_error($temp_file) && file_exists($temp_file) && filesize($temp_file) > 0) {
            ojf_debug_cdn('Download OK', 'debug', ['temp' => $temp_file, 'size' => filesize($temp_file)]);

            // Detectar mime type e extensão
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $temp_file);
            finfo_close($finfo);

            $filename = basename($url);
            $filename = preg_replace('/\?.*/', '', $filename);

            // Garantir extensão válida
            if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                $ext_map = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                    'image/avif' => 'avif',
                ];
                $ext = $ext_map[$mime_type] ?? 'jpg';
                $filename .= '.' . $ext;
            }

            // Capturar dimensões originais
            $image_dims = @getimagesize($temp_file);
            $img_width  = $image_dims ? (int) $image_dims[0] : 0;
            $img_height = $image_dims ? (int) $image_dims[1] : 0;
            ojf_debug_cdn('Dimensões capturadas', 'debug', ['dims' => $img_width . 'x' . $img_height, 'mime' => $mime_type]);

            // Otimizar: resize 1200px + WebP
            $opt = ojf_cdn_optimize_image($temp_file);

            $object_key = ojf_cdn_build_object_key($post_id, $inline_attach_id, $opt['ext']);

            // Upload para R2
            ojf_debug_cdn('Uploading para R2', 'info', ['object_key' => $object_key]);
            $cdn_url = ojf_r2_upload($opt['file'], $object_key, $opt['mime']);

            // Usar dimensões otimizadas
            $final_width  = isset($opt['width']) ? $opt['width'] : $img_width;
            $final_height = isset($opt['height']) ? $opt['height'] : $img_height;

            // Cleanup temp files
            @unlink($temp_file);
            if ($opt['changed']) @unlink($opt['file']);

            if ($cdn_url) {
                ojf_debug_cdn('R2 upload OK', 'info', ['cdn_url' => $cdn_url]);
                if (ojf_cdn_finalize_attachment($inline_attach_id, $cdn_url, $url, $post_id, $opt['mime'], $final_width, $final_height, $object_key)) {
                    ojf_debug_cdn('=== SUCESSO ===', 'info', ['attachment_id' => $inline_attach_id, 'object_key' => $object_key]);
                    return $inline_attach_id;
                }
                ojf_debug_cdn('Finalização do attachment inline FALHOU', 'error', ['attachment_id' => $inline_attach_id]);
                ojf_r2_delete($object_key);
            } else {
                ojf_debug_cdn('R2 upload FALHOU', 'error', ['object_key' => $object_key]);
            }
        } else {
            $err_msg = is_wp_error($temp_file) ? $temp_file->get_error_message() : 'arquivo vazio ou inexistente';
            ojf_debug_cdn('Download FALHOU', 'error', ['tentativa' => $i + 1, 'erro' => $err_msg]);
            if (is_wp_error($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
        }

        if ($i < $max_attempts - 1) {
            sleep(2);
        }
    }

    if ($inline_attach_id) {
        wp_delete_attachment($inline_attach_id, true);
    }

    return false;
}
}

/**
 * Fallback: upload local original (caso CDN esteja desabilitado)
 */
function ojf_upload_image_local($url, $post_id = 0, $max_attempts = 3) {
    for ($i = 0; $i < $max_attempts; $i++) {
        $temp_file = ojf_robust_download($url, 60);

        if (!is_wp_error($temp_file) && file_exists($temp_file) && filesize($temp_file) > 0) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');

            $file_array = [
                'name' => preg_replace('/\?.*/', '', basename($url)),
                'tmp_name' => $temp_file
            ];

            $attachment_id = media_handle_sideload($file_array, $post_id);
            @unlink($temp_file);

            if (!is_wp_error($attachment_id)) {
                // Salvar URL do seller como fallback mesmo no modo local
                update_post_meta($attachment_id, '_ojf_seller_url', $url);
                return $attachment_id;
            }
        }

        if ($i < $max_attempts - 1) {
            sleep(3);
        }
    }

    return false;
}

// ============================================================================
// UPLOAD PARA CLOUDFLARE R2 (S3-COMPATIBLE API)
// ============================================================================

/**
 * Upload de arquivo para Cloudflare R2 usando AWS Signature V4
 *
 * @param string $file_path Caminho do arquivo local
 * @param string $object_key Chave do objeto no R2 (ex: products/123/abc.jpg)
 * @param string $content_type MIME type
 * @return string|false URL pública do CDN ou false
 */
function ojf_r2_upload($file_path, $object_key, $content_type = 'image/jpeg') {
    $file_contents = file_get_contents($file_path);
    if ($file_contents === false) {
        return false;
    }

    $bucket   = OJF_R2_BUCKET;
    $region   = 'auto';
    $service  = 's3';
    $host     = OJF_R2_ACCOUNT_ID . '.r2.cloudflarestorage.com';
    $encoded_object_key = implode('/', array_map('rawurlencode', explode('/', $object_key)));
    $endpoint = OJF_R2_ENDPOINT . '/' . $bucket . '/' . $encoded_object_key;

    $now      = gmdate('Ymd\THis\Z');
    $datestamp = gmdate('Ymd');

    $content_hash = hash('sha256', $file_contents);

    // Canonical request
    $canonical_uri = '/' . $bucket . '/' . $encoded_object_key;
    $canonical_querystring = '';
    $canonical_headers = "content-type:{$content_type}\nhost:{$host}\nx-amz-content-sha256:{$content_hash}\nx-amz-date:{$now}\n";
    $signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

    $canonical_request = "PUT\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$content_hash}";

    // String to sign
    $credential_scope = "{$datestamp}/{$region}/{$service}/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$now}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

    // Signing key
    $k_date    = hash_hmac('sha256', $datestamp, 'AWS4' . OJF_R2_SECRET_KEY, true);
    $k_region  = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    $authorization = "AWS4-HMAC-SHA256 Credential=" . OJF_R2_ACCESS_KEY . "/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

    $response = wp_remote_request($endpoint, [
        'method'  => 'PUT',
        'timeout' => 30,
        'headers' => [
            'Content-Type'         => $content_type,
            'x-amz-content-sha256' => $content_hash,
            'x-amz-date'           => $now,
            'Authorization'        => $authorization,
        ],
        'body' => $file_contents,
    ]);

    if (is_wp_error($response)) {
        ojf_debug_cdn('R2 upload WP_Error', 'error', [
            'object_key' => $object_key,
            'endpoint' => $endpoint,
            'erro' => $response->get_error_message(),
        ]);
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        return OJF_CDN_BASE_URL . '/' . $object_key;
    }

    ojf_debug_cdn('R2 upload HTTP error', 'error', [
        'object_key' => $object_key,
        'endpoint' => $endpoint,
        'http_code' => $code,
        'response' => substr(wp_remote_retrieve_body($response), 0, 500),
    ]);
    return false;
}

/**
 * Deletar objeto do R2
 */
function ojf_r2_delete($object_key) {
    $bucket   = OJF_R2_BUCKET;
    $region   = 'auto';
    $service  = 's3';
    $host     = OJF_R2_ACCOUNT_ID . '.r2.cloudflarestorage.com';
    $encoded_object_key = implode('/', array_map('rawurlencode', explode('/', $object_key)));
    $endpoint = OJF_R2_ENDPOINT . '/' . $bucket . '/' . $encoded_object_key;

    $now       = gmdate('Ymd\THis\Z');
    $datestamp = gmdate('Ymd');

    $content_hash = hash('sha256', '');

    $canonical_uri = '/' . $bucket . '/' . $encoded_object_key;
    $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$content_hash}\nx-amz-date:{$now}\n";
    $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

    $canonical_request = "DELETE\n{$canonical_uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$content_hash}";

    $credential_scope = "{$datestamp}/{$region}/{$service}/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$now}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

    $k_date    = hash_hmac('sha256', $datestamp, 'AWS4' . OJF_R2_SECRET_KEY, true);
    $k_region  = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
    $authorization = "AWS4-HMAC-SHA256 Credential=" . OJF_R2_ACCESS_KEY . "/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

    $response = wp_remote_request($endpoint, [
        'method'  => 'DELETE',
        'timeout' => 10,
        'headers' => [
            'x-amz-content-sha256' => $content_hash,
            'x-amz-date'           => $now,
            'Authorization'        => $authorization,
        ],
    ]);

    if (is_wp_error($response)) {
        ojf_debug_cdn('R2 DELETE erro', 'error', ['key' => $object_key, 'erro' => $response->get_error_message()]);
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        ojf_debug_cdn('R2 DELETE OK', 'debug', ['key' => $object_key]);
        return true;
    }
    ojf_debug_cdn('R2 DELETE HTTP erro', 'error', ['key' => $object_key, 'code' => $code]);
    return false;
}

// ============================================================================
// STUB ATTACHMENT (SEM THUMBNAILS)
// ============================================================================

/**
 * Aplica URL CDN e metadata final em um attachment existente.
 *
 * @param int $attach_id ID do attachment
 * @param string $cdn_url URL pública do CDN
 * @param string $seller_url URL original do seller
 * @param int $post_id ID do produto pai
 * @param string $mime_type MIME type final
 * @param int $width Largura final
 * @param int $height Altura final
 * @param string $object_key Chave do objeto no R2
 * @return bool
 */
function ojf_cdn_finalize_attachment($attach_id, $cdn_url, $seller_url, $post_id, $mime_type, $width = 0, $height = 0, $object_key = '') {
    $attach_id = (int) $attach_id;
    if ($attach_id <= 0) {
        return false;
    }

    wp_update_post([
        'ID'             => $attach_id,
        'guid'           => $cdn_url,
        'post_parent'    => $post_id,
        'post_mime_type' => $mime_type,
    ]);

    update_post_meta($attach_id, '_ojf_cdn_url', $cdn_url);
    update_post_meta($attach_id, '_ojf_seller_url', $seller_url);
    update_post_meta($attach_id, '_wp_attached_file', $cdn_url);

    if ($object_key !== '') {
        update_post_meta($attach_id, '_ojf_r2_object_key', $object_key);
    }

    delete_post_meta($attach_id, '_ojf_cdn_pending');

    $all_sizes = array(
        'thumbnail'                      => array(150, 150, true),
        'medium'                         => array(300, 300, false),
        'medium_large'                   => array(768, 0, false),
        'large'                          => array(1024, 1024, false),
        'woocommerce_thumbnail'          => array(300, 300, true),
        'woocommerce_single'             => array(600, 0, false),
        'woocommerce_gallery_thumbnail'  => array(100, 100, true),
        'shop_catalog'                   => array(300, 300, true),
        'shop_single'                    => array(600, 0, false),
        'shop_thumbnail'                 => array(100, 100, true),
    );

    $sizes_meta = array();
    foreach ($all_sizes as $size_name => $dims) {
        $calculated = ojf_cdn_calculate_dimensions($width, $height, $dims[0], $dims[1], $dims[2]);
        $sizes_meta[$size_name] = array(
            'file'      => ojf_cdn_resize_url($cdn_url, $calculated['width'], $calculated['height']),
            'width'     => $calculated['width'],
            'height'    => $calculated['height'],
            'mime-type' => $mime_type,
        );
    }

    ojf_debug_cdn('Sizes gerados', 'debug', ['total' => count($sizes_meta), 'attach_id' => $attach_id]);

    wp_update_attachment_metadata($attach_id, array(
        'file'         => $cdn_url,
        'width'        => $width,
        'height'       => $height,
        'sizes'        => $sizes_meta,
        'ojf_cdn'  => true,
    ));

    ojf_debug_cdn('Metadata salva OK', 'debug', ['attach_id' => $attach_id, 'dims' => $width . 'x' . $height, 'object_key' => $object_key]);
    return true;
}

/**
 * Cria um attachment "stub" no WordPress apontando para o CDN.
 * NÃO gera thumbnails locais. Gera metadata completa com sizes usando Cloudflare Image Resizing.
 * WooCommerce aceita normalmente porque set_image_id() recebe integer ID.
 *
 * @param string $cdn_url URL da imagem no CDN
 * @param string $seller_url URL original do seller (fallback)
 * @param int $post_id ID do produto pai
 * @param string $filename Nome do arquivo
 * @param string $mime_type MIME type
 * @param int $width Largura original da imagem
 * @param int $height Altura original da imagem
 * @param string $object_key Chave do objeto no R2
 * @return int|false Attachment ID ou false
 */
function ojf_create_stub_attachment($cdn_url, $seller_url, $post_id, $filename, $mime_type, $width = 0, $height = 0, $object_key = '') {
    ojf_debug_cdn('create_stub_attachment', 'info', ['cdn' => $cdn_url, 'seller' => $seller_url, 'dims' => $width . 'x' . $height]);

    $attachment = array(
        'post_mime_type' => $mime_type,
        'post_title'     => sanitize_title(get_the_title($post_id)) ?: sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $post_id,
        'guid'           => $cdn_url,
    );

    $attach_id = wp_insert_attachment($attachment, false, $post_id);

    if (!$attach_id || is_wp_error($attach_id)) {
        ojf_debug_cdn('wp_insert_attachment FALHOU', 'error', ['erro' => is_wp_error($attach_id) ? $attach_id->get_error_message() : 'retornou falsy']);
        return false;
    }

    ojf_debug_cdn('wp_insert_attachment OK', 'debug', ['attach_id' => $attach_id]);

    if (!ojf_cdn_finalize_attachment($attach_id, $cdn_url, $seller_url, $post_id, $mime_type, $width, $height, $object_key)) {
        return false;
    }

    return $attach_id;
}

/**
 * Calcular dimensões respeitando aspect ratio original
 */
function ojf_cdn_calculate_dimensions($orig_w, $orig_h, $dest_w, $dest_h, $crop = false) {
    if ($orig_w <= 0 || $orig_h <= 0) {
        return array('width' => $dest_w ?: 0, 'height' => $dest_h ?: 0);
    }
    if ($dest_w <= 0 && $dest_h <= 0) {
        return array('width' => $orig_w, 'height' => $orig_h);
    }
    if ($crop) {
        return array('width' => $dest_w, 'height' => $dest_h ?: $dest_w);
    }
    // Proporção mantida
    $ratio = $orig_w / $orig_h;
    if ($dest_h <= 0) {
        $w = min($dest_w, $orig_w);
        return array('width' => $w, 'height' => (int) round($w / $ratio));
    }
    // Fit dentro de dest_w x dest_h
    $new_w = min($dest_w, $orig_w);
    $new_h = (int) round($new_w / $ratio);
    if ($new_h > $dest_h) {
        $new_h = $dest_h;
        $new_w = (int) round($new_h * $ratio);
    }
    return array('width' => $new_w, 'height' => $new_h);
}

// ============================================================================
// FILTROS WORDPRESS: REESCREVER URLs PARA CDN
// ============================================================================

/**
 * Filtro 1: wp_get_attachment_url → retorna URL do CDN
 * Cobre: WooCommerce REST API, sync v7, frontend, admin
 */
add_filter('wp_get_attachment_url', 'ojf_cdn_rewrite_attachment_url', 10, 2);
function ojf_cdn_rewrite_attachment_url($url, $attachment_id) {
    $cdn_url = get_post_meta($attachment_id, '_ojf_cdn_url', true);
    if ($cdn_url) {
        return $cdn_url;
    }
    return $url;
}

/**
 * Filtro 2: image_downsize → retorna URL do CDN para qualquer tamanho
 * Sem Cloudflare Image Resizing: retorna original (browser redimensiona via CSS)
 * Com Image Resizing: usa /cdn-cgi/image/width=X,quality=80,format=auto/URL
 */
add_filter('image_downsize', 'ojf_cdn_image_downsize', 10, 3);
function ojf_cdn_image_downsize($out, $attachment_id, $size) {
    $cdn_url = get_post_meta($attachment_id, '_ojf_cdn_url', true);
    if (!$cdn_url) {
        return $out;
    }

    $dims = ojf_cdn_get_size_dimensions($size);

    // Para 'full' size: usar dimensões reais do attachment (PhotoSwipe precisa delas)
    if (($size === 'full' || (is_array($size) && empty(array_filter($size)))) && ($dims['width'] === 0 && $dims['height'] === 0)) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if ($meta && !empty($meta['width']) && !empty($meta['height'])) {
            $dims['width']  = (int) $meta['width'];
            $dims['height'] = (int) $meta['height'];
        }
    }

    $resized_url = ojf_cdn_resize_url($cdn_url, $dims['width'], $dims['height']);

    return array($resized_url, $dims['width'], $dims['height'], true);
}

/**
 * Filtro 3: wp_calculate_image_srcset → srcset com URLs do CDN
 */
add_filter('wp_calculate_image_srcset', 'ojf_cdn_srcset', 10, 5);
function ojf_cdn_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    $cdn_url = get_post_meta($attachment_id, '_ojf_cdn_url', true);
    if (!$cdn_url) {
        return $sources;
    }

    foreach ($sources as $width => &$source) {
        $source['url'] = ojf_cdn_resize_url($cdn_url, (int) $width, 0);
    }

    return $sources;
}

/**
 * Filtro 4: wp_get_attachment_image_attributes → onerror fallback para URL do seller
 */
add_filter('wp_get_attachment_image_attributes', 'ojf_cdn_add_fallback_onerror', 10, 3);
function ojf_cdn_add_fallback_onerror($attr, $attachment, $size) {
    if (!is_object($attachment) || !isset($attachment->ID)) {
        return $attr;
    }

    $seller_url = get_post_meta($attachment->ID, '_ojf_seller_url', true);
    if ($seller_url) {
        $attr['data-fallback-src'] = esc_url($seller_url);
        $attr['onerror'] = "this.onerror=null;this.src=this.getAttribute('data-fallback-src');";
    }

    return $attr;
}

/**
 * Filtro 5: get_attached_file → retorna CDN URL para CDN attachments
 * Retornar false confunde plugins que verificam if ($file)
 */
add_filter('get_attached_file', 'ojf_cdn_attached_file', 10, 2);
function ojf_cdn_attached_file($file, $attachment_id) {
    $cdn_url = get_post_meta($attachment_id, '_ojf_cdn_url', true);
    if ($cdn_url) {
        return $cdn_url;
    }
    return $file;
}

/**
 * Filtro 6: wp_get_attachment_image_src → garantir URL CDN nos sizes
 */
add_filter('wp_get_attachment_image_src', 'ojf_cdn_fix_image_src', 10, 4);
function ojf_cdn_fix_image_src($image, $attachment_id, $size, $icon) {
    if (!$image || !is_array($image)) return $image;
    $cdn_url = get_post_meta($attachment_id, '_ojf_cdn_url', true);
    if (!$cdn_url) return $image;

    // Garantir URL CDN
    if (strpos($image[0], 'files.fullbai.com.ar') === false && strpos($image[0], 'cdn-cgi/image') === false) {
        $dims = ojf_cdn_get_size_dimensions($size);
        $image[0] = ojf_cdn_resize_url($cdn_url, $dims['width'], $dims['height']);
    }

    // Corrigir dimensões 0x0 para full size (PhotoSwipe/lightbox precisa)
    if ((empty($image[1]) || empty($image[2])) && ($size === 'full' || $size === 'woocommerce_single')) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if ($meta && !empty($meta['width']) && !empty($meta['height'])) {
            $image[1] = (int) $meta['width'];
            $image[2] = (int) $meta['height'];
        }
    }

    return $image;
}

/**
 * Filtro 7: wp_get_attachment_metadata → garantir que sizes URLs sejam absolutas
 */
add_filter('wp_get_attachment_metadata', 'ojf_cdn_fix_metadata', 10, 2);
function ojf_cdn_fix_metadata($data, $attachment_id) {
    if (empty($data['ojf_cdn'])) return $data;
    $cdn_url = get_post_meta($attachment_id, '_ojf_cdn_url', true);
    if (!$cdn_url || empty($data['sizes'])) return $data;
    foreach ($data['sizes'] as $name => &$size) {
        if (isset($size['file']) && strpos($size['file'], 'http') !== 0) {
            $dims = ojf_cdn_get_size_dimensions($name);
            $size['file'] = ojf_cdn_resize_url($cdn_url, $dims['width'], $dims['height']);
        }
    }
    return $data;
}

// ============================================================================
// HOOK: LIMPAR R2 AO DELETAR ATTACHMENT
// ============================================================================

add_action('delete_attachment', 'ojf_cdn_cleanup_r2');
function ojf_cdn_cleanup_r2($attachment_id) {
    $cdn_url = get_post_meta($attachment_id, '_ojf_cdn_url', true);
    $object_key = get_post_meta($attachment_id, '_ojf_r2_object_key', true);

    if (!$object_key && $cdn_url) {
        $base = rtrim(OJF_CDN_BASE_URL, '/') . '/';
        if (strpos($cdn_url, $base) === 0) {
            $object_key = substr($cdn_url, strlen($base));
        }
    }

    if ($object_key) {
        ojf_r2_delete($object_key);
    }
}

// ============================================================================
// FUNÇÕES AUXILIARES
// ============================================================================

/**
 * Gerar URL para tamanho específico
 * Retorna URL direta do R2 — browser redimensiona via CSS (width/height attributes)
 * Cloudflare Image Resizing (/cdn-cgi/image/) não funciona cross-domain (files.fullbai != fullbai)
 */
function ojf_cdn_resize_url($cdn_url, $width = 0, $height = 0) {
    // URL direta do R2 — sem Image Resizing
    // O browser usa os atributos width/height do <img> para exibir no tamanho correto
    return $cdn_url;
}

/**
 * Obter dimensões de um tamanho registrado no WordPress
 */
function ojf_cdn_get_size_dimensions($size) {
    if (is_array($size)) {
        return array('width' => $size[0], 'height' => $size[1]);
    }

    // Tamanhos padrão do WooCommerce/WordPress
    $known_sizes = array(
        'thumbnail'              => array('width' => 150,  'height' => 150),
        'medium'                 => array('width' => 300,  'height' => 300),
        'medium_large'           => array('width' => 768,  'height' => 0),
        'large'                  => array('width' => 1024, 'height' => 1024),
        'full'                   => array('width' => 0,    'height' => 0),
        'woocommerce_thumbnail'  => array('width' => 300,  'height' => 300),
        'woocommerce_single'     => array('width' => 600,  'height' => 0),
        'woocommerce_gallery_thumbnail' => array('width' => 100, 'height' => 100),
        'shop_catalog'           => array('width' => 300,  'height' => 300),
        'shop_single'            => array('width' => 600,  'height' => 0),
        'shop_thumbnail'         => array('width' => 100,  'height' => 100),
    );

    if (isset($known_sizes[$size])) {
        return $known_sizes[$size];
    }

    // Tentar pegar tamanhos registrados dinamicamente
    global $_wp_additional_image_sizes;
    if (isset($_wp_additional_image_sizes[$size])) {
        return array(
            'width'  => $_wp_additional_image_sizes[$size]['width'],
            'height' => $_wp_additional_image_sizes[$size]['height'],
        );
    }

    // Fallback: sem dimensões = imagem original
    return array('width' => 0, 'height' => 0);
}

// ============================================================================
// SALVAR URLs DO SELLER NO PRODUTO (FALLBACK PERSISTENTE)
// ============================================================================

/**
 * Salvar array de URLs originais do seller como meta do produto.
 * Chamada após setar imagens no CREATE/UPDATE.
 *
 * Uso:
 *   ojf_save_seller_image_urls($product_id, $data['images']);
 *
 * @param int $product_id
 * @param array $images Array de URLs ou objetos {src: url}
 */
function ojf_save_seller_image_urls($product_id, $images) {
    if (empty($images) || !is_array($images)) {
        return;
    }

    $urls = array();
    foreach ($images as $img) {
        $url = is_array($img) ? ($img['src'] ?? '') : $img;
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            $urls[] = $url;
        }
    }

    if (!empty($urls)) {
        update_post_meta($product_id, '_ojf_seller_image_urls', wp_json_encode($urls));
    }
}

/**
 * Obter URLs originais do seller salvas no produto
 */
function ojf_get_seller_image_urls($product_id) {
    $json = get_post_meta($product_id, '_ojf_seller_image_urls', true);
    if ($json) {
        $urls = json_decode($json, true);
        return is_array($urls) ? $urls : array();
    }
    return array();
}

// ============================================================================
// OTIMIZAÇÃO DE IMAGEM: RESIZE 1200px + WebP
// ============================================================================

/**
 * Otimiza imagem: resize 1200px max + converte para WebP
 * Usa wp_get_image_editor (Imagick ou GD, o que estiver disponível)
 *
 * @param string $temp_file Caminho do arquivo temporário
 * @return array ['file', 'mime', 'ext', 'width', 'height', 'changed']
 */
function ojf_cdn_optimize_image($temp_file) {
    $result = [
        'file'    => $temp_file,
        'mime'    => mime_content_type($temp_file) ?: 'image/jpeg',
        'ext'     => pathinfo($temp_file, PATHINFO_EXTENSION) ?: 'jpg',
        'changed' => false,
    ];

    // Capturar dimensões originais
    $dims = @getimagesize($temp_file);
    if ($dims) {
        $result['width']  = (int) $dims[0];
        $result['height'] = (int) $dims[1];
    }

    if (!function_exists('wp_get_image_editor')) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
    }

    $editor = wp_get_image_editor($temp_file);
    if (is_wp_error($editor)) {
        ojf_debug_cdn('Image editor indisponivel, subindo original', 'warn');
        return $result;
    }

    $size = $editor->get_size();

    // Resize se > 1200px em qualquer dimensão
    if ($size['width'] > 1200 || $size['height'] > 1200) {
        $editor->resize(1200, 1200);
        ojf_debug_cdn('Resized', 'debug', [
            'de'   => $size['width'] . 'x' . $size['height'],
            'para' => '1200 max'
        ]);
    }

    $editor->set_quality(85);

    // Tentar WebP
    $webp_path = $temp_file . '.webp';
    $saved = $editor->save($webp_path, 'image/webp');

    if (!is_wp_error($saved) && file_exists($saved['path'])) {
        $result['file']    = $saved['path'];
        $result['mime']    = 'image/webp';
        $result['ext']     = 'webp';
        $result['changed'] = true;
        ojf_debug_cdn('WebP OK', 'debug', [
            'original_size' => filesize($temp_file),
            'webp_size'     => filesize($saved['path'])
        ]);
    } else {
        // Fallback: salvar JPEG/PNG redimensionado
        $opt_path = $temp_file . '.opt';
        $jpeg_saved = $editor->save($opt_path);
        if (!is_wp_error($jpeg_saved) && file_exists($jpeg_saved['path'])) {
            $result['file']    = $jpeg_saved['path'];
            $result['changed'] = true;
        }
        ojf_debug_cdn('WebP indisponivel, fallback resize only', 'warn');
    }

    // Recapturar dimensões do arquivo otimizado
    $new_dims = @getimagesize($result['file']);
    if ($new_dims) {
        $result['width']  = (int) $new_dims[0];
        $result['height'] = (int) $new_dims[1];
    }

    return $result;
}

// ============================================================================
// IMAGE QUEUE — TABELA
// ============================================================================

function ojf_iq_table() {
    global $wpdb;
    return $wpdb->prefix . OJF_IQ_TABLE;
}

function ojf_iq_create_table() {
    global $wpdb;
    $table   = ojf_iq_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id`             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `attachment_id`  bigint(20) unsigned DEFAULT NULL,
        `product_id`     bigint(20) unsigned NOT NULL,
        `seller_url`     text NOT NULL,
        `cdn_url`        text DEFAULT NULL,
        `status`         varchar(20) NOT NULL DEFAULT 'pending',
        `original_size`  int unsigned DEFAULT NULL COMMENT 'bytes',
        `optimized_size` int unsigned DEFAULT NULL COMMENT 'bytes',
        `original_dims`  varchar(20) DEFAULT NULL COMMENT 'WxH',
        `final_dims`     varchar(20) DEFAULT NULL COMMENT 'WxH',
        `format`         varchar(10) DEFAULT NULL COMMENT 'webp, jpg, png',
        `error`          text DEFAULT NULL,
        `attempts`       tinyint unsigned NOT NULL DEFAULT 0,
        `created_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `started_at`     datetime DEFAULT NULL,
        `completed_at`   datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`, `id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_attachment` (`attachment_id`)
    ) ENGINE=InnoDB {$charset};";

    $wpdb->query($sql);
}

add_action('init', function() {
    if (get_option('ojf_iq_version') !== '1.0.2') {
        ojf_iq_create_table();
        update_option('ojf_iq_version', '1.0.2', false);
    }
}, 5);

// ============================================================================
// IMAGE QUEUE — ENQUEUE + SELLER ATTACHMENT
// ============================================================================

function ojf_iq_build_pending_guid($url, $post_id) {
    return 'fullbai-iq://attachment/' . (int) $post_id . '/' . substr(hash('sha256', (string) $url), 0, 24);
}

/**
 * Cria attachment temporário com URL do seller (sem download)
 */
/** DEDUP: attachment já existente deste produto com a mesma URL de origem.
 *  Evita o bloat da biblioteca e o re-upload no R2 ao re-pushar o produto. */
function ojf_iq_find_existing_attachment($url, $post_id) {
    if (!$post_id) return 0;
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ojf_seller_url'
         WHERE p.post_type = 'attachment' AND p.post_parent = %d AND m.meta_value = %s
         ORDER BY p.ID ASC LIMIT 1", (int) $post_id, $url
    ));
}

function ojf_iq_create_seller_attachment($url, $post_id) {
    $filename = preg_replace('/\?.*/', '', basename($url));
    $is_presigned = ojf_dl_is_presigned_url($url);
    $url_length = strlen((string) $url);
    $guid = ($is_presigned || $url_length > 200)
        ? ojf_iq_build_pending_guid($url, $post_id)
        : $url;

    $attachment = [
        'post_mime_type' => 'image/jpeg',
        'post_title'     => sanitize_title(get_the_title($post_id)) ?: sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $post_id,
        'guid'           => $guid,
    ];

    $attach_id = wp_insert_attachment($attachment, false, $post_id, true);
    if (!$attach_id || is_wp_error($attach_id)) {
        ojf_debug_cdn('Falha ao criar attachment temporário da Image Queue', 'error', [
            'post_id'       => (int) $post_id,
            'url_length'    => $url_length,
            'presigned_url' => $is_presigned,
            'guid'          => $guid,
            'guid_length'   => strlen($guid),
            'wp_error'      => is_wp_error($attach_id) ? $attach_id->get_error_message() : 'wp_insert_attachment retornou vazio',
        ]);
        return false;
    }

    update_post_meta($attach_id, '_ojf_seller_url', $url);
    update_post_meta($attach_id, '_ojf_cdn_pending', '1');
    update_post_meta($attach_id, '_wp_attached_file', $url);

    // Metadata temporária para WooCommerce não quebrar
    wp_update_attachment_metadata($attach_id, [
        'file'        => $url,
        'width'       => 0,
        'height'      => 0,
        'sizes'       => [],
        'ojf_cdn' => true,
    ]);

    return $attach_id;
}

/**
 * Enfileira imagem para processamento
 */
function ojf_iq_enqueue($attachment_id, $seller_url, $product_id) {
    global $wpdb;
    $table = ojf_iq_table();

    $wpdb->insert($table, [
        'attachment_id' => $attachment_id,
        'product_id'    => $product_id,
        'seller_url'    => $seller_url,
        'status'        => 'pending',
        'created_at'    => current_time('mysql'),
    ], ['%d', '%d', '%s', '%s', '%s']);

    $id = (int) $wpdb->insert_id;

    if (!$id) {
        ojf_iq_create_table();
        $wpdb->insert($table, [
            'attachment_id' => $attachment_id,
            'product_id'    => $product_id,
            'seller_url'    => $seller_url,
            'status'        => 'pending',
            'created_at'    => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s']);
        $id = (int) $wpdb->insert_id;
    }

    return $id;
}

function ojf_iq_pending_count() {
    global $wpdb;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . ojf_iq_table() . "` WHERE status = 'pending'");
}

// ============================================================================
// IMAGE QUEUE — TRIGGER + WORKER ONE-SHOT
// ============================================================================

function ojf_iq_trigger_worker() {
    if (!empty($GLOBALS['ojf_iq_trigger_set'])) return;
    $GLOBALS['ojf_iq_trigger_set'] = true;
    add_action('shutdown', 'ojf_iq_become_worker', 10);
}

function ojf_iq_internal_worker_token() {
    return wp_hash('ojf_iq_internal_worker');
}

function ojf_iq_dispatch_async_worker() {
    if (ojf_iq_pending_count() <= 0) {
        return false;
    }

    $response = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => false,
        'body'      => [
            'action' => 'ojf_iq_internal_worker',
            'token'  => ojf_iq_internal_worker_token(),
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('[FullBAI ImgQ] Async worker dispatch failed: ' . $response->get_error_message());
        return false;
    }

    return true;
}

function ojf_iq_dispatch_async_workers($count = 1) {
    $count = max(0, (int) $count);
    $dispatched = 0;

    for ($i = 0; $i < $count; $i++) {
        if (ojf_iq_dispatch_async_worker()) {
            $dispatched++;
        }
    }

    return $dispatched;
}

function ojf_iq_run_one_worker_now($specific_id = 0, $lock_timeout = 5) {
    global $wpdb;

    $specific_id = (int) $specific_id;
    $my_lock = null;

    for ($i = 1; $i <= OJF_IQ_CONCURRENCY; $i++) {
        $lock_name = OJF_IQ_LOCK_NAME . '_' . $i;
        $got = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, $lock_timeout));
        if ($got) {
            $my_lock = $lock_name;
            break;
        }
    }

    if (!$my_lock) {
        return [
            'processed' => false,
            'lock_acquired' => false,
            'remaining_pending' => ojf_iq_pending_count(),
        ];
    }

    try {
        ignore_user_abort(true);
        set_time_limit(OJF_IQ_MAX_LOOP_SEC + 30);
        ojf_iq_recover_stuck();

        $result = ojf_iq_process_one($specific_id);
        $remaining_pending = $result['remaining_pending'] ?? ojf_iq_pending_count();

        if ($remaining_pending > 0) {
            // GENTIL: encadeia 1 sucessor (sem cascata exponencial que afoga o servidor).
            ojf_iq_dispatch_async_workers(1);
        }

        return [
            'processed' => !empty($result['processed']),
            'queue_id' => isset($result['queue_id']) ? (int) $result['queue_id'] : 0,
            'lock_acquired' => true,
            'remaining_pending' => $remaining_pending,
        ];
    } finally {
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $my_lock));
    }
}

function ojf_iq_become_worker() {
    global $wpdb;

    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    $my_lock = null;
    for ($i = 1; $i <= OJF_IQ_CONCURRENCY; $i++) {
        $lock_name = OJF_IQ_LOCK_NAME . '_' . $i;
        $got = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, OJF_IQ_LOCK_TIMEOUT));
        if ($got) { $my_lock = $lock_name; break; }
    }
    if (!$my_lock) return;

    $dispatch_next = false;
    $remaining_pending = ojf_iq_pending_count();

    try {
        ignore_user_abort(true);
        set_time_limit(OJF_IQ_MAX_LOOP_SEC + 30);
        ojf_iq_recover_stuck();
        $started = microtime(true);

        while (true) {
            $result = ojf_iq_process_one();
            $remaining_pending = $result['remaining_pending'] ?? ojf_iq_pending_count();

            if (empty($result['processed'])) {
                break;
            }

            if ($remaining_pending <= 0) {
                break;
            }

            if ((microtime(true) - $started) >= OJF_IQ_DRAIN_SEC) {
                $dispatch_next = true;
                break;
            }
        }

        if (!$dispatch_next) {
            $dispatch_next = $remaining_pending > 0;
        }
    } finally {
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $my_lock));
    }

    if ($dispatch_next) {
        // GENTIL: encadeia 1 sucessor (sem cascata exponencial que afoga o servidor).
        ojf_iq_dispatch_async_workers(1);
    }

    update_option('ojf_iq_last_worker_run', current_time('mysql'));
}

function ojf_iq_claim_pending_item($max_attempts = 5, $specific_id = 0) {
    global $wpdb;
    $table = ojf_iq_table();

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
                "UPDATE `{$table}` SET status = 'processing', started_at = %s, attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
                current_time('mysql'), $item->id
            ));
            if ($claimed) {
                return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $item->id));
            }
        }

        return null;
    }

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $item = $wpdb->get_row("SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        if (!$item) {
            return null;
        }

        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = 'processing', started_at = %s, attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
            current_time('mysql'), $item->id
        ));
        if ($claimed) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $item->id));
        }
    }

    return null;
}

function ojf_iq_process_one($specific_id = 0) {
    global $wpdb;
    $table = ojf_iq_table();

    if (!function_exists('download_url')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
    }

    $item = ojf_iq_claim_pending_item(5, $specific_id);
    if (!$item) {
        return [
            'processed' => false,
            'remaining_pending' => 0,
        ];
    }

    error_log(sprintf('[FullBAI ImgQ] #%d START: attach=%d product=%d', $item->id, $item->attachment_id, $item->product_id));

    try {
        $result = ojf_iq_process_item($item);

        if (is_wp_error($result)) {
            $msg = $result->get_error_message();
            $err_code = $result->get_error_code();

            // Attachment deletado = passed (retorno normal, não é falha)
            if ($err_code === 'attachment_deleted') {
                $wpdb->update($table, [
                    'status'       => 'passed',
                    'error'        => $msg,
                    'completed_at' => current_time('mysql'),
                ], ['id' => $item->id]);
            } elseif ($item->attempts < OJF_IQ_MAX_ATTEMPTS) {
                $wpdb->update($table, [
                    'status'     => 'pending',
                    'started_at' => null,
                    'error'      => $msg . ' [attempt ' . $item->attempts . '/' . OJF_IQ_MAX_ATTEMPTS . ']',
                ], ['id' => $item->id]);
            } else {
                $wpdb->update($table, [
                    'status'       => 'failed',
                    'error'        => $msg,
                    'completed_at' => current_time('mysql'),
                ], ['id' => $item->id]);
            }
        } else {
            $wpdb->update($table, [
                'status'         => 'completed',
                'cdn_url'        => $result['cdn_url'],
                'original_size'  => $result['original_size'],
                'optimized_size' => $result['optimized_size'],
                'original_dims'  => $result['original_dims'],
                'final_dims'     => $result['final_dims'],
                'format'         => $result['format'],
                'error'          => null,
                'completed_at'   => current_time('mysql'),
            ], ['id' => $item->id]);
            error_log(sprintf('[FullBAI ImgQ] #%d OK: %s → %s (%s)',
                $item->id, $result['original_dims'], $result['final_dims'], $result['format']));

            // ── Re-sync produto no Typesense/wp_productos_cache com URL R2 ──
            // Corrige race condition: imagem pode ser processada DEPOIS do sync
            if ($item->product_id && function_exists('ojf_v6_enqueue_event')) {
                ojf_v6_enqueue_event(
                    (int) $item->product_id,
                    defined('OJF_V6_ACTION_UPSERT') ? OJF_V6_ACTION_UPSERT : 'upsert',
                    'cdn_image_completed',
                    defined('OJF_PRIORITY_UPDATE') ? OJF_PRIORITY_UPDATE : 5
                );
                error_log(sprintf('[FullBAI ImgQ] #%d Re-sync enqueued for product %d (R2 URL ready)',
                    $item->id, $item->product_id));
            }
        }
    } catch (Throwable $e) {
        $wpdb->update($table, [
            'status'       => 'failed',
            'error'        => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
            'completed_at' => current_time('mysql'),
        ], ['id' => $item->id]);
    }

    $remaining_pending = ojf_iq_pending_count();
    error_log(sprintf(
        '[FullBAI ImgQ] Worker one-shot done: processed=1 remaining_pending=%d',
        $remaining_pending
    ));

    return [
        'processed' => true,
        'queue_id' => (int) $item->id,
        'remaining_pending' => $remaining_pending,
    ];
}

/**
 * Processa 1 item da fila: download → resize → WebP → R2 → atualizar attachment
 */
function ojf_iq_process_item($item) {
    if (!get_post($item->attachment_id)) {
        return new WP_Error('attachment_deleted', 'Attachment #' . $item->attachment_id . ' não existe mais');
    }

    $temp_file = ojf_robust_download($item->seller_url, 60);
    if (is_wp_error($temp_file)) {
        return new WP_Error('download_failed', 'Download falhou: ' . $temp_file->get_error_message());
    }

    $original_size = filesize($temp_file);
    $orig_dims     = @getimagesize($temp_file);
    $orig_w        = $orig_dims ? (int) $orig_dims[0] : 0;
    $orig_h        = $orig_dims ? (int) $orig_dims[1] : 0;

    // Otimizar
    $opt = ojf_cdn_optimize_image($temp_file);

    $object_key = ojf_cdn_build_object_key($item->product_id, $item->attachment_id, $opt['ext']);
    $cdn_url    = ojf_r2_upload($opt['file'], $object_key, $opt['mime']);

    $optimized_size = file_exists($opt['file']) ? filesize($opt['file']) : 0;

    // Cleanup
    @unlink($temp_file);
    if ($opt['changed']) @unlink($opt['file']);

    if (!$cdn_url) {
        return new WP_Error('r2_failed', 'R2 upload falhou para ' . $object_key);
    }

    $final_w = isset($opt['width'])  ? $opt['width']  : $orig_w;
    $final_h = isset($opt['height']) ? $opt['height'] : $orig_h;

    $attach_id = (int) $item->attachment_id;
    if (!ojf_cdn_finalize_attachment($attach_id, $cdn_url, $item->seller_url, $item->product_id, $opt['mime'], $final_w, $final_h, $object_key)) {
        ojf_r2_delete($object_key);
        return new WP_Error('attachment_finalize_failed', 'Falha ao finalizar attachment #' . $attach_id);
    }

    return [
        'cdn_url'        => $cdn_url,
        'original_size'  => $original_size,
        'optimized_size' => $optimized_size,
        'original_dims'  => $orig_w . 'x' . $orig_h,
        'final_dims'     => $final_w . 'x' . $final_h,
        'format'         => $opt['ext'],
    ];
}

// ============================================================================
// IMAGE QUEUE — RECOVERY + CRON
// ============================================================================

function ojf_iq_recover_stuck() {
    global $wpdb;
    $table  = ojf_iq_table();
    $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - OJF_IQ_STUCK_SEC);

    $stuck = $wpdb->get_results($wpdb->prepare(
        "SELECT id, attempts FROM `{$table}` WHERE status = 'processing' AND started_at < %s", $cutoff
    ));

    foreach ($stuck as $s) {
        if ($s->attempts >= OJF_IQ_MAX_ATTEMPTS) {
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
}

add_filter('cron_schedules', function($s) {
    if (!isset($s['ojf_iq_1min'])) {
        $s['ojf_iq_1min'] = ['interval' => 60, 'display' => 'FullBAI Image Queue 1min'];
    }
    return $s;
});

add_action('init', function() {
    if (!wp_next_scheduled('ojf_iq_cron')) {
        wp_schedule_event(time() + 60, 'ojf_iq_1min', 'ojf_iq_cron');
    }
}, 30);

add_action('ojf_iq_cron', function() {
    if (ojf_iq_pending_count() === 0) return;
    ojf_iq_become_worker();
});

add_action('ojf_iq_kick', function() {
    if (ojf_iq_pending_count() === 0) return;
    ojf_iq_become_worker();
});

add_action('ojf_iq_cron', function() {
    if (get_transient('ojf_iq_cleaned')) return;
    set_transient('ojf_iq_cleaned', 1, HOUR_IN_SECONDS);
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM `" . ojf_iq_table() . "` WHERE status='completed' AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        OJF_IQ_CLEANUP_DAYS
    ));
}, 20);

function ojf_iq_internal_worker_handler() {
    $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
    if (!$token || !hash_equals(ojf_iq_internal_worker_token(), $token)) {
        status_header(403);
        exit('forbidden');
    }

    ojf_iq_become_worker();
    exit('ok');
}

add_action('wp_ajax_ojf_iq_internal_worker', 'ojf_iq_internal_worker_handler');
add_action('wp_ajax_nopriv_ojf_iq_internal_worker', 'ojf_iq_internal_worker_handler');

// ============================================================================
// AUTO-TRIGGER POR PAGE-LOAD — rede de segurança independente do WP-Cron (v6.8)
// ============================================================================
// Cada page load (admin/front) checa, com rate-limit de 50s via transient,
// se existe imagem pending com mais de 60s. Se sim, agenda o worker para o
// shutdown da própria request (não bloqueia a entrega da página).
// Custo médio por request: 1 get_transient (Redis se houver object cache).
// Custo a cada 50s: + 1 SELECT 1 ... LIMIT 1 com índice em `status`.
// NÃO toca worker / lock / retry / recovery — só adiciona um gatilho extra.

add_action('init', function() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (wp_doing_cron()) return;                         // ojf_iq_cron já roda o worker
    if (defined('REST_REQUEST') && REST_REQUEST) return; // request REST nao precisa enxergar isso aqui

    if (get_transient('ojf_iq_autotrigger_lock')) return;
    set_transient('ojf_iq_autotrigger_lock', 1, 50);

    if (!function_exists('ojf_iq_table') || !function_exists('ojf_iq_trigger_worker')) return;

    global $wpdb;
    $stale = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM `" . ojf_iq_table() . "`
         WHERE status='pending' AND created_at < %s LIMIT 1",
        gmdate('Y-m-d H:i:s', time() - 60)
    ));
    if ($stale) ojf_iq_trigger_worker();
}, 100);

// ============================================================================
// TRIGGER EXTERNO via ?ojf_queue_trigger=1 (v6.9)
// ============================================================================
// Mesmo token usado pelo sync v7 e pela api queue. Cada arquivo escuta o
// proprio handler — sem dependencia entre filas. Roda em init priority 15
// (antes do handler do sync que esta em priority 20).
// Nao chama die() — sync responde o JSON unificado.

if (!defined('OJF_QUEUE_TRIGGER_TOKEN')) {
    define('OJF_QUEUE_TRIGGER_TOKEN', 'fb_queue_3b5cfdd28d9a788c411334f9039a3d0a459f4524b806b96f');
}

function ojf_iq_validate_external_trigger() {
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
    if (!ojf_iq_validate_external_trigger()) return;

    @ignore_user_abort(true);
    @set_time_limit(120);

    if (!function_exists('ojf_iq_become_worker')) return;

    ojf_iq_become_worker();
    update_option('ojf_iq_last_trigger_get', current_time('mysql'));
}, 15);

// ============================================================================
// IMAGE QUEUE — FILTRO: PENDING IMAGES MOSTRAM SELLER URL
// ============================================================================

add_filter('wp_get_attachment_url', function($url, $attachment_id) {
    $pending = get_post_meta($attachment_id, '_ojf_cdn_pending', true);
    if ($pending) {
        $seller_url = get_post_meta($attachment_id, '_ojf_seller_url', true);
        if ($seller_url) return $seller_url;
    }
    return $url;
}, 5, 2);
