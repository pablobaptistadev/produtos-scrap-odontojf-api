<?php
/**
 * OdontoJF Woo Bridge — vídeos do produto (REPEATER + shortcode).
 *
 * O WooCommerce filtra a description com kses → <iframe> é REMOVIDO. Por isso o
 * vídeo NÃO vai no HTML salvo. Em vez disso:
 *   - As URLs ficam num custom field _odontojf_video_urls (array JSON).
 *   - O metabox mostra um REPEATER: cada vídeo com campo URL + embed pra assistir
 *     ali mesmo; botões adicionar/remover; salva via AJAX (sem recarregar).
 *   - O shortcode [ojf_video] renderiza TODOS os vídeos, um abaixo do outro.
 *
 * Compat: _odontojf_video_url (single) continua sendo o 1º vídeo.
 */

if (!defined('ABSPATH')) exit;

if (!defined('OJF_VIDEO_META'))  define('OJF_VIDEO_META',  '_odontojf_video_url');   // 1º (compat)
if (!defined('OJF_VIDEOS_META')) define('OJF_VIDEOS_META', '_odontojf_video_urls');  // array JSON

/** Extrai o ID de 11 chars de uma URL de YouTube (watch, embed, youtu.be, shorts). */
function ojf_youtube_id($url) {
    $url = (string) $url;
    if ($url === '') return '';
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m)) {
        return $m[1];
    }
    $q = wp_parse_url($url, PHP_URL_QUERY);
    if ($q) { parse_str($q, $p); if (!empty($p['v']) && preg_match('~^[A-Za-z0-9_-]{11}$~', $p['v'])) return $p['v']; }
    return '';
}

/** Lista de vídeos do produto (array de URLs limpas). */
function ojf_get_product_videos($product_id) {
    $pid = (int) $product_id;
    if (!$pid) return [];
    $raw  = get_post_meta($pid, OJF_VIDEOS_META, true);
    $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : []);
    if (!is_array($list)) $list = [];
    if (empty($list)) { $one = get_post_meta($pid, OJF_VIDEO_META, true); if ($one) $list = [$one]; }
    return array_values(array_filter(array_map(function ($u) { return trim((string) $u); }, $list)));
}

/** Salva a lista (dedup + valida YouTube) e mantém o single (1º) p/ compat. */
function ojf_set_product_videos($product_id, $urls) {
    $pid = (int) $product_id;
    $clean = [];
    foreach ((array) $urls as $u) {
        $u = esc_url_raw(trim((string) $u));
        if ($u !== '' && ojf_youtube_id($u) !== '' && !in_array($u, $clean, true)) $clean[] = $u;
    }
    if ($clean) {
        update_post_meta($pid, OJF_VIDEOS_META, wp_json_encode($clean));
        update_post_meta($pid, OJF_VIDEO_META, $clean[0]);
    } else {
        delete_post_meta($pid, OJF_VIDEOS_META);
        delete_post_meta($pid, OJF_VIDEO_META);
    }
    return $clean;
}

/** Embed responsivo de um YouTube. '' se a URL não for válida.
 *  Usa aspect-ratio no PRÓPRIO iframe com !important (alguns temas/Elementor
 *  forçam height no iframe e deixavam quadrado). width/height = fallback 16:9. */
function ojf_video_embed_html($url, $ratio = '16x9') {
    $id = ojf_youtube_id($url);
    if ($id === '') return '';
    $ar = ($ratio === '4x3') ? '4 / 3' : (($ratio === '1x1') ? '1 / 1' : '16 / 9');
    $hh = ($ratio === '4x3') ? 540 : (($ratio === '1x1') ? 720 : 405);
    $src = 'https://www.youtube.com/embed/' . esc_attr($id) . '?rel=0&modestbranding=1';
    return '<iframe class="ojf-video" width="720" height="' . $hh . '" src="' . $src . '" '
        . 'title="Vídeo do produto" frameborder="0" '
        . 'allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" '
        . 'allowfullscreen loading="lazy" '
        . 'style="display:block !important;width:100% !important;max-width:720px !important;height:auto !important;'
        . 'aspect-ratio:' . $ar . ' !important;margin:16px auto !important;border:0 !important;border-radius:12px;"></iframe>';
}

