<?php

declare(strict_types=1);

// Run in every PHP build/runtime; never accept a Composer platform override as proof.
$errors = [];
if (version_compare(PHP_VERSION, '8.5.12', '<') || version_compare(PHP_VERSION, '8.6.0', '>=')) {
    $errors[] = 'Expected PHP ~8.5.12; got '.PHP_VERSION;
}

$extensions = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'intl', 'mbstring', 'openssl', 'pcntl', 'pdo_pgsql', 'pdo_sqlite', 'redis', 'session', 'sodium', 'tokenizer', 'xml', 'xmlwriter', 'zip', 'Zend OPcache'];
$versions = [];
foreach ($extensions as $extension) {
    if (! extension_loaded($extension)) {
        $errors[] = 'Missing extension: '.$extension;
    } else {
        $versions[$extension] = phpversion($extension);
    }
}

echo json_encode(['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'extensions' => $versions, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors === [] ? 0 : 1);
