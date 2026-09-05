<?php
namespace ListasEstudantes\Repository;

if (!defined('ABSPATH')) exit;

/**
 * Acesso à tabela {prefix}listas_produtos_ordem (ordem dos produtos por categoria/lista).
 */
final class OrdemRepository {

    /** @var \wpdb */
    private $db;

    /** @var string */
    private $table;

    public function __construct($wpdb) {
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'listas_produtos_ordem';
    }

    /**
     * Mapa product_id => position de uma categoria.
     *
     * @return array
     */
    public function getPositions($categoria_id) {
        $positions = array();

        $rows = $this->db->get_results($this->db->prepare(
            "SELECT product_id, position FROM {$this->table} WHERE categoria_id = %d",
            $categoria_id
        ), OBJECT_K);

        foreach ($rows as $product_id => $row) {
            $positions[$product_id] = intval($row->position);
        }

        return $positions;
    }

    /**
     * IDs dos produtos de uma categoria, na ordem customizada.
     *
     * @return int[]
     */
    public function getOrderedProductIds($categoria_id) {
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT product_id FROM {$this->table} WHERE categoria_id = %d ORDER BY position ASC",
            $categoria_id
        ));

        return array_map('intval', $ids);
    }

    public function exists($categoria_id, $product_id) {
        return (bool) $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->table} WHERE categoria_id = %d AND product_id = %d",
            $categoria_id,
            $product_id
        ));
    }

    public function nextPosition($categoria_id) {
        $max_position = $this->db->get_var($this->db->prepare(
            "SELECT MAX(position) FROM {$this->table} WHERE categoria_id = %d",
            $categoria_id
        ));

        return ($max_position !== null) ? $max_position + 1 : 0;
    }

    public function insert($categoria_id, $product_id, $position) {
        $this->db->insert(
            $this->table,
            array(
                'categoria_id' => $categoria_id,
                'product_id' => $product_id,
                'position' => $position
            ),
            array('%d', '%d', '%d')
        );
    }

    public function delete($categoria_id, $product_id) {
        $this->db->delete(
            $this->table,
            array(
                'categoria_id' => $categoria_id,
                'product_id' => $product_id
            ),
            array('%d', '%d')
        );
    }

    public function updatePosition($categoria_id, $product_id, $position) {
        $this->db->update(
            $this->table,
            array('position' => $position),
            array('categoria_id' => $categoria_id, 'product_id' => $product_id),
            array('%d'),
            array('%d', '%d')
        );
    }
}
