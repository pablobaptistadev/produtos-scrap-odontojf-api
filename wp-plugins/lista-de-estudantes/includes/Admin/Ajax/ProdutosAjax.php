<?php
namespace ListasEstudantes\Admin\Ajax;

use ListasEstudantes\Repository\OrdemRepository;
use ListasEstudantes\Repository\SimilaresRepository;
use ListasEstudantes\Domain\ProductSearchService;
use ListasEstudantes\Domain\SkuResolver;
use ListasEstudantes\Domain\VariationData;
use ListasEstudantes\Domain\CategorySync;

if (!defined('ABSPATH')) exit;

/**
 * AJAX admin: busca, adição, remoção, reordenação e importação em massa
 * de produtos da lista. Todas as actions exigem nonce listas_produtos_nonce
 * e capability manage_woocommerce.
 */
final class ProdutosAjax {

    /** @var OrdemRepository */
    private $ordem;

    /** @var SimilaresRepository */
    private $similares;

    /** @var ProductSearchService */
    private $search;

    /** @var SkuResolver */
    private $skuResolver;

    public function __construct(OrdemRepository $ordem, SimilaresRepository $similares, ProductSearchService $search, SkuResolver $skuResolver) {
        $this->ordem = $ordem;
        $this->similares = $similares;
        $this->search = $search;
        $this->skuResolver = $skuResolver;
    }

    public function register() {
        add_action('wp_ajax_listas_search_produtos', array($this, 'search'));
        add_action('wp_ajax_listas_add_produto', array($this, 'add'));
        add_action('wp_ajax_listas_remove_produto', array($this, 'remove'));
        add_action('wp_ajax_listas_reorder_produtos', array($this, 'reorder'));
        add_action('wp_ajax_listas_bulk_import_produtos', array($this, 'bulkImport'));
    }

