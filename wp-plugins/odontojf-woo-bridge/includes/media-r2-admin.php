<?php
/**
 * OdontoJF Woo Bridge — Mídia R2 (admin UI). Design CINZA PURO (sem cor).
 *
 *  1) Biblioteca (upload.php lista): coluna "R2" (●/○), filtro "Off R2",
 *     row action + bulk action "Migrar pro R2".
 *  2) Página "Mídia R2": grid paginado com migrar individual + em massa (AJAX).
 *  3) Página "Upload R2 → /assets/": upload manual com largura máx. por upload.
 *
 * Tudo reusa ojf_media_upload_direct() (media-r2.php). Zero dependência externa.
 */

if (!defined('ABSPATH')) exit;

/* ===========================================================================
 * Paleta CINZA (premium monocromático)
 * ======================================================================== */
function ojf_media_css_vars() {
    return [
        'bg'      => '#000',
        'panel'   => '#1d1d1f',
        'inset'   => '#0c0c0e',
        'border'  => '#2d2d2d',
        'border2' => '#3a3a3c',
        'text'    => '#f5f5f7',
        'muted'   => '#86868b',
        'dim'     => '#48484a',
        'on'      => '#f5f5f7', // ● no R2 (branco)
        'off'     => '#6e6e73', // ○ local (cinza)
    ];
}

/* ===========================================================================
 * 1) BIBLIOTECA — coluna "R2"
 * ======================================================================== */
add_filter('manage_media_columns', function ($columns) {
    $columns['ojf_r2'] = 'R2';
    return $columns;
});
add_action('manage_media_custom_column', function ($column, $post_id) {
    if ($column !== 'ojf_r2') return;
    $c = ojf_media_css_vars();
    if (function_exists('ojf_media_already_on_r2') && ojf_media_already_on_r2($post_id)) {
        $url = (string) get_post_meta($post_id, '_ojf_cdn_url', true);
        echo '<span title="' . esc_attr($url) . '" style="color:' . $c['on'] . ';font-weight:700;">&#9679; no R2</span>';
    } else {
        echo '<span style="color:' . $c['off'] . ';font-weight:700;">&#9675; local</span>';
    }
}, 10, 2);

/* ── botão + filtro "Off R2" na header da biblioteca ──────────────────────── */
add_action('admin_head-upload.php', function () {
    if (!current_user_can('upload_files')) return;
    $c = ojf_media_css_vars();
    $url_filter = admin_url('upload.php?ojf_off_r2=1');
    $url_clear  = admin_url('upload.php');
    $url_grid   = admin_url('admin.php?page=ojf-media-r2');
    $is_active  = !empty($_GET['ojf_off_r2']);
    ?>
    <style>
        .ojf-r2-btn{background:<?php echo $is_active ? $c['text'] : 'transparent'; ?> !important;
            color:<?php echo $is_active ? '#000' : $c['text']; ?> !important;
            border:1px solid <?php echo $c['border2']; ?> !important;box-shadow:none !important;margin-left:6px;}
        .ojf-r2-btn:hover{background:<?php echo $c['text']; ?> !important;color:#000 !important;}
        .ojf-r2-ghost{background:transparent !important;color:<?php echo $c['muted']; ?> !important;border:1px solid <?php echo $c['border2']; ?> !important;margin-left:4px;}
    </style>
    <script>
    jQuery(function($){
        var grid = $('<a class="page-title-action ojf-r2-btn" href="<?php echo esc_url($url_grid); ?>"><?php echo esc_js('Mídia R2 (grid)'); ?></a>');
        var filt = $('<a class="page-title-action ojf-r2-btn" href="<?php echo esc_url($url_filter); ?>"><?php echo $is_active ? esc_js('✓ Off R2 (ativo)') : esc_js('Filtrar Off R2'); ?></a>');
        $('.wp-heading-inline').after(grid).after(filt);
        <?php if ($is_active): ?>
        filt.after($('<a class="page-title-action ojf-r2-ghost" href="<?php echo esc_url($url_clear); ?>"><?php echo esc_js('Limpar filtro'); ?></a>'));
        <?php endif; ?>
    });
    </script>
    <?php
});

add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query() || empty($_GET['ojf_off_r2'])) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'upload') return;
    $existing = $query->get('meta_query');
    if (!is_array($existing)) $existing = [];
    $query->set('meta_query', array_merge(['relation' => 'AND'], $existing, [
        ['key' => '_ojf_r2_object_key', 'compare' => 'NOT EXISTS'],
        ['key' => '_ojf_cdn_url',       'compare' => 'NOT EXISTS'],
    ]));
});

