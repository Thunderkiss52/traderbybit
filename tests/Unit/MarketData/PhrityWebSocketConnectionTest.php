<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData;

use App\MarketData\Infrastructure\WebSocket\PhrityFilteredLogger;
use App\MarketData\Infrastructure\WebSocket\PhrityWebSocketConnection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use WebSocket\Client;
use WebSocket\Exception\CloseException;
use WebSocket\Exception\ConnectionClosedException;
use WebSocket\Exception\ConnectionFailureException;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;

final class PhrityWebSocketConnectionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_receive_returns_null_on_connection_timeout(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('isConnected')->andReturn(true);
        $client->shouldReceive('receive')->once()->andThrow(new ConnectionTimeoutException('timed out'));

        $connection = new PhrityWebSocketConnection($client, 1);

        self::assertNull($connection->receive());
    }

    public function test_receive_returns_content_on_successful_message(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('isConnected')->andReturn(true);
        $client->shouldReceive('receive')->once()->andReturn(new Text('{"op":"pong"}'));

        $connection = new PhrityWebSocketConnection($client, 1);

        self::assertSame('{"op":"pong"}', $connection->receive());
    }

    public function test_receive_throws_terminal_runtime_exception_on_eof(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('isConnected')->andReturn(true);
        $client->shouldReceive('receive')->once()->andThrow(new ConnectionClosedException('remote EOF'));

        $connection = new PhrityWebSocketConnection($client, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WebSocket connection closed by peer (EOF).');
        $connection->receive();
    }

    public function test_receive_throws_on_connection_failure(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('isConnected')->andReturn(true);
        $client->shouldReceive('receive')->once()->andThrow(new ConnectionFailureException('socket reset'));

        $connection = new PhrityWebSocketConnection($client, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WebSocket connection failure');
        $connection->receive();
    }

    public function test_receive_throws_on_close_frame(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('isConnected')->andReturn(true);
        $client->shouldReceive('receive')->once()->andThrow(new CloseException('going away', 1001));

        $connection = new PhrityWebSocketConnection($client, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WebSocket close frame received');
        $connection->receive();
    }

    public function test_receive_throws_when_not_connected(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('isConnected')->andReturn(false);

        $connection = new PhrityWebSocketConnection($client, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WebSocket is not connected; connection dead.');
        $connection->receive();
    }

    public function test_filtered_logger_demotes_timed_out_empty_read_to_debug(): void
    {
        $delegate = Mockery::mock(LoggerInterface::class);
        $delegate->shouldReceive('debug')->once()->with(
            '[connection] Empty read; connection dead?',
            ['meta' => ['timed_out' => true]]
        );
        $delegate->shouldNotReceive('error');

        $filteredLogger = new PhrityFilteredLogger($delegate);
        $filteredLogger->log('error', '[connection] Empty read; connection dead?', [
            'meta' => ['timed_out' => true],
        ]);
    }

    public function test_filtered_logger_preserves_real_error_logs(): void
    {
        $delegate = Mockery::mock(LoggerInterface::class);
        $delegate->shouldReceive('log')->once()->with(
            'error',
            'Connection handshake failed',
            ['reason' => '403 Forbidden']
        );

        $filteredLogger = new PhrityFilteredLogger($delegate);
        $filteredLogger->log('error', 'Connection handshake failed', [
            'reason' => '403 Forbidden',
        ]);
    }
}
