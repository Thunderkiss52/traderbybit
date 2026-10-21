<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RequireResearchToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class RequireResearchTokenTest extends TestCase
{
    private RequireResearchToken $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new RequireResearchToken;
    }

    public function test_returns_500_when_token_is_not_configured(): void
    {
        config(['services.research_api_token' => null]);

        $request = Request::create('/api/v1/quote-integrity/evidence', 'POST');
        $executed = false;

        $response = $this->middleware->handle($request, function () use (&$executed): Response {
            $executed = true;

            return response('OK', 200);
        });

        $this->assertFalse($executed, 'Pipeline was executed when auth not configured');
        $this->assertSame(500, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('AUTH_NOT_CONFIGURED', $data['error']['code']);
    }

    public function test_returns_500_when_token_is_configured_as_empty_string(): void
    {
        config(['services.research_api_token' => '']);

        $request = Request::create('/api/v1/quote-integrity/evidence', 'POST');
        $executed = false;

        $response = $this->middleware->handle($request, function () use (&$executed): Response {
            $executed = true;

            return response('OK', 200);
        });

        $this->assertFalse($executed);
        $this->assertSame(500, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('AUTH_NOT_CONFIGURED', $data['error']['code']);
    }

    public function test_returns_401_when_token_header_is_missing(): void
    {
        config(['services.research_api_token' => 'valid-secret-token-12345']);

        $request = Request::create('/api/v1/quote-integrity/evidence', 'POST');
        $executed = false;

        $response = $this->middleware->handle($request, function () use (&$executed): Response {
            $executed = true;

            return response('OK', 200);
        });

        $this->assertFalse($executed, 'DB side effects must not execute when token is missing');
        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('TOKEN_MISSING', $data['error']['code']);
    }

    public function test_returns_403_when_token_is_invalid(): void
    {
        config(['services.research_api_token' => 'valid-secret-token-12345']);

        $request = Request::create('/api/v1/quote-integrity/evidence', 'POST');
        $request->headers->set('X-Research-Token', 'wrong-token-abc');
        $executed = false;

        $response = $this->middleware->handle($request, function () use (&$executed): Response {
            $executed = true;

            return response('OK', 200);
        });

        $this->assertFalse($executed, 'DB side effects must not execute when token is invalid');
        $this->assertSame(403, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('TOKEN_INVALID', $data['error']['code']);
    }

    public function test_allows_request_when_x_research_token_is_valid(): void
    {
        config(['services.research_api_token' => 'valid-secret-token-12345']);

        $request = Request::create('/api/v1/quote-integrity/evidence', 'POST');
        $request->headers->set('X-Research-Token', 'valid-secret-token-12345');
        $executed = false;

        $response = $this->middleware->handle($request, function () use (&$executed): Response {
            $executed = true;

            return response('OK', 200);
        });

        $this->assertTrue($executed);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_allows_request_when_bearer_token_is_valid(): void
    {
        config(['services.research_api_token' => 'valid-secret-token-12345']);

        $request = Request::create('/api/v1/quote-integrity/evidence', 'POST');
        $request->headers->set('Authorization', 'Bearer valid-secret-token-12345');
        $executed = false;

        $response = $this->middleware->handle($request, function () use (&$executed): Response {
            $executed = true;

            return response('OK', 200);
        });

        $this->assertTrue($executed);
        $this->assertSame(200, $response->getStatusCode());
    }
}
