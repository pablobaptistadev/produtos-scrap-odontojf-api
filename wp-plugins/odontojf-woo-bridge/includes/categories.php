<?php
/**
 * Categorias = as da loja de origem (dentalodontocirurgicajf), por slug (>= 1.0.74).
 *
 * O que dava errado:
 *  - o Worker mandava a hierarquia do ERP (linha > grupoWeb > grupo > ...) e o
 *    plugin CRIAVA cada nível que não achava no pai: ~2.700 categorias-lixo
 *    ("ODONTOLOGICO > PRODUTO ODONTOLOGICO A > PRODUTO ODONTOLOGICO > VIPI");
 *  - o mesmo nome em outro pai virava um termo novo, e o WordPress sufixava o
 *    slug: "cadeira-odontologica-2" com os produtos, "cadeira-odontologica" vazia.
 *
 * Regra agora: o push só MARCA. Cada categoria do payload é procurada pelo slug
 * da origem, e só vale se estiver dentro da árvore da loja (o termo "Loja",
 * ojf_cat_root). Não achou = ignora. Nenhuma categoria é criada aqui. Se nada
 * do payload casar, o produto fica com as categorias que já tinha.
 *
 * "Solicitar orçamento" na origem (needsBudget) = categoria Orçamento aqui.
 */

if (!defined('ABSPATH')) exit;

function ojf_cat_root_id() {
    return (int) get_option('ojf_cat_root', 91656);
}

function ojf_budget_cat_id() {
    $id = (int) get_option('ojf_budget_cat', 91638);
    return ($id && term_exists($id, 'product_cat')) ? $id : 0;
}

/** O termo está dentro da árvore da loja (descendente do "Loja")? */
function ojf_term_in_store_tree($term_id) {
    $root = ojf_cat_root_id();
    if (!$root) return true;
    return in_array($root, array_map('intval', get_ancestors((int) $term_id, 'product_cat', 'taxonomy')), true);
}

/** Slugs da origem do payload → ids de termos que JÁ existem na árvore da loja. */
function ojf_resolve_payload_categories($cats) {
    $ids = [];
    foreach ((array) $cats as $c) {
        $slug = is_array($c) ? trim((string) ($c['slug'] ?? '')) : '';
        if ($slug === '') continue; // só nome (payload antigo ou do ERP): ignora
        $t = get_term_by('slug', sanitize_title($slug), 'product_cat');
        if ($t && !is_wp_error($t) && ojf_term_in_store_tree($t->term_id)) $ids[] = (int) $t->term_id;
    }
    return array_values(array_unique($ids));
}

/**
 * Categorias que não são da origem e o push nunca tira: as do plugin Listas de
 * Estudantes (a da lista e "Brindes", que a regra de brinde confere) e as fixas
 * da loja ("Lançamentos").
 */
function ojf_term_is_store_owned($term_id) {
    $term_id = (int) $term_id;
    if (in_array($term_id, array_map('intval', (array) get_option('ojf_cat_keep', [91676])), true)) return true;
    $raizes = array_map('intval', (array) get_option('ojf_cat_keep_subtrees', [91693, 91694]));
    if (in_array($term_id, $raizes, true)) return true;
    return (bool) array_intersect($raizes, array_map('intval', get_ancestors($term_id, 'product_cat', 'taxonomy')));
}

/** Aplica no produto: categorias da origem + Orçamento conforme needs_budget. Nunca cria termo. */
function ojf_apply_payload_categories($product, $data) {
    $orc   = ojf_budget_cat_id();
    $atual = array_map('intval', (array) $product->get_category_ids());
    $ids   = ojf_resolve_payload_categories($data['categories'] ?? []);
    $base  = $ids ?: array_values(array_diff($atual, [$orc]));
    foreach ($atual as $t) if ($t !== $orc && ojf_term_is_store_owned($t)) $base[] = $t;
    // onde a listagem da categoria na origem mostra o produto, embora a página
    // dele não declare (gravado pelo lote de categorias): o push não tira
    foreach ((array) get_post_meta($product->get_id(), '_ojf_cat_listing', true) as $t) {
        $t = (int) $t;
        if ($t && $t !== $orc && term_exists($t, 'product_cat') && ojf_term_in_store_tree($t)) $base[] = $t;
    }

    if (array_key_exists('needs_budget', (array) $data) && $data['needs_budget'] !== null) {
        $quer_orc = (bool) $data['needs_budget'];
    } else {
        $quer_orc = in_array($orc, $atual, true); // sem informação: não mexe
    }
    if ($orc && $quer_orc) $base[] = $orc;

    $final = array_values(array_unique(array_map('intval', $base)));
    if ($final && $final !== $atual) $product->set_category_ids($final);
}

