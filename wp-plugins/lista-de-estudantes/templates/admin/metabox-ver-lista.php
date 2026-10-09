<?php
/**
 * Meta box "Ver Lista no Site".
 *
 * @var string|int $categoria_id
 */

if (!defined('ABSPATH')) exit;

if ($categoria_id) {
    $term = get_term($categoria_id, 'product_cat');
    if ($term && !is_wp_error($term)) {
        $url = get_term_link($term);
        ?>
            <a href="<?php echo esc_url($url); ?>" target="_blank" class="listas-ver-lista-btn">
                🔗 Ver Lista no Site
            </a>
            <div class="listas-ver-lista-info">
                <strong>URL:</strong><br>
                <a href="<?php echo esc_url($url); ?>" target="_blank" style="word-break: break-all; font-size: 11px;">
                    <?php echo esc_html($url); ?>
                </a>
            </div>
        <?php
    }
} else {
    ?>
        <div class="listas-ver-lista-aviso">
            ⚠️ Publique a lista primeiro para gerar o link
        </div>
    <?php
}
