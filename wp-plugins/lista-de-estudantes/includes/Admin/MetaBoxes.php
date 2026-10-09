<?php
namespace ListasEstudantes\Admin;

use ListasEstudantes\Domain\CategorySync;

if (!defined('ABSPATH')) exit;

/**
 * Meta boxes da tela de edição da lista + salvamento dos metadados.
 */
final class MetaBoxes {

    public function register() {
        add_action('add_meta_boxes', array($this, 'addMetaBoxes'));
        add_action('save_post_lista_estudante', array($this, 'save'), 10, 2);
    }

    public function addMetaBoxes() {
        add_meta_box(
            'listas_info',
            'Informações da Lista',
            array($this, 'renderInfo'),
            'lista_estudante',
            'normal',
            'high'
        );

        add_meta_box(
            'listas_produtos',
            'Produtos da Lista',
            array($this, 'renderProdutos'),
            'lista_estudante',
            'normal',
            'high'
        );

        add_meta_box(
            'listas_cupom',
            'Desconto da Lista',
            array($this, 'renderCupom'),
            'lista_estudante',
            'side',
            'default'
        );

        add_meta_box(
            'listas_ver_lista',
            'Ver Lista no Site',
            array($this, 'renderVerLista'),
            'lista_estudante',
            'side',
            'default'
        );
    }

    public function renderInfo($post) {
        $this->renderTemplate('metabox-info.php', array(
            'escola' => get_post_meta($post->ID, '_listas_escola', true),
            'cidade' => get_post_meta($post->ID, '_listas_cidade', true),
            'uf' => get_post_meta($post->ID, '_listas_uf', true),
            'turma' => get_post_meta($post->ID, '_listas_turma', true),
            'disciplina' => get_post_meta($post->ID, '_listas_disciplina', true),
            'ordem' => get_post_meta($post->ID, '_listas_ordem', true),
            'estados' => array(
                'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
                'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
                'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
                'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
                'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
                'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
                'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins'
            ),
        ));
    }

    public function renderProdutos($post) {
        $this->renderTemplate('metabox-produtos.php', array(
            'categoria_id' => get_post_meta($post->ID, '_listas_categoria_id', true),
        ));
    }

    public function renderCupom($post) {
        $this->renderTemplate('metabox-cupom.php', array(
            'cupom_ativo' => get_post_meta($post->ID, '_listas_cupom_ativo', true),
            'cupom_tipo' => get_post_meta($post->ID, '_listas_cupom_tipo', true) ?: 'percent',
            'cupom_valor' => get_post_meta($post->ID, '_listas_cupom_valor', true),
            'cupom_minimo' => get_post_meta($post->ID, '_listas_cupom_minimo', true),
        ));
    }

    public function renderVerLista($post) {
        $this->renderTemplate('metabox-ver-lista.php', array(
            'categoria_id' => get_post_meta($post->ID, '_listas_categoria_id', true),
        ));
    }

    public function save($post_id, $post) {
        if (!isset($_POST['listas_meta_nonce']) || !wp_verify_nonce($_POST['listas_meta_nonce'], 'listas_save_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array('escola', 'cidade', 'uf', 'turma', 'disciplina', 'ordem');
        foreach ($fields as $field) {
            if (isset($_POST['listas_' . $field])) {
                update_post_meta($post_id, '_listas_' . $field, sanitize_text_field($_POST['listas_' . $field]));
            }
        }

        if (isset($_POST['listas_categoria_id'])) {
            update_post_meta($post_id, '_listas_categoria_id', absint($_POST['listas_categoria_id']));
        }

        $cupom_ativo = isset($_POST['listas_cupom_ativo']) ? '1' : '0';
        update_post_meta($post_id, '_listas_cupom_ativo', $cupom_ativo);

        if ($cupom_ativo === '1') {
            update_post_meta($post_id, '_listas_cupom_tipo', sanitize_text_field($_POST['listas_cupom_tipo']));
            update_post_meta($post_id, '_listas_cupom_valor', sanitize_text_field($_POST['listas_cupom_valor']));
            update_post_meta($post_id, '_listas_cupom_minimo', sanitize_text_field($_POST['listas_cupom_minimo']));
        }

        CategorySync::sync($post_id);
    }

    private function renderTemplate($file, array $vars) {
        extract($vars, EXTR_SKIP);
        include LISTAS_ESTUDANTES_PATH . 'templates/admin/' . $file;
    }
}