/* ── notice com contagem (Off R2) ─────────────────────────────────────────── */
add_action('admin_notices', function () {
    if (empty($_GET['ojf_off_r2']) || !current_user_can('upload_files')) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'upload') return;
    global $wpdb;
    $off = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         WHERE p.post_type='attachment' AND p.post_status IN ('inherit','private')
           AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_ojf_r2_object_key' AND m.meta_value<>'')"
    );
    $all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status IN ('inherit','private')");
    $on  = max(0, $all - $off);
    $c   = ojf_media_css_vars();
    echo '<div class="notice" style="border-left:4px solid ' . $c['off'] . ';padding:12px 16px;">'
       . '<p style="margin:0;font-size:14px;"><strong>Mídia OFF R2:</strong> '
       . '<strong style="font-size:17px;">' . number_format_i18n($off) . '</strong> anexos ainda no disco local · '
       . '<strong>' . number_format_i18n($on) . '</strong> no R2 (de ' . number_format_i18n($all) . ').</p>'
       . '<p style="margin:6px 0 0;color:#666;font-size:13px;">Migre individual pela ação “Migrar pro R2”, em massa pelo dropdown “Ações em massa”, ou pelo grid em <em>Mídia R2</em>.</p>'
       . '</div>';
});

/* ── row action "Migrar pro R2" ───────────────────────────────────────────── */
add_filter('media_row_actions', function ($actions, $post) {
    if (!current_user_can('upload_files')) return $actions;
    if (function_exists('ojf_media_already_on_r2') && ojf_media_already_on_r2($post->ID)) return $actions;
    $url = wp_nonce_url(
        admin_url('admin-post.php?action=ojf_media_migrate_one&att=' . (int) $post->ID),
        'ojf_media_migrate_' . (int) $post->ID
    );
    $actions['ojf_media_migrate'] = '<a href="' . esc_url($url) . '" style="font-weight:600;">Migrar pro R2</a>';
    return $actions;
}, 10, 2);

add_action('admin_post_ojf_media_migrate_one', function () {
    $att = isset($_GET['att']) ? (int) $_GET['att'] : 0;
    if ($att <= 0) wp_die('ID inválido');
    if (!current_user_can('upload_files')) wp_die('Sem permissão');
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ojf_media_migrate_' . $att)) wp_die('Nonce inválido');
    $r = ojf_media_upload_direct($att);
    $back = admin_url('upload.php?ojf_off_r2=1');
    $back = add_query_arg(!empty($r['ok'])
        ? ['ojf_migrated' => 1, 'ojf_route' => $r['route'], 'ojf_deleted' => (int) $r['local_deleted']]
        : ['ojf_migrate_err' => rawurlencode($r['reason'] ?? 'erro')], $back);
    wp_safe_redirect($back); exit;
});

