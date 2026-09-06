<?php
/**
 * Meta box "Desconto da Lista" (cupom).
 *
 * @var string $cupom_ativo
 * @var string $cupom_tipo
 * @var string $cupom_valor
 * @var string $cupom_minimo
 */

if (!defined('ABSPATH')) exit;
?>
    <div class="listas-cupom-toggle">
        <input
            type="checkbox"
            id="listas_cupom_ativo"
            name="listas_cupom_ativo"
            value="1"
            <?php checked($cupom_ativo, '1'); ?>
        >
        <label for="listas_cupom_ativo" style="margin: 0; cursor: pointer;">Ativar Desconto</label>
    </div>

    <div id="listas-cupom-fields" style="<?php echo $cupom_ativo ? '' : 'display:none;'; ?>">
        <div class="listas-cupom-field">
            <label>Tipo de Desconto:</label>
            <select name="listas_cupom_tipo">
                <option value="percent" <?php selected($cupom_tipo, 'percent'); ?>>Porcentagem (%)</option>
                <option value="fixed_cart" <?php selected($cupom_tipo, 'fixed_cart'); ?>>Valor Fixo (R$)</option>
            </select>
        </div>

        <div class="listas-cupom-field">
            <label>Valor do Desconto:</label>
            <input
                type="number"
                name="listas_cupom_valor"
                value="<?php echo esc_attr($cupom_valor); ?>"
                step="0.01"
                min="0"
                placeholder="Ex: 10"
            >
        </div>

        <div class="listas-cupom-field">
            <label>Valor Mínimo da Compra (R$):</label>
            <input
                type="number"
                name="listas_cupom_minimo"
                value="<?php echo esc_attr($cupom_minimo); ?>"
                step="0.01"
                min="0"
                placeholder="Opcional"
            >
            <small style="display: block; margin-top: 5px; color: #666;">Deixe em branco para sem mínimo</small>
        </div>
    </div>