/* ── URLs antigas: /produtos/.../cadeira-odontologica-2/ → a categoria certa ─ */

add_action('template_redirect', function () {
    if (!is_404()) return;
    $base = 'produtos';
    if (function_exists('wc_get_permalink_structure')) {
        $ps = wc_get_permalink_structure();
        if (!empty($ps['category_base'])) $base = trim((string) $ps['category_base'], '/');
    }
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if (strpos($path, $base . '/') !== 0) return;
    $last = basename($path);
    $map  = (array) get_option('ojf_catfix_redirects', []);
    $alvo = $map[$last] ?? preg_replace('/-\d+$/', '', $last);
    if ($alvo === '' || $alvo === $last) return;
    $t = get_term_by('slug', $alvo, 'product_cat');
    if (!$t || is_wp_error($t)) return;
    $link = get_term_link($t);
    if (is_wp_error($link)) return;
    wp_safe_redirect($link, 301);
    exit;
}, 1);

/* ── lote de limpeza (roda uma vez por versão do arquivo de dados) ─────────── */

define('OJF_CATFIX_FILE', OJF_BRIDGE_DIR . 'data/category-fix.json');
define('OJF_CATFIX_PAUSE', 5);

function ojf_catfix_data() {
    static $d = null;
    if ($d !== null) return $d;
    $d = [];
    if (is_readable(OJF_CATFIX_FILE)) {
        $j = json_decode((string) file_get_contents(OJF_CATFIX_FILE), true);
        if (is_array($j)) $d = $j;
    }
    return $d;
}

/**
 * Versão e liberação do arquivo de dados, guardadas numa option autoload e
 * relidas só quando o arquivo muda (filemtime). Assim nenhuma requisição comum
 * decodifica os ~100 KB do JSON. Quando o arquivo muda, grava também as opções
 * que o push usa (raiz da árvore, Orçamento, proteções, redirecionamentos).
 */
function ojf_catfix_meta() {
    static $m = null;
    if ($m !== null) return $m;
    $mt = is_readable(OJF_CATFIX_FILE) ? (int) filemtime(OJF_CATFIX_FILE) : 0;
    $m  = get_option('ojf_catfix_meta', []);
    if (!is_array($m) || ($m['mtime'] ?? -1) !== $mt) {
        $d = ojf_catfix_data();
        $m = ['mtime' => $mt, 'version' => (string) ($d['version'] ?? ''), 'allow_delete' => !empty($d['allow_delete'])];
        update_option('ojf_catfix_meta', $m, true);
        if (!empty($d['version'])) {
            if (!empty($d['root'])) update_option('ojf_cat_root', (int) $d['root'], true);
            if (!empty($d['budget_term'])) update_option('ojf_budget_cat', (int) $d['budget_term'], true);
            update_option('ojf_catfix_redirects', (array) ($d['redirect_slugs'] ?? []), true);
            update_option('ojf_cat_keep_subtrees', array_map('intval', (array) ($d['keep_subtrees'] ?? [91693, 91694])), true);
            update_option('ojf_cat_keep', array_values(array_diff(array_map('intval', (array) ($d['keep'] ?? [])), [(int) ($d['root'] ?? 0), (int) ($d['budget_term'] ?? 0)])), true);
        }
    }
    return $m;
}

function ojf_catfix_state() {
    $s = get_option('ojf_catfix_state', []);
    return is_array($s) ? $s : [];
}

function ojf_catfix_save($st) {
    update_option('ojf_catfix_state', $st, false);
}

function ojf_catfix_pending() {
    $m = ojf_catfix_meta();
    if ($m['version'] === '') return false;
    $st = ojf_catfix_state();
    if (($st['version'] ?? '') !== $m['version']) return true;
    $phase = $st['phase'] ?? '';
    if ($phase === 'done') return false;
    // Etapa 1 pronta: só segue para apagar quando o arquivo de dados liberar.
    if ($phase === 'wait_delete') return !empty($m['allow_delete']);
    return true;
}

