<?php

declare(strict_types=1);

// Release tests may connect only to the ephemeral runner service.
if (getenv('APP_ENV') !== 'testing' || getenv('RUN_DB') !== 'bybit_test'
    || getenv('TEST_DB_DATABASE') !== 'bybit_test'
    || ! in_array(getenv('TEST_DB_HOST'), ['127.0.0.1', 'traderbybit-php85-postgres'], true)) {
    throw new RuntimeException('Isolated test database configuration required.');
}
// Verify the *actual* connection settings consumed by Laravel, not only
// auxiliary TEST_DB_* values. A populated TEST_DB_HOST alone cannot prove
// that PHPUnit will not use another (possibly production) DB_HOST / DB_URL.
if (getenv('DB_URL') !== false && trim((string) getenv('DB_URL')) !== '') {
    throw new RuntimeException('Database URL override is not permitted in isolated release tests.');
}
$connection = getenv('DB_CONNECTION');
if ($connection === 'pgsql') {
    foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $key) {
        if (getenv('DB_'.$key) !== getenv('TEST_DB_'.$key)) {
            throw new RuntimeException('Actual PostgreSQL connection differs from verified test configuration.');
        }
    }
} elseif ($connection === 'sqlite') {
    if (getenv('DB_DATABASE') !== ':memory:') {
        throw new RuntimeException('SQLite release tests must run in memory.');
    }
} else {
    throw new RuntimeException('Unsupported release-test database driver.');
}

$pdo = new PDO('pgsql:host='.getenv('TEST_DB_HOST').';port='.getenv('TEST_DB_PORT').';dbname=bybit_test', getenv('TEST_DB_USERNAME'), getenv('TEST_DB_PASSWORD'));
$identity = $pdo->query('select current_database(), current_user')->fetch(PDO::FETCH_NUM);
if ($identity !== ['bybit_test', 'postgres']) {
    throw new RuntimeException('Test database identity mismatch.');
}

// Ensure bybit_signals test role exists for QIM immutability verification.
$pdo->exec("
    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bybit_signals') THEN
            CREATE ROLE bybit_signals WITH LOGIN PASSWORD 'signals_test_pass';
        END IF;
    END \$\$;
");

echo "Isolated test database identity verified.\n";
