<?php
/**
 * Meta box "Produtos da Lista" (busca, importação em massa e grid).
 * O comportamento vive em assets/admin/js/admin.js.
 *
 * @var string|int $categoria_id
 */

if (!defined('ABSPATH')) exit;
?>
    <div id="listas-produtos-container">
        <div class="listas-search-section">
            <input
                type="text"
                id="listas-produto-search"
                class="listas-search-input"
                placeholder="Buscar produto por nome, ID ou SKU..."
            >

            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #d2d2d7;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600; color: #1d1d1f;">Importar produtos em massa</h4>
                <p style="font-size: 12px; color: #666; margin-bottom: 10px;">Cole uma lista de SKUs separados por vírgula ou um por linha (ex: SKU1, SKU2, SKU3)</p>
                <textarea id="listas-produtos-bulk-import" class="listas-bulk-import-textarea" placeholder="SKU1, SKU2, SKU3&#10;ou um SKU por linha"></textarea>
                <button type="button" class="listas-btn-bulk-import" data-type="produtos" style="margin-top: 10px;">
                    Importar Produtos à Lista
                </button>
            </div>
        </div>

        <div id="listas-produtos-grid" class="listas-produtos-grid">
            <div class="listas-loading">Carregando produtos...</div>
        </div>

        <input type="hidden" name="listas_categoria_id" id="listas_categoria_id" value="<?php echo esc_attr($categoria_id); ?>">
    </div>
