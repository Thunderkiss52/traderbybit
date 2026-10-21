<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\QuoteIntegrity\Domain\QimEvidenceProvenance;
use App\QuoteIntegrity\Domain\QimInvestigationStatus;
use App\QuoteIntegrity\Infrastructure\Persistence\QimEvidenceFileRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

class QimEvidenceImportService
{
    public function importEvidence(
        UploadedFile $file,
        string $provenanceClass,
        string $declaredSource,
        string $declaredTimezone = 'UTC',
        ?array $missingMetadata = null,
        string $uploadedBy = 'operator',
    ): QimEvidenceFileRecord {
        $maxBytes = (int) config('quote_integrity.evidence.max_upload_size_bytes', 20 * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            throw new HttpException(413, 'EVIDENCE_TOO_LARGE: Uploaded file exceeds the 20 MB limit.');
        }

        $allowedExts = (array) config(
            'quote_integrity.evidence.allowed_extensions',
            ['png', 'jpg', 'jpeg', 'pdf', 'csv', 'json', 'txt']
        );
        $clientExt = strtolower($file->getClientOriginalExtension());
        if (! in_array($clientExt, $allowedExts, true)) {
            throw new HttpException(415, "EVIDENCE_FORMAT_NOT_ALLOWED: Extension [{$clientExt}] is not permitted.");
        }

        // Validate provenance enum
        $provenance = QimEvidenceProvenance::tryFrom($provenanceClass) ?? QimEvidenceProvenance::ORIGINAL_EXPORT;

        // Compute SHA-256 strictly from original raw bytes
        $rawBytes = (string) file_get_contents($file->getRealPath());
        $sha256 = hash('sha256', $rawBytes);

        // Generate private storage path using UUID4 outside webroot
        $fileUuid = Uuid::uuid4()->toString();
        $safeFileName = "{$fileUuid}.{$clientExt}";
        $storageDir = (string) config('quote_integrity.evidence.storage_directory', 'qim-evidence');
        $storageDisk = (string) config('quote_integrity.evidence.storage_disk', 'local');

        $storedPath = Storage::disk($storageDisk)->putFileAs($storageDir, $file, $safeFileName);
        if (! $storedPath) {
            throw new \RuntimeException('Failed to store private evidence file.');
        }

        $now = CarbonImmutable::now();

        return QimEvidenceFileRecord::create([
            'file_uuid' => $fileUuid,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storedPath,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size_bytes' => $file->getSize(),
            'sha256_hash' => $sha256,
            'provenance_class' => $provenance->value,
            'declared_source' => $declaredSource,
            'declared_timezone' => $declaredTimezone,
            'missing_metadata' => $missingMetadata ?? [],
            'investigation_status' => QimInvestigationStatus::INSUFFICIENT_EVIDENCE->value,
            'uploaded_by' => $uploadedBy,
            'uploaded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
