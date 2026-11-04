<?php

declare(strict_types=1);

return [
    'schema_version' => '1.2.0',
    'row_batch' => 500,
    'max_archive_bytes' => 250 * 1024 * 1024,
    'max_staging_bytes' => 500 * 1024 * 1024,
    'min_free_disk_bytes' => 10 * 1024 * 1024 * 1024,
    'retention_hours' => 24,
    'quota_bytes' => 1024 * 1024 * 1024,
];