    private function guard() {
        check_ajax_referer('listas_produtos_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sem permissão');
        }
    }

    public function search() {
        $this->guard();

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $categoria_id = isset($_POST['categoria_id']) ? absint($_POST['categoria_id']) : 0;

        // A tabela de ordem é a fonte de verdade do que está na lista (o ERP
        // pode resetar a categoria product_cat do produto, então não dependemos
        // dela para saber o que está na lista).
        $produtos_ordem = array();
        $produtos_na_lista = array();
        $variacao_por_produto = array(); // product_id => variation_id fixada

        if ($categoria_id) {
            $produtos_ordem = $this->ordem->getPositions($categoria_id);
            $produtos_na_lista = array_map('intval', array_keys($produtos_ordem));
            foreach ($this->ordem->getOrderedRows($categoria_id) as $row) {
                if ($row['variation_id'] > 0) {
                    $variacao_por_produto[$row['product_id']] = $row['variation_id'];
                }
            }
        }

        // Resolver a lista de IDs a exibir
        if (empty($search) && $categoria_id && !empty($produtos_na_lista)) {
            // Sem busca: produtos da lista (ordem salva) no topo e, abaixo,
            // uma leva de produtos de sugestão do catálogo (ainda não na lista).
            $query = new \WP_Query(array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'post__in' => $produtos_na_lista,
                'orderby' => 'post__in',
                'fields' => 'ids',
            ));
            $ids_da_lista = array_map('intval', $query->posts);

            usort($ids_da_lista, function($a, $b) use ($produtos_ordem) {
                $pos_a = isset($produtos_ordem[$a]) ? $produtos_ordem[$a] : 9999;
                $pos_b = isset($produtos_ordem[$b]) ? $produtos_ordem[$b] : 9999;
                return $pos_a - $pos_b;
            });

            $sugestoes = $this->suggestionIds($produtos_na_lista, 30);
            $ids_para_exibir = array_merge($ids_da_lista, $sugestoes);
        } elseif (!empty($search)) {
            // Busca por nome, ID ou SKU (aceita SKU de variação e regra OD-)
            $ids_para_exibir = $this->search->searchIds($search, 50);
        } else {
            $query = new \WP_Query(array(
                'post_type' => 'product',
                'posts_per_page' => 50,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
            ));
            $ids_para_exibir = array_map('intval', $query->posts);
        }

        // Buscar contagem de similares para todos os produtos de uma vez (alta performance)
        $similares_counts = $this->similares->countForProducts($ids_para_exibir);

        $produtos = array();

        foreach ($ids_para_exibir as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $product_id = (int) $product_id;

            // Quando a lista fixou uma variação, é ELA que o admin mostra —
            // título, SKU, preço, peso e dimensões próprios. Sem isso o
            // professor colava "411" e via "Fórceps Adulto" com a faixa de
            // preço do pai, sem saber qual tinha entrado.
            $pinned = isset($variacao_por_produto[$product_id]) ? $variacao_por_produto[$product_id] : 0;
            $info = VariationData::get($product_id, $pinned);

            $produtos[] = array(
                'id' => $product_id,
                'variation_id' => $info ? (int) $info['variation_id'] : 0,
                'name' => $info ? $info['title'] : get_the_title($product_id),
                'sku' => $info ? $info['sku'] : ($product->get_sku() ?: ''),
                'price' => $info ? $info['price_html'] : $product->get_price_html(),
                'weight' => $info ? $info['weight_html'] : '',
                'dimensions' => $info ? $info['dimensions_html'] : '',
                'is_variable' => $product->is_type('variable'),
                'image' => ($info && $info['variation_id'])
                    ? $info['image']
                    : (wp_get_attachment_image_url($product->get_image_id(), 'medium') ?: wc_placeholder_img_src('medium')),
                'link' => get_permalink($product_id),
                // "in_category" aqui significa "já está na lista" (tabela de ordem)
                'in_category' => in_array($product_id, $produtos_na_lista),
                'similares_count' => isset($similares_counts[$product_id]) ? intval($similares_counts[$product_id]) : 0,
                'menu_order' => isset($produtos_ordem[$product_id]) ? intval($produtos_ordem[$product_id]) : 9999
            );
        }

        wp_send_json_success($produtos);
    }

    /**
     * IDs de produtos para sugerir abaixo dos já adicionados (catálogo, por
     * título), excluindo os que já estão na lista.
     *
     * @param int[] $excluir IDs já na lista
     * @param int   $limite
     * @return int[]
     */
    private function suggestionIds($excluir, $limite = 30) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $limite,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
        );

        if (!empty($excluir)) {
            $args['post__not_in'] = array_map('intval', $excluir);
        }

        $query = new \WP_Query($args);

        return array_map('intval', $query->posts);
    }

    public function add() {
        $this->guard();

        $post_id = absint($_POST['post_id']);
        $product_id = absint($_POST['product_id']);
        $categoria_id = absint($_POST['categoria_id']);

        if (!$categoria_id) {
            CategorySync::sync($post_id);
            $categoria_id = get_post_meta($post_id, '_listas_categoria_id', true);
        }

        if (!$categoria_id) {
            wp_send_json_error('Erro ao criar categoria');
        }

        $current_cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        if (!in_array($categoria_id, $current_cats)) {
            $current_cats[] = $categoria_id;
            wp_set_post_terms($product_id, $current_cats, 'product_cat');

            // Adicionar na tabela de ordem com a próxima posição disponível
            $this->ordem->insert($categoria_id, $product_id, $this->ordem->nextPosition($categoria_id));
        }

        wp_send_json_success(array(
            'message' => 'Produto adicionado à lista',
            'categoria_id' => $categoria_id
        ));
    }

    public function remove() {
        $this->guard();

        $post_id = absint($_POST['post_id']);
        $product_id = absint($_POST['product_id']);
        $categoria_id = absint($_POST['categoria_id']);

        if (!$product_id || !$categoria_id) {
            wp_send_json_error('Dados inválidos');
        }

        // Remover categoria do produto
        $current_cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        $new_cats = array_diff($current_cats, array($categoria_id));
        wp_set_post_terms($product_id, $new_cats, 'product_cat');

        // Remover da tabela de ordem
        $this->ordem->delete($categoria_id, $product_id);

        wp_send_json_success(array(
            'message' => 'Produto removido da lista'
        ));
    }

    public function reorder() {
        $this->guard();

        $categoria_id = absint($_POST['categoria_id']);
        $order = isset($_POST['order']) ? json_decode(stripslashes($_POST['order']), true) : array();

        if (!$categoria_id || empty($order)) {
            wp_send_json_error('Dados inválidos');
        }

        // Atualizar ou inserir a ordem de cada produto para esta categoria específica
        foreach ($order as $item) {
            $product_id = absint($item['product_id']);
            $position = absint($item['position']);

            if ($this->ordem->exists($categoria_id, $product_id)) {
                $this->ordem->updatePosition($categoria_id, $product_id, $position);
            } else {
                $this->ordem->insert($categoria_id, $product_id, $position);
            }
        }

        wp_send_json_success(array('message' => 'Ordem atualizada com sucesso'));
    }

    public function bulkImport() {
        $this->guard();

        $post_id = absint($_POST['post_id']);
        $categoria_id = absint($_POST['categoria_id']);
        $skus = isset($_POST['skus']) ? json_decode(stripslashes($_POST['skus']), true) : array();

        if (!$post_id || empty($skus) || !is_array($skus)) {
            wp_send_json_error('Dados inválidos');
        }

        // Se não tiver categoria, criar
        if (!$categoria_id) {
            CategorySync::sync($post_id);
            $categoria_id = get_post_meta($post_id, '_listas_categoria_id', true);
        }

        if (!$categoria_id) {
            wp_send_json_error('Erro ao criar categoria');
        }

        $added = 0;
        $already_in_list = 0;
        $errors = array();

        // Buscar produtos por SKU (aceita SKU de variação -> produto pai e regra OD-)
        foreach ($skus as $sku) {
            $sku = trim($sku);
            if (empty($sku)) continue;

            // resolve() (e não resolveProductId()) porque o código colado
            // costuma ser o da VARIAÇÃO — o professor cola "411" querendo o
            // Fórceps N°150, e guardar só o pai fazia a lista mostrar outro
            // fórceps. Agora a variação vai junto e é ela que aparece.
            $match = $this->skuResolver->resolve($sku);

            if (!$match) {
                $errors[] = "SKU '{$sku}' não encontrado";
                continue;
            }

            $product_id   = (int) $match['product_id'];
            $variation_id = (int) $match['variation_id'];

            // Verificar se já está na categoria
            $current_cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
            $is_in_category = in_array($categoria_id, $current_cats);

            if (!$is_in_category) {
                // Adicionar à categoria
                $current_cats[] = $categoria_id;
                wp_set_post_terms($product_id, $current_cats, 'product_cat');
            }

            // Já está como ITEM (produto + variação)? Duas variações do mesmo
            // pai são dois itens legítimos da lista.
            if ($this->ordem->exists($categoria_id, $product_id, $variation_id)) {
                $already_in_list++;
                continue;
            }

            // Colou o código da variação e o pai já estava na lista "solto"
            // (linha legada com variation_id = 0): promove aquela linha em vez
            // de duplicar o produto na tela.
            if ($variation_id > 0 && $this->ordem->exists($categoria_id, $product_id, 0)) {
                $this->ordem->setVariation($categoria_id, $product_id, 0, $variation_id);
                $added++;
                continue;
            }

            $this->ordem->insert($categoria_id, $product_id, $this->ordem->nextPosition($categoria_id), $variation_id);
            $added++;
        }

        wp_send_json_success(array(
            'message' => 'Importação concluída',
            'added' => $added,
            'already_in_list' => $already_in_list,
            'errors' => $errors,
            'categoria_id' => $categoria_id
        ));
    }
}
