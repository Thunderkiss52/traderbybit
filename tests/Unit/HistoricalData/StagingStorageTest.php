<?php

declare(strict_types=1);

namespace Tests\Unit\HistoricalData;

use App\HistoricalData\AcquisitionFailure;
use App\HistoricalData\DiskSpaceProbe;
use App\HistoricalData\StagingStorage;
use Tests\TestCase;

final class StagingStorageTest extends TestCase
{
    public function test_admit_succeeds_when_free_space_meets_requirements(): void
    {
        $probe = $this->createStub(DiskSpaceProbe::class);
        $probe->method('freeBytes')->willReturn(50_000_000_000.0); // 50 GB

        $storage = new StagingStorage($probe);
        $result = $storage->admit(databaseEstimate: 1_000_000_000);

        self::assertIsArray($result);
        self::assertSame(50_000_000_000, $result['free_bytes']);
        self::assertGreaterThan(0, $result['required_bytes']);
        self::assertArrayHasKey('staged_bytes', $result);
    }

    public function test_admit_fails_when_free_space_is_insufficient(): void
    {
        $probe = $this->createStub(DiskSpaceProbe::class);
        $probe->method('freeBytes')->willReturn(1_000_000.0); // 1 MB, insufficient

        $storage = new StagingStorage($probe);

        $this->expectException(AcquisitionFailure::class);
        $this->expectExceptionMessage('INSUFFICIENT_SAFE_DISK_SPACE');

        $storage->admit(databaseEstimate: 500_000_000);
    }

    public function test_admit_fails_when_disk_space_probe_returns_false(): void
    {
        $probe = $this->createStub(DiskSpaceProbe::class);
        $probe->method('freeBytes')->willReturn(false);

        $storage = new StagingStorage($probe);

        $this->expectException(AcquisitionFailure::class);
        $this->expectExceptionMessage('INSUFFICIENT_SAFE_DISK_SPACE');

        $storage->admit();
    }
}
