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
        $itens_na_lista = array();       // "product:variation" => true
        $linhas_da_lista = array();      // itens na ordem salva

        if ($categoria_id) {
            $produtos_ordem = $this->ordem->getPositions($categoria_id);
            $produtos_na_lista = array_map('intval', array_keys($produtos_ordem));
            foreach ($this->ordem->getOrderedRows($categoria_id) as $row) {
                $pid = (int) $row['product_id'];
                $vid = (int) $row['variation_id'];
                if ($vid > 0) {
                    $variacao_por_produto[$pid] = $vid;
                }
                $itens_na_lista[$pid . ':' . $vid] = true;
                $linhas_da_lista[] = array('product_id' => $pid, 'variation_id' => $vid);
            }
        }

        // Resolver os ITENS a exibir. Um item é {product_id, variation_id}: duas
        // variações do mesmo pai são duas linhas legítimas da lista.
        $itens = array();

        if (empty($search) && $categoria_id && !empty($linhas_da_lista)) {
            foreach ($linhas_da_lista as $linha) {
                if (get_post_status($linha['product_id']) === 'publish') $itens[] = $linha;
            }
            foreach ($this->suggestionIds($produtos_na_lista, 30) as $id) {
                $itens[] = array('product_id' => (int) $id, 'variation_id' => 0);
            }
        } elseif (!empty($search)) {
            // Nome, ID, SKU exato (aceita SKU de variação e lista por vírgula).
            // Quando o código é de uma variação, o resultado É a variação.
            $itens = $this->search->search($search, 50);
        } else {
            $query = new \WP_Query(array(
                'post_type' => 'product',
                'posts_per_page' => 50,
                'post_status' => 'publish',
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
            ));
            foreach ($query->posts as $id) {
                $itens[] = array('product_id' => (int) $id, 'variation_id' => 0);
            }
        }


        // Buscar contagem de similares para todos os produtos de uma vez (alta performance)
        $similares_counts = $this->similares->countForProducts(
            array_values(array_unique(array_column($itens, 'product_id')))
        );

        $produtos = array();

        foreach ($itens as $item) {
            $product_id = (int) $item['product_id'];
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // Quando a lista fixou uma variação, é ELA que o admin mostra —
            // título, SKU, preço, peso e dimensões próprios. Sem isso o
            // professor colava "411" e via "Fórceps Adulto" com a faixa de
            // preço do pai, sem saber qual tinha entrado.
            // A variação vem do próprio resultado (busca por SKU de variação) ou,
            // quando o item já está na lista, da linha salva.
            $pinned = (int) $item['variation_id'];
            if (!$pinned && isset($variacao_por_produto[$product_id])) {
                $pinned = (int) $variacao_por_produto[$product_id];
            }
            $info = VariationData::get($product_id, $pinned);
            $vid_final = $info ? (int) $info['variation_id'] : 0;
            $ja_na_lista = isset($itens_na_lista[$product_id . ':' . $vid_final])
                || ($vid_final === 0 && in_array($product_id, $produtos_na_lista, true));

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
                // "in_category" = ESTE item (produto + variação) já está na lista.
                'in_category' => $ja_na_lista,
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
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
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
        }

        // A busca por SKU de variação devolve a variação; é ELA que entra na
        // lista. Sem isso, clicar em "+ Adicionar" no resultado "Fórceps Adulto
        // - N° 17" gravava o pai solto e a lista mostrava outro fórceps.
        if ($variation_id > 0) {
            $pai = (int) wp_get_post_parent_id($variation_id);
            if ($pai !== $product_id) {
                $variation_id = 0;
            }
        }

        if ($this->ordem->exists($categoria_id, $product_id, $variation_id)) {
            wp_send_json_success(array(
                'message' => 'Este item já está na lista',
                'categoria_id' => $categoria_id,
                'variation_id' => $variation_id,
            ));
        }

        // Já havia a linha do pai "solta" (legado) e agora veio a variação:
        // promove aquela linha em vez de duplicar o produto na tela.
        if ($variation_id > 0 && $this->ordem->exists($categoria_id, $product_id, 0)) {
            $this->ordem->setVariation($categoria_id, $product_id, 0, $variation_id);
        } else {
            // Erro do banco aqui é o que fazia o botão dizer "adicionado" e a
            // lista continuar vazia. Agora ele aparece na tela.
            $ok = $this->ordem->insert($categoria_id, $product_id, $this->ordem->nextPosition($categoria_id), $variation_id);
            if (!$ok) {
                wp_send_json_error('Não foi possível gravar o item na lista (erro no banco).');
            }
        }

        wp_send_json_success(array(
            'message' => 'Produto adicionado à lista',
            'categoria_id' => $categoria_id,
            'variation_id' => $variation_id,
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