/* ── bulk action "Migrar pro R2 (apaga local)" ────────────────────────────── */
add_filter('bulk_actions-upload', function ($actions) {
    return array_merge(['ojf_media_migrate_bulk' => 'Migrar pro R2 (apaga local)'], $actions);
});
add_filter('handle_bulk_actions-upload', function ($redirect, $action, $ids) {
    if ($action !== 'ojf_media_migrate_bulk' || !current_user_can('upload_files')) return $redirect;
    @set_time_limit(600);
    $ok = 0; $skip = 0; $del = 0;
    foreach ((array) $ids as $att) {
        $r = ojf_media_upload_direct((int) $att);
        if (!empty($r['ok'])) { $ok++; $del += (int) $r['local_deleted']; }
        else $skip++;
    }
    return add_query_arg(['ojf_migrated' => $ok, 'ojf_skipped' => $skip, 'ojf_deleted' => $del], $redirect);
}, 10, 3);

add_action('admin_notices', function () {
    if (!isset($_GET['ojf_migrated']) && !isset($_GET['ojf_migrate_err'])) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'upload') return;
    if (isset($_GET['ojf_migrate_err'])) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>Erro ao migrar:</strong> ' . esc_html(rawurldecode((string) $_GET['ojf_migrate_err'])) . '</p></div>';
        return;
    }
    $ok = (int) $_GET['ojf_migrated']; $skip = (int) ($_GET['ojf_skipped'] ?? 0); $del = (int) ($_GET['ojf_deleted'] ?? 0);
    echo '<div class="notice notice-success is-dismissible"><p><strong>&#9679; ' . $ok . '</strong> anexo(s) migrado(s) pro R2 · <strong>' . $del . '</strong> arquivo(s) local(is) apagado(s)'
       . ($skip > 0 ? ' · <span style="color:#666;">' . $skip . ' pulado(s) (já no R2 ou não-imagem)</span>' : '') . '.</p></div>';
});

/* ===========================================================================
 * 2) PÁGINA "Mídia R2" (grid) + 3) Upload /assets/ — menus
 * ======================================================================== */
add_action('admin_menu', function () {
    add_menu_page('Mídia R2', 'Mídia R2', 'upload_files', 'ojf-media-r2', 'ojf_media_render_grid_page', 'dashicons-cloud', 21);
    add_submenu_page('ojf-media-r2', 'Mídia R2 — Grid', 'Grid', 'upload_files', 'ojf-media-r2', 'ojf_media_render_grid_page');
    add_submenu_page('ojf-media-r2', 'Upload R2 → /assets/', 'Upload /assets/', 'upload_files', 'ojf-media-r2-upload', 'ojf_media_render_upload_page');
    add_submenu_page('upload.php', 'Mídia R2', 'Mídia R2', 'upload_files', 'ojf-media-r2', 'ojf_media_render_grid_page');
}, 9);

/* ── AJAX: listar anexos (paginado) ───────────────────────────────────────── */
add_action('wp_ajax_ojf_media_list', function () {
    if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Sem permissão']);
    check_ajax_referer('ojf_media_nonce', 'nonce');
    global $wpdb;
    $limit  = max(20, min(500, (int) ($_POST['limit'] ?? 60)));
    $offset = max(0, (int) ($_POST['offset'] ?? 0));
    $filter = sanitize_key((string) ($_POST['filter'] ?? 'all'));   // all | local | r2
    $search = sanitize_text_field((string) ($_POST['search'] ?? ''));

    $where = "p.post_type='attachment' AND p.post_status IN ('inherit','private') AND p.post_mime_type LIKE 'image/%%'";
    $args = [];
    if ($filter === 'local') {
        $where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_ojf_r2_object_key' AND m.meta_value<>'')";
    } elseif ($filter === 'r2') {
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_ojf_r2_object_key' AND m.meta_value<>'')";
    }
    if ($search !== '') { $where .= " AND p.post_title LIKE %s"; $args[] = '%' . $wpdb->esc_like($search) . '%'; }

    $count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}";
    $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, $args)) : $wpdb->get_var($count_sql));

    $list_sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.ID DESC LIMIT %d OFFSET %d";
    $list_args = array_merge($args, [$limit, $offset]);
    $ids = $wpdb->get_col($wpdb->prepare($list_sql, $list_args));

    $items = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        $on = ojf_media_already_on_r2($id);
        $thumb = $on ? (string) get_post_meta($id, '_ojf_cdn_url', true) : (string) wp_get_attachment_image_url($id, [120, 120]);
        if ($thumb === '') $thumb = (string) wp_get_attachment_url($id);
        $items[] = [
            'id'    => $id,
            'title' => get_the_title($id) ?: ('#' . $id),
            'thumb' => $thumb,
            'on_r2' => $on ? 1 : 0,
            'edit'  => admin_url('post.php?post=' . $id . '&action=edit'),
        ];
    }
    $counts = [
        'all'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status IN ('inherit','private') AND post_mime_type LIKE 'image/%'"),
        'r2'    => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_ojf_r2_object_key' AND meta_value<>''"),
    ];
    $counts['local'] = max(0, $counts['all'] - $counts['r2']);
    wp_send_json_success(['items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'counts' => $counts]);
});

