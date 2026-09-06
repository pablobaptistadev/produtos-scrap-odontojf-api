<?php
namespace ListasEstudantes\Setup;

if (!defined('ABSPATH')) exit;

/**
 * Criação das tabelas custom e hooks de ativação/desativação.
 * Otimizada para alta performance com 60k+ produtos.
 */
final class Activation {

    const DB_VERSION = '1.3';

    public function register() {
        // O bootstrap do plugin já roda dentro de 'plugins_loaded', então
        // checamos direto em vez de re-hookar (um novo add_action('plugins_loaded', ...)
        // registrado durante o próprio 'plugins_loaded' dispara tarde demais).
        self::maybeUpgrade();
    }

    public static function maybeUpgrade() {
        if (get_option('listas_similares_db_version') !== self::DB_VERSION) {
            self::createTables();
        }
    }

    public static function createTables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'listas_produtos_similares';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            similar_product_id BIGINT(20) UNSIGNED NOT NULL,
            position INT(11) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_similar (product_id, similar_product_id),
            KEY idx_product_id (product_id),
            KEY idx_similar_product_id (similar_product_id),
            KEY idx_position (product_id, position)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Criar tabela para ordem dos produtos por categoria/lista
        $table_ordem = $wpdb->prefix . 'listas_produtos_ordem';

        // variation_id (>= 1.2): a lista fixa a VARIAÇÃO escolhida pelo
        // professor, não só o produto pai. 0 = produto simples ou "qualquer
        // variação" (comportamento legado).
        $sql_ordem = "CREATE TABLE $table_ordem (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            categoria_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            position INT(11) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY unique_produto_variacao (categoria_id, product_id, variation_id),
            KEY idx_categoria_id (categoria_id),
            KEY idx_position (categoria_id, position)
        ) $charset_collate;";

        dbDelta($sql_ordem);

        // Cinto e suspensório: o dbDelta é sensível a formatação e já falhou
        // aqui uma vez (com "IF NOT EXISTS" ele lê o nome da tabela como "IF" e
        // não altera nada). Sem a coluna, todo insert da lista falha calado.
        self::ensureVariationColumn($table_ordem);
        self::upgradeOrdemIndex($table_ordem);

        // Só marca a migração como feita se a coluna EXISTE de verdade. Gravar a
        // versão sem ela deixava o plugin achando que já migrou e nunca mais
        // tentar — foi assim que a lista parou de salvar.
        if (self::hasVariationColumn($table_ordem)) {
            update_option('listas_similares_db_version', self::DB_VERSION);
        }
    }

    /**
     * Troca a UNIQUE KEY antiga (categoria_id, product_id) pela que inclui a
     * variação. O dbDelta adiciona colunas com segurança, mas não é confiável
     * para REDEFINIR um índice já existente — daí o ALTER explícito, feito só
     * uma vez e nunca fatal: se falhar, a lista continua funcionando com uma
     * variação por produto, que é exatamente o comportamento anterior.
     */
    /** A coluna variation_id já existe na tabela de ordem? */
    private static function hasVariationColumn($table_ordem) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'variation_id'",
            $table_ordem
        ));
    }

    /** Adiciona variation_id se o dbDelta não tiver adicionado. Idempotente. */
    private static function ensureVariationColumn($table_ordem) {
        global $wpdb;
        if (self::hasVariationColumn($table_ordem)) return;
        $wpdb->query("ALTER TABLE `{$table_ordem}`
                      ADD COLUMN `variation_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `product_id`");
    }

    private static function upgradeOrdemIndex($table_ordem) {
        global $wpdb;

        if (!self::hasVariationColumn($table_ordem)) {
            return; // sem a coluna não dá para mexer no índice
        }

        $old_index = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'unique_produto_categoria'",
            $table_ordem
        ));
        if ($old_index) {
            $wpdb->query("ALTER TABLE `{$table_ordem}` DROP INDEX `unique_produto_categoria`");
        }

        $new_index = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'unique_produto_variacao'",
            $table_ordem
        ));
        if (!$new_index) {
            $wpdb->query("ALTER TABLE `{$table_ordem}`
                          ADD UNIQUE KEY `unique_produto_variacao` (categoria_id, product_id, variation_id)");
        }
    }

    public static function activate() {
        self::createTables();
        PostType::registerPostType();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
