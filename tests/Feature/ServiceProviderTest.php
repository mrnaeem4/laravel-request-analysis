<?php

namespace MrNaeem\LaravelRequestAnalysis\Tests\Feature;

use MrNaeem\LaravelRequestAnalysis\Commands\RequestLogRotate;
use MrNaeem\LaravelRequestAnalysis\Services\RequestLogService;
use MrNaeem\LaravelRequestAnalysis\Tests\TestCase;

/**
 * Tests for the service provider wiring: config merge, singleton binding,
 * command registration, and the artisan command itself.
 */
class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_from_the_package_defaults(): void
    {
        $config = $this->app->make('config')->get('request-log');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('log_file', $config);
        $this->assertArrayHasKey('retention_days', $config);
    }

    public function test_service_is_bound_as_a_singleton(): void
    {
        $a = $this->app->make(RequestLogService::class);
        $b = $this->app->make(RequestLogService::class);

        $this->assertInstanceOf(RequestLogService::class, $a);
        $this->assertSame($a, $b, 'RequestLogService must be a singleton.');
    }

    public function test_command_is_registered(): void
    {
        $commands = array_keys(
            $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all()
        );

        $this->assertContains('requestlog:rotate', $commands);
    }

    public function test_config_is_publishable(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::$publishGroups;

        $this->assertArrayHasKey('request-log-config', $paths);
    }

    public function test_rotate_command_executes_successfully(): void
    {
        $this->artisan('requestlog:rotate')
            ->expectsOutputToContain('requestlog:rotate done.')
            ->assertSuccessful();
    }
}
