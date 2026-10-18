<?php

require __DIR__.'/../vendor/autoload.php';

// Explicit local integration testing only. Consumers continue using Composer releases.
if ($source = getenv('SAOLA_CORE_SOURCE')) {
    $root = realpath($source);
    if ($root === false || ! is_file($root.'/src/core/Routing/Permission.php')) {
        throw new RuntimeException('SAOLA_CORE_SOURCE must point to the updated core checkout.');
    }
    spl_autoload_register(function (string $class) use ($root): void {
        $prefix = 'Saola\\Core\\';
        if (str_starts_with($class, $prefix)) {
            $file = $root.'/src/core/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}
