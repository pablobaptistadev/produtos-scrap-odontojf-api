<?php
/**
 * Card de produto da lista (dentro do loop de $produtos_query).
 * Os atributos data-* são o contrato com assets/frontend/js/lista.js.
 *
 * @var array $similares_count_map
 */

if (!defined('ABSPATH')) exit;

$product = wc_get_product(get_the_ID());
$product_id = get_the_ID();
$is_variable = $product->is_type('variable');

// Verificar se tem produtos similares vinculados
$has_similares = isset($similares_count_map[$product_id]) && $similares_count_map[$product_id] > 0;

$preco_original = floatval($product->get_regular_price());
$preco_atual = floatval($product->get_price());
$produto_desconto_percent = listas_get_product_discount_percent($product);

if ($preco_atual <= 0) {
    $preco_atual = $preco_original;
}

// Para produtos variáveis, pegar a primeira variação disponível
$default_variation_id = 0;
$default_variation = null;
$variation_attributes = array();

if ($is_variable) {
    $preco_atual = 0;
    $variations = $product->get_available_variations();
    if (!empty($variations)) {
        // Pegar atributos do produto
        $attributes = $product->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_options) {
            $selected_value = '';

            $variation_attributes[$attr_name] = array(
                'name' => wc_attribute_label($attr_name),
                'options' => $attr_options,
                'selected' => $selected_value,
                'attr_name' => $attr_name // Guardar o nome exato do atributo
            );
        }
    }
}
?>

                <div class="listas-produto-wrapper" data-product-id="<?php echo $product_id; ?>" data-is-variable="<?php echo $is_variable ? '1' : '0'; ?>" data-variation-id="<?php echo $default_variation_id; ?>">
                    <div class="listas-produto-item">
                        <div class="listas-produto-checkbox">
                            <input type="checkbox" class="listas-produto-check" value="<?php echo $product_id; ?>" data-price="<?php echo $preco_atual; ?>" data-variation-id="<?php echo $default_variation_id; ?>">
                        </div>

                        <div class="listas-produto-image">
                            <?php echo $product->get_image('woocommerce_thumbnail'); ?>
                        </div>

                        <div class="listas-produto-info">
                            <h3 class="listas-produto-title"><?php echo get_the_title(); ?></h3>
                            <div class="listas-produto-meta">
                                ID: <?php echo $product_id; ?><?php echo $product->get_sku() ? ' | SKU: ' . esc_html($product->get_sku()) : ''; ?>
                            </div>
                        </div>

                        <div class="listas-produto-price-actions">
                            <div class="listas-produto-price">
                                <?php echo $product->get_price_html(); ?>
                                <?php if ($produto_desconto_percent > 0): ?>
                                    <span class="listas-desconto-tag"><?php echo $produto_desconto_percent; ?>% OFF</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($has_similares): ?>
                            <button type="button" class="listas-ver-similares" data-product-id="<?php echo $product_id; ?>" title="Ver similares">
                                <svg viewBox="0 0 16 16">
                                    <path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41zm-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9z"/>
                                    <path fill-rule="evenodd" d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3zM3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9H3.1z"/>
                                </svg>
                                Ver similares
                            </button>
                            <?php endif; ?>
                            <?php if ($is_variable && !empty($variation_attributes)): ?>
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

                    <div class="listas-produto-feedback" id="listas-feedback-<?php echo $product_id; ?>"></div>

                    <?php if ($is_variable && !empty($variation_attributes)): ?>
                        <div class="listas-variacoes-wrapper" id="variacoes-<?php echo $product_id; ?>">
                            <?php foreach ($variation_attributes as $attr_name => $attr_data): ?>
                                <div class="listas-variacao-attr">
                                    <label><?php echo esc_html($attr_data['name']); ?>:</label>
                                    <div class="listas-variacao-tags" data-attribute="<?php echo esc_attr($attr_data['attr_name']); ?>" data-product-id="<?php echo $product_id; ?>">
                                        <?php
                                        $attr_slug_name = $attr_data['attr_name']; // ex: 'pa_cor' ou 'Cor'
                                        $is_taxonomy = taxonomy_exists($attr_slug_name);

                                        // $attr_data['options'] é uma array de Nomes, ex: ['Adulto', 'Infantil']
                                        foreach ($attr_data['options'] as $option_name):

                                            // Inicializar com slug do nome (sanitize_title converte para slug)
                                            $option_slug = sanitize_title($option_name);

                                            // Se for uma taxonomia global (ex: pa_cor), buscamos o slug real do termo
                                            if ($is_taxonomy) {
                                                $term = get_term_by('name', $option_name, $attr_slug_name);
                                                if ($term && !is_wp_error($term)) {
                                                    $option_slug = $term->slug; // ex: 'infantil'
                                                }
                                            }

                                            // $attr_data['selected'] já é o slug (vindo da variação padrão)
                                            // Comparar tanto com slug quanto com nome para garantir match
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

                    <div class="listas-similares-expanded" id="similares-<?php echo $product_id; ?>">
                        <div class="listas-similares-title">Produtos similares:</div>
                        <div class="listas-similares-grid"></div>
                    </div>
                </div>
