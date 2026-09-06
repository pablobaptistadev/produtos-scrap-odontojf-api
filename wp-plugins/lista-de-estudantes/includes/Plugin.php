<?php
namespace ListasEstudantes;

use ListasEstudantes\Setup\Activation;
use ListasEstudantes\Setup\PostType;
use ListasEstudantes\Setup\AdminMenu;
use ListasEstudantes\Repository\SimilaresRepository;
use ListasEstudantes\Repository\OrdemRepository;
use ListasEstudantes\Domain\SkuResolver;
use ListasEstudantes\Domain\ProductSearchService;
use ListasEstudantes\Domain\CategorySync;
use ListasEstudantes\Admin\MetaBoxes;
use ListasEstudantes\Admin\Assets as AdminAssets;
use ListasEstudantes\Admin\Ajax\ProdutosAjax;
use ListasEstudantes\Admin\Ajax\SimilaresAjax;
use ListasEstudantes\Frontend\TemplateController;
use ListasEstudantes\Frontend\Assets as FrontendAssets;
use ListasEstudantes\Frontend\Ajax\FrontendAjax;
use ListasEstudantes\Woo\CartDiscounts;
use ListasEstudantes\Woo\CheckoutGuard;

if (!defined('ABSPATH')) exit;

/**
 * Composition root: instancia os módulos e registra todos os hooks.
 */
final class Plugin {

    /** @var Plugin|null */
    private static $instance = null;

    public static function boot() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register();
        }
        return self::$instance;
    }

    private function register() {
        global $wpdb;

        // Serviços compartilhados
        $similares    = new SimilaresRepository($wpdb);
        $ordem        = new OrdemRepository($wpdb);
        $skuResolver  = new SkuResolver($wpdb);
        $search       = new ProductSearchService($skuResolver, $wpdb);
        $frontAssets  = new FrontendAssets();

        // Módulos com hooks próprios
        $modules = array(
            new Activation(),
            new PostType(),
            new AdminMenu(),
            new CategorySync(),
            new MetaBoxes(),
            new AdminAssets(),
            new ProdutosAjax($ordem, $similares, $search, $skuResolver),
            new SimilaresAjax($similares, $skuResolver),
            new TemplateController($ordem, $similares, $frontAssets),
            new FrontendAjax($similares),
            new CartDiscounts(),
            new CheckoutGuard(),
            new Shortcodes(),
        );

        foreach ($modules as $module) {
            $module->register();
        }
    }
}