add_action('init', function () {
    $m = ojf_catfix_meta();
    if ($m['version'] === '') return;
    $st = ojf_catfix_state();
    if (($st['version'] ?? '') !== $m['version']) {
        ojf_catfix_save([
            'version' => $m['version'], 'phase' => 'backup', 'cursor' => 0, 'backup_file' => null,
            'started' => current_time('mysql'), 'finished' => null,
            'terms_updated' => 0, 'products_updated' => 0, 'products_missing' => 0,
            'deleted' => 0, 'kept_with_products' => 0, 'menu_repointed' => 0, 'menu_kept' => 0,
            'last_error' => null,
        ]);
    }
}, 29);

/** Um passo do lote. Cada fase guarda o cursor; seguro repetir. */
function ojf_catfix_step() {
    global $wpdb;
    if (get_transient('ojf_catfix_lock')) return;
    set_transient('ojf_catfix_lock', 1, 5 * MINUTE_IN_SECONDS);
    $st = ojf_catfix_state();
    $d  = ojf_catfix_data();
    try {
        $phase = $st['phase'] ?? 'done';

        if ($phase === 'wait_delete' && !empty($d['allow_delete'])) {
            // devolve marcações que a etapa 1 tirou e não devia (ex.: produto na
            // categoria da sua lista de estudantes) — acrescenta, não substitui
            if (!empty($d['append']) && ($st['appended'] ?? '') !== ($d['append_rev'] ?? '1')) {
                $n = 0;
                foreach ((array) $d['append'] as $pid => $tids) {
                    if (get_post_type((int) $pid) !== 'product') continue;
                    $ok = array_values(array_filter(array_map('intval', (array) $tids), function ($t) { return (bool) term_exists($t, 'product_cat'); }));
                    if ($ok && !is_wp_error(wp_set_object_terms((int) $pid, $ok, 'product_cat', true))) { clean_post_cache((int) $pid); $n++; }
                }
                $st['appended'] = $d['append_rev'] ?? '1';
                $st['appended_products'] = $n;
            }
            $st['phase'] = 'delete'; $st['cursor'] = 0; $st['queue'] = ojf_catfix_delete_queue($d);
            $phase = 'delete';
        }

        if ($phase === 'backup') {
            // Antes de tocar em qualquer coisa: todas as categorias e as categorias
            // de cada produto, para dar para voltar exatamente ao estado de antes.
            $terms = $wpdb->get_results(
                "SELECT t.term_id, t.name, t.slug, tt.parent, tt.term_taxonomy_id, tt.count
                   FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                  WHERE tt.taxonomy = 'product_cat'", ARRAY_A);
            $rels = $wpdb->get_results(
                "SELECT tr.object_id, tt.term_id
                   FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE tt.taxonomy = 'product_cat'", ARRAY_A);
            $menu = $wpdb->get_results(
                "SELECT m.post_id, m.meta_value AS term_id FROM {$wpdb->postmeta} m
                   JOIN {$wpdb->postmeta} o ON o.post_id = m.post_id AND o.meta_key = '_menu_item_object' AND o.meta_value = 'product_cat'
                  WHERE m.meta_key = '_menu_item_object_id'", ARRAY_A);
            $up  = wp_upload_dir();
            $dir = trailingslashit($up['basedir']) . 'ojf-backup';
            wp_mkdir_p($dir);
            if (!file_exists($dir . '/index.php')) file_put_contents($dir . '/index.php', '<?php // silence');
            $file = $dir . '/categorias-' . sanitize_key($d['version']) . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false) . '.json';
            $ok = file_put_contents($file, wp_json_encode([
                'created' => gmdate('c'), 'version' => $d['version'],
                'terms' => $terms, 'relationships' => $rels, 'menu_items' => $menu,
            ]));
            if (!$ok) throw new \RuntimeException('não consegui gravar o backup em ' . $dir);
            $st['backup_file'] = basename($file);
            $st['backup_terms'] = count($terms);
            $st['backup_relationships'] = count($rels);
            $st['phase'] = 'terms'; $st['cursor'] = 0;
        }

        elseif ($phase === 'terms') {
            $terms = array_values((array) ($d['terms'] ?? []));
            $i = (int) $st['cursor'];
            // categoria da origem que falta na loja: cria UMA vez, com o slug da
            // origem e o pai da origem (só se o slug ainda não existir)
            if ($i === 0) {
                foreach ((array) ($d['create'] ?? []) as $c) {
                    $slug = sanitize_title((string) ($c['slug'] ?? ''));
                    if ($slug === '' || get_term_by('slug', $slug, 'product_cat')) continue;
                    $pai = get_term_by('slug', sanitize_title((string) ($c['parent_slug'] ?? '')), 'product_cat');
                    if (!$pai || is_wp_error($pai)) { $st['last_error'] = 'criar ' . $slug . ': pai não existe'; continue; }
                    $r = wp_insert_term((string) $c['name'], 'product_cat', ['slug' => $slug, 'parent' => (int) $pai->term_id]);
                    if (is_wp_error($r)) $st['last_error'] = 'criar ' . $slug . ': ' . $r->get_error_message();
                    else $st['created'][] = $slug . '#' . (int) $r['term_id'];
                }
            }
            foreach (array_slice($terms, $i, 25) as $t) {
                $i++;
                if (!term_exists((int) $t['id'], 'product_cat')) continue;
                $args = ['name' => (string) $t['name'], 'parent' => (int) $t['parent']];
                if (!empty($t['slug'])) $args['slug'] = (string) $t['slug'];
                $r = wp_update_term((int) $t['id'], 'product_cat', $args);
                if (!is_wp_error($r)) $st['terms_updated']++;
                else $st['last_error'] = 'termo #' . (int) $t['id'] . ': ' . $r->get_error_message();
            }
            $st['cursor'] = $i;
            if ($i >= count($terms)) { $st['phase'] = 'products'; $st['cursor'] = 0; }
        }

        elseif ($phase === 'products') {
            $prods = (array) ($d['products'] ?? []);
            $keys  = array_keys($prods);
            $i = (int) $st['cursor'];
            foreach (array_slice($keys, $i, 50) as $pid) {
                $i++;
                $pid = (int) $pid;
                if (get_post_type($pid) !== 'product') { $st['products_missing']++; continue; }
                $ids = array_values(array_filter(array_map('intval', (array) $prods[$pid]), function ($id) {
                    return (bool) term_exists($id, 'product_cat');
                }));
                if (!$ids) continue;
                $r = wp_set_object_terms($pid, $ids, 'product_cat', false);
                if (is_wp_error($r)) { $st['last_error'] = 'produto #' . $pid . ': ' . $r->get_error_message(); continue; }
                if (isset($d['listing'][(string) $pid])) {
                    update_post_meta($pid, '_ojf_cat_listing', array_values(array_map('intval', (array) $d['listing'][(string) $pid])));
                }
                clean_post_cache($pid);
                if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                $st['products_updated']++;
            }
            $st['cursor'] = $i;
            if ($i >= count($keys)) {
                if (!empty($d['allow_delete'])) { $st['phase'] = 'delete'; $st['cursor'] = 0; $st['queue'] = ojf_catfix_delete_queue($d); }
                else { $st['phase'] = 'recount'; $st['cursor'] = 0; } // etapa 1: recontar e parar
            }
        }

        elseif ($phase === 'delete') {
            $queue = array_values((array) ($st['queue'] ?? []));
            $i = (int) $st['cursor'];
            $canon_by_name = ojf_catfix_canon_by_name($d);
            foreach (array_slice($queue, $i, 40) as $tid) {
                $i++;
                $term = get_term((int) $tid, 'product_cat');
                if (!$term || is_wp_error($term)) continue;
                $n = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", (int) $term->term_taxonomy_id
                ));
                if ($n > 0 && !empty($d['strip_junk'])) {
                    // Lixo ainda usado: tira dos produtos (qualquer status) e, se o
                    // produto ficar sem categoria nenhuma, vai para "Sem categoria"
                    // — é como ele está na origem (que não o classifica).
                    $padrao = (int) get_option('default_product_cat');
                    foreach ((array) get_objects_in_term((int) $term->term_id, 'product_cat') as $obj) {
                        wp_remove_object_terms((int) $obj, (int) $term->term_id, 'product_cat');
                        $resto = wp_get_object_terms((int) $obj, 'product_cat', ['fields' => 'ids']);
                        if (!is_wp_error($resto) && !$resto && $padrao) wp_set_object_terms((int) $obj, [$padrao], 'product_cat');
                        clean_post_cache((int) $obj);
                        $st['stripped'] = (int) ($st['stripped'] ?? 0) + 1;
                    }
                    $n = 0;
                }
                if ($n > 0) { $st['kept_with_products']++; continue; }
                $menu = $wpdb->get_col($wpdb->prepare(
                    "SELECT m.post_id FROM {$wpdb->postmeta} m
                       JOIN {$wpdb->postmeta} o ON o.post_id = m.post_id AND o.meta_key = '_menu_item_object' AND o.meta_value = 'product_cat'
                      WHERE m.meta_key = '_menu_item_object_id' AND m.meta_value = %s", (string) $term->term_id
                ));
                if ($menu) {
                    // equivalente escolhido no arquivo; senão, o de mesmo nome
                    $alvo = (int) ($d['menu_map'][(string) $term->term_id] ?? 0);
                    if ($alvo && !term_exists($alvo, 'product_cat')) $alvo = 0;
                    if (!$alvo) $alvo = $canon_by_name[ojf_catfix_norm($term->name)] ?? 0;
                    // sem equivalente: fica — a não ser que o arquivo mande apagar
                    // (o WordPress tira o item de menu junto com a categoria)
                    if (!$alvo && empty($d['delete_menu_terms'])) { $st['menu_kept']++; continue; }
                    if (!$alvo) { $st['menu_removed'] = (int) ($st['menu_removed'] ?? 0) + count($menu); $menu = []; }
                    foreach ($menu as $mid) update_post_meta((int) $mid, '_menu_item_object_id', (string) $alvo);
                    $st['menu_repointed'] += count($menu);
                }
                $r = wp_delete_term((int) $term->term_id, 'product_cat');
                if ($r && !is_wp_error($r)) $st['deleted']++;
                elseif (is_wp_error($r)) $st['last_error'] = 'apagar #' . (int) $term->term_id . ': ' . $r->get_error_message();
            }
            $st['cursor'] = $i;
            if ($i >= count($queue)) { $st['phase'] = 'recount'; $st['cursor'] = 0; $st['delete_done'] = true; unset($st['queue']); }
        }

        elseif ($phase === 'recount') {
            $tts = $wpdb->get_col("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat'");
            wp_update_term_count_now(array_map('intval', $tts), 'product_cat');
            if (function_exists('wc_recount_all_terms')) wc_recount_all_terms();
            delete_transient('wc_term_counts');
            clean_taxonomy_cache('product_cat');
            do_action('litespeed_purge_all');
            if (empty($st['delete_done'])) {
                $st['phase'] = !empty($d['allow_delete']) ? 'delete' : 'wait_delete';
                if ($st['phase'] === 'delete') { $st['cursor'] = 0; $st['queue'] = ojf_catfix_delete_queue($d); }
                $st['stage1_finished'] = current_time('mysql');
                error_log('[ojf] categorias: etapa 1 concluída (árvore + produtos), aguardando liberação para apagar');
                return;
            }
            $st['phase'] = 'done';
            $st['finished'] = current_time('mysql');
            error_log('[ojf] limpeza de categorias concluída: ' . wp_json_encode($st));
        }
    } catch (\Throwable $e) {
        $st['last_error'] = get_class($e) . ': ' . $e->getMessage();
        $st['cursor'] = (int) ($st['cursor'] ?? 0) + 1; // pula o item que quebrou
    } finally {
        ojf_catfix_save($st);
        delete_transient('ojf_catfix_lock');
    }
}

