<?php

declare(strict_types=1);

namespace App\Strategy\Validation;

use App\Strategy\Domain\MethodCategory;
use App\Strategy\Domain\MethodImplementationStatus;
use App\Strategy\Domain\MethodType;
use App\Strategy\Domain\TradingMethodDefinition;
use App\Strategy\MethodRegistry;

final readonly class MethodValidator
{
    public function __construct(
        private MethodRegistry $registry = new MethodRegistry
    ) {}

    /**
     * Validate a combination of trading methods and their parameters.
     *
     * Rules:
     * - Strategy composition must contain at least one signal generator or style.
     * - Strategy composition must have risk management (stop loss or partial exit).
     * - Methods with BLOCKED_BY_DATA cannot be promoted to PAPER_READY.
     * - Parameter bounds are checked against definitions in MethodRegistry.
     *
     * @param  list<string>  $methodIds
     * @param  array<string, mixed>  $parameters
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     eligible_lifecycle: string
     * }
     */
    public function validateComposition(array $methodIds, array $parameters = []): array
    {
        if (empty($methodIds)) {
            return [
                'valid' => false,
                'errors' => ['Strategy composition must contain at least one method.'],
                'warnings' => [],
                'eligible_lifecycle' => 'INELIGIBLE',
            ];
        }

        $methods = [];
        $errors = [];
        $warnings = [];

        $hasSignalOrStyle = false;
        $hasRiskManagement = false;
        $hasBlockedByData = false;
        $minLifecycleRank = 4; // 4 = PaperReady, 3 = ShadowReady, 2 = ResearchReady, 1 = Catalogued, 0 = BlockedByData

        foreach ($methodIds as $id) {
            $method = $this->registry->find($id);
            if ($method === null) {
                $errors[] = "Unknown trading method: '{$id}'.";

                continue;
            }

            $methods[$id] = $method;

            // Strategy composition must contain at least one signal generator or style
            if ($this->isSignalOrStyle($method)) {
                $hasSignalOrStyle = true;
            }

            // Strategy composition must have risk management (stop loss or partial exit)
            if ($this->isRiskManagement($method)) {
                $hasRiskManagement = true;
            }

            // Checks that methods with BLOCKED_BY_DATA cannot be promoted to PAPER_READY
            if ($method->status === MethodImplementationStatus::BlockedByData) {
                $hasBlockedByData = true;
                $warnings[] = "Method '{$method->id}' has status BLOCKED_BY_DATA ({$method->blockerReason}) and cannot be promoted to PAPER_READY.";
            }

            $rank = match ($method->status) {
                MethodImplementationStatus::PaperReady => 4,
                MethodImplementationStatus::ShadowReady => 3,
                MethodImplementationStatus::ResearchReady => 2,
                MethodImplementationStatus::Catalogued, MethodImplementationStatus::Implemented => 1,
                default => 0,
            };

            if ($rank < $minLifecycleRank) {
                $minLifecycleRank = $rank;
            }
        }

        if (! $hasSignalOrStyle) {
            $errors[] = 'Strategy composition must contain at least one signal generator or style.';
        }

        if (! $hasRiskManagement) {
            $errors[] = 'Strategy composition must have risk management (stop loss or partial exit).';
        }

        // Validate parameter bounds against method definitions in MethodRegistry
        $paramErrors = $this->validateParameters($methods, $parameters);
        foreach ($paramErrors as $paramError) {
            $errors[] = $paramError;
        }

        $valid = count($errors) === 0;

        $eligibleLifecycle = match (true) {
            ! $valid => 'INELIGIBLE',
            $hasBlockedByData => MethodImplementationStatus::BlockedByData->value,
            $minLifecycleRank === 4 => MethodImplementationStatus::PaperReady->value,
            $minLifecycleRank === 3 => MethodImplementationStatus::ShadowReady->value,
            $minLifecycleRank === 2 => MethodImplementationStatus::ResearchReady->value,
            $minLifecycleRank === 1 => MethodImplementationStatus::Catalogued->value,
            default => MethodImplementationStatus::BlockedByData->value,
        };

        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'eligible_lifecycle' => $eligibleLifecycle,
        ];
    }

    /**
     * Helper to verify if a strategy composition can be promoted to PAPER_READY.
     *
     * @param  list<string>  $methodIds
     * @param  array<string, mixed>  $parameters
     */
    public function canPromoteToPaper(array $methodIds, array $parameters = []): bool
    {
        $result = $this->validateComposition($methodIds, $parameters);

        return $result['valid'] && $result['eligible_lifecycle'] === MethodImplementationStatus::PaperReady->value;
    }

    private function isSignalOrStyle(TradingMethodDefinition $method): bool
    {
        if ($method->type === MethodType::Signal || $method->type === MethodType::Style) {
            return true;
        }

        $role = (string) ($method->composabilityRules['role'] ?? '');

        return in_array($role, ['signal', 'style'], true);
    }

    private function isRiskManagement(TradingMethodDefinition $method): bool
    {
        if ($method->category === MethodCategory::TradeManagementRisk) {
            return true;
        }

        $role = (string) ($method->composabilityRules['role'] ?? '');
        if ($role === 'risk') {
            return true;
        }

        return in_array($method->id, ['stop_loss', 'trailing_stop', 'partial_entry_exit'], true);
    }

    /**
     * @param  array<string, TradingMethodDefinition>  $methods
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    private function validateParameters(array $methods, array $parameters): array
    {
        $errors = [];

        foreach ($methods as $methodId => $method) {
            $scopedParams = [];
            if (isset($parameters[$methodId]) && is_array($parameters[$methodId])) {
                $scopedParams = $parameters[$methodId];
            }

            foreach ($method->parameters as $spec) {
                $paramName = $spec['name'];
                $type = $spec['type'] ?? 'float';

                $value = null;
                if (array_key_exists($paramName, $scopedParams)) {
                    $value = $scopedParams[$paramName];
                } elseif (array_key_exists($paramName, $parameters) && ! is_array($parameters[$paramName])) {
                    $value = $parameters[$paramName];
                }

                if ($value === null) {
                    continue;
                }

                if ($type === 'integer') {
                    if (! is_int($value) && ! (is_numeric($value) && (int) $value == $value)) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be an integer, got ".gettype($value).'.';

                        continue;
                    }

                    $numVal = (int) $value;
                    if (isset($spec['min']) && $numVal < $spec['min']) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be at least {$spec['min']}, got {$numVal}.";
                    }
                    if (isset($spec['max']) && $numVal > $spec['max']) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be at most {$spec['max']}, got {$numVal}.";
                    }
                } elseif ($type === 'float') {
                    if (! is_numeric($value)) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be numeric, got ".gettype($value).'.';

                        continue;
                    }

                    $numVal = (float) $value;
                    if (isset($spec['min']) && $numVal < $spec['min']) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be at least {$spec['min']}, got {$numVal}.";
                    }
                    if (isset($spec['max']) && $numVal > $spec['max']) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be at most {$spec['max']}, got {$numVal}.";
                    }
                } elseif ($type === 'string') {
                    if (! is_string($value)) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be a string, got ".gettype($value).'.';

                        continue;
                    }
                }

                if (isset($spec['options']) && is_array($spec['options'])) {
                    if (! in_array($value, $spec['options'], true)) {
                        $errors[] = "Parameter '{$paramName}' in method '{$methodId}' must be one of: ".implode(', ', $spec['options']).'.';
                    }
                }
            }
        }

        return $errors;
    }
}
