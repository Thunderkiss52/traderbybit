<?php

declare(strict_types=1);

return [
    'symbols' => ['BTCUSDT', 'ETHUSDT'],
    'queue' => 'research',
    'queue_connection' => env('HISTORICAL_QUEUE_CONNECTION', 'redis'),
    'replay_binary' => env('HISTORICAL_REPLAY_BINARY', base_path('research-go/bin/historical-replay')),
    'concurrency' => 1,
    'max_attempts' => 4,
    'lease_seconds' => 300,
    'download_timeout' => 180,
    'max_archive_bytes' => 536870912,
    'max_expanded_bytes' => 2000000000,
    'max_file_bytes' => 1500000000,
    'max_entries' => 100,
    'decompression_seconds' => 120,
    'chunk_rows' => 5000,
    'reserve_disk_bytes' => 10737418240,
    'max_staging_bytes' => 6442450944,
    'go_binary' => env('HISTORICAL_IMPORT_BINARY', base_path('research-go/bin/historical-import')),
    'license' => [
        'version' => 'VISION-1.0-2026-08-26',
        'attribution' => 'Binance Vision / Binance Data Collection',
        'license' => 'CC-BY-NC-SA-4.0',
        'terms' => 'https://github.com/binance/binance-public-data/blob/master/TERMS_AND_CONDITIONS.md',
        'permitted_uses' => ['NONCOMMERCIAL_RESEARCH', 'NONCOMMERCIAL_LEARNING'],
        'exports_enabled' => false,
    ],
];
