<?php
/**
 * OdontoJF Woo Bridge — product create/update/delete handlers.
 *
 * Key adaptations vs the multi-seller reference:
 *   - native _sku = ERP code (NOT a random Full-XXXX); find-or-create by _sku.
 *   - MANUAL attributes only (WC_Product_Attribute->set_id(0)); no global pa_*.
 *   - generic meta_data[] loop (preserves all _odontojf_* keys).
 *   - categories[{name}] → resolve/create term ids.
 *   - images via the image queue (ojf_upload_image returns an attachment id now,
 *     CDN URL fills in async).
 * Each handler returns execution_time_ms so the api_queue records duration_ms.
 */

if (!defined('ABSPATH')) exit;

/* ── route registration (interceptado pela api-queue → enfileira) ─────────── */
add_action('rest_api_init', function () {
    $ns = OJF_NAMESPACE;
    register_rest_route($ns, '/create-product', [
        'methods' => 'POST', 'permission_callback' => 'ojf_rest_permission', 'callback' => 'ojf_create_product_handler',
    ]);
    register_rest_route($ns, '/update-product', [
        'methods' => 'POST', 'permission_callback' => 'ojf_rest_permission', 'callback' => 'ojf_update_product_handler',
    ]);
    register_rest_route($ns, '/delete-product', [
        'methods' => 'POST', 'permission_callback' => 'ojf_rest_permission', 'callback' => 'ojf_delete_product_handler',
    ]);
    register_rest_route($ns, '/sync-categories', [
        'methods' => 'POST', 'permission_callback' => 'ojf_rest_permission', 'callback' => 'ojf_sync_categories_handler',
    ]);
    // Price-only update: SÍNCRONO (não passa pela api-queue), só mexe em preço/estoque.
    register_rest_route($ns, '/update-price', [
        'methods' => 'POST', 'permission_callback' => 'ojf_rest_permission', 'callback' => 'ojf_update_price_handler',
    ]);
    register_rest_route($ns, '/health', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () { return new WP_REST_Response(['ok' => true, 'api_version' => OJF_BRIDGE_VERSION], 200); },
    ]);
});

/**
 * Cria a ÁRVORE de categorias inteira (de uma vez, ANTES dos produtos), com
 * hierarquia certa. Recebe os nós da origem {id, title, parent} e cria em ondas
 * (pai antes do filho), mapeando id-da-origem → term_id do WP. Síncrono (não
 * passa pela fila). Idempotente (reusa termo existente no mesmo pai).
 */
function ojf_sync_categories_handler($request) {
    $body  = $request->get_json_params();

    // MODO root_parent: põe TODAS as raízes (parent=0) sob uma categoria MÃE.
    // Ex: {"root_parent": 91656} → "Loja" vira mãe de tudo. Rodar SÓ no final
    // (depois dos produtos), senão o push criaria raízes duplicadas no nível 0.
    if (!empty($body['root_parent'])) {
        $rp = (int) $body['root_parent'];
        $roots = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false, 'fields' => 'ids']);
        $moved = 0;
        foreach ((array) $roots as $tid) {
            $tid = (int) $tid;
            if ($tid <= 0 || $tid === $rp) continue;
            if (!is_wp_error(wp_update_term($tid, 'product_cat', ['parent' => $rp]))) $moved++;
        }
        return new WP_REST_Response(['ok' => true, 'mode' => 'root_parent', 'parent' => $rp, 'moved' => $moved], 200);
    }

    // WIPE: apaga TODAS as categorias antes de recriar (recria 100% igual ao menu).
    $wiped = 0;
    if (!empty($body['wipe'])) {
        $all = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids']);
        foreach ((array) $all as $tid) {
            $tid = (int) $tid;
            if ($tid > 0 && wp_delete_term($tid, 'product_cat') === true) $wiped++;
        }
    }

    // MODO PATHS: caminhos [[{name,slug},...]] (ou [["Nome",...]]) — cria
    // hierarquicamente gravando o SLUG EXATO da origem (URL igual).
    if (isset($body['paths']) && is_array($body['paths'])) {
        $created = 0; $reused = 0; $skipped = 0;
        foreach ($body['paths'] as $path) {
            if (!is_array($path)) continue;
            $parent = 0;
            foreach ($path as $item) {
                if (is_array($item)) { $name = trim((string) ($item['name'] ?? '')); $slug = trim((string) ($item['slug'] ?? '')); }
                else { $name = trim((string) $item); $slug = ''; }
                if ($name === '') continue;
                $existed = false;
                $cid = ojf_get_or_create_category_id_slug($name, (int) $parent, $slug, $existed);
                if ($cid) { $existed ? $reused++ : $created++; $parent = (int) $cid; }
                else { $skipped++; break; }
            }
        }
        return new WP_REST_Response(['ok' => true, 'mode' => 'paths', 'wiped' => $wiped, 'created' => $created, 'reused' => $reused, 'skipped' => $skipped], 200);
    }

    // MODO NODES (árvore da origem por id/parent)
    $nodes = (isset($body['nodes']) && is_array($body['nodes'])) ? $body['nodes'] : [];
    if (empty($nodes)) return new WP_REST_Response(['ok' => false, 'error' => 'no paths/nodes'], 400);

    $byId = [];
    foreach ($nodes as $n) {
        $id = (string) ($n['id'] ?? '');
        if ($id === '') continue;
        $parent = $n['parent'] ?? null;
        if (is_array($parent)) $parent = $parent[0] ?? null;
        $byId[$id] = ['title' => trim((string) ($n['title'] ?? '')), 'parent' => ($parent ? (string) $parent : null)];
    }

    $termOf = []; $created = 0; $reused = 0; $skipped = 0;
    $pending = array_keys($byId); $guard = 0;
    while (!empty($pending) && $guard < 60) {
        $guard++; $next = [];
        foreach ($pending as $id) {
            $node = $byId[$id]; $par = $node['parent']; $parentTerm = 0;
            if ($par !== null && isset($byId[$par])) {
                if (isset($termOf[$par])) { $parentTerm = $termOf[$par]; }
                else { $next[] = $id; continue; } // pai ainda não criado → próxima onda
            }
            if ($node['title'] === '') { $skipped++; continue; }
            $before = get_terms(['taxonomy' => 'product_cat', 'parent' => (int) $parentTerm, 'name' => $node['title'], 'hide_empty' => false, 'number' => 1, 'fields' => 'ids']);
            $exists = !is_wp_error($before) && !empty($before);
            $cid = ojf_get_or_create_category_id($node['title'], (int) $parentTerm);
            if ($cid) { $termOf[$id] = (int) $cid; $exists ? $reused++ : $created++; }
            else { $skipped++; }
        }
        if (count($next) === count($pending)) break; // sem progresso (ciclo/pais ausentes)
        $pending = $next;
    }
    return new WP_REST_Response(['ok' => true, 'total' => count($byId), 'created' => $created, 'reused' => $reused, 'skipped' => $skipped, 'unresolved' => count($pending)], 200);
}

/* ── helpers ──────────────────────────────────────────────────────────────── */

