<?php
/**
 * OdontoJF Woo Bridge — Mídia R2 (biblioteca de mídia).
 *
 * Núcleo: sobe QUALQUER attachment do WordPress pro R2 (otimiza WebP), com
 * roteamento de pasta:
 *   - anexo de PRODUTO (tem produto-pai) → products/{id}/{slug}-{att}.webp
 *   - upload SOLTO (banner/blog/logo)    → assets/{slug}-{att}.webp
 * e auto-upload opcional em TODO upload do WP (hook add_attachment).
 *
 * 100% religado à infra R2 existente do plugin — NÃO usa nada de terceiros:
 *   ojf_r2_upload(), ojf_r2_delete() (assinam SigV4 com OJF_R2_* das options),
 *   ojf_cdn_build_object_key() (key de produto), metas _ojf_r2_object_key /
 *   _ojf_cdn_url / _ojf_seller_url. O delete_attachment → ojf_cdn_cleanup_r2
 *   já existente apaga do R2 qualquer anexo migrado (zero lixo).
 */

if (!defined('ABSPATH')) exit;

if (!defined('OJF_MEDIA_ASSETS_PREFIX')) define('OJF_MEDIA_ASSETS_PREFIX', 'assets');
if (!defined('OJF_MEDIA_QUALITY'))       define('OJF_MEDIA_QUALITY', 85);
if (!defined('OJF_MEDIA_DEFAULT_MAXW'))  define('OJF_MEDIA_DEFAULT_MAXW', 1600);

/* ── options (toggles do admin) ───────────────────────────────────────────── */

/** Auto-upload pro R2 em todo upload do WP ligado? (default OFF p/ segurança). */
function ojf_media_auto_enabled() {
    return (int) get_option('ojf_media_auto_r2', 0) === 1;
}
/** Largura máx. default do auto-upload. */
function ojf_media_auto_maxw() {
    $w = (int) get_option('ojf_media_auto_maxw', OJF_MEDIA_DEFAULT_MAXW);
    return max(50, min(8000, $w ?: OJF_MEDIA_DEFAULT_MAXW));
}
/** Apagar arquivo local após o R2 confirmar? (default SIM). */
function ojf_media_delete_local_enabled() {
    return (int) get_option('ojf_media_delete_local', 1) === 1;
}

/* ── helpers ──────────────────────────────────────────────────────────────── */

/** Já está no R2? (tem object key OU cdn url). */
function ojf_media_already_on_r2($attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) return false;
    if ((string) get_post_meta($attachment_id, '_ojf_r2_object_key', true) !== '') return true;
    if ((string) get_post_meta($attachment_id, '_ojf_cdn_url', true) !== '') return true;
    return false;
}

/** Resolve o produto-pai de um anexo (sobe da variação). 0 = upload solto. */
function ojf_media_resolve_product_id($attachment_id) {
    $parent_id = (int) wp_get_post_parent_id($attachment_id);
    if (!$parent_id) return 0;
    $parent_type = get_post_type($parent_id);
    if ($parent_type === 'product_variation') {
        $grandparent = (int) wp_get_post_parent_id($parent_id);
        return ($grandparent && get_post_type($grandparent) === 'product') ? $grandparent : 0;
    }
    return $parent_type === 'product' ? $parent_id : 0;
}

/** URL de origem do anexo (seller url salva, senão a URL atual do anexo). */
function ojf_media_resolve_url($attachment_id) {
    $url = (string) get_post_meta($attachment_id, '_ojf_seller_url', true);
    if ($url === '') $url = (string) wp_get_attachment_url($attachment_id);
    return $url;
}

/** Object key dedicado pra pasta /assets/ (uploads soltos do admin). */
function ojf_media_build_assets_key($attachment_id, $filename, $ext = 'webp') {
    $attachment_id = (int) $attachment_id;
    $base = pathinfo((string) $filename, PATHINFO_FILENAME);
    $slug = sanitize_title($base);
    if ($slug === '') $slug = 'asset';
    $ext = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $ext));
    if ($ext === '') $ext = 'webp';
    return sprintf('%s/%s-%d.%s', trim(OJF_MEDIA_ASSETS_PREFIX, '/'), $slug, $attachment_id, $ext);
}

