<?php

namespace MrNaeem\LaravelRequestAnalysis\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use MrNaeem\LaravelRequestAnalysis\Http\Middleware\RequestLogMiddleware;
use MrNaeem\LaravelRequestAnalysis\Services\RequestLogService;
use MrNaeem\LaravelRequestAnalysis\Tests\TestCase;

/**
 * End-to-end tests exercising the middleware through a real Laravel router.
 *
 * Testbench dispatches requests from 127.0.0.1, which the package whitelists by
 * default. The base environment therefore clears the whitelist so logging is
 * observable; the whitelist-specific test re-adds it explicitly.
 */
class RequestLogMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Arrange: no whitelist so 127.0.0.1 requests are logged.
        $this->setConfig('request-log.whitelist_ips', []);

        Route::middleware('requestlog')->group(function () {
            Route::get('/hello', fn () => response()->json(['ok' => true]));
            Route::post('/echo', fn (Request $request) => response()->json($request->all()));
        });
    }

    protected function setConfig(string $key, mixed $value): void
    {
        $this->app->make(Repository::class)->set($key, $value);
        $this->app->forgetInstance(RequestLogService::class);
    }

    public function test_middleware_alias_is_registered_by_the_service_provider(): void
    {
        $middleware = $this->app->make(Router::class)->getMiddleware();

        $this->assertArrayHasKey('requestlog', $middleware);
        $this->assertSame(RequestLogMiddleware::class, $middleware['requestlog']);
    }

    public function test_request_is_logged_as_jsonl_and_response_is_untouched(): void
    {
        $this->getJson('/hello')->assertOk()->assertJson(['ok' => true]);

        $lines = $this->readLogLines();

        $this->assertCount(1, $lines, 'Exactly one JSONL line should be written.');

        $entry = $lines[0];

        $this->assertArrayHasKey('log_data', $entry);
        $this->assertArrayHasKey('retry_count', $entry);
        $this->assertArrayHasKey('created_at', $entry);

        $this->assertSame('/hello', $entry['log_data']['path']);
        $this->assertSame('GET', $entry['log_data']['method']);
        $this->assertArrayHasKey('headers', $entry['log_data']);
        $this->assertArrayHasKey('timestamp', $entry['log_data']);
    }

    public function test_json_body_is_redacted_before_logging(): void
    {
        $this->postJson('/echo', [
            'name'     => 'User',
            'email'    => 'user@example.com',
            'password' => 'super-secret',
        ])->assertOk();

        $lines = $this->readLogLines();
        $this->assertCount(1, $lines);

        $rawBody = $lines[0]['log_data']['raw_body'];
        $decoded = json_decode($rawBody, true);

        $this->assertIsArray($decoded);
        $this->assertSame('***REDACTED***', $decoded['password']);
        $this->assertSame('User', $decoded['name']);
        $this->assertStringNotContainsString('super-secret', $rawBody);
    }

    public function test_disabled_middleware_writes_nothing(): void
    {
        $this->setConfig('request-log.enabled', false);

        $this->getJson('/hello')->assertOk();

        $this->assertSame([], $this->readLogLines(), 'Disabled middleware must not log.');
    }

    public function test_multiple_requests_append_multiple_lines(): void
    {
        $this->getJson('/hello')->assertOk();
        $this->getJson('/hello')->assertOk();
        $this->getJson('/hello')->assertOk();

        $this->assertCount(3, $this->readLogLines());
    }

    public function test_whitelisted_ip_is_not_logged(): void
    {
        $this->setConfig('request-log.whitelist_ips', ['127.0.0.1']);

        $this->getJson('/hello')->assertOk();

        $this->assertSame([], $this->readLogLines(), 'Whitelisted IP must be skipped.');
    }

    public function test_non_whitelisted_ip_is_logged(): void
    {
        $this->setConfig('request-log.whitelist_ips', ['10.0.0.0/8']);

        $this->getJson('/hello')->assertOk();

        $this->assertCount(1, $this->readLogLines(), 'Non-whitelisted IP must be logged.');
    }

    public function test_middleware_failure_does_not_break_the_request(): void
    {
        // Point the log directory at a path that cannot be created (a file),
        // forcing the write to fail. The request must still succeed.
        $blocker = $this->logDir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'x');

        $this->setConfig('request-log.log_dir', $blocker . DIRECTORY_SEPARATOR . 'sub');

        $this->getJson('/hello')->assertOk()->assertJson(['ok' => true]);
    }
}
