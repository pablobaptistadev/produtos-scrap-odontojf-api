<?php
/**
 * Seção de brindes da página da lista (barra de progresso + itens travados).
 *
 * @var \WP_Query $brindes_query
 */

if (!defined('ABSPATH')) exit;
?>
        <?php if ($brindes_query->have_posts()): ?>
        <div class="listas-brindes-section" id="listas-brindes-section">
            <div class="listas-brindes-header">
                <h2>Brindes</h2>
                <div class="listas-brindes-description">
                    Após sua lista bater R$ <?php echo number_format(LISTAS_BRINDES_VALOR_MINIMO, 2, ',', '.'); ?> em produtos você pode escolher 1 brinde grátis
                </div>
                <div class="listas-brindes-progress">
                    <div class="listas-brindes-progress-bar" id="listas-brindes-progress-bar" style="width: 0%;"></div>
                </div>
                <div class="listas-brindes-progress-text" id="listas-brindes-progress-text">
                    Selecione produtos no valor de R$ <strong id="listas-brindes-faltam"><?php echo number_format(LISTAS_BRINDES_VALOR_MINIMO, 2, ',', '.'); ?></strong> para liberar os brindes
                </div>
            </div>

            <div class="listas-brindes-list" id="listas-brindes-list">
                <?php while ($brindes_query->have_posts()): $brindes_query->the_post();
                    $brinde_product = wc_get_product(get_the_ID());
                    $brinde_id = get_the_ID();
                    $brinde_price = floatval($brinde_product->get_price());
                ?>
                <div class="listas-brinde-item brinde-locked" data-product-id="<?php echo $brinde_id; ?>" data-price="<?php echo $brinde_price; ?>">
                    <div class="listas-brinde-checkbox">
                        <input type="checkbox" class="listas-brinde-check" value="<?php echo $brinde_id; ?>" data-price="0" disabled>
                    </div>

                    <div class="listas-brinde-image">
                        <?php echo $brinde_product->get_image('thumbnail'); ?>
                    </div>

                    <div class="listas-brinde-info">
                        <h3 class="listas-brinde-title"><?php echo get_the_title(); ?></h3>
                        <div class="listas-brinde-meta">
                            SKU: <?php echo $brinde_product->get_sku() ? esc_html($brinde_product->get_sku()) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="listas-brinde-price listas-brinde-price-gratis">
                        <span style="text-decoration: line-through; color: #999; font-size: 12px;"><?php echo $brinde_product->get_price_html(); ?></span>
                        <div class="listas-brinde-price-content">
                            <span style="color: #34c759; font-weight: 700;">Grátis</span>
                            <span class="listas-brinde-badge">BRINDE</span>
                        </div>
                    </div>

                    <div class="listas-brinde-actions">
                        <button type="button" class="listas-btn-adicionar-brinde" data-product-id="<?php echo $brinde_id; ?>" disabled>
                            Adicionar
                        </button>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>
