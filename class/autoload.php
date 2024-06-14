<?php

/**
 * Registers class autoloader.
 * @param $class string The fully-qualified class name.
 * @return void
 */
spl_autoload_register(function ($class) {

    $prefix = 'Sv\\Network\\VmsRtbw\\';

    $base_dir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);

    $file = str_replace('\\', '/', $base_dir . $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    } else {
        echo "File $file not found";
    }
});