/** Shortcode [ojf_video] — renderiza TODOS os vídeos, um abaixo do outro. */
add_shortcode('ojf_video', function ($atts) {
    $atts = shortcode_atts(['id' => 0, 'ratio' => '16x9'], $atts, 'ojf_video');
    $pid  = (int) $atts['id'] ?: (int) get_the_ID();
    if (!$pid) return '';
    $videos = ojf_get_product_videos($pid);
    if (empty($videos)) return '';
    $out = '<div class="ojf-videos">';
    foreach ($videos as $u) $out .= ojf_video_embed_html($u, $atts['ratio']);
    return $out . '</div>';
});

/** Condicional p/ tema/Elementor. */
function ojf_product_has_video($product_id = 0) {
    $pid = (int) $product_id ?: (int) get_the_ID();
    return $pid ? !empty(ojf_get_product_videos($pid)) : false;
}

/* ── Metabox REPEATER (URL + embed + add/remove + salvar via AJAX) ───────────── */
add_action('add_meta_boxes', function () {
    add_meta_box('ojf_video_box', 'Vídeos do produto', 'ojf_render_video_metabox', 'product', 'normal', 'high');
});

/** HTML de UMA linha do repeater. */
function ojf_video_row_html($url = '') {
    $id    = $url ? ojf_youtube_id($url) : '';
    $embed = $id ? 'https://www.youtube.com/embed/' . esc_attr($id) . '?rel=0&modestbranding=1' : '';
    ob_start(); ?>
    <div class="ojf-vrow" style="display:flex;gap:12px;align-items:flex-start;margin-bottom:12px;padding:12px;border:1px solid #dcdcde;border-radius:10px;background:#fff;">
        <div style="flex:0 0 280px;">
            <div class="ojf-vembed" style="position:relative;width:280px;padding-bottom:157px;height:0;overflow:hidden;border-radius:8px;background:#000;<?php echo $embed ? '' : 'display:none;'; ?>">
                <iframe class="ojf-viframe" src="<?php echo $embed; ?>" frameborder="0" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"></iframe>
            </div>
            <div class="ojf-vplaceholder" style="width:280px;height:157px;background:#f0f0f1;border-radius:8px;display:<?php echo $embed ? 'none' : 'flex'; ?>;align-items:center;justify-content:center;color:#a7aaad;font-size:13px;">cole a URL pra ver o vídeo</div>
        </div>
        <div style="flex:1;">
            <label style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.4px;">URL do YouTube</label>
            <input type="url" class="ojf-vurl widefat" value="<?php echo esc_attr($url); ?>" placeholder="https://www.youtube.com/watch?v=..." style="margin-top:4px;">
            <p style="margin:8px 0 0;">
                <a class="ojf-vlink" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" style="font-size:12px;<?php echo $url ? '' : 'display:none;'; ?>">▶ abrir no YouTube</a>
                <button type="button" class="button-link ojf-vremove" style="color:#b32d2e;font-size:12px;margin-left:12px;">✕ remover</button>
            </p>
        </div>
    </div>
    <?php return ob_get_clean();
}