function ojf_find_product_id_by_sku($sku) {
    $sku = (string) $sku;
    if ($sku === '') return 0;
    global $wpdb;
    // Só PRODUTOS-PAI (post_type='product'), NUNCA variações. Uma variação com o
    // código cru (de push antigo/race com concorrência>1) sequestrava o SKU e o
    // wc_get_product_id_by_sku devolvia a variação → o create do variável quebrava
    // ("SKU inválido ou duplicado" / "Produto inválido"). Aqui ignoramos variações.
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.post_type = 'product' AND p.post_status != 'trash'
         ORDER BY p.ID ASC LIMIT 1", $sku
    ));
}

/** Libera um SKU sequestrado por variações órfãs (re-sufixa a variação) para que
 *  o produto-PAI possa reivindicá-lo sem o erro "SKU inválido ou duplicado". */
function ojf_free_sku_from_variations($sku) {
    $sku = (string) $sku;
    if ($sku === '') return;
    global $wpdb;
    $vids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.post_type = 'product_variation'", $sku
    ));
    foreach ($vids as $vid) {
        $v = wc_get_product((int) $vid);
        if ($v) { $v->set_sku($sku . '-v' . (int) $vid); $v->save(); }
    }
}

/** Libera um SKU de QUALQUER detentor (produto, variação, draft, lixeira) EXCETO
 *  $keep_id. Órfãos de tentativas anteriores (que o store API não mostra por
 *  serem draft/trash) seguravam o código e a variação batia "SKU duplicado".
 *  Variação órfã → re-sufixa; produto/draft órfão → apaga (é lixo de re-push). */
function ojf_free_sku_global($sku, $keep_id = 0) {
    $sku = (string) $sku;
    if ($sku === '') return;
    global $wpdb;
    $keep_id = (int) $keep_id;
    $ids = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_type FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.ID <> %d", $sku, $keep_id
    ));
    foreach ($ids as $r) {
        $pid = (int) $r->ID;
        if ($r->post_type === 'product_variation') {
            $v = wc_get_product($pid);
            if ($v) { $v->set_sku($sku . '-v' . $pid); $v->save(); }
        } else {
            // produto-pai órfão (draft/lixo de tentativa anterior) → apaga de vez
            wp_delete_post($pid, true);
        }
        clean_post_cache($pid);
    }
}

/**
 * Dono do SKU quando ele está numa VARIAÇÃO viva (>= 1.0.36).
 *
 * Na origem cada tamanho é um produto próprio (`type: familyProduct`) cujo
 * código já vive numa variação do pai aqui. Se um desses filhos entrar no
 * pipeline como produto solto, ojf_free_sku_global() re-sufixaria a variação
 * legítima (411 -> 411-v786729) e o código sumiria da loja. Este lookup existe
 * para RECUSAR esse create em vez de roubar o SKU.
 *
 * @param string $sku
 * @return array{variation_id:int,parent_id:int,parent_sku:string}|null
 */
function ojf_variation_owner_of_sku($sku) {
    $sku = (string) $sku;
    if ($sku === '') return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT p.ID, p.post_parent FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.post_type = 'product_variation'
         ORDER BY p.ID ASC LIMIT 1", $sku
    ));
    if (!$row || !(int) $row->post_parent) return null;

    $parent_id = (int) $row->post_parent;
    // Pai na lixeira / rascunho é lixo de tentativa anterior: deixa o
    // ojf_free_sku_global() limpar como sempre fez.
    if (get_post_status($parent_id) !== 'publish') return null;

    return [
        'variation_id' => (int) $row->ID,
        'parent_id'    => $parent_id,
        'parent_sku'   => (string) get_post_meta($parent_id, '_sku', true),
    ];
}

/**
 * Produto NOSSO com este slug (>= 1.0.56).
 *
 * Só devolve produto marcado como nosso (_seller = odontojf) para nunca adotar
 * algo cadastrado à mão na loja.
 *
 * @param string $slug
 * @return int
 */
function ojf_find_owned_product_by_slug($slug) {
    $slug = sanitize_title((string) $slug);
    if ($slug === '') return 0;
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_seller'
         WHERE p.post_name = %s AND p.post_type = 'product' AND p.post_status = 'publish'
           AND m.meta_value = 'odontojf'
         ORDER BY p.ID ASC LIMIT 1", $slug
    ));
}

/**
 * Produto NOSSO com este id de origem (>= 1.0.63).
 *
 * A origem dá a cada produto um id imutável (`id: "KDznXluDKlRQwI2mdOvo"`), que o
 * scrape já grava em `_odontojf_scrape_id`. Ao contrário do slug — que a origem pode
 * reescrever, e que o WordPress sufixa com `-2` quando o post_name está ocupado — esse
 * id não muda. É a única âncora que não depende de nada mudar de ideia.
 */
function ojf_find_owned_product_by_origin_id($origin_id) {
    $origin_id = trim((string) $origin_id);
    if ($origin_id === '') return 0;
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_odontojf_scrape_id'
         WHERE m.meta_value = %s AND p.post_type = 'product' AND p.post_status != 'trash'
         ORDER BY p.ID ASC LIMIT 1", $origin_id
    ));
}

/** O id de origem que veio no payload (meta_data[] do pai). */
function ojf_payload_origin_id($data) {
    if (empty($data['meta_data']) || !is_array($data['meta_data'])) return '';
    foreach ($data['meta_data'] as $m) {
        if (isset($m['key']) && $m['key'] === '_odontojf_scrape_id') {
            return trim((string) ($m['value'] ?? ''));
        }
    }
    return '';
}

/** Códigos ERP das variações que vêm no payload. @return string[] */
function ojf_payload_variation_codes($data) {
    $out = [];
    if (empty($data['variations']) || !is_array($data['variations'])) return $out;
    foreach ($data['variations'] as $var) {
        $c = (string) ($var['sku'] ?? '');
        if ($c !== '') $out[] = $c;
    }
    return array_values(array_unique($out));
}

/** Códigos ERP das variações VIVAS de um pai. @return array<int,string> id => código */
function ojf_parent_variation_codes($parent_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID,
                MAX(CASE WHEN m.meta_key = '_ojf_erp_code' THEN m.meta_value END) AS erp,
                MAX(CASE WHEN m.meta_key = '_sku'          THEN m.meta_value END) AS sku
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status != 'trash'
         GROUP BY p.ID", (int) $parent_id
    ));
    $out = [];
    foreach ((array) $rows as $r) {
        $code = (string) ($r->erp !== null && $r->erp !== '' ? $r->erp : $r->sku);
        if ($code !== '') $out[(int) $r->ID] = $code;
    }
    return $out;
}

/**
 * Solta um SKU de quem o segura, SEM apagar nada (>= 1.0.56).
 *
 * O ojf_free_sku_global() apaga o produto detentor (`wp_delete_post(.., true)`),
 * e isso leva os anexos e os objetos no R2 junto pelo hook delete_attachment.
 * Na adoção o detentor é um produto VIVO com fotos — aqui só zeramos o `_sku`.
 */
/** Zera o sku na wc_product_meta_lookup — é ela que o Woo consulta para dizer
 *  "SKU inválido ou duplicado". O save() cuida disso quando há mudança; aqui
 *  garantimos também quando o postmeta já estava vazio e só o lookup ficou para trás. */
function ojf_clear_sku_lookup($pid) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'wc_product_meta_lookup';
    // @phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->update($tabela, ['sku' => ''], ['product_id' => (int) $pid], ['%s'], ['%d']);
}

