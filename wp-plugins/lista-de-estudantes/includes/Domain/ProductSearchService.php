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
