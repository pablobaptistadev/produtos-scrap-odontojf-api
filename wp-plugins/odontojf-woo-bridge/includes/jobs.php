<?php
/**
 * Lotes em segundo plano sem WP-Cron (>= 1.0.80).
 *
 * Nesta loja o WP-Cron está desligado e quase toda página sai do cache sem
 * tocar o PHP. O que funcionou nos lotes de título e de categorias: cada passo
 * termina disparando o próximo por um admin-ajax interno não bloqueante, com
 * uma pausa entre passos, e qualquer requisição que chegue ao PHP religa a
 * corrente se ela tiver morrido. Aqui isso vira um registro reutilizável.
 *
 *   ojf_job_register('nome', $pendente, $passo, $pausa_s);
 *     $pendente(): bool  — ainda há trabalho?
 *     $passo(): void     — faz um pedaço e guarda o próprio cursor
 */

if (!defined('ABSPATH')) exit;

$GLOBALS['ojf_jobs'] = $GLOBALS['ojf_jobs'] ?? [];

function ojf_job_register($key, callable $pending, callable $step, $pause = 5) {
    $GLOBALS['ojf_jobs'][$key] = ['pending' => $pending, 'step' => $step, 'pause' => (int) $pause];
    $handler = function () use ($key) { ojf_job_tick($key); };
    add_action('wp_ajax_ojf_job_' . $key, $handler);
    add_action('wp_ajax_nopriv_ojf_job_' . $key, $handler);
}

function ojf_job_token($key) {
    return wp_hash('ojf_job_' . $key);
}

/** Dispara o próximo passo. $chain vazio = corrente nova. */
function ojf_job_dispatch($key, $chain = '') {
    $chain = $chain !== '' ? $chain : strtolower(wp_generate_password(12, false));
    set_transient('ojf_job_chain_' . $key, $chain, 3 * MINUTE_IN_SECONDS);
    $r = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout' => 0.01, 'blocking' => false, 'sslverify' => false,
        'body' => ['action' => 'ojf_job_' . $key, 'token' => ojf_job_token($key), 'chain' => $chain],
    ]);
    if (is_wp_error($r)) {
        delete_transient('ojf_job_chain_' . $key);
        error_log('[ojf] lote ' . $key . ': falha ao disparar: ' . $r->get_error_message());
    }
}

/** Pede para o lote rodar logo (ex.: um estoque acabou de voltar). */
function ojf_job_kick($key) {
    if (get_transient('ojf_job_chain_' . $key)) return;
    add_action('shutdown', function () use ($key) {
        if (!get_transient('ojf_job_chain_' . $key)) ojf_job_dispatch($key);
    }, 99);
}

function ojf_job_tick($key) {
    $job = $GLOBALS['ojf_jobs'][$key] ?? null;
    if (!$job) exit;
    if (!hash_equals(ojf_job_token($key), (string) ($_POST['token'] ?? ''))) { status_header(403); exit; }
    $chain = sanitize_key((string) ($_POST['chain'] ?? ''));
    if ($chain === '' || get_transient('ojf_job_chain_' . $key) !== $chain) exit;

    ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(180);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    set_transient('ojf_job_chain_' . $key, $chain, 3 * MINUTE_IN_SECONDS);
    if ($job['pause'] > 0) sleep($job['pause']);
    if (get_transient('ojf_job_chain_' . $key) !== $chain) exit;

    if (!get_transient('ojf_job_lock_' . $key)) {
        set_transient('ojf_job_lock_' . $key, 1, 5 * MINUTE_IN_SECONDS);
        try {
            call_user_func($job['step']);
        } catch (\Throwable $e) {
            error_log('[ojf] lote ' . $key . ': ' . get_class($e) . ': ' . $e->getMessage());
        } finally {
            delete_transient('ojf_job_lock_' . $key);
        }
    }

    if (call_user_func($job['pending'])) ojf_job_dispatch($key, $chain);
    else delete_transient('ojf_job_chain_' . $key);
    exit;
}

/* religa correntes mortas: no máximo uma tentativa por minuto por lote */
add_action('init', function () {
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return;
    $acao = (string) ($_REQUEST['action'] ?? '');
    if (wp_doing_ajax() && strpos($acao, 'ojf_job_') === 0) return;
    foreach ($GLOBALS['ojf_jobs'] as $key => $job) {
        if (get_transient('ojf_job_chain_' . $key) || get_transient('ojf_job_kick_' . $key)) continue;
        if (!call_user_func($job['pending'])) continue;
        set_transient('ojf_job_kick_' . $key, 1, 60);
        add_action('shutdown', function () use ($key) { ojf_job_dispatch($key); }, 99);
    }
}, 35);