/**
 * Otimiza imagem: resize se > $max_width + converte pra WebP (q85). Usa o editor
 * nativo do WP (GD/Imagick). Diferente do ojf_cdn_optimize_image (1200 fixo):
 * aqui a largura é parametrizável (medida por upload, pedido do usuário).
 * Retorna ['file','mime','ext','width','height'] ou WP_Error.
 */
function ojf_media_optimize_image($source_path, $max_width = null) {
    if ($max_width === null) $max_width = (int) OJF_MEDIA_DEFAULT_MAXW;
    $max_width = max(50, min(8000, (int) $max_width));
    if (!file_exists($source_path)) return new WP_Error('no_file', 'Arquivo fonte não encontrado: ' . $source_path);
    if (!function_exists('wp_get_image_editor')) require_once ABSPATH . 'wp-admin/includes/image.php';

    $editor = wp_get_image_editor($source_path);
    if (is_wp_error($editor)) return $editor;
    $size = $editor->get_size();
    if (!empty($size['width']) && !empty($size['height']) && ($size['width'] > $max_width || $size['height'] > $max_width)) {
        $editor->resize($max_width, $max_width);
    }
    $editor->set_quality((int) OJF_MEDIA_QUALITY);

    $tmp_dir = get_temp_dir();
    $webp_target = trailingslashit($tmp_dir) . 'ojf-media-' . wp_generate_password(8, false) . '.webp';
    $saved = $editor->save($webp_target, 'image/webp');
    if (is_wp_error($saved)) return $saved;
    if (empty($saved['path']) || !file_exists($saved['path'])) {
        return new WP_Error('save_failed', 'Editor salvou mas arquivo não existe');
    }
    $dims = @getimagesize($saved['path']);
    return [
        'file'   => $saved['path'],
        'mime'   => 'image/webp',
        'ext'    => 'webp',
        'width'  => $dims ? (int) $dims[0] : 0,
        'height' => $dims ? (int) $dims[1] : 0,
    ];
}

/**
 * Apaga os arquivos físicos do anexo (master + thumbnails) do disco. Restrito a
 * wp-content/uploads/ via realpath() — não deleta o post nem as metas, só o disco.
 */
function ojf_media_delete_local_files($attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) return 0;
    $upload_dir = wp_get_upload_dir();
    $base_dir = isset($upload_dir['basedir']) ? realpath($upload_dir['basedir']) : false;
    if (!$base_dir) return 0;

    $paths = [];
    $attached = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
    if ($attached !== '' && !preg_match('#^https?://#i', $attached)) {
        $paths[] = $upload_dir['basedir'] . '/' . ltrim($attached, '/');
    }
    $meta = wp_get_attachment_metadata($attachment_id);
    if (!empty($meta['sizes']) && is_array($meta['sizes']) && !empty($paths[0])) {
        $original_dir = dirname($paths[0]);
        foreach ($meta['sizes'] as $size) {
            if (!empty($size['file']) && is_string($size['file'])) {
                $paths[] = $original_dir . '/' . $size['file'];
            }
        }
    }

    $deleted = 0; $seen = [];
    foreach ($paths as $p) {
        if (!is_string($p) || $p === '' || isset($seen[$p])) continue;
        $seen[$p] = true;
        $real = realpath($p);
        if (!$real) continue;
        if (strpos($real, $base_dir . DIRECTORY_SEPARATOR) !== 0 && $real !== $base_dir) continue;
        if (is_dir($real)) continue;
        if (@unlink($real)) $deleted++;
    }
    return $deleted;
}

/**
 * NÚCLEO — sobe um anexo pro R2 (síncrono), com roteamento de pasta + (opcional)
 * apaga o local. Reusa 100% a infra do plugin (ojf_r2_upload + ojf_cdn_build_object_key).
 * Retorna ['ok'=>bool, 'cdn_url', 'object_key', 'route'('products'|'assets'),
 *          'local_deleted', 'reason'].
 */
