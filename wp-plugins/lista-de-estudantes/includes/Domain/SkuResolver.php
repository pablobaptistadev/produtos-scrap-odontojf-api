<?php
namespace ListasEstudantes\Domain;

if (!defined('ABSPATH')) exit;

/**
 * Resolve um código digitado/colado para um produto pai publicado.
 *
 * Regras de negócio:
 * - Aceita SKU exato de produto simples/pai.
 * - Aceita SKU de variação (post_type product_variation) e resolve para o
 *   produto PAI — listas funcionam atribuindo o produto pai à categoria.
 * - Regra do prefixo OD-: produtos variáveis têm SKU no formato "OD-20013",
 *   mas o cliente cola apenas "20013". O resolver tenta o código cru E com
 *   o prefixo "OD-".
 *
 * Precedência de match (garantida pelo ORDER BY):
 *   1. SKU cru em produto
 *   2. SKU cru em variação (resolve para o pai)
 *   3. SKU com prefixo OD- em produto
 *   4. SKU com prefixo OD- em variação (resolve para o pai)
 *
 * Variações desabilitadas no WooCommerce têm post_status 'private' e são
 * excluídas de propósito; variações órfãs ou de pai não publicado também.
 */
final class SkuResolver {

    const PREFIX = 'OD-';

    /** @var \wpdb */
    private $db;

    public function __construct($wpdb) {
        $this->db = $wpdb;
    }

    /**
     * @param string $raw_sku
     * @return int|null ID do produto pai/simples publicado
     */
    public function resolveProductId($raw_sku) {
        $match = $this->resolve($raw_sku);
        return $match ? $match['product_id'] : null;
    }

    /**
     * @param string $raw_sku
     * @return array|null {product_id, variation_id (0 se match direto), matched_sku, matched_via}
     */
    public function resolve($raw_sku) {
        $candidates = $this->candidates($raw_sku);

        if (empty($candidates)) {
            return null;
        }

        $in_placeholders = implode(',', array_fill(0, count($candidates), '%s'));

        $sql = "SELECT p.ID, p.post_type, p.post_parent, pm.meta_value AS matched_sku
                FROM {$this->db->postmeta} pm
                INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_sku'
                  AND pm.meta_value IN ($in_placeholders)
                  AND p.post_type IN ('product', 'product_variation')
                  AND p.post_status = 'publish'
                ORDER BY FIELD(pm.meta_value, $in_placeholders) ASC,
                         FIELD(p.post_type, 'product', 'product_variation') ASC
                LIMIT 10";

        $rows = $this->db->get_results($this->db->prepare(
            $sql,
            array_merge($candidates, $candidates)
        ));

        if (empty($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row->post_type === 'product') {
                return array(
                    'product_id' => (int) $row->ID,
                    'variation_id' => 0,
                    'matched_sku' => $row->matched_sku,
                    'matched_via' => 'product',
                );
            }

            // Variação: só aceita se o pai existe, é produto e está publicado
            $parent_id = (int) $row->post_parent;
            if ($parent_id > 0
                && get_post_type($parent_id) === 'product'
                && get_post_status($parent_id) === 'publish') {
                return array(
                    'product_id' => $parent_id,
                    'variation_id' => (int) $row->ID,
                    'matched_sku' => $row->matched_sku,
                    'matched_via' => 'variation',
                );
            }
        }

        return null;
    }

    /**
     * Candidatos de SKU para o código informado, na ordem de prioridade.
     *
     * @param string $raw_sku
     * @return string[]
     */
    public function candidates($raw_sku) {
        $sku = trim((string) $raw_sku);

        if ($sku === '') {
            return array();
        }

        $candidates = array($sku);

        if (stripos($sku, self::PREFIX) !== 0) {
            // Cliente colou só o código (ex: 20013) -> tentar também OD-20013
            $candidates[] = self::PREFIX . $sku;
        } elseif (substr($sku, 0, 3) !== self::PREFIX) {
            // Começa com "od-" em caixa diferente -> tentar também na forma canônica
            $candidates[] = self::PREFIX . substr($sku, 3);
        }

        return array_values(array_unique($candidates));
    }
}
