<?php
/**
 * Brain System Autoloader
 * PSR-4 Implementation for Controllers, Models, Services, etc.
 */

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix (optional, currently using flat directory structure for simple PSR-4)
    // Map directory names to class categories
    $map = [
        'Controllers' => __DIR__ . '/../Controllers/',
        'Models'      => __DIR__ . '/../models/',
        'Services'    => __DIR__ . '/../services/',
        'Core'        => __DIR__ . '/../core/',
        'Engine'      => __DIR__ . '/../engine/',
        'Utils'       => __DIR__ . '/../utils/',
    ];

    foreach ($map as $prefix => $base_dir) {
        // If the class uses the prefix (e.g. Models\User)
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relative_class = substr($class, $len + 1);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
        
        // Fallback for flat names (e.g. class UserController in Controllers folder)
        // Since the current structure seems to have files like brain/Controllers/AdminController.php
        // but it's unclear if they use namespaces. 
        // We'll support both.
        
        $file = $base_dir . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
