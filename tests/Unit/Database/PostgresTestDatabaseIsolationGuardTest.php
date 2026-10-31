<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Illuminate\Database\Connection;
use Tests\TestCase;

final class PostgresTestDatabaseIsolationGuardTest extends TestCase
{
    public function test_rejects_production_environment(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('strictly in testing environment');
            $this->assertIsolatedDatabase();
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
        }
    }

    public function test_rejects_staging_environment(): void
    {
        app()->detectEnvironment(static fn (): string => 'staging');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('strictly in testing environment');
            $this->assertIsolatedDatabase();
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
        }
    }

    public function test_rejects_non_pgsql_driver(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('getDriverName')->willReturn('sqlite');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("driver must be 'pgsql'");
        $this->assertIsolatedDatabase($mock);
    }

    public function test_rejects_production_database_name(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('getDriverName')->willReturn('pgsql');
        $mock->method('getDatabaseName')->willReturn('bybit_signals');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("target database must be strictly 'bybit_test'");
        $this->assertIsolatedDatabase($mock);
    }

    public function test_rejects_unsafe_remote_host(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('getDriverName')->willReturn('pgsql');
        $mock->method('getDatabaseName')->willReturn('bybit_test');
        $mock->method('getConfig')->willReturnMap([
            ['host', null, '198.51.100.25'],
            ['port', null, 5432],
            ['url', null, null],
            ['username', null, 'postgres'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("host '198.51.100.25' is not an authorized local or isolated container endpoint");
        $this->assertIsolatedDatabase($mock);
    }

    public function test_rejects_dangerous_database_url_override(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('getDriverName')->willReturn('pgsql');
        $mock->method('getDatabaseName')->willReturn('bybit_test');
        $mock->method('getConfig')->willReturnMap([
            ['host', null, '127.0.0.1'],
            ['port', null, 25432],
            ['url', null, 'pgsql://user:pass@dangerous-remote-host:5432/bybit_test'],
            ['username', null, 'postgres'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dangerous database URL override detected');
        $this->assertIsolatedDatabase($mock);
    }

    public function test_rejects_server_side_database_identity_mismatch(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('getDriverName')->willReturn('pgsql');
        $mock->method('getDatabaseName')->willReturn('bybit_test');
        $mock->method('getConfig')->willReturnMap([
            ['host', null, '127.0.0.1'],
            ['port', null, 25432],
            ['url', null, null],
            ['username', null, 'postgres'],
        ]);
        $mock->method('selectOne')->willReturn((object) [
            'database_name' => 'production_replica',
            'database_user' => 'postgres',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("server current_database() is 'production_replica', strictly 'bybit_test' required");
        $this->assertIsolatedDatabase($mock);
    }

    public function test_accepts_verified_local_wsl_loopback_topology(): void
    {
        // Must succeed without exception against the active test database
        $this->assertIsolatedDatabase();
        self::assertTrue(true);
    }

    public function test_accepts_verified_ci_container_topology(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('getDriverName')->willReturn('pgsql');
        $mock->method('getDatabaseName')->willReturn('bybit_test');
        $mock->method('getConfig')->willReturnMap([
            ['host', null, 'postgres'],
            ['port', null, 5432],
            ['url', null, null],
            ['username', null, 'postgres'],
        ]);
        $mock->method('selectOne')->willReturn((object) [
            'database_name' => 'bybit_test',
            'database_user' => 'postgres',
        ]);

        $this->assertIsolatedDatabase($mock);
        self::assertTrue(true);
    }

    public function test_is_safe_test_host_recognizes_valid_topologies(): void
    {
        // Safe loopback
        self::assertTrue($this->isSafeTestHost('127.0.0.1'));
        self::assertTrue($this->isSafeTestHost('localhost'));
        self::assertTrue($this->isSafeTestHost('::1'));

        // Safe container service names
        self::assertTrue($this->isSafeTestHost('postgres'));
        self::assertTrue($this->isSafeTestHost('pgsql'));
        self::assertTrue($this->isSafeTestHost('db'));

        // Safe private subnets
        self::assertTrue($this->isSafeTestHost('172.17.0.4'));
        self::assertTrue($this->isSafeTestHost('10.0.1.15'));
        self::assertTrue($this->isSafeTestHost('192.168.1.100'));

        // Unsafe public endpoints
        self::assertFalse($this->isSafeTestHost('8.8.8.8'));
        self::assertFalse($this->isSafeTestHost('198.51.100.5'));
        self::assertFalse($this->isSafeTestHost('database.production.internal'));
        self::assertFalse($this->isSafeTestHost('aws-rds-endpoint.amazonaws.com'));
    }
}
