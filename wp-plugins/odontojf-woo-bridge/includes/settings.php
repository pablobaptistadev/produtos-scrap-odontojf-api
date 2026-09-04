<?php
/**
 * OdontoJF Woo Bridge — página ÚNICA de configurações (todas as chaves).
 *
 * Bearer + R2 (account / access key / secret / bucket / CDN) num lugar só.
 * Salva tudo em options. Nada de wp-config.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'OdontoJF Bridge', 'OdontoJF Bridge', 'manage_woocommerce',
        'ojf-bridge-settings', 'ojf_settings_page', 'dashicons-rest-api', 56
    );
    add_submenu_page('ojf-bridge-settings', 'Configurações', 'Configurações', 'manage_woocommerce', 'ojf-bridge-settings', 'ojf_settings_page');
}, 9);

/** Campos: option_key => [label, tipo, default, descrição] */
function ojf_settings_fields() {
    return [
        'ojf_api_secret'     => ['Bearer secret (API)', 'text',     '', 'Deve ser IGUAL ao WOO_PLUGIN_API_KEY do Worker. É a chave que autentica o Worker → este plugin.'],
        'ojf_r2_account_id'  => ['R2 Account ID', 'text', 'ce2d7a097ff927538d0a6d8da21c1d4d', 'ID da conta Cloudflare do bucket R2.'],
        'ojf_r2_access_key'  => ['R2 Access Key ID', 'text', '8f290cbe293b3e8619d23a8ee022e5dd', 'Access Key do token S3 do R2.'],
        'ojf_r2_secret_key'  => ['R2 Secret Access Key', 'password', '', 'Secret do token S3 do R2 (fica salvo no banco, oculto).'],
        'ojf_r2_bucket'      => ['R2 Bucket', 'text', 'odonto-loja', 'Nome do bucket das imagens.'],
        'ojf_cdn_base_url'   => ['CDN Base URL', 'text', 'https://arquivos.dentalodontocirurgica.com.br', 'Domínio público das imagens (sem barra no final).'],
        'ojf_erp_base_url'   => ['ERP — Base URL', 'text', 'http://45.227.82.180:8082/ecommerceapi/v1', 'Endpoint da API do ERP (Space).'],
        'ojf_erp_login'      => ['ERP — Login', 'text', 'apiecommerce2', 'Usuário do login do ERP (o token renova sozinho).'],
        'ojf_erp_senha'      => ['ERP — Senha', 'password', 'api123', 'Senha do login do ERP.'],
        'ojf_erp_filial'     => ['ERP — Filial', 'text', '1', 'filialCodigo enviado no login.'],
    ];
}

