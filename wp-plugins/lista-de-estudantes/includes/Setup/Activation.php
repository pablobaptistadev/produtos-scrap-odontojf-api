<?php
namespace ListasEstudantes\Setup;

if (!defined('ABSPATH')) exit;

/**
 * Criação das tabelas custom e hooks de ativação/desativação.
 * Otimizada para alta performance com 60k+ produtos.
 */
final class Activation {

    const DB_VERSION = '1.2';

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

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
        $sql_ordem = "CREATE TABLE IF NOT EXISTS $table_ordem (
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

        self::upgradeOrdemIndex($table_ordem);

        // Atualizar versão para controle
        update_option('listas_similares_db_version', self::DB_VERSION);
    }

    /**
     * Troca a UNIQUE KEY antiga (categoria_id, product_id) pela que inclui a
     * variação. O dbDelta adiciona colunas com segurança, mas não é confiável
     * para REDEFINIR um índice já existente — daí o ALTER explícito, feito só
     * uma vez e nunca fatal: se falhar, a lista continua funcionando com uma
     * variação por produto, que é exatamente o comportamento anterior.
     */
    private static function upgradeOrdemIndex($table_ordem) {
        global $wpdb;

        $has_column = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'variation_id'",
            $table_ordem
        ));
        if (!$has_column) {
            return; // dbDelta não conseguiu adicionar a coluna: não mexe no índice
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
