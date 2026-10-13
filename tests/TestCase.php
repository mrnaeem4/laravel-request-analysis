<?php

namespace MrNaeem\LaravelRequestAnalysis\Tests;

use Illuminate\Contracts\Config\Repository;
use MrNaeem\LaravelRequestAnalysis\RequestLogServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the package.
 *
 * Uses Orchestra Testbench so the package can be exercised against a real
 * Laravel application container. The log directory is redirected to a
 * throwaway temp path so tests never touch the host's storage/logs folder.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Per-test throwaway log directory.
     */
    protected string $logDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'lrq-tests-' . bin2hex(random_bytes(6));

        if (! is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        // Point the package config at the throwaway directory.
        $this->app->make(Repository::class)->set('request-log.log_dir', $this->logDir);
        $this->app->make(Repository::class)->set('request-log.enabled', true);

        // Rebuild the singleton so it picks up the overridden config.
        $this->app->forgetInstance(\MrNaeem\LaravelRequestAnalysis\Services\RequestLogService::class);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->logDir);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            RequestLogServiceProvider::class,
        ];
    }

    /**
     * Default package config overrides for tests.
     *
     * @return array<string, mixed>
     */
    protected function defineEnvironment($app): array
    {
        return [
            'request-log.enabled' => true,
        ];
    }

    protected function logFile(): string
    {
        return $this->logDir . DIRECTORY_SEPARATOR . 'analysis.log';
    }

    /**
     * Read the JSONL log file and decode every line.
     *
     * @return list<array<string, mixed>>
     */
    protected function readLogLines(): array
    {
        $path = $this->logFile();

        if (! is_file($path)) {
            return [];
        }

        $lines = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    protected function deleteDirectory(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
