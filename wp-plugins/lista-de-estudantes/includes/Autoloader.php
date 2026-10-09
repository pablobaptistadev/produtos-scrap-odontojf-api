<?php
namespace ListasEstudantes;

if (!defined('ABSPATH')) exit;

final class Autoloader {

    public static function register() {
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    public static function autoload($class) {
        $prefix = 'ListasEstudantes\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = LISTAS_ESTUDANTES_PATH . 'includes/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require $file;
        }
    }
}
