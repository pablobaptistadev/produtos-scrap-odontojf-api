<?php
/**
 * Meta box "Informações da Lista".
 *
 * @var string $escola
 * @var string $cidade
 * @var string $uf
 * @var string $turma
 * @var string $disciplina
 * @var string $ordem
 * @var array  $estados
 */

if (!defined('ABSPATH')) exit;

wp_nonce_field('listas_save_meta', 'listas_meta_nonce');
?>
    <div class="listas-field-group">
        <label for="listas_escola">Escola:</label>
        <input type="text" id="listas_escola" name="listas_escola" value="<?php echo esc_attr($escola); ?>" required>
    </div>

    <div class="listas-field-row-3">
        <div class="listas-field-group">
            <label for="listas_cidade">Cidade:</label>
            <input type="text" id="listas_cidade" name="listas_cidade" value="<?php echo esc_attr($cidade); ?>" required>
        </div>

        <div class="listas-field-group">
            <label for="listas_uf">UF:</label>
            <select id="listas_uf" name="listas_uf" required>
                <option value="">Selecione</option>
                <?php foreach ($estados as $sigla => $nome): ?>
                    <option value="<?php echo $sigla; ?>" <?php selected($uf, $sigla); ?>>
                        <?php echo $sigla; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="listas-field-group">
            <label for="listas_ordem">Ordem:</label>
            <input type="number" id="listas_ordem" name="listas_ordem" value="<?php echo esc_attr($ordem); ?>" min="0" style="width: 100%;">
        </div>
    </div>

    <div class="listas-field-row">
        <div class="listas-field-group">
            <label for="listas_turma">Turma:</label>
            <input type="text" id="listas_turma" name="listas_turma" value="<?php echo esc_attr($turma); ?>" required>
        </div>

        <div class="listas-field-group">
            <label for="listas_disciplina">Disciplina:</label>
            <input type="text" id="listas_disciplina" name="listas_disciplina" value="<?php echo esc_attr($disciplina); ?>" required>
        </div>
    </div>
