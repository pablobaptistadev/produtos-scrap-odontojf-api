<?php
namespace ListasEstudantes\Domain;

if (!defined('ABSPATH')) exit;

/**
 * Busca de produtos do admin: por SKU (com regra OD-/variação), por ID
 * numérico e por nome (LIKE no título).
 *
 * Retorna sempre IDs de produtos pai/simples publicados, sem duplicatas,
 * com o match de SKU em primeiro na ordenação.
 */
final class ProductSearchService {

    /** @var SkuResolver */
    private $resolver;

    /** @var \wpdb */
    private $db;

    public function __construct(SkuResolver $resolver, $wpdb) {
        $this->resolver = $resolver;
        $this->db = $wpdb;
    }

    /**
     * @param string $term
     * @param int $limit
     * @param int $exclude_id ID de produto a excluir dos resultados
     * @return int[]
     */
    /**
     * Busca que devolve ITENS, não só produtos pai (>= 2.2.0).
     *
     * O professor cola o código da variação ("3200") esperando ver
     * "Fórceps Adulto - N° 17". Resolver para o pai e mostrar "Fórceps Adulto"
     * com a faixa de preço dele é justamente o que fazia entrar o fórceps errado
     * na lista. Quando o código bate numa variação, o resultado É a variação.
     *
     * Aceita vários códigos de uma vez, separados por vírgula, ponto-e-vírgula
     * ou quebra de linha — o mesmo formato do campo de importação em massa.
     *
     * @param string $term
     * @param int $limit
     * @param int $exclude_id
     * @return array[] lista de {product_id, variation_id}
     */
    public function search($term, $limit = 50, $exclude_id = 0) {
        $term = trim((string) $term);
        $exclude_id = (int) $exclude_id;
        if ($term === '') return array();

        $partes = preg_split('/[,;\r\n]+/', $term);
        $partes = array_values(array_filter(array_map('trim', (array) $partes), 'strlen'));
        if (empty($partes)) return array();

        $itens = array();
        $vistos = array();

        $push = function ($product_id, $variation_id) use (&$itens, &$vistos, $exclude_id) {
            $product_id = (int) $product_id;
            $variation_id = (int) $variation_id;
            if (!$product_id || $product_id === $exclude_id) return;
            $chave = $product_id . ':' . $variation_id;
            if (isset($vistos[$chave])) return;
            $vistos[$chave] = true;
            $itens[] = array('product_id' => $product_id, 'variation_id' => $variation_id);
        };

        foreach ($partes as $parte) {
            // 1) SKU exato (regra OD- + variação). A variação vem como ela mesma.
            $match = $this->resolver->resolve($parte);
            if ($match) {
                // O PAI vem sempre primeiro, mesmo quando o código digitado é o
                // de uma variação: a tela mostra a hierarquia (pai e, abaixo, as
                // variações dele) em vez de uma linha solta sem contexto.
                $push($match['product_id'], 0);
                $push($match['product_id'], $match['variation_id']);
                // Casou o PAI de um variável: lista também as variações dele, para
                // dar para escolher sem precisar saber o código de cada uma. O pai
                // continua na lista de resultados — dá para adicionar os dois.
                if (!$match['variation_id'] && count($partes) === 1) {
                    foreach ($this->variationIds($match['product_id']) as $vid) {
                        $push($match['product_id'], $vid);
                    }
                }
                continue;
            }

            // 2) ID numérico de post (produto ou variação)
            if (ctype_digit($parte)) {
                $post_id = (int) $parte;
                if ($post_id && get_post_status($post_id) === 'publish') {
                    $tipo = get_post_type($post_id);
                    if ($tipo === 'product') {
                        $push($post_id, 0);
                        continue;
                    }
                    if ($tipo === 'product_variation') {
                        $pai = (int) wp_get_post_parent_id($post_id);
                        if ($pai && get_post_type($pai) === 'product' && get_post_status($pai) === 'publish') {
                            $push($pai, $post_id);
                            continue;
                        }
                    }
                }
            }

            // 3) Nome. Só vale quando é uma busca só — colando uma lista de
            //    códigos, um LIKE por texto só traria ruído.
            if (count($partes) === 1) {
                foreach ($this->titleIds($parte, $limit, $exclude_id) as $id) {
                    $push($id, 0);
                }
            }
        }

        return array_slice($itens, 0, $limit);
    }