function ojf_catfix_norm($s) {
    $s = html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
    $s = function_exists('remove_accents') ? remove_accents($s) : $s;
    return trim(preg_replace('/\s+/', ' ', strtolower($s)));
}

/** nome normalizado → id, só entre as categorias da origem (para religar itens de menu) */
function ojf_catfix_canon_by_name($d) {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach ((array) ($d['canon'] ?? []) as $id) {
        $t = get_term((int) $id, 'product_cat');
        if ($t && !is_wp_error($t)) $m[ojf_catfix_norm($t->name)] = (int) $t->term_id;
    }
    return $m;
}

/** Fila de apagar: tudo que não é da origem nem protegido, filhos antes dos pais. */
function ojf_catfix_delete_queue($d) {
    $proteger = array_map('intval', array_merge((array) ($d['canon'] ?? []), (array) ($d['keep'] ?? [])));
    $proteger[] = (int) get_option('default_product_cat');
    foreach ((array) ($d['create'] ?? []) as $c) {
        $t = get_term_by('slug', sanitize_title((string) ($c['slug'] ?? '')), 'product_cat');
        if ($t && !is_wp_error($t)) $proteger[] = (int) $t->term_id;
    }
    $todos = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'id=>parent']);
    if (is_wp_error($todos)) return [];
    $prof = function ($id) use ($todos) {
        $n = 0;
        while (!empty($todos[$id]) && $n < 50) { $id = (int) $todos[$id]; $n++; }
        return $n;
    };
    // Subárvores de outros plugins (Listas de Estudantes: "Listas estudantes" e
    // "Brindes"): nada dentro delas é lixo, nem as listas criadas depois.
    $raizes = array_map('intval', (array) ($d['keep_subtrees'] ?? []));
    $dentro = function ($id) use ($todos, $raizes) {
        $n = 0;
        while ($id && $n < 50) {
            if (in_array((int) $id, $raizes, true)) return true;
            $id = (int) ($todos[$id] ?? 0); $n++;
        }
        return false;
    };
    $fila = [];
    foreach (array_keys($todos) as $id) {
        if (in_array((int) $id, $proteger, true) || $dentro((int) $id)) continue;
        $fila[(int) $id] = $prof((int) $id);
    }
    arsort($fila); // mais fundo primeiro
    return array_map('intval', array_keys($fila));
}

