<?php
/**
 * Card de um ITEM da lista.
 *
 * Desde a 2.1.0 o loop é por item ($item = {product_id, variation_id}) e não
 * por post: uma lista pode fixar a variação que o professor escolheu, e duas
 * variações do mesmo pai são dois itens.
 *
 * Quando há variação fixada, o card mostra os dados DELA (título próprio,
 * SKU, preço, peso, dimensões, descrição e imagem) e o botão adiciona aquela
 * variação direto — sem obrigar o aluno a adivinhar em "Ver opções", que era
 * exatamente onde o pedido saía errado.
 *
 * Os atributos data-* são o contrato com assets/frontend/js/lista.js.
 *
 * @var array $item
 * @var array $similares_count_map
 */

if (!defined('ABSPATH')) exit;

use ListasEstudantes\Domain\VariationData;

$product_id   = (int) $item['product_id'];
$variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;

$product = wc_get_product($product_id);
if (!$product) return;

$info = VariationData::get($product_id, $variation_id);
if (!$info) return;

$is_variable  = $info['is_variable'];
$has_pinned   = $info['is_variation'];          // variação escolhida na lista
$needs_choice = $is_variable && !$has_pinned;   // aluno ainda precisa escolher

// Id único por card: dois itens podem compartilhar o mesmo product_id.
$uid = $has_pinned ? $product_id . '-' . $variation_id : (string) $product_id;

$has_similares = isset($similares_count_map[$product_id]) && $similares_count_map[$product_id] > 0;

$produto_desconto_percent = listas_get_product_discount_percent($product);

// Preço que vai no data-price (usado pelo somatório da seleção): com variação
// fixada é o dela; sem escolher ainda, 0 — como era antes.
$preco_atual = $needs_choice ? 0 : (float) $info['price'];
if (!$is_variable && $preco_atual <= 0) {
    $preco_atual = (float) $info['regular_price'];
}

$default_variation_id = $has_pinned ? $variation_id : 0;

// Atributos só são oferecidos quando o aluno precisa escolher.
$variation_attributes = array();
if ($needs_choice) {
    $variations = $product->get_available_variations();
    if (!empty($variations)) {
        foreach ($product->get_variation_attributes() as $attr_name => $attr_options) {
            $variation_attributes[$attr_name] = array(
                'name' => wc_attribute_label($attr_name),
                'options' => $attr_options,
                'selected' => '',
                'attr_name' => $attr_name
            );
        }
    }
}

$medidas = array_filter(array($info['weight_html'], $info['dimensions_html']));
$descricao = trim(wp_strip_all_tags((string) $info['description']));
?>

                <div class="listas-produto-wrapper" data-product-id="<?php echo $product_id; ?>" data-is-variable="<?php echo $needs_choice ? '1' : '0'; ?>" data-variation-id="<?php echo $default_variation_id; ?>">
                    <div class="listas-produto-item">
                        <div class="listas-produto-checkbox">
                            <input type="checkbox" class="listas-produto-check" value="<?php echo $product_id; ?>" data-price="<?php echo esc_attr($preco_atual); ?>" data-variation-id="<?php echo $default_variation_id; ?>">
                        </div>

                        <div class="listas-produto-image">
                            <img src="<?php echo esc_url($info['image']); ?>" alt="<?php echo esc_attr($info['title']); ?>" loading="lazy">
                        </div>

                        <div class="listas-produto-info">
                            <h3 class="listas-produto-title"><?php echo esc_html($info['title']); ?></h3>
                            <div class="listas-produto-meta">
                                ID: <?php echo $product_id; ?><?php echo $info['sku'] !== '' ? ' | SKU: ' . esc_html($info['sku']) : ''; ?>
                            </div>
                            <?php if ($medidas): ?>
                                <div class="listas-produto-medidas"><?php echo esc_html(implode(' · ', $medidas)); ?></div>
                            <?php endif; ?>
                            <?php if ($descricao !== ''): ?>
                                <div class="listas-produto-descricao"><?php echo esc_html(wp_trim_words($descricao, 28)); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="listas-produto-price-actions">
                            <div class="listas-produto-price">
                                <?php echo wp_kses_post($info['price_html']); ?>
                                <?php if ($produto_desconto_percent > 0): ?>
                                    <span class="listas-desconto-tag"><?php echo $produto_desconto_percent; ?>% OFF</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($has_similares): ?>
                            <button type="button" class="listas-ver-similares" data-product-id="<?php echo $product_id; ?>" title="Ver similares">
                                <svg viewBox="0 0 16 16">
                                    <path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/>
                                    <path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/></svg>
                                Ver similares
                            </button>
                            <?php endif; ?>
                            <?php if ($needs_choice && !empty($variation_attributes)): ?>
                                <button type="button" class="listas-btn-selecionar-variacao" data-product-id="<?php echo $product_id; ?>">
                                    Ver opções
                                </button>
                            <?php else: ?>
                                <button type="button" class="listas-btn-adicionar" data-product-id="<?php echo $product_id; ?>" data-variation-id="<?php echo $default_variation_id; ?>">
                                    Adicionar
                                </button>
                            <?php endif; ?>
                        </div>
                        </div>

                    <div class="listas-produto-feedback" id="listas-feedback-<?php echo esc_attr($uid); ?>"></div>

                    <?php if ($needs_choice && !empty($variation_attributes)): ?>
                        <div class="listas-variacoes-wrapper" id="variacoes-<?php echo $product_id; ?>">
                            <?php foreach ($variation_attributes as $attr_name => $attr_data): ?>
                                <div class="listas-variacao-attr">
                                    <label><?php echo esc_html($attr_data['name']); ?>:</label>
                                    <div class="listas-variacao-tags" data-attribute="<?php echo esc_attr($attr_data['attr_name']); ?>" data-product-id="<?php echo $product_id; ?>">
                                        <?php
                                        $attr_slug_name = $attr_data['attr_name'];
                                        $is_taxonomy = taxonomy_exists($attr_slug_name);

                                        foreach ($attr_data['options'] as $option_name):
                                            $option_slug = sanitize_title($option_name);

                                            if ($is_taxonomy) {
                                                $term = get_term_by('name', $option_name, $attr_slug_name);
                                                if ($term && !is_wp_error($term)) {
                                                    $option_slug = $term->slug;
                                                }
                                            }

                                            $is_active = ($attr_data['selected'] === $option_slug ||
                                                         $attr_data['selected'] === $option_name ||
                                                         sanitize_title($attr_data['selected']) === $option_slug);
                                        ?>
                                            <span class="listas-variacao-tag <?php echo $is_active ? 'active' : ''; ?>"
                                                  data-value="<?php echo esc_attr($option_slug); ?>"
                                                  data-attribute="<?php echo esc_attr($attr_data['attr_name']); ?>"
                                                  data-name="<?php echo esc_attr($option_name); ?>">
                                                <?php echo esc_html($option_name); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="listas-variacao-selected is-empty" id="variacao-info-<?php echo $product_id; ?>">
                                Selecione as opções para ver o preço
                            </div>
                            <button type="button" class="listas-btn-adicionar" data-product-id="<?php echo $product_id; ?>" data-variation-id="0" id="btn-adicionar-var-<?php echo $product_id; ?>" disabled>
                                Adicionar
                            </button>
                        </div>
                    <?php endif; ?>

                    <div class="listas-similares-expanded" id="similares-<?php echo esc_attr($uid); ?>">
                        <div class="listas-similares-title">Produtos similares:</div>
                        <div class="listas-similares-grid"></div>
                    </div>
                </div>
