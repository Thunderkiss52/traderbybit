<?php

declare(strict_types=1);

namespace App\Exchanges\Http;

use App\Exchanges\Application\ExchangeCapabilityValidator;
use App\Exchanges\Application\ExchangeCatalogService;
use App\Exchanges\Application\ExchangeCoverageService;
use App\Exchanges\Application\HistoricalCandleCsvImporter;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ExchangeManagementController extends Controller
{
    /**
     * List all registered exchanges with summary metrics, status, and capabilities.
     */
    public function index(ExchangeCatalogService $catalog): JsonResponse
    {
        return response()->json([
            'data' => $catalog->all(),
        ]);
    }

    /**
     * Register a new exchange identifier.
     */
    public function store(Request $request, ExchangeCatalogService $catalog): JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'required|string|min:2|max:32|regex:/^[a-z0-9_-]+$/',
                'name' => 'required|string|min:1|max:100',
                'market_type' => 'sometimes|string|in:linear_perpetual,spot,inverse_perpetual',
                'description' => 'nullable|string|max:500',
                'capabilities' => 'sometimes|array',
                'cost_model' => 'sometimes|array',
                'paper_execution_assumptions' => 'sometimes|array',
            ]);

            $exchange = $catalog->register($validated);

            return response()->json([
                'data' => $exchange->toArray(),
                'message' => "Exchange '{$exchange->id}' registered successfully in OFFLINE_DATA_SOURCE status.",
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_ARGUMENT',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'REGISTRATION_FAILED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get detailed status, capabilities, and eligibility diagnostics for an exchange.
     */
    public function show(
        string $id,
        ExchangeCatalogService $catalog,
        ExchangeCapabilityValidator $validator
    ): JsonResponse {
        $exchange = $catalog->find($id);
        if ($exchange === null) {
            return response()->json([
                'error' => [
                    'code' => 'EXCHANGE_NOT_FOUND',
                    'message' => "Exchange '{$id}' is not registered.",
                ],
                'message' => "Exchange '{$id}' is not registered.",
            ], Response::HTTP_NOT_FOUND);
        }

        $eligibility = $validator->evaluate($exchange);

        return response()->json([
            'data' => array_merge($exchange->toArray(), [
                'eligibility' => $eligibility,
            ]),
        ]);
    }

    /**
     * Get historical dataset coverage and diagnostics report for an exchange.
     */
    public function coverage(string $id, ExchangeCoverageService $coverage, ExchangeCatalogService $catalog): JsonResponse
    {
        $exchange = $catalog->find($id);
        if ($exchange === null) {
            return response()->json([
                'error' => [
                    'code' => 'EXCHANGE_NOT_FOUND',
                    'message' => "Exchange '{$id}' is not registered.",
                ],
                'message' => "Exchange '{$id}' is not registered.",
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $coverage->report($id),
        ]);
    }

    /**
     * Upload and validate historical candle CSV dataset for an exchange.
     */
    public function uploadCandles(
        Request $request,
        string $id,
        HistoricalCandleCsvImporter $importer,
        ExchangeCatalogService $catalog
    ): JsonResponse {
        $exchange = $catalog->find($id);
        if ($exchange === null) {
            return response()->json([
                'error' => [
                    'code' => 'EXCHANGE_NOT_FOUND',
                    'message' => "Exchange '{$id}' is not registered.",
                ],
                'message' => "Exchange '{$id}' is not registered.",
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $request->validate([
                'symbol' => 'required|string|min:2|max:32',
                'interval' => 'required|string|in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d',
                'category' => 'sometimes|string',
                'file' => 'required_without:csv|file|max:51200',
                'csv' => 'required_without:file|string',
            ]);

            $csvContent = '';
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                if ($file === null || ! $file->isValid()) {
                    throw new InvalidArgumentException('Uploaded file is invalid or missing.');
                }
                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    throw new InvalidArgumentException('Failed to read uploaded file contents.');
                }
                $csvContent = $content;
            } else {
                $csvContent = (string) $request->input('csv', '');
            }

            if (trim($csvContent) === '') {
                throw new InvalidArgumentException('CSV content cannot be empty.');
            }

            $symbol = strtoupper(trim((string) $request->input('symbol')));
            $interval = trim((string) $request->input('interval'));
            $category = (string) $request->input('category', 'linear');

            $result = $importer->import(
                exchangeId: $id,
                symbol: $symbol,
                interval: $interval,
                csvContent: $csvContent,
                category: $category
            );

            return response()->json([
                'data' => $result,
                'message' => "Successfully processed CSV for {$symbol} ({$interval}): {$result['imported_bars']} imported, {$result['duplicates_skipped']} duplicates skipped, {$result['conflicts_recorded']} conflicts recorded.",
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_REJECTED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_FAILED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update exchange lifecycle state (OFFLINE_DATA_SOURCE, CONNECTED_PUBLIC_DATA, RESEARCH_ENABLED, PAPER_ELIGIBLE).
     */
    public function updateLifecycle(
        Request $request,
        string $id,
        ExchangeCatalogService $catalog
    ): JsonResponse {
        try {
            $request->validate([
                'status' => 'required|string|in:OFFLINE_DATA_SOURCE,CONNECTED_PUBLIC_DATA,RESEARCH_ENABLED,PAPER_ELIGIBLE',
            ]);

            $status = (string) $request->input('status');
            $exchange = $catalog->updateLifecycle($id, $status);

            return response()->json([
                'data' => $exchange->toArray(),
                'message' => "Exchange '{$id}' lifecycle updated to {$exchange->lifecycle_status}.",
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'LIFECYCLE_TRANSITION_REJECTED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'UPDATE_FAILED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Explicit owner approval for PAPER activation.
     */
    public function approvePaper(
        Request $request,
        string $id,
        ExchangeCatalogService $catalog
    ): JsonResponse {
        try {
            $request->validate([
                'approved' => 'required|boolean|accepted',
                'reference' => 'sometimes|string|max:100',
            ]);

            $reference = (string) $request->input('reference', 'OWNER_APPROVAL');
            $exchange = $catalog->approvePaper($id, true, $reference);

            return response()->json([
                'data' => $exchange->toArray(),
                'message' => "Exchange '{$id}' approved for PAPER activation. Lifecycle status is now ACTIVE_PAPER.",
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'PAPER_ACTIVATION_REJECTED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'ACTIVATION_FAILED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Provision 13 primary PAPER accounts for an approved exchange.
     */
    public function syncTopology(
        Request $request,
        string $id,
        PaperTopologySynchronizer $synchronizer,
        ExchangeCatalogService $catalog
    ): JsonResponse {
        try {
            $exchange = $catalog->get($id);
            if (! $exchange->paper_approved) {
                return response()->json([
                    'error' => [
                        'code' => 'EXCHANGE_NOT_APPROVED_FOR_PAPER',
                        'message' => "Exchange '{$id}' is not approved for PAPER trading. Owner approval is required before provisioning PAPER accounts.",
                    ],
                    'message' => "Exchange '{$id}' is not approved for PAPER trading. Owner approval is required before provisioning PAPER accounts.",
                ], Response::HTTP_FORBIDDEN);
            }

            $apply = $request->boolean('apply');
            $result = $synchronizer->sync($apply);

            return response()->json([
                'data' => $result,
                'message' => $apply
                    ? 'Topology synchronized successfully for approved exchanges. 13 primary accounts provisioned.'
                    : 'Topology sync dry-run completed successfully.',
            ]);
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'TOPOLOGY_SYNC_REJECTED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            return response()->json([
                'error' => [
                    'code' => 'TOPOLOGY_SYNC_FAILED',
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