/* ── AJAX: migrar 1+ anexos ───────────────────────────────────────────────── */
add_action('wp_ajax_ojf_media_migrate', function () {
    if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Sem permissão']);
    check_ajax_referer('ojf_media_nonce', 'nonce');
    @set_time_limit(300);
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode(wp_unslash($ids), true);
    if (!is_array($ids)) wp_send_json_error(['message' => 'ids inválidos']);
    $maxw = isset($_POST['maxw']) ? (int) $_POST['maxw'] : null;
    $ok = 0; $skip = 0; $del = 0; $detail = [];
    foreach ($ids as $id) {
        $r = ojf_media_upload_direct((int) $id, $maxw);
        if (!empty($r['ok'])) { $ok++; $del += (int) $r['local_deleted']; $detail[] = ['id' => (int) $id, 'ok' => 1, 'route' => $r['route'], 'cdn' => $r['cdn_url']]; }
        else { $skip++; $detail[] = ['id' => (int) $id, 'ok' => 0, 'reason' => $r['reason'] ?? '']; }
    }
    wp_send_json_success(['migrated' => $ok, 'skipped' => $skip, 'deleted' => $del, 'detail' => $detail]);
});

/* ── render: GRID ─────────────────────────────────────────────────────────── */
function ojf_media_render_grid_page() {
    if (!current_user_can('upload_files')) wp_die('Sem permissão');
    $c = ojf_media_css_vars();
    $nonce = wp_create_nonce('ojf_media_nonce');
    $ajax  = admin_url('admin-ajax.php');
    ?>
    <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:<?php echo $c['bg']; ?>;color:<?php echo $c['text']; ?>;padding:36px 24px;margin-left:-20px;min-height:100vh;">
    <div style="max-width:1500px;margin:0 auto;">
        <h1 style="font-size:32px;font-weight:700;letter-spacing:-.5px;margin:0 0 4px;">Mídia R2</h1>
        <p style="color:<?php echo $c['muted']; ?>;font-size:14px;margin:0 0 22px;">Grid das imagens da biblioteca. &#9679; = no R2 · &#9675; = local. Selecione e migre — anexo de produto vai pra <code>products/</code>, upload solto vai pra <code>assets/</code>.</p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
            <button class="ojf-chip" data-filter="all"   style="background:<?php echo $c['text']; ?>;color:#000;border:none;padding:6px 14px;border-radius:980px;cursor:pointer;font-size:12px;font-weight:600;">Todos <span class="cnt">—</span></button>
            <button class="ojf-chip" data-filter="local" style="background:<?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;border:none;padding:6px 14px;border-radius:980px;cursor:pointer;font-size:12px;font-weight:600;">&#9675; Local <span class="cnt">—</span></button>
            <button class="ojf-chip" data-filter="r2"    style="background:<?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;border:none;padding:6px 14px;border-radius:980px;cursor:pointer;font-size:12px;font-weight:600;">&#9679; No R2 <span class="cnt">—</span></button>
            <input type="search" id="ojf-search" placeholder="Buscar título…" style="background:<?php echo $c['panel']; ?>;border:1px solid <?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;padding:6px 14px;border-radius:980px;font-size:13px;width:240px;margin-left:auto;">
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;background:<?php echo $c['panel']; ?>;padding:12px 16px;border-radius:10px;border:1px solid <?php echo $c['border']; ?>;margin-bottom:14px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <label style="color:<?php echo $c['muted']; ?>;font-size:12px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="ojf-all"> Selecionar visíveis</label>
                <span id="ojf-sel" style="color:<?php echo $c['muted']; ?>;font-size:13px;"><strong style="color:<?php echo $c['text']; ?>;">0</strong> selecionados</span>
                <button id="ojf-migrate" disabled style="background:<?php echo $c['text']; ?>;color:#000;border:none;padding:8px 18px;border-radius:980px;cursor:pointer;font-weight:600;font-size:13px;opacity:.5;">Migrar selecionados pro R2</button>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <label style="color:<?php echo $c['muted']; ?>;font-size:12px;">Largura máx:</label>
                <input type="number" id="ojf-maxw" value="<?php echo (int) OJF_MEDIA_DEFAULT_MAXW; ?>" min="50" max="8000" step="50" style="background:<?php echo $c['inset']; ?>;border:1px solid <?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;padding:6px 10px;border-radius:8px;width:90px;font-size:13px;">
                <span id="ojf-result" style="color:<?php echo $c['muted']; ?>;font-size:13px;margin-left:6px;"></span>
                <button id="ojf-prev" disabled style="background:transparent;border:1px solid <?php echo $c['border2']; ?>;color:<?php echo $c['text']; ?>;padding:6px 12px;border-radius:8px;cursor:pointer;">‹</button>
                <button id="ojf-next" disabled style="background:transparent;border:1px solid <?php echo $c['border2']; ?>;color:<?php echo $c['text']; ?>;padding:6px 12px;border-radius:8px;cursor:pointer;">›</button>
            </div>
        </div>

        <div id="ojf-prog" style="display:none;background:<?php echo $c['panel']; ?>;border:1px solid <?php echo $c['border2']; ?>;border-radius:10px;padding:12px 16px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;"><strong id="ojf-prog-msg">Migrando…</strong><span id="ojf-prog-cnt" style="color:<?php echo $c['muted']; ?>;"></span></div>
            <div style="background:<?php echo $c['inset']; ?>;border-radius:980px;height:6px;overflow:hidden;"><div id="ojf-prog-bar" style="background:<?php echo $c['text']; ?>;height:100%;width:0;transition:width .3s;"></div></div>
        </div>

        <div id="ojf-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;">
            <div style="grid-column:1/-1;text-align:center;color:<?php echo $c['muted']; ?>;padding:40px;">Carregando…</div>
        </div>
    </div></div>

    <script>
    (function(){
        const C = <?php echo wp_json_encode(ojf_media_css_vars()); ?>;
        const N='<?php echo esc_js($nonce); ?>', U='<?php echo esc_js($ajax); ?>';
        const post=(a,d={})=>fetch(U,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:a,nonce:N,...d})}).then(r=>r.json());
        const esc=v=>String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        let limit=60, offset=0, filter='all', search='', total=0;
        const sel=new Set();

        function card(it){
            const on=it.on_r2===1;
            const dot=on?('<span style="color:'+C.on+';">&#9679;</span>'):('<span style="color:'+C.off+';">&#9675;</span>');
            const border=sel.has(it.id)?C.text:C.border;
            const img=it.thumb?('<img src="'+esc(it.thumb)+'" loading="lazy" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:'+C.inset+';">')
                :('<div style="width:100%;aspect-ratio:1/1;background:'+C.inset+';display:flex;align-items:center;justify-content:center;color:'+C.dim+';font-size:30px;">▦</div>');
            return '<div class="ojf-card" data-id="'+it.id+'" style="position:relative;background:'+C.inset+';border:1px solid '+border+';border-radius:8px;overflow:hidden;cursor:pointer;">'
                +'<div style="position:absolute;top:6px;left:6px;background:rgba(0,0,0,.6);border-radius:4px;padding:1px 4px;z-index:2;"><input type="checkbox" class="ojf-cb"'+(sel.has(it.id)?' checked':'')+' style="margin:0;"></div>'
                +'<div style="position:absolute;top:6px;right:8px;z-index:2;font-size:13px;">'+dot+'</div>'
                +img
                +'<div style="padding:7px 8px;font-size:11px;line-height:1.35;">'
                +'<div style="color:'+C.text+';white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(it.title)+'</div>'
                +'<div style="display:flex;justify-content:space-between;margin-top:3px;color:'+C.muted+';">'
                +'<span>'+(on?'no R2':'local')+'</span>'
                +(on?'':'<button class="ojf-one" data-id="'+it.id+'" onclick="event.stopPropagation()" style="background:transparent;border:1px solid '+C.border2+';color:'+C.text+';padding:1px 8px;border-radius:980px;cursor:pointer;font-size:10px;font-weight:600;">Migrar</button>')
                +'<a href="'+esc(it.edit)+'" target="_blank" onclick="event.stopPropagation()" style="color:'+C.muted+';text-decoration:none;">#'+it.id+'</a>'
                +'</div></div></div>';
        }
        function updSel(){
            document.getElementById('ojf-sel').innerHTML='<strong style="color:'+C.text+';">'+sel.size+'</strong> selecionados';
            const b=document.getElementById('ojf-migrate'); b.disabled=sel.size===0; b.style.opacity=sel.size?'1':'.5';
        }
        async function load(){
            const g=document.getElementById('ojf-grid');
            g.innerHTML='<div style="grid-column:1/-1;text-align:center;color:'+C.muted+';padding:40px;">Carregando…</div>';
            const j=await post('ojf_media_list',{limit,offset,filter,search});
            if(!j.success){g.innerHTML='<div style="grid-column:1/-1;color:#c33;padding:40px;text-align:center;">Erro</div>';return;}
            total=j.data.total; const items=j.data.items||[], cn=j.data.counts||{};
            document.querySelectorAll('.ojf-chip').forEach(b=>{const f=b.dataset.filter;b.querySelector('.cnt').textContent='('+(cn[f]??'—')+')';b.style.background=f===filter?C.text:C.border;b.style.color=f===filter?'#000':C.text;});
            document.getElementById('ojf-result').textContent=(total?(offset+1):0)+'–'+(offset+items.length)+' de '+total;
            document.getElementById('ojf-prev').disabled=offset===0;
            document.getElementById('ojf-next').disabled=offset+items.length>=total;
            g.innerHTML=items.length?items.map(card).join(''):'<div style="grid-column:1/-1;text-align:center;color:'+C.muted+';padding:40px;">Nenhuma imagem</div>';
        }
        document.getElementById('ojf-grid').addEventListener('click',e=>{
            const one=e.target.closest('.ojf-one'); if(one){migrate([parseInt(one.dataset.id,10)]);return;}
            const card=e.target.closest('.ojf-card'); if(!card)return;
            const id=parseInt(card.dataset.id,10), cb=card.querySelector('.ojf-cb');
            const on=!cb.checked; cb.checked=on;
            if(on){sel.add(id);card.style.borderColor=C.text;}else{sel.delete(id);card.style.borderColor=C.border;}
            updSel();
        });
        document.getElementById('ojf-all').addEventListener('change',e=>{
            document.querySelectorAll('.ojf-card').forEach(card=>{const id=parseInt(card.dataset.id,10),cb=card.querySelector('.ojf-cb');
                if(e.target.checked){sel.add(id);cb.checked=true;card.style.borderColor=C.text;}else{sel.delete(id);cb.checked=false;card.style.borderColor=C.border;}});
            updSel();
        });
        document.querySelectorAll('.ojf-chip').forEach(b=>b.addEventListener('click',()=>{filter=b.dataset.filter;offset=0;load();}));
        let st; document.getElementById('ojf-search').addEventListener('input',e=>{clearTimeout(st);st=setTimeout(()=>{search=e.target.value;offset=0;load();},400);});
        document.getElementById('ojf-prev').addEventListener('click',()=>{offset=Math.max(0,offset-limit);load();});
        document.getElementById('ojf-next').addEventListener('click',()=>{offset+=limit;load();});
        document.getElementById('ojf-migrate').addEventListener('click',()=>{if(sel.size)migrate(Array.from(sel));});

        async function migrate(ids){
            if(!ids.length)return;
            if(!confirm('Migrar '+ids.length+' imagem(ns) pro R2? O arquivo local é apagado após o R2 confirmar (conforme config).'))return;
            const maxw=parseInt(document.getElementById('ojf-maxw').value,10)||<?php echo (int) OJF_MEDIA_DEFAULT_MAXW; ?>;
            const prog=document.getElementById('ojf-prog'); prog.style.display='block';
            document.getElementById('ojf-prog-bar').style.width='30%';
            document.getElementById('ojf-prog-msg').textContent='Migrando '+ids.length+'…';
            const j=await post('ojf_media_migrate',{ids:JSON.stringify(ids),maxw});
            document.getElementById('ojf-prog-bar').style.width='100%';
            if(j.success){document.getElementById('ojf-prog-msg').textContent='✓ '+j.data.migrated+' migrado(s) · '+j.data.deleted+' local apagado(s)'+(j.data.skipped?(' · '+j.data.skipped+' pulado(s)'):'');document.getElementById('ojf-prog-cnt').textContent='';}
            else document.getElementById('ojf-prog-msg').textContent='Erro';
            sel.clear(); updSel();
            setTimeout(()=>{prog.style.display='none';document.getElementById('ojf-prog-bar').style.width='0';},2500);
            load();
        }
        load();
    })();
    </script>
    <?php
}

