<?php
namespace ImageUsageTracker;

class Autoloader {
    public function __construct() {
        spl_autoload_register([$this, 'autoload']);
    }

    public function autoload($class_name) {
        // Only handle classes in our namespace
        if (strpos($class_name, 'ImageUsageTracker\\') !== 0) {
            return;
        }

        $class_name = str_replace('ImageUsageTracker\\', '', $class_name);
        $class_name = strtolower($class_name);
        $class_name = str_replace('_', '-', $class_name);
        $file_name = 'class-' . $class_name . '.php';

        $file = IUT_PLUGIN_DIR . 'includes/' . $file_name;

        if (file_exists($file)) {
            require_once $file;
        }
    }
}

new Autoloader();
