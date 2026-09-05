<?php
/**
 * Página da lista de estudante (takeover da categoria).
 * Renderizada por Frontend\TemplateController::renderPage().
 *
 * @var array     $lista_info
 * @var \WP_Query $produtos_query
 * @var \WP_Query $brindes_query
 * @var array     $similares_count_map map product_id => count
 */

if (!defined('ABSPATH')) exit;
?>
    <div class="listas-container">
        <div class="listas-header">
            <h1><?php echo esc_html($lista_info['escola']); ?></h1>
            <div class="listas-header-subtitle">
                <?php echo esc_html($lista_info['cidade'] . ' - ' . $lista_info['uf']); ?>
            </div>

            <div class="listas-info-grid">
                <div class="listas-info-item">
                    <span class="listas-info-label">Turma:</span>
                    <span class="listas-info-value"><?php echo esc_html($lista_info['turma']); ?></span>
                </div>
                <div class="listas-info-item">
                    <span class="listas-info-label">Disciplina:</span>
                    <span class="listas-info-value"><?php echo esc_html($lista_info['disciplina']); ?></span>
                </div>
                <div class="listas-info-item">
                    <span class="listas-info-label">Lista criada em:</span>
                    <span class="listas-info-value"><?php echo esc_html($lista_info['data_criacao']); ?></span>
                </div>
                <div class="listas-info-item">
                    <span class="listas-info-label">Código da lista:</span>
                    <span class="listas-info-value"><?php echo esc_html($lista_info['codigo']); ?></span>
                </div>
            </div>
        </div>

        <div class="listas-selecao-bar">
            <label class="listas-selecionar-tudo">
                <input type="checkbox" id="listas-selecionar-tudo">
                Selecionar tudo
            </label>

            <div class="listas-total-bar">
                <div class="listas-total-valor">
                    Total: <strong id="listas-total-selecionados">0,00</strong>
                </div>
                <button type="button" class="listas-btn-comprar-selecionados" id="listas-btn-comprar-selecionados" disabled>
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/>
                    </svg>
                    Comprar <span id="listas-qtd-selecionados">0</span> itens selecionados
                </button>
            </div>
        </div>

        <?php if ($produtos_query->have_posts()): ?>
            <div class="listas-produtos-lista">
                <?php while ($produtos_query->have_posts()): $produtos_query->the_post(); ?>
                    <?php include LISTAS_ESTUDANTES_PATH . 'templates/frontend/parts/produto-card.php'; ?>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 40px; color: #666;">Nenhum produto nesta lista ainda.</p>
        <?php endif; ?>

        <?php wp_reset_postdata(); ?>

        <?php include LISTAS_ESTUDANTES_PATH . 'templates/frontend/parts/brindes.php'; ?>

        <?php wp_reset_postdata(); ?>
    </div>