/* ── render: UPLOAD /assets/ ──────────────────────────────────────────────── */
function ojf_media_render_upload_page() {
    if (!current_user_can('upload_files')) wp_die('Sem permissão');
    $c = ojf_media_css_vars();
    $nonce = wp_create_nonce('ojf_media_upload');
    $r2_ok = function_exists('ojf_r2_upload');
    $last  = isset($_GET['cdn']) ? esc_url_raw(rawurldecode((string) $_GET['cdn'])) : '';
    $err   = isset($_GET['err']) ? sanitize_text_field(rawurldecode((string) $_GET['err'])) : '';
    ?>
    <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:<?php echo $c['bg']; ?>;color:<?php echo $c['text']; ?>;padding:36px 24px;margin-left:-20px;min-height:100vh;">
    <div style="max-width:720px;margin:0 auto;">
        <h1 style="font-size:30px;font-weight:700;margin:0 0 4px;">Upload R2 <span style="background:<?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;font-size:11px;padding:3px 10px;border-radius:980px;vertical-align:middle;">/assets/</span></h1>
        <p style="color:<?php echo $c['muted']; ?>;font-size:14px;line-height:1.6;">Upload de imagem do admin (banner, logo, blog) direto pro R2 na pasta <code>/assets/</code>, com largura máx. escolhida POR upload. Otimiza WebP, sobe e apaga o local. Não toca em <code>/products/</code>.</p>
        <?php if (!$r2_ok): ?><div class="notice notice-error"><p><code>ojf_r2_upload()</code> indisponível.</p></div><?php endif; ?>
        <?php if ($last): ?>
            <div style="background:<?php echo $c['panel']; ?>;border:1px solid <?php echo $c['border2']; ?>;border-radius:10px;padding:14px;margin:14px 0;">
                <strong>&#9679; Upload concluído.</strong>
                <p style="margin:8px 0 0;"><input type="text" readonly value="<?php echo esc_attr($last); ?>" onclick="this.select()" style="width:100%;font-family:monospace;font-size:12px;background:<?php echo $c['inset']; ?>;border:1px solid <?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;padding:8px;border-radius:6px;"></p>
            </div>
        <?php elseif ($err): ?><div class="notice notice-error"><p><strong>Erro:</strong> <?php echo esc_html($err); ?></p></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:<?php echo $c['panel']; ?>;border:1px solid <?php echo $c['border']; ?>;border-radius:12px;padding:22px;margin-top:16px;">
            <input type="hidden" name="action" value="ojf_media_assets_upload">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <p style="margin:0 0 16px;"><label style="display:block;font-weight:600;margin-bottom:6px;">Arquivo</label>
                <input type="file" name="file" accept="image/*" required style="width:100%;color:<?php echo $c['text']; ?>;"></p>
            <p style="margin:0 0 20px;"><label style="display:block;font-weight:600;margin-bottom:6px;">Largura máxima (px) — por upload</label>
                <input type="number" name="maxw" value="<?php echo (int) OJF_MEDIA_DEFAULT_MAXW; ?>" min="50" max="8000" step="50" style="width:130px;background:<?php echo $c['inset']; ?>;border:1px solid <?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;padding:8px 10px;border-radius:8px;">
                <span style="color:<?php echo $c['muted']; ?>;font-size:12px;margin-left:8px;">redimensiona só se maior</span></p>
            <button type="submit" style="background:<?php echo $c['text']; ?>;color:#000;border:none;padding:11px 24px;border-radius:980px;cursor:pointer;font-weight:600;font-size:14px;">Enviar pro R2 /assets/</button>
        </form>
    </div></div>
    <?php
}

