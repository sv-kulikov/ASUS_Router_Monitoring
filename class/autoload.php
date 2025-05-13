<?php

/**
 * This function dynamically loads classes based on their fully qualified names.
 *
 * @param string $class The fully qualified class name.
 * @return void
 */
spl_autoload_register(function ($class) {
    // Define the namespace prefix for this autoloader
    $prefix = 'Sv\\Network\\VmsRtbw\\';

    // Define the base directory for the namespace prefix
    $baseDir = __DIR__ . '/';

    // Check if the class uses the specified namespace prefix
    $prefixLength = strlen($prefix);
    if (strncmp($prefix, $class, $prefixLength) !== 0) {
        // If the class is not in the namespace, skip this autoloader
        return;
    }

    // Remove the namespace prefix from the class name to get the relative class path
    $relativeClass = substr($class, $prefixLength);

    // Replace namespace separators with directory separators and append .php extension
    $filePath = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // Check if the file exists and include it, otherwise throw an error
    if (file_exists($filePath)) {
        require $filePath;
    } else {
        // Log an error instead of just echoing, to follow best practices
        error_log("Autoloader: file not found for class [$class] at path [$filePath]!");
    }
});