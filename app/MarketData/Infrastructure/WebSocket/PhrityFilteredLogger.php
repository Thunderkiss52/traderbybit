<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\WebSocket;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

final class PhrityFilteredLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly LoggerInterface $delegate) {}

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $messageStr = (string) $message;

        // Demote phrity internal read timeout errors to debug level so normal idle timeouts do not pollute logs
        if (str_contains($messageStr, 'Empty read; connection dead?') && ! empty($context['meta']['timed_out'])) {
            $this->delegate->debug($messageStr, $context);

            return;
        }

        $this->delegate->log($level, $messageStr, $context);
    }
}