add_action('admin_post_ojf_media_assets_upload', function () {
    if (!current_user_can('upload_files')) wp_die('Sem permissão');
    if (!check_admin_referer('ojf_media_upload')) wp_die('Nonce inválido');
    $page = admin_url('admin.php?page=ojf-media-r2-upload');
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('Nenhum arquivo enviado'), $page)); exit;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $maxw = isset($_POST['maxw']) ? (int) $_POST['maxw'] : (int) OJF_MEDIA_DEFAULT_MAXW;
    @set_time_limit(180);

    $up = wp_handle_upload($_FILES['file'], ['test_form' => false]);
    if (!is_array($up) || empty($up['file']) || !empty($up['error'])) {
        wp_safe_redirect(add_query_arg('err', rawurlencode(is_array($up) && !empty($up['error']) ? $up['error'] : 'wp_handle_upload falhou'), $page)); exit;
    }
    $att = wp_insert_attachment([
        'post_mime_type' => $up['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($up['file'])),
        'post_status'    => 'inherit',
    ], $up['file']);
    if (is_wp_error($att) || !$att) {
        wp_safe_redirect(add_query_arg('err', rawurlencode('wp_insert_attachment falhou'), $page)); exit;
    }
    wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $up['file']));

    // add_attachment já pode ter migrado (se auto ligado). Garante migração + medida escolhida.
    if (!ojf_media_already_on_r2($att)) {
        $r = ojf_media_upload_direct($att, $maxw, true);
        if (empty($r['ok'])) { wp_safe_redirect(add_query_arg('err', rawurlencode('R2 falhou: ' . ($r['reason'] ?? '?')), $page)); exit; }
        $cdn = $r['cdn_url'];
    } else {
        $cdn = (string) get_post_meta($att, '_ojf_cdn_url', true);
    }
    wp_safe_redirect(add_query_arg('cdn', rawurlencode($cdn), $page)); exit;
});
