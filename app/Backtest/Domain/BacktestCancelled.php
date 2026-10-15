<?php

declare(strict_types=1);

namespace App\Backtest\Domain;

use RuntimeException;

final class BacktestCancelled extends RuntimeException {}
