<?php
/**
 * OdontoJF Woo Bridge — cliente ERP (Space Informática).
 *
 * Autenticação: POST /autenticacao/entrar {login, senha, filialCodigo} → {token}.
 * Cada chamada de produto leva `Authorization: SPACE <token>` (esquema custom).
 * O token EXPIRA — então cacheamos por ~25min e renovamos automaticamente via
 * novo login sempre que (a) o cache vence, ou (b) o ERP responde 401. Mesma
 * lógica do Worker (erp/client.ts).
 *
 * O PHP do WordPress alcança a porta 8082 direto (sem a restrição de portas do
 * Cloudflare Workers), então usamos wp_remote_* normalmente.
 */

if (!defined('ABSPATH')) exit;

function ojf_erp_base() { return rtrim(OJF_ERP_BASE_URL, '/'); }

/** Token ERP (cacheado). $force=true ignora o cache e faz login de novo. */
function ojf_erp_token($force = false) {
    if (!$force) {
        $t = get_transient('ojf_erp_token');
        if ($t) return $t;
    }
    $resp = wp_remote_post(ojf_erp_base() . '/autenticacao/entrar', [
        'timeout' => 12,
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => wp_json_encode([
            'login'        => OJF_ERP_LOGIN,
            'senha'        => OJF_ERP_SENHA,
            'filialCodigo' => OJF_ERP_FILIAL,
        ]),
    ]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) >= 300) {
        error_log('[OJF ERP] login falhou: ' . (is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp)));
        return null;
    }
    $data  = json_decode(wp_remote_retrieve_body($resp), true);
    $token = is_array($data) ? ($data['token'] ?? null) : null;
    if ($token) {
        // TTL menor que a expiração real do ERP — renova com folga.
        set_transient('ojf_erp_token', $token, 25 * MINUTE_IN_SECONDS);
    }
    return $token;
}

/**
 * Desembrulha o nó do produto da resposta do ERP. O Space ERP envelopa em
 * { tipo, status, sucesso, produtos: [ { codigo, descricao, preco, ... } ] }.
 */
function ojf_erp_unwrap($data) {
    if (!is_array($data)) return null;
    if (isset($data['produtos']) && is_array($data['produtos']) && !empty($data['produtos'])) return $data['produtos'][0];
    if (isset($data['produto']) && is_array($data['produto'])) return $data['produto'];
    if (isset($data['Produto']) && is_array($data['Produto'])) return $data['Produto'];
    return $data; // já desembrulhado
}

/** GET /produto/{codigo} com renovação de token em 401. Retorna o NÓ do produto. */
function ojf_erp_get_product($codigo) {
    $codigo = trim((string) $codigo);
    if ($codigo === '') return null;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = ojf_erp_token($attempt > 0);
        if (!$token) return null;

        $resp = wp_remote_get(ojf_erp_base() . '/produto/' . rawurlencode($codigo), [
            'timeout' => 12,
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'SPACE ' . $token],
        ]);
        if (is_wp_error($resp)) {
            error_log('[OJF ERP] produto ' . $codigo . ' erro: ' . $resp->get_error_message());
            return null;
        }
        $code = wp_remote_retrieve_response_code($resp);

        // 401 → token expirou: limpa o cache e tenta UMA vez com login novo.
        if ($code === 401 && $attempt === 0) {
            delete_transient('ojf_erp_token');
            continue;
        }
        if ($code >= 300) return null;

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return ojf_erp_unwrap($data);
    }
    return null;
}

/**
 * Preço + estoque do ERP para um código, com cache curto (60s) para não
 * martelar a cada recálculo do carrinho. $fresh=true ignora o cache (usado no
 * momento exato do add-to-cart).
 */
function ojf_erp_price_stock($codigo, $fresh = false) {
    $codigo = trim((string) $codigo);
    if ($codigo === '') return ['price' => null, 'stock' => null];
    $ck = 'ojf_erp_ps_' . md5($codigo);
    if ($fresh) delete_transient($ck);
    else {
        $c = get_transient($ck);
        if ($c !== false) return $c;
    }
    $p = ojf_erp_get_product($codigo);
    $out = ['price' => null, 'stock' => null];
    if (is_array($p)) {
        // campos do Space ERP: preco (ou precoPromocional > 0), estoque.
        $preco = null;
        if (isset($p['precoPromocional']) && (float) $p['precoPromocional'] > 0) $preco = (float) $p['precoPromocional'];
        elseif (isset($p['preco'])) $preco = (float) $p['preco'];
        $out['price'] = $preco;
        if (isset($p['estoque'])) $out['stock'] = (int) $p['estoque'];
    }
    set_transient($ck, $out, 60);
    return $out;
}