/* corrente própria, como o lote do título: admin-ajax interno não bloqueante */

function ojf_catfix_token() {
    return wp_hash('ojf_catfix_tick');
}

function ojf_catfix_dispatch($chain = '') {
    $chain = $chain !== '' ? $chain : strtolower(wp_generate_password(12, false));
    set_transient('ojf_catfix_chain', $chain, 3 * MINUTE_IN_SECONDS);
    $r = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout' => 0.01, 'blocking' => false, 'sslverify' => false,
        'body' => ['action' => 'ojf_catfix_tick', 'token' => ojf_catfix_token(), 'chain' => $chain],
    ]);
    if (is_wp_error($r)) {
        delete_transient('ojf_catfix_chain');
        error_log('[ojf] categorias: falha ao disparar o lote: ' . $r->get_error_message());
    }
}

function ojf_catfix_tick_handler() {
    if (!hash_equals(ojf_catfix_token(), (string) ($_POST['token'] ?? ''))) { status_header(403); exit; }
    $chain = sanitize_key((string) ($_POST['chain'] ?? ''));
    if ($chain === '' || get_transient('ojf_catfix_chain') !== $chain) exit;
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(180);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    set_transient('ojf_catfix_chain', $chain, 3 * MINUTE_IN_SECONDS);
    sleep(OJF_CATFIX_PAUSE);
    if (get_transient('ojf_catfix_chain') !== $chain) exit;
    ojf_catfix_step();
    if (ojf_catfix_pending()) ojf_catfix_dispatch($chain);
    else delete_transient('ojf_catfix_chain');
    exit;
}
add_action('wp_ajax_ojf_catfix_tick', 'ojf_catfix_tick_handler');
add_action('wp_ajax_nopriv_ojf_catfix_tick', 'ojf_catfix_tick_handler');

add_action('init', function () {
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return;
    if (wp_doing_ajax() && ($_REQUEST['action'] ?? '') === 'ojf_catfix_tick') return;
    if (!ojf_catfix_pending() || get_transient('ojf_catfix_chain')) return;
    if (get_transient('ojf_catfix_kick')) return;
    set_transient('ojf_catfix_kick', 1, 60);
    add_action('shutdown', function () { ojf_catfix_dispatch(); }, 99);
}, 33);

add_action('rest_api_init', function () {
    register_rest_route('odontojf/v1', '/category-fix-status', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $st = ojf_catfix_state();
            if (isset($st['queue'])) { $st['queue_size'] = count((array) $st['queue']); unset($st['queue']); }
            $d = ojf_catfix_data();
            return new WP_REST_Response([
                'data_version' => $d['version'] ?? null,
                'terms_in_file' => count((array) ($d['terms'] ?? [])),
                'products_in_file' => count((array) ($d['products'] ?? [])),
                'state' => $st,
                'chain' => (bool) get_transient('ojf_catfix_chain'),
                'now' => gmdate('c'),
            ], 200);
        },
    ]);
});
