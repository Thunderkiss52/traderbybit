<?php

declare(strict_types=1);

// Keep PHPUnit isolated from production secrets. A developer environment file
// is initialized from the public example only when no local file exists.
$root = dirname(__DIR__);
$environmentFile = $root.'/.env';

if (! is_file($environmentFile)) {
    $example = $root.'/.env.example';

    if (! is_file($example) || ! copy($example, $environmentFile)) {
        throw new RuntimeException('Could not initialize the local .env for PHPUnit.');
    }
}

require $root.'/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    } elseif (str_starts_with($class, 'Tests\\')) {
        $file = $root.'/tests/'.str_replace('\\', '/', substr($class, 6)).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, prepend: true);