function ojf_release_sku_from_product($sku, $keep_id = 0) {
    $sku = (string) $sku;
    if ($sku === '') return;
    global $wpdb;
    $keep_id = (int) $keep_id;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_type FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.ID <> %d", $sku, $keep_id
    ));
    foreach ((array) $rows as $r) {
        $pid = (int) $r->ID;
        if ($r->post_type === 'product_variation') {
            $v = wc_get_product($pid);
            if ($v) { $v->set_sku($sku . '-v' . $pid); $v->save(); }
        } else {
            // Pelo CRUD, NÃO por update_post_meta: o Woo valida SKU duplicado
            // contra a tabela wc_product_meta_lookup, e só o save() do produto
            // atualiza essa tabela. Zerando só o postmeta, o lookup continuava
            // com o código e o save do canônico morria em "SKU inválido ou
            // duplicado" — foi o que travou #773421 e #770465.
            $dono = wc_get_product($pid);
            if ($dono) { $dono->set_sku(''); $dono->save(); }
            else       { update_post_meta($pid, '_sku', ''); }
            ojf_clear_sku_lookup($pid);
            update_post_meta($pid, '_ojf_sku_released', $sku);
            if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
        }
        clean_post_cache($pid);
    }
    if (class_exists('WC_Cache_Helper')) WC_Cache_Helper::invalidate_cache_group('products');
}

/** Sobreposição mínima com as variações vivas do candidato. @return true|WP_Error */
function ojf_check_adoption_overlap($candidate, $codes, $is_variable, $via) {
    if (!$is_variable) return true;
    $live  = ojf_parent_variation_codes($candidate);
    $total = count($live);
    if ($total === 0) return true;
    $overlap = count(array_intersect(array_values($live), $codes));
    if ($overlap >= (int) ceil($total / 2)) return true;
    return new WP_Error('adoption_overlap_too_low', sprintf(
        'Produto #%d casa por %s mas só %d das %d variações vivas dele estão no payload. Recusado: adotar aqui faria o PILAR C apagar as outras %d.',
        $candidate, $via, $overlap, $total, $total - $overlap
    ), ['status' => 409]);
}

/** O gêmeo pode ser absorvido? Tem de ser NOSSO e ficar vazio depois do sync. */
function ojf_check_can_absorb_twin($twin_id, $codes) {
    if (get_post_meta($twin_id, '_seller', true) !== 'odontojf') {
        return new WP_Error('duplicate_products', sprintf(
            'O slug e o SKU apontam para produtos diferentes e o #%d não é nosso (_seller != odontojf). Recusado: resolver isso à mão.',
            $twin_id
        ), ['status' => 409]);
    }
    $live   = ojf_parent_variation_codes($twin_id);
    $orphan = array_diff(array_values($live), $codes);
    if ($orphan) {
        return new WP_Error('duplicate_products', sprintf(
            'O slug e o SKU apontam para produtos diferentes, e o #%d tem %d variação(ões) que NÃO estão neste payload (%s). Recusado: elas ficariam órfãs num produto despublicado.',
            $twin_id, count($orphan), implode(', ', array_slice($orphan, 0, 8))
        ), ['status' => 409]);
    }
    return true;
}

/**
 * Qual produto este payload JÁ É (>= 1.0.56). A identidade é o SLUG da origem.
 *
 * O `_sku` do pai variável é sintético — `OD-<código da 1ª variação>` — e a origem
 * reordena/remove tamanhos, então esse código muda sozinho. Quando mudava, o create
 * não achava o pai, o Woo criava um SEGUNDO produto e o save de cada variação
 * reescrevia o post_parent dela: o original ficava publicado e VAZIO. Foi o que
 * aconteceu com #773421 (OD-19722) -> #791839 (OD-20591), 12 variações.
 *
 * O slug é a chave estável (é a URL da origem e a URL que o cliente e o Google têm).
 *
 * @return array{id:int,via:string,twin:int}|WP_Error
 *         id=0 → criar do zero. twin = duplicata a absorver depois do sync.
 */
function ojf_resolve_target_product($data, $is_variable, $by_sku) {
    $codes   = ojf_payload_variation_codes($data);
    $by_sku  = (int) $by_sku;

    $by_origin = ojf_find_owned_product_by_origin_id(ojf_payload_origin_id($data));
    $by_slug   = !empty($data['slug']) ? ojf_find_owned_product_by_slug((string) $data['slug']) : 0;

    // Quem é o CANÔNICO: o dono do slug da origem, sempre que existir. É a URL que
    // o cliente e o Google têm. O `_odontojf_scrape_id` não serve para escolher —
    // ele marca onde escrevemos por último, e num par duplicado isso é justamente o
    // gêmeo. O id entra como âncora quando o slug não acha ninguém: origem renomeou
    // o produto, ou o WordPress sufixou o nosso post_name com -2.
    $canonico = $by_slug ?: $by_origin;
    $ancora   = $by_slug ? 'slug' : 'id de origem';

    // Todo o resto que aponta para outro produto é gêmeo a absorver.
    $gemeos = [];
    foreach ([$by_sku, $by_origin] as $cand) {
        $cand = (int) $cand;
        if ($cand && $cand !== $canonico && !in_array($cand, $gemeos, true)) $gemeos[] = $cand;
    }

    // 1. o SKU acha, e nada discorda: caminho de sempre.
    if ($by_sku && $by_sku === $canonico) {
        return ['id' => $by_sku, 'via' => 'sku', 'twin' => 0];
    }
    if ($by_sku && !$canonico) {
        return ['id' => $by_sku, 'via' => 'sku', 'twin' => 0];
    }

    // 2. DUPLICAÇÃO VIVA: o canônico é um produto e o SKU (ou o id de origem) é de
    //    outro. O canônico manda; o outro vira gêmeo, absorvido depois do sync.
    if ($canonico) {
        if (count($gemeos) > 1) {
            return new WP_Error('duplicate_products', sprintf(
                'O produto #%d (por %s) concorre com mais de uma duplicata (%s). Recusado: resolver isso à mão.',
                $canonico, $ancora, '#' . implode(', #', $gemeos)
            ), ['status' => 409]);
        }
        $twin = $gemeos ? (int) $gemeos[0] : 0;
        if ($twin) {
            $can = ojf_check_can_absorb_twin($twin, $codes);
            if (is_wp_error($can)) return $can;
        }
        $ok = ojf_check_adoption_overlap($canonico, $codes, $is_variable, $ancora);
        if (is_wp_error($ok)) return $ok;
        return [
            'id'   => $canonico,
            'via'  => $ancora . ($twin ? ' (duplicata #' . $twin . ' absorvida)' : ''),
            'twin' => $twin,
        ];
    }

    // 4. nem slug nem SKU: quem é o dono atual das variações do payload?
    if ($is_variable && $codes) {
        $owners = [];
        foreach ($codes as $c) {
            $o = ojf_variation_owner_of_sku($c);
            if ($o) {
                $pid = (int) $o['parent_id'];
                $owners[$pid] = isset($owners[$pid]) ? $owners[$pid] + 1 : 1;
            }
        }
        if (count($owners) > 1) {
            arsort($owners);
            $desc = [];
            foreach ($owners as $pid => $n) $desc[] = '#' . $pid . ' (' . $n . ')';
            return new WP_Error('variations_span_parents', sprintf(
                'As variações deste payload já pertencem a %d produtos publicados: %s. Recusado: criar um pai novo roubaria as variações e esvaziaria os originais.',
                count($owners), implode(', ', $desc)
            ), ['status' => 409]);
        }
        if ($owners) {
            reset($owners);
            $cand = (int) key($owners);
            $ok = ojf_check_adoption_overlap($cand, $codes, $is_variable, 'variações');
            if (is_wp_error($ok)) return $ok;
            return ['id' => $cand, 'via' => 'variações', 'twin' => 0];
        }
    }

    return ['id' => 0, 'via' => '', 'twin' => 0];
}