function ojf_settings_page() {
    if (!current_user_can('manage_woocommerce')) return;
    $fields = ojf_settings_fields();

    // salvar
    if (isset($_POST['ojf_save']) && check_admin_referer('ojf_settings_save')) {
        foreach ($fields as $key => $f) {
            if (!isset($_POST[$key])) continue;
            $val = trim((string) wp_unslash($_POST[$key]));
            if ($key === 'ojf_cdn_base_url') $val = untrailingslashit(esc_url_raw($val));
            else $val = sanitize_text_field($val);
            update_option($key, $val, false);
        }
        echo '<div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div>';
    }

    // gerar bearer
    if (isset($_POST['ojf_gen_secret']) && check_admin_referer('ojf_settings_save')) {
        $new = 'ojf_' . bin2hex(random_bytes(24));
        update_option('ojf_api_secret', $new, false);
        echo '<div class="notice notice-info is-dismissible"><p>Novo Bearer gerado: <code>' . esc_html($new) . '</code> — <strong>copie e cole no Worker</strong> (WOO_PLUGIN_API_KEY).</p></div>';
    }

    // mapa constante → quem está mandando (detecta override de wp-config)
    $const_map = [
        'ojf_api_secret'    => null, // tratado por auth (option direto)
        'ojf_r2_account_id' => 'OJF_R2_ACCOUNT_ID',
        'ojf_r2_access_key' => 'OJF_R2_ACCESS_KEY',
        'ojf_r2_secret_key' => 'OJF_R2_SECRET_KEY',
        'ojf_r2_bucket'     => 'OJF_R2_BUCKET',
        'ojf_cdn_base_url'  => 'OJF_CDN_BASE_URL',
        'ojf_erp_base_url'  => 'OJF_ERP_BASE_URL',
        'ojf_erp_login'     => 'OJF_ERP_LOGIN',
        'ojf_erp_senha'     => 'OJF_ERP_SENHA',
        'ojf_erp_filial'    => 'OJF_ERP_FILIAL',
    ];

    // toggle ERP no carrinho + opções de Mídia R2
    if (isset($_POST['ojf_save']) && check_admin_referer('ojf_settings_save')) {
        update_option('ojf_erp_cart_live', isset($_POST['ojf_erp_cart_live']) ? '1' : '0', false);
        update_option('ojf_media_auto_r2', isset($_POST['ojf_media_auto_r2']) ? '1' : '0', false);
        update_option('ojf_media_delete_local', isset($_POST['ojf_media_delete_local']) ? '1' : '0', false);
        $mw = isset($_POST['ojf_media_auto_maxw']) ? (int) $_POST['ojf_media_auto_maxw'] : 1600;
        update_option('ojf_media_auto_maxw', max(50, min(8000, $mw ?: 1600)), false);
    }

    // testar ERP (login + 1 produto)
    $erp_test_html = '';
    if (isset($_POST['ojf_test_erp']) && check_admin_referer('ojf_settings_save')) {
        $code = trim((string) ($_POST['ojf_test_code'] ?? '230'));
        $tok = ojf_erp_token(true);
        if (!$tok) {
            $erp_test_html = '<div class="notice notice-error"><p>❌ ERP login FALHOU — confira base URL / login / senha / filial (e se o servidor alcança a porta 8082).</p></div>';
        } else {
            $p = ojf_erp_get_product($code);
            if (is_array($p)) {
                $desc = $p['descricao'] ?? ($p['nome'] ?? '?');
                $preco = $p['preco'] ?? '?'; $est = $p['estoque'] ?? '?';
                $erp_test_html = '<div class="notice notice-success"><p>✅ ERP OK — login renovou token e consultou o código <code>' . esc_html($code) . '</code>:<br>'
                    . 'descrição: <strong>' . esc_html((string) $desc) . '</strong> | preço: <strong>' . esc_html((string) $preco) . '</strong> | estoque: <strong>' . esc_html((string) $est) . '</strong></p></div>';
            } else {
                $erp_test_html = '<div class="notice notice-warning"><p>⚠ Login OK, mas o código <code>' . esc_html($code) . '</code> não retornou produto. Tente outro código.</p></div>';
            }
        }
    }

    echo '<div class="wrap"><h1>OdontoJF Bridge — Configurações <small style="color:#888">v' . esc_html(OJF_BRIDGE_VERSION) . '</small></h1>';
    echo '<p>Tudo num lugar só. Salve aqui — <strong>não precisa mexer em wp-config</strong>.</p>';
    echo $erp_test_html;

    echo '<form method="post">';
    wp_nonce_field('ojf_settings_save');
    echo '<table class="form-table" role="presentation">';
    foreach ($fields as $key => $f) {
        list($label, $type, $default, $desc) = $f;
        $val = get_option($key, $default);
        // detecta se uma constante (wp-config) está sobrescrevendo
        $locked = false; $effective = $val;
        $cn = $const_map[$key] ?? null;
        if ($cn && defined($cn) && constant($cn) !== $val && constant($cn) !== '') {
            $locked = true; $effective = constant($cn);
        }
        echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        $masked = ($type === 'password');
        printf(
            '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" style="width:520px" autocomplete="off" %s />',
            $masked ? 'password' : 'text',
            esc_attr($key), esc_attr($key), esc_attr($val), $locked ? 'readonly' : ''
        );
        if ($locked) {
            echo '<p style="color:#b32d2e"><strong>⚠ Travado no wp-config</strong> — valor efetivo: <code>' . esc_html($masked ? '••••••' : $effective) . '</code>. Remova o define do wp-config para gerenciar aqui.</p>';
        }
        echo '<p class="description">' . esc_html($desc) . '</p>';
        echo '</td></tr>';
    }
    // Toggle: consulta ERP ao vivo no carrinho
    $cart_live = get_option('ojf_erp_cart_live', '1') === '1';
    echo '<tr><th scope="row">Carrinho ao vivo (ERP)</th><td>';
    echo '<label><input type="checkbox" name="ojf_erp_cart_live" value="1" ' . checked($cart_live, true, false) . ' /> ';
    echo 'Consultar <strong>preço e estoque no ERP</strong> toda vez que um produto é adicionado ao carrinho</label>';
    echo '<p class="description">Token do ERP é renovado automaticamente via login. Bloqueia o add se faltar estoque e usa o preço atual do ERP.</p></td></tr>';

    // Mídia R2: auto-upload de todo upload do WP
    $m_auto = get_option('ojf_media_auto_r2', '0') === '1';
    $m_del  = get_option('ojf_media_delete_local', '1') === '1';
    $m_maxw = (int) get_option('ojf_media_auto_maxw', 1600);
    echo '<tr><th scope="row">Mídia R2 — auto-upload</th><td>';
    echo '<label><input type="checkbox" name="ojf_media_auto_r2" value="1" ' . checked($m_auto, true, false) . ' /> ';
    echo '<strong>Subir TODO upload do WordPress pro R2</strong> automaticamente (banners, blog, mídia em geral)</label>';
    echo '<p class="description">Anexo de produto vai pra <code>products/{id}/</code>; upload solto vai pra <code>assets/</code>. Imagens de produto já enfileiradas são puladas (a fila de imagens cuida delas).</p>';
    echo '<p style="margin-top:10px;"><label><input type="checkbox" name="ojf_media_delete_local" value="1" ' . checked($m_del, true, false) . ' /> ';
    echo '<strong>Apagar o arquivo local</strong> depois que o R2 confirmar</label> ';
    echo '<span style="color:#888">(desligue para manter cópia no disco do WordPress)</span></p>';
    echo '<p style="margin-top:10px;">Largura máxima padrão do auto-upload: ';
    echo '<input type="number" name="ojf_media_auto_maxw" value="' . esc_attr($m_maxw) . '" min="50" max="8000" step="50" class="small-text" /> px ';
    echo '<span style="color:#888">(redimensiona só se a imagem for maior)</span></p>';
    echo '</td></tr>';
    echo '</table>';

    echo '<p><button name="ojf_save" class="button button-primary button-hero">💾 Salvar configurações</button> ';
    echo '<button name="ojf_gen_secret" class="button" onclick="return confirm(\'Gerar um novo Bearer? Você terá que atualizar o Worker.\')">🔑 Gerar novo Bearer</button></p>';

    echo '<hr><h3>Testar ERP</h3><p>Faz login (renova token) e consulta um código pra confirmar a conexão.</p>';
    echo '<p><input type="text" name="ojf_test_code" value="230" class="small-text" placeholder="código ERP" /> ';
    echo '<button name="ojf_test_erp" class="button">🔌 Testar conexão ERP</button></p>';
    echo '</form>';

    // status / endpoints
    $base = untrailingslashit(get_site_url());
    echo '<hr><h2>Status</h2><table class="widefat" style="max-width:900px"><tbody>';
    echo '<tr><td><strong>Endpoint de criação</strong></td><td><code>' . esc_html($base . '/wp-json/' . OJF_NAMESPACE . '/create-product') . '</code></td></tr>';
    echo '<tr><td><strong>Health</strong></td><td><code>' . esc_html($base . '/wp-json/' . OJF_NAMESPACE . '/health') . '</code></td></tr>';
    echo '<tr><td><strong>R2 endpoint</strong></td><td><code>' . esc_html(defined('OJF_R2_ENDPOINT') ? OJF_R2_ENDPOINT : '-') . '/' . esc_html(get_option('ojf_r2_bucket','odonto-loja')) . '</code></td></tr>';
    echo '<tr><td><strong>Imagens (CDN)</strong></td><td><code>' . esc_html(get_option('ojf_cdn_base_url','-')) . '/products/{id}/{slug}-{attach}.webp</code></td></tr>';
    echo '<tr><td><strong>Bearer configurado?</strong></td><td>' . (ojf_get_api_secret() !== '' ? '<span style="color:#1a7f37">✔ sim</span>' : '<span style="color:#b32d2e">✘ não — preencha acima</span>') . '</td></tr>';
    echo '<tr><td><strong>R2 Secret configurado?</strong></td><td>' . (OJF_R2_SECRET_KEY !== '' ? '<span style="color:#1a7f37">✔ sim</span>' : '<span style="color:#b32d2e">✘ não — preencha acima</span>') . '</td></tr>';
    echo '<tr><td><strong>Carrinho ao vivo (ERP)</strong></td><td>' . (get_option('ojf_erp_cart_live','1')==='1' ? '<span style="color:#1a7f37">✔ ligado</span>' : '<span style="color:#888">desligado</span>') . ' — consulta preço/estoque ao adicionar no carrinho</td></tr>';
    echo '<tr><td><strong>Produtos novos</strong></td><td>publicados automaticamente (status <code>publish</code>)</td></tr>';
    echo '</tbody></table>';
    echo '<p style="margin-top:14px;color:#888">Filas e tempos: veja os menus de dashboard da API Queue e Image Queue.</p>';
    echo '</div>';
}
