<?php
namespace ListasEstudantes\Frontend;

use ListasEstudantes\Repository\OrdemRepository;
use ListasEstudantes\Repository\SimilaresRepository;

if (!defined('ABSPATH')) exit;

/**
 * Takeover do template de categoria: qualquer arquivo de product_cat cujo
 * pai seja LISTAS_PARENT_CAT_ID e que tenha uma lista vinculada vira a
 * página customizada da lista (render completo + exit).
 */
final class TemplateController {

    /** @var OrdemRepository */
    private $ordem;

    /** @var SimilaresRepository */
    private $similares;

    /** @var Assets */
    private $assets;

    public function __construct(OrdemRepository $ordem, SimilaresRepository $similares, Assets $assets) {
        $this->ordem = $ordem;
        $this->similares = $similares;
        $this->assets = $assets;
    }

    public function register() {
        add_filter('template_include', array($this, 'maybeTakeover'));
    }

    public function maybeTakeover($template) {
        if (is_product_category()) {
            $category = get_queried_object();

            if ($category->parent == LISTAS_PARENT_CAT_ID) {
                $lista_post = get_posts(array(
                    'post_type' => 'lista_estudante',
                    'meta_key' => '_listas_categoria_id',
                    'meta_value' => $category->term_id,
                    'posts_per_page' => 1
                ));

                if (!empty($lista_post)) {
                    add_action('wp_enqueue_scripts', array($this->assets, 'enqueue'));

                    ob_start();
                    $this->renderPage();
                    $output = ob_get_clean();

                    echo $output;
                    exit;
                }
            }
        }

        return $template;
    }

    public function renderPage() {
        get_header();

        $lista_info = self::getListaInfo();

        if (!$lista_info) {
            echo '<p>Lista não encontrada.</p>';
            get_footer();
            return;
        }

        $category = get_queried_object();

        // A tabela de ordem é a fonte de verdade do que está na lista. Não
        // filtramos por product_cat aqui de propósito: os plugins de ERP
        // sincronizam produtos e podem resetar as categorias, o que faria o
        // produto sumir da lista mesmo estando cadastrado nela. Usamos os IDs
        // salvos na tabela e apenas exigimos que estejam publicados.
        $ordered_ids = $this->ordem->getOrderedProductIds($category->term_id);

        if (!empty($ordered_ids)) {
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'post__in' => $ordered_ids,
                'orderby' => 'post__in',
            );
        } else {
            // Legado: lista sem ordem salva na tabela, cair na categoria
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $category->term_id
                    )
                )
            );
        }

        $produtos_query = new \WP_Query($args);

        // Buscar contagem de similares para todos os produtos de uma vez (alta performance)
        $product_ids_for_similares = array();
        if ($produtos_query->have_posts()) {
            foreach ($produtos_query->posts as $p) {
                $product_ids_for_similares[] = $p->ID;
            }
        }
        $similares_count_map = $this->similares->countForProducts($product_ids_for_similares);
        $produtos_query->rewind_posts();

        // A partir da 2.1.0 a página é renderizada por ITEM da lista, não por
        // produto: a tabela de ordem pode fixar uma variação, e duas variações
        // do mesmo pai são dois itens legítimos (o kit pede o fórceps N°150 e
        // o N°16). Um WP_Query devolveria o pai uma vez só.
        $publicados = array();
        foreach ($produtos_query->posts as $p) {
            $publicados[(int) $p->ID] = true;
        }

        $itens = array();
        if (!empty($ordered_ids)) {
            foreach ($this->ordem->getOrderedRows($category->term_id) as $row) {
                if (isset($publicados[$row['product_id']])) {
                    $itens[] = $row;
                }
            }
        }
        if (empty($itens)) {
            // Legado: lista sem ordem salva — um item por produto da categoria.
            foreach ($produtos_query->posts as $p) {
                $itens[] = array('product_id' => (int) $p->ID, 'variation_id' => 0, 'position' => 0);
            }
        }

        // Buscar produtos da categoria Brindes
        $brindes_query = new \WP_Query(array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => LISTAS_BRINDES_CAT_ID
                )
            )
        ));

        include LISTAS_ESTUDANTES_PATH . 'templates/frontend/lista.php';

        get_footer();
    }

    /**
     * Dados da lista vinculada à categoria atualmente consultada.
     *
     * @return array|null
     */
    public static function getListaInfo() {
        $category = get_queried_object();

        $lista_post = get_posts(array(
            'post_type' => 'lista_estudante',
            'meta_key' => '_listas_categoria_id',
            'meta_value' => $category->term_id,
            'posts_per_page' => 1
        ));

        if (empty($lista_post)) {
            return null;
        }

        $lista_id = $lista_post[0]->ID;

        return array(
            'id' => $lista_id,
            'titulo' => $lista_post[0]->post_title,
            'escola' => get_post_meta($lista_id, '_listas_escola', true),
            'cidade' => get_post_meta($lista_id, '_listas_cidade', true),
            'uf' => get_post_meta($lista_id, '_listas_uf', true),
            'turma' => get_post_meta($lista_id, '_listas_turma', true),
            'disciplina' => get_post_meta($lista_id, '_listas_disciplina', true),
            'codigo' => $lista_id,
            'data_criacao' => get_the_date('d/m/Y', $lista_id),
            'cupom_ativo' => get_post_meta($lista_id, '_listas_cupom_ativo', true),
            'cupom_tipo' => get_post_meta($lista_id, '_listas_cupom_tipo', true),
            'cupom_valor' => get_post_meta($lista_id, '_listas_cupom_valor', true),
            'categoria_id' => $category->term_id
        );
    }
}