/**
 * Absorve a duplicata depois que o sync puxou as variações de volta pro canônico.
 *
 * NUNCA apaga: `wp_delete_post` levaria os anexos e os objetos no R2 junto. Só
 * despublica e marca — reversível, e sobra rastro pra conferir.
 *
 * @return string  o que foi feito (entra na resposta e no log)
 */
function ojf_absorb_duplicate($twin_id, $canonical_id) {
    $twin_id = (int) $twin_id;
    if (!$twin_id || $twin_id === (int) $canonical_id) return '';
    clean_post_cache($twin_id);
    $left = ojf_parent_variation_codes($twin_id);
    if ($left) {
        $msg = sprintf('duplicata #%d MANTIDA publicada: ainda restaram %d variação(ões) nela', $twin_id, count($left));
        error_log('[ojf] ' . $msg);
        return $msg;
    }
    $gemeo = wc_get_product($twin_id);
    if ($gemeo && (string) $gemeo->get_sku() !== '') { $gemeo->set_sku(''); $gemeo->save(); }
    ojf_clear_sku_lookup($twin_id);
    update_post_meta($twin_id, '_ojf_duplicate_of', (int) $canonical_id);
    update_post_meta($twin_id, '_ojf_duplicate_at', current_time('mysql'));
    wp_update_post(['ID' => $twin_id, 'post_status' => 'draft']);
    clean_post_cache($twin_id);
    if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($twin_id);
    $msg = sprintf('duplicata #%d despublicada (rascunho), variações devolvidas ao #%d', $twin_id, (int) $canonical_id);
    error_log('[ojf] ' . $msg);
    return $msg;
}

/** Compat com o interceptor da api-queue (update/delete): acha por _sku (ERP),
 *  ignorando o conceito de seller (single-tenant). */
function ojf_buscar_produto_por_sku($sku, $seller = null) {
    return ojf_find_product_id_by_sku($sku);
}

/** Todos os IDs de anexo de um produto: featured + galeria + imagens das variações. */
/**
 * IDs dos anexos da galeria por variação (_odontojf_variation_gallery).
 * Guardado como CSV de IDs; a thumbnail (image_id) NÃO entra aqui.
 */
function ojf_get_variation_gallery_ids($variation_id) {
    $raw = get_post_meta((int) $variation_id, '_odontojf_variation_gallery', true);
    if (!$raw) return [];
    $ids = array_map('intval', array_filter(array_map('trim', explode(',', (string) $raw))));
    return array_values(array_filter($ids));
}

function ojf_collect_product_attachment_ids($product) {
    if (!$product instanceof WC_Product) return [];
    $ids = [];
    if ($product->get_image_id()) $ids[] = (int) $product->get_image_id();
    foreach ((array) $product->get_gallery_image_ids() as $g) $ids[] = (int) $g;
    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $cid) {
            $c = wc_get_product($cid);
            if ($c && $c->get_image_id()) $ids[] = (int) $c->get_image_id();
            // PILAR B: a galeria da variação também está "em uso". Sem esta
            // linha a varredura de órfãos apagaria os anexos extras no próximo
            // update — e o hook delete_attachment levaria o objeto no R2 junto.
            foreach (ojf_get_variation_gallery_ids($cid) as $g) $ids[] = (int) $g;
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

/**
 * PILAR A (anti-órfão): ao excluir um produto OU variação por QUALQUER caminho
 * (nosso endpoint, wp-admin, lixeira esvaziada), deleta os anexos de imagem
 * dele — o que dispara o hook delete_attachment → ojf_r2_delete (limpa o R2).
 * Só toca anexos NOSSOS (com object_key R2), nunca mídia de terceiros.
 */
add_action('before_delete_post', 'ojf_cleanup_images_on_product_delete', 10, 1);
function ojf_cleanup_images_on_product_delete($post_id) {
    $pt = get_post_type($post_id);
    if ($pt !== 'product' && $pt !== 'product_variation') return;
    $product = wc_get_product($post_id);
    if (!$product) return;
    foreach (ojf_collect_product_attachment_ids($product) as $aid) {
        if (get_post_meta($aid, '_ojf_r2_object_key', true)) {
            wp_delete_attachment((int) $aid, true);
        }
    }
    // Imagens do CORPO (post_content): vão DIRETO pro R2, sem virar attachment,
    // então não saem pelo loop acima. Cada uma fica registrada em meta
    // _ojf_body_img_<hash> = URL no R2 → extrai a object key e apaga do R2.
    if ($pt === 'product' && function_exists('ojf_r2_delete')) {
        global $wpdb;
        $cdn = rtrim(OJF_CDN_BASE_URL, '/') . '/';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key LIKE %s",
            $post_id, $wpdb->esc_like('_ojf_body_img_') . '%'
        ));
        foreach ((array) $rows as $r) {
            $url = (string) $r->meta_value;
            if ($url !== '' && strpos($url, $cdn) === 0) {
                $key = substr($url, strlen($cdn));
                if ($key !== '') ojf_r2_delete($key);
            }
        }
    }
}

function ojf_get_or_create_category_id($name, $parent_id = 0) {
    $name = trim((string) $name);
    if ($name === '') return 0;
    $parent_id = (int) $parent_id;

    // HIERÁRQUICO (preserva a árvore): procura o termo com esse nome DENTRO do
    // PAI atual do breadcrumb. O mesmo nome em ramos diferentes = categorias
    // diferentes (hierarquia mantida). REUSA o que já existe nesse pai → nunca
    // cria 2º igual no mesmo pai. (A slug pode virar "-2" SÓ quando o mesmo nome
    // é filho de pais diferentes — aí são categorias legítimas e distintas.)
    $args = ['taxonomy' => 'product_cat', 'parent' => $parent_id, 'name' => $name,
             'hide_empty' => false, 'number' => 1, 'fields' => 'ids'];
    $hit = get_terms($args);
    if (!is_wp_error($hit) && !empty($hit)) return (int) $hit[0];

    $res = wp_insert_term($name, 'product_cat', ['parent' => $parent_id]);
    if (is_wp_error($res)) {
        // race: tenta de novo no mesmo pai
        $hit = get_terms($args);
        if (!is_wp_error($hit) && !empty($hit)) return (int) $hit[0];
        $data = $res->get_error_data();
        return is_array($data) && !empty($data['term_id']) ? (int) $data['term_id'] : 0;
    }
    return (int) $res['term_id'];
}

/**
 * Igual ao acima, mas grava a SLUG EXATA da origem (URL idêntica ao menu).
 * Reusa pela slug (única no Woo); senão pelo nome no pai; senão cria com a slug.
 */
