<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\QuoteIntegrity\Application\QimEvidenceImportService;
use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Application\QimIncidentService;
use App\QuoteIntegrity\Domain\QimClassification;
use App\QuoteIntegrity\Domain\QimClockSyncState;
use App\QuoteIntegrity\Http\Requests\EvidenceUploadRequest;
use App\QuoteIntegrity\Http\Resources\QimAlertResource;
use App\QuoteIntegrity\Http\Resources\QimComparisonResource;
use App\QuoteIntegrity\Http\Resources\QimEvidenceResource;
use App\QuoteIntegrity\Http\Resources\QimIncidentDetailResource;
use App\QuoteIntegrity\Http\Resources\QimIncidentResource;
use App\QuoteIntegrity\Http\Resources\QimStatusResource;
use App\QuoteIntegrity\Http\Resources\QimSummaryResource;
use App\QuoteIntegrity\Infrastructure\Persistence\QimAlertRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimComparisonRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimEvidenceFileRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimGapEventRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimIncidentRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuoteIntegrityController extends Controller
{
    public function __construct(
        private readonly QimFeatureFlag $featureFlag,
    ) {}

    private function ensureEnabled(): ?JsonResponse
    {
        if (! $this->featureFlag->isEnabled()) {
            return response()->json([
                'error' => [
                    'code' => 'FEATURE_DISABLED',
                    'message' => 'Quote Integrity Monitor is disabled (QUOTE_INTEGRITY_ENABLED=false).',
                ],
            ], 503);
        }

        return null;
    }

    public function status(): JsonResponse|QimStatusResource
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $capReached = $this->featureFlag->isCapReached();
        $measurementMode = $this->featureFlag->isMeasurementMode();

        $data = [
            'feature_enabled' => true,
            'measurement_mode' => $measurementMode,
            'measurement_cap_reached' => $capReached,
            'level_1_status' => $capReached ? 'CAP_REACHED' : 'ACTIVE',
            'level_2_status' => 'CROSS_SOURCE_UNAVAILABLE',
            'clock_sync_state' => QimClockSyncState::CLOCK_SYNC_UNKNOWN->value,
            'rules_version' => (string) config('quote_integrity.rules_version', '1.0.0'),
        ];

        return new QimStatusResource($data);
    }

    public function summary(Request $request): JsonResponse|QimSummaryResource
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $from = $request->query('from')
            ? CarbonImmutable::parse((string) $request->query('from'))
            : CarbonImmutable::now()->subDay();
        $to = $request->query('to')
            ? CarbonImmutable::parse((string) $request->query('to'))
            : CarbonImmutable::now();

        $comparisonsQuery = QimComparisonRecord::whereBetween('compared_at', [$from, $to]);
        $totalComparisons = (clone $comparisonsQuery)->count();
        $matchedComparisons = (clone $comparisonsQuery)
            ->where('classification', QimClassification::MATCHED->value)
            ->count();
        $channelInconsistencies = (clone $comparisonsQuery)
            ->where('classification', QimClassification::CHANNEL_INCONSISTENCY->value)
            ->count();
        $staleObservationsCount = (clone $comparisonsQuery)
            ->where('classification', QimClassification::STALE_DATA->value)
            ->count();

        $obsQuery = QimObservationRecord::whereBetween('created_at', [$from, $to]);
        $exactDuplicates = (clone $obsQuery)
            ->where('duplicate_classification', QimClassification::DUPLICATE_EXACT->value)
            ->count();
        $conflictingDuplicates = (clone $obsQuery)
            ->where('duplicate_classification', QimClassification::DUPLICATE_CONFLICTING->value)
            ->count();
        $identityConfigDuplicates = (clone $obsQuery)
            ->where('duplicate_classification', QimClassification::DUPLICATE_IDENTITY_CONFIG->value)
            ->count();

        $gapEventsCount = QimGapEventRecord::whereBetween('detected_at', [$from, $to])->count();

        $incidentsCount = [
            'total' => QimIncidentRecord::count(),
            'new' => QimIncidentRecord::where('status', 'NEW')->count(),
            'investigating' => QimIncidentRecord::where('status', 'INVESTIGATING')->count(),
            'explained' => QimIncidentRecord::where('status', 'EXPLAINED')->count(),
            'unresolved' => QimIncidentRecord::where('status', 'UNRESOLVED')->count(),
            'closed' => QimIncidentRecord::where('status', 'CLOSED')->count(),
        ];

        $data = [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'total_comparisons' => $totalComparisons,
            'matched_comparisons' => $matchedComparisons,
            'channel_inconsistencies' => $channelInconsistencies,
            'duplicate_counts' => [
                'exact' => $exactDuplicates,
                'conflicting' => $conflictingDuplicates,
                'identity_config' => $identityConfigDuplicates,
            ],
            'gap_events_count' => $gapEventsCount,
            'stale_observations_count' => $staleObservationsCount,
            'incidents_count' => $incidentsCount,
            'calculated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return new QimSummaryResource($data);
    }

    public function comparisons(Request $request): JsonResponse
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $query = QimComparisonRecord::with(['restObservation', 'wsObservation']);

        if ($symbol = $request->query('symbol')) {
            $query->where('symbol', (string) $symbol);
        }
        if ($interval = $request->query('interval')) {
            $query->where('interval', (string) $interval);
        }
        if ($classification = $request->query('classification')) {
            $query->where('classification', (string) $classification);
        }
        if ($request->has('is_comparable')) {
            $query->where('is_comparable', filter_var($request->query('is_comparable'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($from = $request->query('from')) {
            $query->where('compared_at', '>=', CarbonImmutable::parse((string) $from));
        }
        if ($to = $request->query('to')) {
            $query->where('compared_at', '<=', CarbonImmutable::parse((string) $to));
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $paginated = $query->orderByDesc('id')->paginate($perPage);

        return response()->json(QimComparisonResource::collection($paginated)->response()->getData(true));
    }

    public function incidents(Request $request): JsonResponse
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $query = QimIncidentRecord::query();

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', (string) $severity);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', (string) $priority);
        }
        if ($symbol = $request->query('symbol')) {
            $query->where('symbol', (string) $symbol);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $paginated = $query->orderByDesc('id')->paginate($perPage);

        return response()->json(QimIncidentResource::collection($paginated)->response()->getData(true));
    }

    public function showIncident(string $id): JsonResponse
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $incident = QimIncidentRecord::with('events')
            ->where('id', $id)
            ->orWhere('incident_key', $id)
            ->first();

        if ($incident === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Incident [{$id}] not found.",
                ],
            ], 404);
        }

        return response()->json(['data' => (new QimIncidentDetailResource($incident))->resolve()]);
    }

    public function alerts(Request $request): JsonResponse
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $query = QimAlertRecord::query();

        if ($severity = $request->query('severity')) {
            $query->where('severity', (string) $severity);
        }
        if ($symbol = $request->query('symbol')) {
            $query->where('symbol', (string) $symbol);
        }
        if ($ruleKey = $request->query('rule_key')) {
            $query->where('rule_key', (string) $ruleKey);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $paginated = $query->orderByDesc('id')->paginate($perPage);

        return response()->json(QimAlertResource::collection($paginated)->response()->getData(true));
    }

    public function storeEvidence(EvidenceUploadRequest $request, QimEvidenceImportService $importService): JsonResponse
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $file = $request->file('file');
        if (! $file) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_PAYLOAD',
                    'message' => 'No evidence file uploaded.',
                ],
            ], 422);
        }

        $record = $importService->importEvidence(
            file: $file,
            provenanceClass: (string) $request->input('provenance_class'),
            declaredSource: (string) $request->input('declared_source'),
            declaredTimezone: (string) $request->input('declared_timezone', 'UTC'),
            missingMetadata: $request->input('missing_metadata'),
            uploadedBy: 'operator',
        );

        return response()->json(['data' => (new QimEvidenceResource($record))->resolve()], 201);
    }

    public function downloadEvidence(string $id): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $record = QimEvidenceFileRecord::where('id', $id)
            ->orWhere('file_uuid', $id)
            ->first();

        if ($record === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Evidence file [{$id}] not found.",
                ],
            ], 404);
        }

        $disk = (string) config('quote_integrity.evidence.storage_disk', 'local');
        if (! Storage::disk($disk)->exists($record->storage_path)) {
            return response()->json([
                'error' => [
                    'code' => 'FILE_MISSING_ON_DISK',
                    'message' => 'Physical evidence file missing on private storage.',
                ],
            ], 404);
        }

        return Storage::disk($disk)->download($record->storage_path, $record->original_filename);
    }

    public function transitionIncident(
        Request $request,
        string $id,
        QimIncidentService $incidentService
    ): JsonResponse {
        if ($disabledResponse = $this->ensureEnabled()) {
            return $disabledResponse;
        }

        $incident = QimIncidentRecord::with('events')
            ->where('id', $id)
            ->orWhere('incident_key', $id)
            ->first();

        if ($incident === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => "Incident [{$id}] not found.",
                ],
            ], 404);
        }

        $newStatus = (string) $request->input('status');
        $explanation = (string) $request->input('explanation');
        $evidenceReferences = $request->input('evidence_references');
        $actor = (string) ($request->input('actor') ?? 'operator');

        try {
            $updated = $incidentService->transition(
                incident: $incident,
                newStatus: $newStatus,
                actor: $actor,
                explanation: $explanation,
                evidenceReferences: is_array($evidenceReferences) ? $evidenceReferences : null,
            );

            return response()->json(['data' => (new QimIncidentDetailResource($updated->load('events')))->resolve()]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_TRANSITION',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
