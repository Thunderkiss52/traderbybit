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