function ojf_get_or_create_category_id_slug($name, $parent_id = 0, $slug = '', &$existed = false) {
    $name = trim((string) $name);
    if ($name === '') { $existed = false; return 0; }
    $parent_id = (int) $parent_id;
    $slug = trim((string) $slug);

    if ($slug !== '') {
        $t = get_term_by('slug', $slug, 'product_cat');
        if ($t && !is_wp_error($t)) { $existed = true; return (int) $t->term_id; }
    }
    $find = ['taxonomy' => 'product_cat', 'parent' => $parent_id, 'name' => $name, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids'];
    $hit = get_terms($find);
    if (!is_wp_error($hit) && !empty($hit)) { $existed = true; return (int) $hit[0]; }

    $args = ['parent' => $parent_id];
    if ($slug !== '') $args['slug'] = $slug;
    $res = wp_insert_term($name, 'product_cat', $args);
    $existed = false;
    if (is_wp_error($res)) {
        if ($slug !== '') { $t = get_term_by('slug', $slug, 'product_cat'); if ($t && !is_wp_error($t)) { $existed = true; return (int) $t->term_id; } }
        $hit = get_terms($find);
        if (!is_wp_error($hit) && !empty($hit)) { $existed = true; return (int) $hit[0]; }
        $data = $res->get_error_data();
        return is_array($data) && !empty($data['term_id']) ? (int) $data['term_id'] : 0;
    }
    return (int) $res['term_id'];
}

/** Normalize an attribute name into the slug used as the WC attribute key. */
function ojf_attr_slug($name) {
    return sanitize_title(strtoupper((string) $name));
}

/** Build manual WC_Product_Attribute[] from the payload attributes[]. */
function ojf_build_manual_attributes($attributes) {
    $out = [];
    if (!is_array($attributes)) return $out;
    foreach ($attributes as $attr) {
        $name = (string) ($attr['name'] ?? '');
        $options = $attr['options'] ?? [];
        if ($name === '' || empty($options)) continue;
        $values = array_values(array_filter(array_map(function ($v) {
            return is_array($v) ? null : trim((string) $v);
        }, (array) $options), function ($v) { return $v !== null && $v !== ''; }));
        if (empty($values)) continue;

        $a = new WC_Product_Attribute();
        $a->set_id(0); // 0 = custom/manual attribute (NOT a global pa_* taxonomy)
        $a->set_name(ojf_attr_slug($name));
        $a->set_options($values);
        $a->set_visible(isset($attr['visible']) ? (bool) $attr['visible'] : true);
        $a->set_variation(isset($attr['variation']) ? (bool) $attr['variation'] : false);
        $out[] = $a;
    }
    return $out;
}

/** Apply the common (non-variation) product fields shared by create/update. */
function ojf_apply_product_fields($product, $data) {
    if (isset($data['name']))              $product->set_name((string) $data['name']);
    if (isset($data['description']))       $product->set_description((string) $data['description']);
    if (isset($data['short_description'])) $product->set_short_description((string) $data['short_description']);
    if (isset($data['slug']) && $data['slug']) $product->set_slug(sanitize_title((string) $data['slug']));

    // status: SEMPRE publicado (regra do cliente). Produto cadastrado já entra
    // ativo na loja, sem revisão em rascunho.
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');

    $is_variable = ($product instanceof WC_Product_Variable);
    if (!$is_variable) {
        if (isset($data['regular_price'])) $product->set_regular_price((string) $data['regular_price']);
        if (isset($data['sale_price']) && $data['sale_price'] !== '') $product->set_sale_price((string) $data['sale_price']);
        if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $data['stock_quantity']);
        }
        if (!empty($data['stock_status'])) $product->set_stock_status((string) $data['stock_status']);
        if (!empty($data['weight'])) $product->set_weight((string) $data['weight']);
    }

    if (!empty($data['dimensions']) && is_array($data['dimensions'])) {
        $d = $data['dimensions'];
        if (!empty($d['length'])) $product->set_length((string) $d['length']);
        if (!empty($d['width']))  $product->set_width((string) $d['width']);
        if (!empty($d['height'])) $product->set_height((string) $d['height']);
    }

    // categories — HIERÁRQUICAS: o array vem como breadcrumb [raiz, ..., folha].
    // Cada nível é criado como FILHO do anterior (Dentística e Estética → Adesivo),
    // não flat. O produto é atribuído a todo o caminho.
    $ids = [];
    if (!empty($data['categories']) && is_array($data['categories'])) {
        $parent = 0;
        foreach ($data['categories'] as $cat) {
            $cname = is_array($cat) ? ($cat['name'] ?? '') : (string) $cat;
            $cid = ojf_get_or_create_category_id($cname, $parent);
            if ($cid) { $ids[] = $cid; $parent = $cid; }
        }
    }
    // Categorias vêm SÓ do breadcrumb que o worker manda. (Orçamento NÃO é mais
    // adicionado em todo produto — agora é só nos que vierem na categoria certa,
    // ex: OLSEN/Cadeira Odontológica. Quem decide é o worker, pela categoria.)
    if ($ids) $product->set_category_ids(array_values(array_unique($ids)));

    // manual attributes
    $attrs = ojf_build_manual_attributes($data['attributes'] ?? []);
    if ($attrs) $product->set_attributes($attrs);

    // generic meta_data loop (preserves _odontojf_*)
    if (!empty($data['meta_data']) && is_array($data['meta_data'])) {
        foreach ($data['meta_data'] as $m) {
            if (!isset($m['key'])) continue;
            $product->update_meta_data((string) $m['key'], $m['value'] ?? '');
        }
    }

    // single-tenant owner tag — mantém o ownership-check do interceptor da
    // api-queue (update/delete) passando para o seller fixo 'odontojf'.
    $product->update_meta_data('_seller', 'odontojf');

    // código ERP do produto (p/ a consulta de preço/estoque no carrinho).
    // Em simples = o próprio _sku; no pai variável é só referência (o carrinho
    // usa o _ojf_erp_code de cada variação).
    if (!empty($data['sku'])) $product->update_meta_data('_ojf_erp_code', (string) $data['sku']);
}

/** Attach images: each src → ojf_upload_image → attachment id; first = featured. */
function ojf_apply_images($product_id, $data) {
    if (empty($data['images']) || !is_array($data['images'])) return;
    $ids = [];
    foreach ($data['images'] as $img) {
        $src = is_array($img) ? ($img['src'] ?? '') : (string) $img;
        if ($src === '') continue;
        $aid = ojf_upload_image($src, $product_id);
        if ($aid) $ids[] = $aid;
    }
    if (!$ids) return;
    $product = wc_get_product($product_id);
    if (!$product) return;
    $product->set_image_id($ids[0]);
    if (count($ids) > 1) $product->set_gallery_image_ids(array_slice($ids, 1));
    $product->save();
}

/** Quem segura este _sku hoje. @return array<int,object{ID,post_type,post_parent,parent_status}> */
function ojf_sku_holders($sku) {
    global $wpdb;
    $sku = (string) $sku;
    if ($sku === '') return [];
    return (array) $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_type, p.post_parent, p.post_status,
                (SELECT pp.post_status FROM {$wpdb->posts} pp WHERE pp.ID = p.post_parent) AS parent_status
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
         WHERE m.meta_value = %s AND p.post_status != 'trash'", $sku
    ));
}

