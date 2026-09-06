<?php
/**
 * OdontoJF Woo Bridge — Bearer auth against a SINGLE shared secret.
 *
 * Unlike the multi-seller reference (which looked the key up in a sellers_meta
 * table), this is single-tenant: one shared secret stored as the WP option
 * `ojf_api_secret` (with a wp-config constant fallback). Constant-time compare.
 */

if (!defined('ABSPATH')) exit;

function ojf_get_api_secret() {
    $opt = get_option('ojf_api_secret', '');
    if ($opt) return (string) $opt;
    if (defined('OJF_API_SECRET_FALLBACK') && OJF_API_SECRET_FALLBACK !== '') return (string) OJF_API_SECRET_FALLBACK;
    return '';
}

/** Extract the Bearer token from the request Authorization header. */
function ojf_extract_bearer($request = null) {
    $auth = '';
    if ($request instanceof WP_REST_Request) {
        $auth = $request->get_header('authorization');
    }
    if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!$auth && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') { $auth = $v; break; }
        }
    }
    $auth = trim((string) $auth);
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return $auth; // tolerate a bare token
}

/** Returns true if the request carries the correct shared secret. */
function ojf_validate_bearer($request = null) {
    $secret = ojf_get_api_secret();
    if ($secret === '') return false; // not configured → deny
    $token = ojf_extract_bearer($request);
    if ($token === '') return false;
    return hash_equals($secret, $token);
}

/** Permission callback usable on REST routes. */
function ojf_rest_permission($request) {
    if (ojf_validate_bearer($request)) return true;
    // Admins can hit read-only ops (queue-status) from the dashboard context.
    if (current_user_can('manage_woocommerce')) return true;
    return new WP_Error('ojf_unauthorized', 'Bearer inválido ou ausente', ['status' => 401]);
}
