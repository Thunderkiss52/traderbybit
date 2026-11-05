<?php

declare(strict_types=1);

return [
    'go_binary' => env('STRATEGY_DSL_BINARY', base_path('research-go/bin/strategy-dsl')),
    'timeout_seconds' => 60,
    'max_days' => 7,
    'max_active_optimizations' => 2,
    'hosted' => [
        'enabled' => env('STRATEGY_AUTHORING_HOSTED_ENABLED', false),
        'url' => env('STRATEGY_AUTHORING_HOSTED_URL', ''),
        'token' => env('STRATEGY_AUTHORING_HOSTED_TOKEN', ''),
        'model' => env('STRATEGY_AUTHORING_HOSTED_MODEL', ''),
    ],
];