function ojf_media_upload_direct($attachment_id, $max_width = null, $delete_local = null) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) return ['ok' => false, 'reason' => 'invalid_id'];
    if (ojf_media_already_on_r2($attachment_id)) return ['ok' => false, 'reason' => 'already_on_r2'];
    if (!function_exists('ojf_r2_upload')) return ['ok' => false, 'reason' => 'r2_handler_missing'];
    if ($max_width === null)   $max_width = ojf_media_auto_maxw();
    if ($delete_local === null) $delete_local = ojf_media_delete_local_enabled();

    $source = get_attached_file($attachment_id);
    if (!$source || !file_exists($source)) return ['ok' => false, 'reason' => 'local_file_missing'];

    // Só imagem (R2/WebP). Outros tipos: ignora (fica local).
    $mime_in = (string) get_post_mime_type($attachment_id);
    if (stripos($mime_in, 'image/') !== 0) return ['ok' => false, 'reason' => 'not_image'];

    $opt = ojf_media_optimize_image($source, $max_width);
    if (is_wp_error($opt)) return ['ok' => false, 'reason' => 'optimize_failed: ' . $opt->get_error_message()];

    // Roteamento: produto → products/{id}/...; solto → assets/...
    $product_id = ojf_media_resolve_product_id($attachment_id);
    if ($product_id > 0 && function_exists('ojf_cdn_build_object_key')) {
        $object_key = ojf_cdn_build_object_key($product_id, $attachment_id, $opt['ext']);
        $route = 'products';
    } else {
        $object_key = ojf_media_build_assets_key($attachment_id, basename($source), $opt['ext']);
        $route = 'assets';
    }

    $cdn_url = ojf_r2_upload($opt['file'], $object_key, $opt['mime']);
    if (!empty($opt['file']) && $opt['file'] !== $source) @unlink($opt['file']);
    if (empty($cdn_url) || !is_string($cdn_url)) {
        return ['ok' => false, 'reason' => 'r2_upload_failed', 'object_key' => $object_key];
    }

    // Metas idênticas ao finalize do plugin → filtro/coluna reconhecem + o
    // delete_attachment (ojf_cdn_cleanup_r2) apaga só este object_key do R2.
    update_post_meta($attachment_id, '_ojf_r2_object_key', $object_key);
    update_post_meta($attachment_id, '_ojf_cdn_url', $cdn_url);
    update_post_meta($attachment_id, '_wp_attached_file', $cdn_url);
    delete_post_meta($attachment_id, '_ojf_cdn_pending');

    $meta = wp_get_attachment_metadata($attachment_id);
    if (!is_array($meta)) $meta = [];
    $meta['file']   = $cdn_url;
    $meta['width']  = $opt['width'];
    $meta['height'] = $opt['height'];
    wp_update_attachment_metadata($attachment_id, $meta);

    $local_deleted = 0;
    if ($delete_local) $local_deleted = ojf_media_delete_local_files($attachment_id);

    return [
        'ok'            => true,
        'cdn_url'       => $cdn_url,
        'object_key'    => $object_key,
        'route'         => $route,
        'product_id'    => $product_id,
        'local_deleted' => $local_deleted,
    ];
}

/* ── auto-upload: TODO upload do WP vai pro R2 (se ligado) ─────────────────── */

add_action('add_attachment', 'ojf_media_auto_upload_on_add', 99, 1);
function ojf_media_auto_upload_on_add($attachment_id) {
    if (!ojf_media_auto_enabled()) return;
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) return;
    // Pula o que já está no R2 ou está na fila de imagem do produto (_ojf_cdn_pending):
    // a fila ojf_cdn_image_queue já cuida desses — não duplicar.
    if (ojf_media_already_on_r2($attachment_id)) return;
    if ((string) get_post_meta($attachment_id, '_ojf_cdn_pending', true) !== '') return;
    // Só imagens.
    if (stripos((string) get_post_mime_type($attachment_id), 'image/') !== 0) return;

    ojf_media_upload_direct($attachment_id, ojf_media_auto_maxw(), ojf_media_delete_local_enabled());
}