function ojf_render_video_metabox($post) {
    $videos = ojf_get_product_videos($post->ID);
    if (empty($videos)) $videos = [''];
    wp_nonce_field('ojf_video_save', 'ojf_video_nonce');
    $ajax = admin_url('admin-ajax.php');
    ?>
    <div id="ojf-vwrap" data-pid="<?php echo (int) $post->ID; ?>" data-ajax="<?php echo esc_attr($ajax); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('ojf_video_save')); ?>">
        <p style="color:#646970;font-size:12px;margin:0 0 10px;">Cole a URL do YouTube → o vídeo aparece na hora. Adicione quantos quiser (aparecem um abaixo do outro na loja via <code>[ojf_video]</code>). Salve aqui mesmo.</p>
        <div id="ojf-vlist">
            <?php foreach ($videos as $u) echo ojf_video_row_html($u); ?>
        </div>
        <p style="margin:12px 0 0;">
            <button type="button" class="button" id="ojf-vadd">➕ Adicionar vídeo</button>
            <button type="button" class="button button-primary" id="ojf-vsave">💾 Salvar vídeos</button>
            <span id="ojf-vmsg" style="margin-left:10px;font-size:13px;"></span>
        </p>
    </div>
    <script>
    (function(){
        var wrap = document.getElementById('ojf-vwrap');
        if (!wrap) return;
        var list = document.getElementById('ojf-vlist');
        var msg  = document.getElementById('ojf-vmsg');

        function ytId(u){
            if(!u) return '';
            var m = u.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i);
            if(m) return m[1];
            try{ var q=new URL(u).searchParams.get('v'); if(q && /^[A-Za-z0-9_-]{11}$/.test(q)) return q; }catch(e){}
            return '';
        }
        function refreshRow(row){
            var url = row.querySelector('.ojf-vurl').value.trim();
            var id  = ytId(url);
            var embed = row.querySelector('.ojf-vembed');
            var ph    = row.querySelector('.ojf-vplaceholder');
            var iframe= row.querySelector('.ojf-viframe');
            var link  = row.querySelector('.ojf-vlink');
            if(id){
                iframe.src = 'https://www.youtube.com/embed/'+id+'?rel=0&modestbranding=1';
                embed.style.display=''; ph.style.display='none';
                link.href=url; link.style.display='';
            } else {
                iframe.src=''; embed.style.display='none'; ph.style.display='flex';
                link.style.display='none';
            }
        }
        function bindRow(row){
            var inp = row.querySelector('.ojf-vurl');
            inp.addEventListener('input', function(){ refreshRow(row); });
            inp.addEventListener('change', function(){ refreshRow(row); });
            row.querySelector('.ojf-vremove').addEventListener('click', function(){
                row.remove();
                if(!list.querySelector('.ojf-vrow')) addRow('');
            });
        }
        var ROW_HTML = <?php echo wp_json_encode(ojf_video_row_html('')); ?>;
        function addRow(url){
            var tmp=document.createElement('div'); tmp.innerHTML=ROW_HTML.trim();
            var row=tmp.firstChild;
            list.appendChild(row); bindRow(row);
            if(url){ row.querySelector('.ojf-vurl').value=url; refreshRow(row); }
            return row;
        }
        list.querySelectorAll('.ojf-vrow').forEach(bindRow);
        document.getElementById('ojf-vadd').addEventListener('click', function(){ addRow('').querySelector('.ojf-vurl').focus(); });

        document.getElementById('ojf-vsave').addEventListener('click', function(){
            var urls=[];
            list.querySelectorAll('.ojf-vurl').forEach(function(i){ var v=i.value.trim(); if(v) urls.push(v); });
            msg.style.color='#646970'; msg.textContent='Salvando...';
            var body=new URLSearchParams();
            body.append('action','ojf_save_videos');
            body.append('nonce', wrap.dataset.nonce);
            body.append('pid', wrap.dataset.pid);
            urls.forEach(function(u){ body.append('urls[]', u); });
            fetch(wrap.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
              .then(function(r){return r.json();})
              .then(function(j){
                if(j && j.success){ msg.style.color='#1a7f37'; msg.textContent='✓ '+(j.data.count||0)+' vídeo(s) salvo(s)'; }
                else { msg.style.color='#b32d2e'; msg.textContent='Erro: '+((j&&j.data)||'falhou'); }
              })
              .catch(function(e){ msg.style.color='#b32d2e'; msg.textContent='Erro: '+e.message; });
        });
    })();
    </script>
    <?php
}

/** AJAX: salva a lista de vídeos do produto. */
add_action('wp_ajax_ojf_save_videos', function () {
    check_ajax_referer('ojf_video_save', 'nonce');
    $pid = isset($_POST['pid']) ? (int) $_POST['pid'] : 0;
    if (!$pid || !current_user_can('edit_post', $pid)) wp_send_json_error('sem permissão');
    $urls  = isset($_POST['urls']) && is_array($_POST['urls']) ? wp_unslash($_POST['urls']) : [];
    $saved = ojf_set_product_videos($pid, $urls);
    wp_send_json_success(['videos' => $saved, 'count' => count($saved)]);
});