/**
 * O código já é de algo VIVO que não é uma variação deste pai? (>= 1.0.56)
 *
 * A origem reaproveita o mesmo código de item em kits e promoções — `resina-filtek-
 * z350-xt` e `resina-filtek-z350-xt-4g-promo-sof-lex` dividem 10 códigos. O Woo exige
 * `_sku` único, então quem empurrasse por último levava a variação do outro: os dois
 * produtos se revezavam para sempre. Aqui isso vira sufixo, não roubo.
 */
function ojf_sku_taken_by_other($sku, $parent_id, $absorb_from = 0) {
    foreach (ojf_sku_holders($sku) as $r) {
        $pid = (int) $r->ID;
        if ($r->post_type === 'product_variation') {
            if ((int) $r->post_parent === (int) $parent_id) continue;      // é nossa
            if ($absorb_from && (int) $r->post_parent === (int) $absorb_from) continue; // do gêmeo sendo absorvido
            if ($r->parent_status === 'publish') return $pid;              // de outro pai vivo
            continue;                                                       // órfã → liberável
        }
        if ($r->post_status === 'publish') return $pid;                     // produto vivo
    }
    return 0;
}

/** Variação DESTE pai que já carrega o código (por _ojf_erp_code ou _sku). */
function ojf_find_own_variation($parent_id, $erp_code, $vsku) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE p.post_parent = %d AND p.post_type = 'product_variation'
           AND p.post_status != 'trash'
           AND ((m.meta_key = '_ojf_erp_code' AND m.meta_value = %s)
             OR (m.meta_key = '_sku'          AND m.meta_value = %s))
         ORDER BY p.ID ASC LIMIT 1", (int) $parent_id, (string) $erp_code, (string) $vsku
    ));
}

/**
 * Solta um _sku preso em ÓRFÃO (variação sem pai publicado, produto em rascunho).
 * Ao contrário do ojf_free_sku_global(), nunca mexe em variação de pai publicado
 * nem apaga produto publicado — esses casos já viraram sufixo antes de chegar aqui.
 */
function ojf_free_orphan_sku($sku, $keep_id = 0) {
    $keep_id = (int) $keep_id;
    foreach (ojf_sku_holders($sku) as $r) {
        $pid = (int) $r->ID;
        if ($pid === $keep_id) continue;
        if ($r->post_type === 'product_variation') {
            if ($r->parent_status === 'publish') continue;
            $v = wc_get_product($pid);
            if ($v) { $v->set_sku($sku . '-v' . $pid); $v->save(); }
        } else {
            if ($r->post_status === 'publish') continue;
            wp_delete_post($pid, true); // rascunho/lixo de tentativa anterior
        }
        clean_post_cache($pid);
    }
    if (class_exists('WC_Cache_Helper')) WC_Cache_Helper::invalidate_cache_group('products');
}

/** Create/update variations by _sku for a variable parent. */
function ojf_sync_variations($parent_id, $variations, $absorb_from = 0) {
    if (!is_array($variations)) return 0;
    $parent = wc_get_product($parent_id);
    $parent_sku = $parent ? (string) $parent->get_sku() : '';
    $n = 0;
    $desired_skus = []; // PILAR C: _sku finais das variações que devem existir
    $ck_map    = [];    // CommerceKit: [ sanitize_title(valor) => "ids csv" ]
    $axis_slugs = [];   // todos os valores do eixo vistos neste push
    foreach ($variations as $var) {
        $erp_code = (string) ($var['sku'] ?? '');   // código ERP real da variação
        if ($erp_code === '') continue;

        // _sku da variação = código ERP. Se colidir com o _sku do PAI (o Woo
        // exige unicidade global), sufixa o _sku da variação — mas o código ERP
        // real fica em meta _ojf_erp_code (usado pelo carrinho p/ preço/estoque).
        $vsku = $erp_code;
        if ($vsku === $parent_sku) {
            $label = '';
            if (!empty($var['attributes'][0]['option'])) $label = sanitize_title((string) $var['attributes'][0]['option']);
            $vsku = $erp_code . '-' . ($label !== '' ? $label : 'v');
        }

        // 1) já temos uma variação com esse código? (casa por _ojf_erp_code, então
        //    sobrevive ao sufixo e não recria a variação a cada push)
        $existing_id = ojf_find_own_variation($parent_id, $erp_code, $vsku);

        // 2) o código está preso em algo vivo de outro produto → sufixa em vez de
        //    roubar. O código real continua em _ojf_erp_code (é o que o carrinho usa).
        if (!$existing_id && ojf_sku_taken_by_other($vsku, $parent_id, $absorb_from)) {
            $vsku = $erp_code . '-p' . (int) $parent_id;
            $existing_id = ojf_find_own_variation($parent_id, $erp_code, $vsku);
        }

        // 3) senão, reusa a variação solta que já carrega esse _sku.
        if (!$existing_id) {
            $found = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($vsku) : 0;
            if ($found && get_post_type($found) === 'product_variation') $existing_id = $found;
        }

        $variation = $existing_id ? new WC_Product_Variation($existing_id) : new WC_Product_Variation();
        $variation->set_parent_id($parent_id);
        // Libera o SKU só de ÓRFÃOS antes de reivindicar → resolve "SKU inválido ou
        // duplicado" no retry sem tirar a variação de um pai publicado.
        ojf_free_orphan_sku($vsku, $existing_id);
        $variation->set_sku($vsku);
        $variation->update_meta_data('_ojf_erp_code', $erp_code); // código ERP p/ o carrinho
        $variation->set_status('publish');
        if (isset($var['regular_price'])) $variation->set_regular_price((string) $var['regular_price']);
        if (isset($var['sale_price']) && $var['sale_price'] !== '') $variation->set_sale_price((string) $var['sale_price']);
        if (array_key_exists('stock_quantity', $var) && $var['stock_quantity'] !== null) {
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity((int) $var['stock_quantity']);
        }
        // Título e descrição próprios da variação (>= 1.0.36). Na origem cada
        // variação é um produto com página, título e descrição próprios — o
        // `name` do payload é o título completo ("Fórceps Adulto N°150"), não
        // o rótulo do seletor (esse vem em attributes[].option).
        if (!empty($var['name'])) $variation->set_name((string) $var['name']);
        if (isset($var['description'])) $variation->set_description((string) $var['description']);
        if (!empty($var['weight'])) $variation->set_weight((string) $var['weight']);
        if (!empty($var['dimensions']) && is_array($var['dimensions'])) {
            $d = $var['dimensions'];
            if (!empty($d['length'])) $variation->set_length((string) $d['length']);
            if (!empty($d['width']))  $variation->set_width((string) $d['width']);
            if (!empty($d['height'])) $variation->set_height((string) $d['height']);
        }
        // attributes: key MUST equal the parent's manual attribute slug
        if (!empty($var['attributes']) && is_array($var['attributes'])) {
            $va = [];
            foreach ($var['attributes'] as $a) {
                $aname = (string) ($a['name'] ?? '');
                $opt   = (string) ($a['option'] ?? '');
                if ($aname === '' || $opt === '') continue;
                $va[ojf_attr_slug($aname)] = $opt;
            }
            if ($va) $variation->set_attributes($va);
        }
        if (!empty($var['meta_data']) && is_array($var['meta_data'])) {
            foreach ($var['meta_data'] as $m) {
                if (isset($m['key'])) $variation->update_meta_data((string) $m['key'], $m['value'] ?? '');
            }
        }
        $vid = $variation->save();

        // Galeria da variação (>= 1.0.36). Aceita `images[]` (origem completa) e
        // mantém `image` (singular) para compatibilidade. A 1ª vira a thumbnail
        // nativa; as demais ficam em _odontojf_variation_gallery.
        //
        // ATENÇÃO: qualquer anexo gravado aqui PRECISA ser visível para
        // ojf_collect_product_attachment_ids(), senão o PILAR B apaga tudo no
        // próximo update (e o hook delete_attachment leva junto o objeto no R2).
        $gallery_srcs = [];
        if (!empty($var['images']) && is_array($var['images'])) {
            foreach ($var['images'] as $img) {
                $src = is_array($img) ? ($img['src'] ?? '') : (string) $img;
                if ($src !== '') $gallery_srcs[] = $src;
            }
        }
        if (empty($gallery_srcs) && !empty($var['image']['src'])) {
            $gallery_srcs[] = (string) $var['image']['src'];
        }

        if ($gallery_srcs) {
            $aids = [];
            foreach ($gallery_srcs as $src) {
                $aid = ojf_upload_image($src, $parent_id);
                if ($aid) $aids[] = (int) $aid;
            }
            if ($aids) {
                // CommerceKit renderiza a galeria da PDP nesta loja e lê o meta
                // commercekit_image_gallery do PAI, indexado pelo valor da
                // variação. Só faz sentido com UM atributo (chave de 1 segmento).
                if (!empty($var['attributes']) && count($var['attributes']) === 1
                    && !empty($var['attributes'][0]['option'])) {
                    $ck_slug = sanitize_title((string) $var['attributes'][0]['option']);
                    if ($ck_slug !== '') $ck_map[$ck_slug] = implode(',', $aids);
                }
                $variation->set_image_id($aids[0]);
                $rest = array_slice($aids, 1);
                if ($rest) {
                    $variation->update_meta_data('_odontojf_variation_gallery', implode(',', $rest));
                } else {
                    $variation->delete_meta_data('_odontojf_variation_gallery');
                }
                $variation->save();
            }
        }

        if (!empty($var['attributes']) && count($var['attributes']) === 1
            && !empty($var['attributes'][0]['option'])) {
            $as = sanitize_title((string) $var['attributes'][0]['option']);
            if ($as !== '') $axis_slugs[] = $as;
        }

        if ($vid) { $n++; $desired_skus[] = $vsku; }
    }

    if (function_exists('ojf_sync_commercekit_gallery')) {
        ojf_sync_commercekit_gallery($parent_id, $ck_map, array_values(array_unique($axis_slugs)));
    }

    // PILAR C (anti-órfão): remove variações que NÃO existem mais no payload.
    // O delete da variação dispara o hook que limpa a imagem dela no R2.
    if ($parent && $parent->is_type('variable')) {
        foreach ($parent->get_children() as $cid) {
            $c = wc_get_product($cid);
            if ($c && !in_array((string) $c->get_sku(), $desired_skus, true)) {
                $c->delete(true);
            }
        }
    }
    return $n;
}

