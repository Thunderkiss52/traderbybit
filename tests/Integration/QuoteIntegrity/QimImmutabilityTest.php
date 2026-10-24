<?php

declare(strict_types=1);

namespace Tests\Integration\QuoteIntegrity;

use Carbon\CarbonImmutable;
use PDO;
use PDOException;
use Tests\TestCase;

final class QimImmutabilityTest extends TestCase
{
    private string $testHost;

    private int $testPort;

    private string $testDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePostgresTestDatabaseReady('qim_observations');

        $this->testPort = (int) env('TEST_DB_PORT', 25432);
        $this->testHost = (string) env('TEST_DB_HOST', '127.0.0.1');
        $this->testDb = (string) env('TEST_DB_DATABASE', 'bybit_test');

        $adminPdo = new PDO(
            "pgsql:host={$this->testHost};port={$this->testPort};dbname={$this->testDb}",
            'postgres',
            'testpass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $adminPdo->exec("
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bybit_signals') THEN
                    CREATE ROLE bybit_signals WITH LOGIN PASSWORD 'signals_test_pass';
                ELSE
                    ALTER ROLE bybit_signals WITH LOGIN PASSWORD 'signals_test_pass';
                END IF;
            END \$\$;
            GRANT CONNECT ON DATABASE {$this->testDb} TO bybit_signals;
            GRANT USAGE ON SCHEMA public TO bybit_signals;
            GRANT SELECT, INSERT ON qim_observations TO bybit_signals;
            REVOKE UPDATE, DELETE ON qim_observations FROM bybit_signals;
            GRANT SELECT, INSERT ON qim_gap_events TO bybit_signals;
            REVOKE UPDATE, DELETE ON qim_gap_events FROM bybit_signals;
        ");
    }

    public function test_bybit_signals_role_cannot_update_or_delete_qim_observations(): void
    {
        // 1. Insert initial observation using admin/migration connection
        $adminPdo = new PDO(
            "pgsql:host={$this->testHost};port={$this->testPort};dbname={$this->testDb}",
            'postgres',
            'testpass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $now = CarbonImmutable::now()->toISOString();
        $adminStmt = $adminPdo->prepare("
            INSERT INTO qim_observations (
                provider, channel, category, symbol, interval,
                candle_open_time, candle_close_time, open, high, low, close, volume,
                is_closed, local_received_at, clock_sync_state, logical_key, capture_attempt_id,
                payload_sha256, raw_payload, created_at
            ) VALUES (
                'bybit', 'WS', 'linear', 'BTCUSDT', '1m',
                :open_time, :close_time, 65000, 65100, 64900, 65050, 10,
                true, :local_rec, 'CLOCK_SYNC_UNKNOWN', 'test-logical-key-1', 'test-capture-1',
                'dummy-sha256', '{\"test\": true}', :created_at
            ) RETURNING id;
        ");
        $adminStmt->execute([
            ':open_time' => $now,
            ':close_time' => $now,
            ':local_rec' => $now,
            ':created_at' => $now,
        ]);
        $obsId = $adminStmt->fetchColumn();
        $this->assertNotEmpty($obsId);

        // 2. Connect as ordinary application role: bybit_signals
        $appPdo = new PDO(
            "pgsql:host={$this->testHost};port={$this->testPort};dbname={$this->testDb}",
            'bybit_signals',
            'signals_test_pass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // 3. SELECT must succeed
        $selectStmt = $appPdo->prepare('SELECT id, provider, symbol FROM qim_observations WHERE id = :id');
        $selectStmt->execute([':id' => $obsId]);
        $row = $selectStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('BTCUSDT', $row['symbol']);

        // 4. Negative Control: UPDATE must fail with permission denied (SQLSTATE 42501)
        $updateRejected = false;
        try {
            $updateStmt = $appPdo->prepare('UPDATE qim_observations SET open = 99999 WHERE id = :id');
            $updateStmt->execute([':id' => $obsId]);
        } catch (PDOException $e) {
            $updateRejected = true;
            $this->assertSame('42501', $e->getCode(), 'Expected 42501 insufficient_privilege on UPDATE');
        }
        $this->assertTrue($updateRejected, 'Ordinary DB role MUST be denied UPDATE on qim_observations');

        // 5. Negative Control: DELETE must fail with permission denied (SQLSTATE 42501)
        $deleteRejected = false;
        try {
            $deleteStmt = $appPdo->prepare('DELETE FROM qim_observations WHERE id = :id');
            $deleteStmt->execute([':id' => $obsId]);
        } catch (PDOException $e) {
            $deleteRejected = true;
            $this->assertSame('42501', $e->getCode(), 'Expected 42501 insufficient_privilege on DELETE');
        }
        $this->assertTrue($deleteRejected, 'Ordinary DB role MUST be denied DELETE on qim_observations');

        // 6. Cleanup via admin
        $adminPdo->exec("DELETE FROM qim_observations WHERE id = {$obsId}");
    }

    public function test_bybit_signals_role_cannot_update_or_delete_qim_gap_events(): void
    {
        $adminPdo = new PDO(
            "pgsql:host={$this->testHost};port={$this->testPort};dbname={$this->testDb}",
            'postgres',
            'testpass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $now = CarbonImmutable::now()->toISOString();
        $adminStmt = $adminPdo->prepare("
            INSERT INTO qim_gap_events (
                detection_id, event_type, provider, channel, category, symbol, interval,
                gap_from, gap_to, detected_at, created_at
            ) VALUES (
                'test-det-id-1', 'DETECTED', 'bybit', 'WS', 'linear', 'BTCUSDT', '1m',
                :gap_from, :gap_to, :detected_at, :created_at
            ) RETURNING id;
        ");
        $adminStmt->execute([
            ':gap_from' => $now,
            ':gap_to' => $now,
            ':detected_at' => $now,
            ':created_at' => $now,
        ]);
        $gapId = $adminStmt->fetchColumn();
        $this->assertNotEmpty($gapId);

        $appPdo = new PDO(
            "pgsql:host={$this->testHost};port={$this->testPort};dbname={$this->testDb}",
            'bybit_signals',
            'signals_test_pass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Negative Control: UPDATE must fail
        $updateRejected = false;
        try {
            $updateStmt = $appPdo->prepare("UPDATE qim_gap_events SET outcome = 'TAMPERED' WHERE id = :id");
            $updateStmt->execute([':id' => $gapId]);
        } catch (PDOException $e) {
            $updateRejected = true;
            $this->assertSame('42501', $e->getCode());
        }
        $this->assertTrue($updateRejected, 'Ordinary DB role MUST be denied UPDATE on qim_gap_events');

        // Negative Control: DELETE must fail
        $deleteRejected = false;
        try {
            $deleteStmt = $appPdo->prepare('DELETE FROM qim_gap_events WHERE id = :id');
            $deleteStmt->execute([':id' => $gapId]);
        } catch (PDOException $e) {
            $deleteRejected = true;
            $this->assertSame('42501', $e->getCode());
        }
        $this->assertTrue($deleteRejected, 'Ordinary DB role MUST be denied DELETE on qim_gap_events');

        // Cleanup via admin
        $adminPdo->exec("DELETE FROM qim_gap_events WHERE id = {$gapId}");
    }
}
