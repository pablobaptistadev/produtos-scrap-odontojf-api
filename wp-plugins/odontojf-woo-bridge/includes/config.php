<?php
/**
 * OdontoJF Woo Bridge — config.
 *
 * TODA a configuração (Bearer, R2, CDN) é gerenciada numa ÚNICA página no
 * admin: "OdontoJF Bridge → Configurações". Os valores ficam em options do
 * WordPress e são lidos aqui, virando as constantes que o código portado usa.
 *
 * Prioridade de cada valor:  wp-config (define)  >  option (admin)  >  default.
 * Ou seja: NÃO precisa mexer em wp-config — basta a página de Configurações.
 * (Se algum define existir no wp-config, ele "trava" o valor e a página avisa.)
 */

if (!defined('ABSPATH')) exit;

// REST namespace.
if (!defined('OJF_NAMESPACE')) define('OJF_NAMESPACE', 'odontojf/v1');

// Política de status: honra publish/draft do payload (ERP `ativo`). false = sempre draft.
if (!defined('OJF_HONOR_STATUS')) define('OJF_HONOR_STATUS', true);

// Fallback de boot p/ o segredo Bearer (a fonte real é a option ojf_api_secret).
if (!defined('OJF_API_SECRET_FALLBACK')) define('OJF_API_SECRET_FALLBACK', '');

/**
 * R2 / CDN — definidos a partir das options (página de Configurações), com
 * defaults conhecidos para já funcionar "out of the box" (só falta o usuário
 * preencher o Secret Key e o Bearer). if(!defined) preserva override via
 * wp-config se o usuário insistir.
 */
if (!defined('OJF_R2_ACCOUNT_ID')) define('OJF_R2_ACCOUNT_ID', get_option('ojf_r2_account_id', 'ce2d7a097ff927538d0a6d8da21c1d4d'));
if (!defined('OJF_R2_ACCESS_KEY')) define('OJF_R2_ACCESS_KEY', get_option('ojf_r2_access_key', '8f290cbe293b3e8619d23a8ee022e5dd'));
if (!defined('OJF_R2_SECRET_KEY')) define('OJF_R2_SECRET_KEY', get_option('ojf_r2_secret_key', ''));
if (!defined('OJF_R2_BUCKET'))     define('OJF_R2_BUCKET',     get_option('ojf_r2_bucket', 'odonto-loja'));
if (!defined('OJF_R2_ENDPOINT'))   define('OJF_R2_ENDPOINT',   'https://' . OJF_R2_ACCOUNT_ID . '.r2.cloudflarestorage.com');
if (!defined('OJF_CDN_BASE_URL'))  define('OJF_CDN_BASE_URL',  get_option('ojf_cdn_base_url', 'https://arquivos.dentalodontocirurgica.com.br'));

/**
 * ERP (Space Informática) — usado para consultar preço/estoque em tempo real
 * no carrinho. Token renovado automaticamente via login (POST /autenticacao/entrar).
 */
if (!defined('OJF_ERP_BASE_URL')) define('OJF_ERP_BASE_URL', get_option('ojf_erp_base_url', 'http://45.227.82.180:8082/ecommerceapi/v1'));
if (!defined('OJF_ERP_LOGIN'))    define('OJF_ERP_LOGIN',    get_option('ojf_erp_login', 'apiecommerce2'));
if (!defined('OJF_ERP_SENHA'))    define('OJF_ERP_SENHA',    get_option('ojf_erp_senha', 'api123'));
if (!defined('OJF_ERP_FILIAL'))   define('OJF_ERP_FILIAL',   (int) get_option('ojf_erp_filial', 1));
// Liga/desliga a consulta ERP no carrinho.
if (!defined('OJF_ERP_CART_LIVE')) define('OJF_ERP_CART_LIVE', get_option('ojf_erp_cart_live', '1') === '1');
