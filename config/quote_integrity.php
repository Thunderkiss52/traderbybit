<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Quote Integrity Monitor Feature Flag
    |--------------------------------------------------------------------------
    |
    | Master switch for QIM. When false (default), all observation recording,
    | background checks, and scheduled jobs are disabled.
    | Read APIs return 503 FEATURE_DISABLED.
    |
    */
    'enabled' => (bool) env('QUOTE_INTEGRITY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Rules & Tolerances Version
    |--------------------------------------------------------------------------
    |
    | Version string identifying the current set of comparison rules and
    | threshold settings. Stored with every comparison and alert.
    |
    */
    'rules_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Bounded Measurement Period Settings
    |--------------------------------------------------------------------------
    |
    | Active only when explicit operator permission is given.
    | Requires both disk cap and TTL to be strictly positive.
    |
    */
    'measurement_mode' => (bool) env('QUOTE_INTEGRITY_MEASUREMENT_MODE', false),
    'measurement_disk_cap_gb' => env('QUOTE_INTEGRITY_MEASUREMENT_DISK_CAP_GB') !== null
        ? (int) env('QUOTE_INTEGRITY_MEASUREMENT_DISK_CAP_GB')
        : 10,
    'measurement_ttl_days' => env('QUOTE_INTEGRITY_MEASUREMENT_TTL_DAYS') !== null
        ? (int) env('QUOTE_INTEGRITY_MEASUREMENT_TTL_DAYS')
        : 7,

    /*
    |--------------------------------------------------------------------------
    | Permanent Retention and Purge Authorization
    |--------------------------------------------------------------------------
    |
    | Permanent retention period in days. If null, measurement-mode cleanup
    | applies via measurement_ttl_days. Scheduled purge requires explicit
    | authorization via QIM_PURGE_AUTHORIZED=true.
    |
    */
    'retention_days' => env('QUOTE_INTEGRITY_RETENTION_DAYS') !== null
        ? (int) env('QUOTE_INTEGRITY_RETENTION_DAYS')
        : null,
    'purge_authorized' => (bool) env('QIM_PURGE_AUTHORIZED', false),

    /*
    |--------------------------------------------------------------------------
    | Sampling & Comparison Parameters
    |--------------------------------------------------------------------------
    |
    | Default candle sampling interval (1m), grace period before REST sampling,
    | maximum data age before flagging STALE_DATA, and clock-skew tolerance.
    |
    */
    'default_sampling_interval' => '1m',
    'grace_period_seconds' => (int) env('QUOTE_INTEGRITY_GRACE_PERIOD_SECONDS', 10),
    'max_data_age_seconds' => (int) env('QUOTE_INTEGRITY_MAX_DATA_AGE_SECONDS', 300),
    'clock_skew_tolerance_ms' => (int) env('QUOTE_INTEGRITY_CLOCK_SKEW_TOLERANCE_MS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Comparison Tolerances (Decimal Strings / Ratios)
    |--------------------------------------------------------------------------
    |
    | Tolerances used by QimComparisonEngine. Respect instrument precision
    | without floating-point rounding.
    |
    */
    'tolerances' => [
        'price_absolute' => env('QUOTE_INTEGRITY_TOLERANCE_PRICE_ABS', '0.00000001'),
        'price_relative' => env('QUOTE_INTEGRITY_TOLERANCE_PRICE_REL', '0.0001'), // 0.01%
        'volume_relative' => env('QUOTE_INTEGRITY_TOLERANCE_VOLUME_REL', '0.001'), // 0.1%
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence Storage & Upload Limits
    |--------------------------------------------------------------------------
    */
    'evidence' => [
        'max_upload_size_bytes' => (int) env('QUOTE_INTEGRITY_MAX_UPLOAD_BYTES', 20 * 1024 * 1024), // 20 MB
        'allowed_extensions' => ['png', 'jpg', 'jpeg', 'pdf', 'csv', 'json', 'txt'],
        'allowed_mimes' => [
            'image/png',
            'image/jpeg',
            'application/pdf',
            'text/csv',
            'application/json',
            'text/plain',
        ],
        'storage_disk' => env('QUOTE_INTEGRITY_STORAGE_DISK', 'local'),
        'storage_directory' => 'qim-evidence',
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting Thresholds
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'window_minutes' => (int) env('QUOTE_INTEGRITY_ALERT_WINDOW_MINUTES', 60),
        'latency_threshold_ms' => (int) env('QUOTE_INTEGRITY_LATENCY_THRESHOLD_MS', 5000),
        'repeated_incidents_threshold' => (int) env('QUOTE_INTEGRITY_REPEATED_INCIDENTS_THRESHOLD', 3),
    ],
];