/* ── handlers (return WP_REST_Response or WP_Error) ───────────────────────── */

function ojf_create_product_handler($request) {
    $t0 = microtime(true);
    $data = $request->get_json_params();
    if (!is_array($data) || empty($data['sku']) || empty($data['name']) || empty($data['type'])) {
        return new WP_Error('bad_request', 'sku, name e type são obrigatórios', ['status' => 400]);
    }

    $sku = (string) $data['sku'];
    $existing = ojf_find_product_id_by_sku($sku);
    $is_variable = ($data['type'] === 'variable');

    // GUARDA ANTI-DUPLICAÇÃO (>= 1.0.56): o _sku do pai não bateu. Antes de criar
    // um produto novo, confere se ele já existe com OUTRO _sku (slug da origem /
    // dono das variações). Sem isso o create rouba as variações do original.
    $adopted_from = '';
    $twin_id      = 0;
    $resolved = ojf_resolve_target_product($data, $is_variable, $existing);
    if (is_wp_error($resolved)) return $resolved;
    $twin_id = (int) $resolved['twin'];
    if ((int) $resolved['id'] && (int) $resolved['id'] !== (int) $existing) {
        $existing     = (int) $resolved['id'];
        $adopted_from = (string) get_post_meta($existing, '_sku', true);
    }

    try {
        // PILAR B (anti-órfão): no UPDATE, guarda os anexos atuais ANTES de
        // trocar as imagens, pra deletar depois os que saírem (galeria/variação
        // que mudou). Limpa o R2 via hook delete_attachment.
        $old_att_ids = $existing ? ojf_collect_product_attachment_ids(wc_get_product($existing)) : [];
        // preço antigo (simples) para registrar mudança no histórico via sync.
        $old_price = ($existing && !$is_variable && ($ep = wc_get_product($existing))) ? $ep->get_regular_price() : null;

        if ($existing) {
            $product = $is_variable ? new WC_Product_Variable($existing) : wc_get_product($existing);
            if (!$product) $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
            // Adotado por slug/variações: re-chaveia o _sku no produto que já existe,
            // em vez de deixar um segundo produto nascer com o SKU novo.
            if ($adopted_from !== '' && $adopted_from !== $sku) {
                ojf_release_sku_from_product($sku, $existing);
                $product->set_sku($sku);
                $product->update_meta_data('_ojf_previous_sku', $adopted_from);
                error_log(sprintf('[ojf] adoção: produto #%d re-chaveado %s -> %s', $existing, $adopted_from, $sku));
            }
        } else {
            // Guarda anti-duplicação (>= 1.0.36): se o SKU já é de uma variação
            // de um produto PUBLICADO, este payload é um `familyProduct` da
            // origem (a variação virando produto solto). Recusa em vez de deixar
            // o ojf_free_sku_global() re-sufixar a variação legítima.
            if (!$is_variable && ($owner = ojf_variation_owner_of_sku($sku))) {
                return new WP_Error('sku_belongs_to_variation', sprintf(
                    'SKU %s já é da variação #%d do produto #%d (%s). Recusado: criar um produto solto com este SKU renomearia a variação e sumiria com o código da loja.',
                    $sku, $owner['variation_id'], $owner['parent_id'], $owner['parent_sku'] !== '' ? $owner['parent_sku'] : 's/ sku'
                ), ['status' => 409]);
            }
            $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
            // Libera o SKU de variações órfãs antes do pai reivindicar (evita
            // "SKU inválido ou duplicado" quando uma variação cru segurava o código).
            ojf_free_sku_global($sku, $existing ?: 0);
            $product->set_sku($sku);
        }
        ojf_apply_product_fields($product, $data);
        $product_id = $product->save();
        if (!$product_id) throw new Exception('save() retornou 0');

        $vcount = 0;
        if ($is_variable) {
            $vcount = ojf_sync_variations($product_id, $data['variations'] ?? [], $twin_id);
            // recompute price range / sync
            if (class_exists('WC_Product_Variable')) WC_Product_Variable::sync($product_id);
        }

        // O sync já reparentou as variações do payload para o canônico (o Woo casa
        // por SKU e reescreve o post_parent). Só agora o gêmeo pode sair do ar.
        $twin_note = $twin_id ? ojf_absorb_duplicate($twin_id, (int) $product_id) : '';
        ojf_apply_images($product_id, $data);

        // IMAGENS DO CORPO (post_content) → R2 da loja. Precisa do product_id, então
        // roda APÓS o save. Baixa cada <img> externa (media.odontoapi etc.), sobe pro
        // R2 e reescreve o src → resolve as imagens quebradas (hotlink/erro 1011).
        if (isset($data['description']) && function_exists('ojf_process_body_images')) {
            $orig_desc  = (string) $data['description'];
            $fixed_desc = ojf_process_body_images($orig_desc, $product_id);
            if ($fixed_desc !== $orig_desc) {
                $pp = wc_get_product($product_id);
                if ($pp) { $pp->set_description($fixed_desc); $pp->save(); }
            }
        }

        // registra mudança de preço (simples) no histórico, se mudou no update.
        if (!$is_variable && $old_price !== null && $old_price !== '' && isset($data['regular_price'])
            && function_exists('ojf_log_price_change_sync')) {
            ojf_log_price_change_sync($product_id, (float) $old_price, (float) $data['regular_price']);
        }

        // PILAR B: deleta os anexos antigos que não fazem mais parte do produto
        // (somente os NOSSOS — com object_key R2 — pra não tocar mídia de terceiros).
        if ($old_att_ids) {
            $new_att_ids = ojf_collect_product_attachment_ids(wc_get_product($product_id));
            foreach (array_diff($old_att_ids, $new_att_ids) as $aid) {
                if (get_post_meta($aid, '_ojf_r2_object_key', true)) {
                    wp_delete_attachment((int) $aid, true); // → hook limpa o R2
                }
            }
        }

        return new WP_REST_Response([
            'success'            => true,
            'product_id'         => (int) $product_id,
            'sku'                => $sku,
            'created'            => !$existing,
            'adopted_from_sku'   => $adopted_from !== '' && $adopted_from !== $sku ? $adopted_from : null,
            'matched_by'         => (string) $resolved['via'],
            'duplicate_absorbed' => $twin_note !== '' ? $twin_note : null,
            'variations_created' => $vcount,
            'execution_time_ms'  => (int) round((microtime(true) - $t0) * 1000),
            'api_version'        => OJF_BRIDGE_VERSION,
        ], 200);
    } catch (Throwable $e) {
        return new WP_Error('create_failed', $e->getMessage(), ['status' => 500]);
    }
}

