<?php
namespace ListasEstudantes\Repository;

if (!defined('ABSPATH')) exit;

/**
 * Acesso à tabela {prefix}listas_produtos_similares.
 * Queries diretas para alta performance com 60k+ produtos.
 */
final class SimilaresRepository {

    /** @var \wpdb */
    private $db;

    /** @var string */
    private $table;

    public function __construct($wpdb) {
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'listas_produtos_similares';
    }

    /**
     * IDs dos similares de um produto, na ordem salva.
     *
     * @return int[]
     */
    public function getSimilarIds($product_id, $limit = 0) {
        $sql = "SELECT similar_product_id FROM {$this->table}
                WHERE product_id = %d
                ORDER BY position ASC, id ASC";
        $params = array($product_id);

        if ($limit > 0) {
            $sql .= " LIMIT %d";
            $params[] = $limit;
        }

        $ids = $this->db->get_col($this->db->prepare($sql, $params));

        return array_map('intval', $ids);
    }

    /**
     * Contagem de similares por produto, em uma única query.
     *
     * @param int[] $product_ids
     * @return array map product_id => count
     */
    public function countForProducts(array $product_ids) {
        $counts = array();

        if (empty($product_ids)) {
            return $counts;
        }

        $ids_placeholder = implode(',', array_map('intval', $product_ids));
        $rows = $this->db->get_results(
            "SELECT product_id, COUNT(*) as count FROM {$this->table}
             WHERE product_id IN ($ids_placeholder)
             GROUP BY product_id",
            OBJECT_K
        );

        foreach ($rows as $product_id => $row) {
            $counts[$product_id] = intval($row->count);
        }

        return $counts;
    }

    public function countFor($product_id) {
        return intval($this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE product_id = %d",
            $product_id
        )));
    }

    public function exists($product_id, $similar_id) {
        return (bool) $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->table} WHERE product_id = %d AND similar_product_id = %d",
            $product_id,
            $similar_id
        ));
    }

    public function nextPosition($product_id) {
        $max_position = $this->db->get_var($this->db->prepare(
            "SELECT MAX(position) FROM {$this->table} WHERE product_id = %d",
            $product_id
        ));

        return ($max_position !== null) ? $max_position + 1 : 0;
    }

    /** @return bool */
    public function insert($product_id, $similar_id, $position) {
        return (bool) $this->db->insert(
            $this->table,
            array(
                'product_id' => $product_id,
                'similar_product_id' => $similar_id,
                'position' => $position
            ),
            array('%d', '%d', '%d')
        );
    }

    /** @return int|false linhas removidas */
    public function delete($product_id, $similar_id) {
        return $this->db->delete(
            $this->table,
            array(
                'product_id' => $product_id,
                'similar_product_id' => $similar_id
            ),
            array('%d', '%d')
        );
    }

    public function updatePosition($product_id, $similar_id, $position) {
        $this->db->update(
            $this->table,
            array('position' => $position),
            array(
                'product_id' => $product_id,
                'similar_product_id' => $similar_id
            ),
            array('%d'),
            array('%d', '%d')
        );
    }
}