    /** Variações publicadas de um pai variável, na ordem do menu. @return int[] */
    private function variationIds($product_id, $limit = 30) {
        $produto = wc_get_product($product_id);
        if (!$produto || !$produto->is_type('variable')) return array();

        $ids = array_map('intval', (array) $produto->get_children());
        $vivas = array();
        foreach ($ids as $id) {
            if (get_post_status($id) === 'publish') $vivas[] = $id;
            if (count($vivas) >= $limit) break;
        }
        return $vivas;
    }

    /** IDs de produto pai cujo título casa com o termo. @return int[] */
    private function titleIds($term, $limit, $exclude_id) {
        $like = '%' . $this->db->esc_like($term) . '%';

        if ($exclude_id) {
            return array_map('intval', (array) $this->db->get_col($this->db->prepare(
                "SELECT ID FROM {$this->db->posts}
                 WHERE post_type = 'product' AND post_status = 'publish'
                   AND ID != %d AND post_title LIKE %s
                 ORDER BY post_title ASC LIMIT %d",
                $exclude_id, $like, $limit
            )));
        }

        return array_map('intval', (array) $this->db->get_col($this->db->prepare(
            "SELECT ID FROM {$this->db->posts}
             WHERE post_type = 'product' AND post_status = 'publish'
               AND post_title LIKE %s
             ORDER BY post_title ASC LIMIT %d",
            $like, $limit
        )));
    }

    public function searchIds($term, $limit = 50, $exclude_id = 0) {
        $term = trim((string) $term);
        $exclude_id = (int) $exclude_id;

        if ($term === '') {
            return array();
        }

        $ids = array();

        // 1) SKU exato (regra OD- + variação -> pai) tem prioridade máxima
        $sku_hit = $this->resolver->resolveProductId($term);
        if ($sku_hit && $sku_hit !== $exclude_id) {
            $ids[] = $sku_hit;
        }

        // 2) Busca por ID numérico (variação também resolve para o pai)
        if (ctype_digit($term)) {
            $post_id = (int) $term;
            if ($post_id && get_post_status($post_id) === 'publish') {
                $post_type = get_post_type($post_id);

                if ($post_type === 'product' && $post_id !== $exclude_id) {
                    $ids[] = $post_id;
                } elseif ($post_type === 'product_variation') {
                    $parent_id = (int) wp_get_post_parent_id($post_id);
                    if ($parent_id
                        && $parent_id !== $exclude_id
                        && get_post_type($parent_id) === 'product'
                        && get_post_status($parent_id) === 'publish') {
                        $ids[] = $parent_id;
                    }
                }
            }
        }

        // 3) Busca por nome (título)
        $like = '%' . $this->db->esc_like($term) . '%';

        if ($exclude_id) {
            $title_ids = $this->db->get_col($this->db->prepare(
                "SELECT ID FROM {$this->db->posts}
                 WHERE post_type = 'product'
                 AND post_status = 'publish'
                 AND ID != %d
                 AND post_title LIKE %s
                 ORDER BY post_title ASC
                 LIMIT %d",
                $exclude_id,
                $like,
                $limit
            ));
        } else {
            $title_ids = $this->db->get_col($this->db->prepare(
                "SELECT ID FROM {$this->db->posts}
                 WHERE post_type = 'product'
                 AND post_status = 'publish'
                 AND post_title LIKE %s
                 ORDER BY post_title ASC
                 LIMIT %d",
                $like,
                $limit
            ));
        }

        foreach ($title_ids as $id) {
            $ids[] = (int) $id;
        }

        return array_slice(array_values(array_unique($ids)), 0, $limit);
    }
}