function ojf_update_product_handler($request) {
    // Upsert semantics → identical to create (find-by-_sku internally).
    return ojf_create_product_handler($request);
}

function ojf_delete_product_handler($request) {
    $t0 = microtime(true);
    $data = $request->get_json_params();
    $sku = (string) ($data['sku'] ?? '');
    if ($sku === '') return new WP_Error('bad_request', 'sku obrigatório', ['status' => 400]);
    $id = ojf_find_product_id_by_sku($sku);
    if (!$id) return new WP_Error('not_found', "produto _sku={$sku} não existe", ['status' => 404]);
    $product = wc_get_product($id);
    if ($product) {
        foreach ($product->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if ($child) $child->delete(true);
        }
        $product->delete(true);
    }
    return new WP_REST_Response([
        'success'           => true,
        'deleted_id'        => (int) $id,
        'sku'               => $sku,
        'execution_time_ms' => (int) round((microtime(true) - $t0) * 1000),
        'api_version'       => OJF_BRIDGE_VERSION,
    ], 200);
}

/**
 * UPDATE PRICE (price/stock only) — SÍNCRONO e rápido (~ms).
 *
 * NÃO passa pela api-queue (rota não interceptada), NÃO exige name/type,
 * NÃO toca em categorias/imagens/descrição e NÃO apaga variações.
 * Payload:
 *   simples:  { "sku":"X", "regular_price":"79.00", "sale_price":"59.00" }
 *   variável: { "sku":"X", "type":"variable", "variations":[
 *                {"sku":"X-L","regular_price":"79.00","sale_price":null,"stock_quantity":8}, ... ] }
 * sale_price null/"" => limpa a oferta. Variações casadas por _sku OU _ojf_erp_code.
 */
function ojf_update_price_handler($request) {
    $t0 = microtime(true);
    $data = $request->get_json_params();
    if (!is_array($data) || empty($data['sku'])) {
        return new WP_Error('bad_request', 'sku obrigatório', ['status' => 400]);
    }
    $sku = (string) $data['sku'];
    $product_id = ojf_find_product_id_by_sku($sku);
    if (!$product_id) return new WP_Error('not_found', "produto _sku={$sku} não existe", ['status' => 404]);
    $product = wc_get_product($product_id);
    if (!$product) return new WP_Error('not_found', "produto inválido id={$product_id}", ['status' => 404]);

    $set_price = function ($obj, $arr) {
        if (array_key_exists('regular_price', $arr) && $arr['regular_price'] !== null && $arr['regular_price'] !== '') {
            $obj->set_regular_price((string) $arr['regular_price']);
        }
        if (array_key_exists('sale_price', $arr)) {
            $sp = $arr['sale_price'];
            $obj->set_sale_price(($sp === null || $sp === '') ? '' : (string) $sp);
        }
        if (array_key_exists('stock_quantity', $arr) && $arr['stock_quantity'] !== null) {
            $obj->set_manage_stock(true);
            $obj->set_stock_quantity((int) $arr['stock_quantity']);
        }
    };

    $is_variable = $product->is_type('variable');
    $updated_variations = 0;
    $missing = [];

    try {
        if (!$is_variable) {
            $set_price($product, $data);
            $product->save();
        } else {
            // mapa _sku E _ojf_erp_code => variation_id (casa qualquer um dos dois)
            $by_key = [];
            foreach ($product->get_children() as $cid) {
                $c = wc_get_product($cid);
                if (!$c) continue;
                $vs = (string) $c->get_sku();
                if ($vs !== '') $by_key[$vs] = $cid;
                $erp = (string) $c->get_meta('_ojf_erp_code');
                if ($erp !== '') $by_key[$erp] = $cid;
            }
            $variations = (isset($data['variations']) && is_array($data['variations'])) ? $data['variations'] : [];
            foreach ($variations as $var) {
                $vkey = (string) ($var['sku'] ?? '');
                if ($vkey === '' || !isset($by_key[$vkey])) { if ($vkey !== '') $missing[] = $vkey; continue; }
                $v = wc_get_product($by_key[$vkey]);
                if (!$v) { $missing[] = $vkey; continue; }
                $set_price($v, $var);
                $v->save();
                $updated_variations++;
            }
            // preço do pai (alguns temas leem) + recomputa faixa
            if (array_key_exists('regular_price', $data) || array_key_exists('sale_price', $data)) {
                $set_price($product, $data);
                $product->save();
            }
            if (class_exists('WC_Product_Variable')) WC_Product_Variable::sync($product_id);
        }
    } catch (Throwable $e) {
        return new WP_Error('update_price_failed', $e->getMessage(), ['status' => 500]);
    }

    if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($product_id);

    return new WP_REST_Response([
        'success'            => true,
        'product_id'         => (int) $product_id,
        'sku'                => $sku,
        'type'               => $is_variable ? 'variable' : 'simple',
        'updated_variations' => $updated_variations,
        'missing_variations' => $missing,
        'execution_time_ms'  => (int) round((microtime(true) - $t0) * 1000),
        'api_version'        => OJF_BRIDGE_VERSION,
    ], 200);
}
