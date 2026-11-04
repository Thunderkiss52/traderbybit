<?php

declare(strict_types=1);

return [
    'go_binary' => env('STRATEGY_DSL_BINARY', base_path('research-go/bin/strategy-dsl')),
    'timeout_seconds' => 60,
    'max_days' => 7,
];
