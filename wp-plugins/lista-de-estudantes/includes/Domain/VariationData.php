<?php
namespace ListasEstudantes\Domain;

if (!defined('ABSPATH')) exit;

/**
 * Dados de exibição de um item da lista, resolvendo a VARIAÇÃO quando houver.
 *
 * Existe um único lugar que sabe ler título, preço, peso, dimensões e descrição
 * de uma variação, porque admin e front precisam exatamente do mesmo, e antes
 * cada um montava o seu — o admin mostrando o pai e o front mostrando "Selecione
 * as opções".
 *
 * Título: a origem do catálogo dá um título próprio a cada variação
 * ("Fórceps Adulto N°150"), que o OdontoJF Woo Bridge grava no meta
 * `_odontojf_variation_title`. O WooCommerce não tem campo nativo para isso —
 * WC_Product_Variation::get_title() devolve o título do PAI — então lemos o meta
 * primeiro e só caímos no nome montado pelo Woo ("Fórceps Adulto - N°150")
 * quando ele não existe.
 */
final class VariationData {

    const META_TITLE = '_odontojf_variation_title';

    /**
     * @param int $product_id   produto pai (ou simples)
     * @param int $variation_id variação fixada na lista; 0 = sem variação
     * @return array|null
     */
    public static function get($product_id, $variation_id = 0) {
        $product = wc_get_product((int) $product_id);
        if (!$product) {
            return null;
        }

        $variation = null;
        if ((int) $variation_id > 0) {
            $candidate = wc_get_product((int) $variation_id);
            // Só aceita se for mesmo variação DESTE pai: a variação pode ter
            // sido apagada e o id reaproveitado por outro post.
            if ($candidate instanceof \WC_Product_Variation
                && (int) $candidate->get_parent_id() === (int) $product->get_id()) {
                $variation = $candidate;
            }
        }

        $target = $variation ?: $product;

        return array(
            'product_id'   => (int) $product->get_id(),
            'variation_id' => $variation ? (int) $variation->get_id() : 0,
            'is_variation' => (bool) $variation,
            'is_variable'  => $product->is_type('variable'),
            'title'        => self::title($product, $variation),
            'sku'          => (string) $target->get_sku(),
            'price'        => (float) $target->get_price(),
            'regular_price'=> (float) $target->get_regular_price(),
            'price_html'   => $target->get_price_html(),
            'weight'       => (string) $target->get_weight(),
            'weight_html'  => self::weightHtml($target),
            'dimensions'   => array(
                'length' => (string) $target->get_length(),
                'width'  => (string) $target->get_width(),
                'height' => (string) $target->get_height(),
            ),
            'dimensions_html' => wc_format_dimensions($target->get_dimensions(false)),
            'description'  => self::description($product, $variation),
            'image'        => self::image($product, $variation),
            'attributes'   => $variation ? $variation->get_variation_attributes() : array(),
            'permalink'    => $variation ? $variation->get_permalink() : get_permalink($product->get_id()),
            'in_stock'     => $target->is_in_stock(),
        );
    }

    /**
     * Título próprio da variação, com fallback para o nome montado pelo Woo.
     */
    public static function title($product, $variation) {
        if ($variation) {
            $own = $variation->get_meta(self::META_TITLE, true);
            if (is_string($own) && trim($own) !== '') {
                return trim($own);
            }
            // get_name() numa variação devolve o título do pai; o que distingue
            // é o resumo dos atributos.
            $suffix = wc_get_formatted_variation($variation, true, false, false);
            $base   = $product->get_name();
            return $suffix !== '' ? $base . ' - ' . $suffix : $base;
        }

        return $product->get_name();
    }

    /**
     * Descrição da variação, caindo na do pai quando ela não tem uma própria.
     */
    private static function description($product, $variation) {
        if ($variation) {
            $own = $variation->get_description();
            if (trim(wp_strip_all_tags((string) $own)) !== '') {
                return $own;
            }
        }
        return $product->get_short_description() ?: $product->get_description();
    }

    private static function image($product, $variation) {
        $id = 0;
        if ($variation && $variation->get_image_id()) {
            $id = (int) $variation->get_image_id();
        } elseif ($product->get_image_id()) {
            $id = (int) $product->get_image_id();
        }

        return $id
            ? (wp_get_attachment_image_url($id, 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail'))
            : wc_placeholder_img_src('woocommerce_thumbnail');
    }

    private static function weightHtml($target) {
        $weight = $target->get_weight();
        return ($weight !== '' && $weight !== null)
            ? wc_format_weight($weight)
            : '';
    }
}
