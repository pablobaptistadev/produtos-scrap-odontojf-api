<?php
namespace ListasEstudantes\Repository;

if (!defined('ABSPATH')) exit;

/**
 * Acesso à tabela {prefix}listas_produtos_ordem (ordem dos produtos por categoria/lista).
 *
 * Desde a 2.1.0 cada linha pode fixar uma VARIAÇÃO (variation_id). O parâmetro
 * é opcional e vale 0 em toda chamada antiga, que continua significando
 * "o produto, sem variação fixada".
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
    /**
     * Linhas da lista na ordem salva, com a variação fixada de cada uma.
     * É o que o front precisa para renderizar um card por ITEM e não por
     * produto — duas variações do mesmo pai são dois itens da lista.
     *
     * @return array[] {product_id, variation_id, position}
     */
    public function getOrderedRows($categoria_id) {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT product_id, variation_id, position
               FROM {$this->table}
              WHERE categoria_id = %d
              ORDER BY position ASC, id ASC",
            $categoria_id
        ), ARRAY_A);

        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'product_id'   => (int) $row['product_id'],
                'variation_id' => isset($row['variation_id']) ? (int) $row['variation_id'] : 0,
                'position'     => (int) $row['position'],
            );
        }

        return $out;
    }

    public function getOrderedProductIds($categoria_id) {
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT product_id FROM {$this->table} WHERE categoria_id = %d ORDER BY position ASC",
            $categoria_id
        ));

        return array_map('intval', $ids);
    }

    public function exists($categoria_id, $product_id, $variation_id = 0) {
        return (bool) $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->table}
              WHERE categoria_id = %d AND product_id = %d AND variation_id = %d",
            $categoria_id,
            $product_id,
            (int) $variation_id
        ));
    }

    /** Alguma linha deste produto na lista, com qualquer variação. */
    public function existsAnyVariation($categoria_id, $product_id) {
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

    public function insert($categoria_id, $product_id, $position, $variation_id = 0) {
        $this->db->insert(
            $this->table,
            array(
                'categoria_id' => $categoria_id,
                'product_id' => $product_id,
                'variation_id' => (int) $variation_id,
                'position' => $position
            ),
            array('%d', '%d', '%d', '%d')
        );
    }

    /**
     * Remove o item. Sem $variation_id explícito remove TODAS as variações do
     * produto naquela lista — que é o que o botão "remover" do admin faz hoje,
     * e o que o chamador antigo esperava.
     */
    public function delete($categoria_id, $product_id, $variation_id = null) {
        $where  = array('categoria_id' => $categoria_id, 'product_id' => $product_id);
        $format = array('%d', '%d');

        if ($variation_id !== null) {
            $where['variation_id'] = (int) $variation_id;
            $format[] = '%d';
        }

        $this->db->delete($this->table, $where, $format);
    }

    /**
     * Troca a variação fixada de uma linha existente, preservando a posição.
     * Usado quando o professor cola o código de uma variação de um produto que
     * já estava na lista sem variação escolhida.
     */
    public function setVariation($categoria_id, $product_id, $from_variation_id, $to_variation_id) {
        $this->db->update(
            $this->table,
            array('variation_id' => (int) $to_variation_id),
            array(
                'categoria_id' => $categoria_id,
                'product_id' => $product_id,
                'variation_id' => (int) $from_variation_id,
            ),
            array('%d'),
            array('%d', '%d', '%d')
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
