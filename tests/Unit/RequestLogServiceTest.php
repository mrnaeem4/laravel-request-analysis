<?php

namespace MrNaeem\LaravelRequestAnalysis\Tests\Unit;

use MrNaeem\LaravelRequestAnalysis\Services\RequestLogService;
use MrNaeem\LaravelRequestAnalysis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Unit tests for the framework-agnostic RequestLogService.
 */
class RequestLogServiceTest extends TestCase
{
    protected function service(array $config = []): RequestLogService
    {
        return new RequestLogService(array_merge([
            'log_dir' => $this->logDir,
        ], $config));
    }

    public function test_default_config_is_applied(): void
    {
        $config = $this->service()->getConfig();

        $this->assertSame('analysis.log', $config['log_file']);
        $this->assertSame(3145728, $config['max_body_size']);
        $this->assertSame(30, $config['retention_days']);
        $this->assertContains('password', $config['redact_fields']);
    }

    public function test_is_enabled_reflects_config(): void
    {
        $this->assertFalse($this->service(['enabled' => false])->isEnabled());
        $this->assertTrue($this->service(['enabled' => true])->isEnabled());
    }

    public function test_redact_is_case_insensitive_and_recursive(): void
    {
        $service = $this->service();

        $result = $service->redact([
            'Password'      => 'secret',
            'API-KEY'       => 'abc',
            'nested'        => ['Nik' => '123', 'keep' => 'v'],
            'name'          => 'User',
        ], ['password', 'api-key', 'nik']);

        $this->assertSame('***REDACTED***', $result['Password']);
        $this->assertSame('***REDACTED***', $result['API-KEY']);
        $this->assertSame('***REDACTED***', $result['nested']['Nik']);
        $this->assertSame('v', $result['nested']['keep']);
        $this->assertSame('User', $result['name']);
    }

    public function test_truncate_appends_suffix_only_when_needed(): void
    {
        $service = $this->service();

        $this->assertSame('short', $service->truncate('short', 100, '...'));
        $this->assertSame('abc...', $service->truncate('abcdef', 3, '...'));
    }

    public function test_is_ip_whitelisted_handles_exact_and_cidr(): void
    {
        $service = $this->service([
            'whitelist_ips' => ['10.0.0.0/8', '192.168.0.0/16', '127.0.0.1'],
        ]);

        $this->assertTrue($service->isIpWhitelisted('10.1.2.3'));
        $this->assertTrue($service->isIpWhitelisted('192.168.5.5'));
        $this->assertTrue($service->isIpWhitelisted('127.0.0.1'));
        $this->assertFalse($service->isIpWhitelisted('203.0.113.10'));
        $this->assertFalse($service->isIpWhitelisted('11.0.0.1'));
    }

    public function test_collect_builds_expected_payload(): void
    {
        $service = $this->service();

        $payload = $service->collect([
            'content_type' => 'application/json',
            'headers'      => ['Content-Type' => 'application/json'],
            'post'         => [],
            'files'        => [],
            'raw_body'     => json_encode(['password' => 'x', 'name' => 'User']),
            'domain'       => 'example.test',
            'path'         => '/login',
            'method'       => 'POST',
            'srcip'        => '203.0.113.10',
            'user_agent'   => 'PHPUnit',
            'query_string' => 'a=1',
        ]);

        $this->assertSame('/login', $payload['path']);
        $this->assertSame('POST', $payload['method']);
        $this->assertSame('203.0.113.10', $payload['srcip']);
        $this->assertSame(0, $payload['file_count']);
        $this->assertSame([], $payload['file_names']);

        $body = json_decode($payload['raw_body'], true);
        $this->assertSame('***REDACTED***', $body['password']);
        $this->assertSame('User', $body['name']);
    }

    public function test_write_appends_a_jsonl_line(): void
    {
        $service = $this->service();

        $this->assertTrue($service->write(['path' => '/a']));
        $this->assertTrue($service->write(['path' => '/b']));

        $lines = $this->readLogLines();

        $this->assertCount(2, $lines);
        $this->assertSame('/a', $lines[0]['log_data']['path']);
        $this->assertSame('/b', $lines[1]['log_data']['path']);
        $this->assertSame(0, $lines[0]['retry_count']);
    }

    public function test_write_creates_the_directory_when_missing(): void
    {
        $nested = $this->logDir . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'nested';
        $service = $this->service(['log_dir' => $nested]);

        $this->assertTrue($service->write(['path' => '/x']));
        $this->assertFileExists($nested . DIRECTORY_SEPARATOR . 'analysis.log');
    }

    public function test_file_metadata_extraction(): void
    {
        $service = $this->service();

        $tmp = tempnam(sys_get_temp_dir(), 'lrq');
        file_put_contents($tmp, 'hello world');

        $upload = new UploadedFile($tmp, 'shell.php.jpg', 'image/jpeg', null, true);

        $meta = $service->extractFileMetadata(['upload' => $upload]);

        $this->assertCount(1, $meta);
        $this->assertSame('shell.php.jpg', $meta[0]['original_name']);
        $this->assertSame('jpg', $meta[0]['extension']);
        $this->assertTrue($meta[0]['has_double_extension']);
        $this->assertSame(hash('sha256', 'hello world'), $meta[0]['hash']);
        $this->assertSame(11, $meta[0]['size']);

        @unlink($tmp);
    }

    #[DataProvider('doubleExtensionProvider')]
    public function test_double_extension_detection(string $filename, bool $expected): void
    {
        $service = $this->service();

        $method = new \ReflectionMethod($service, 'hasDoubleExtension');
        $method->setAccessible(true);

        $this->assertSame(
            $expected,
            $method->invoke($service, $filename),
            "hasDoubleExtension('{$filename}') should be " . var_export($expected, true)
        );
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    public static function doubleExtensionProvider(): array
    {
        return [
            // Dangerous extension hidden before the final, harmless one.
            'php before jpg'      => ['shell.php.jpg', true],
            'php before png'      => ['a.php.png', true],
            'uppercase php'       => ['shell.PHP.jpg', true],
            'htaccess before php' => ['.htaccess.php', true],
            // Dangerous extension in the final, executable position.
            'php as final ext'    => ['evil.jpg.php', true],
            'php after benign'    => ['a.b.php', true],
            // Benign names must not be flagged (negative controls).
            'plain image'         => ['image.jpg', false],
            'plain text'          => ['note.txt', false],
            'safe tar.gz'         => ['archive.tar.gz', false],
            'single dot'          => ['file.php', false],
            'no dot'              => ['README', false],
        ];
    }

    public function test_rotate_compresses_a_previous_day_log(): void
    {
        $logFile = $this->logDirectoryForService() . DIRECTORY_SEPARATOR . 'analysis.log';
        file_put_contents($logFile, "old line\n");

        // Backdate the file to yesterday so rotation triggers.
        touch($logFile, strtotime('-1 day'));

        $service = $this->service();
        $service->rotate();

        $this->assertFileDoesNotExist($logFile, 'Active log should be renamed.');

        $rotated = glob($this->logDir . DIRECTORY_SEPARATOR . 'analysis-*.log.gz');
        $this->assertNotEmpty($rotated, 'A gzip-compressed rotated log should exist.');

        $compressed = gzdecode(file_get_contents($rotated[0]));
        $this->assertSame("old line\n", $compressed);
    }

    protected function logDirectoryForService(): string
    {
        return $this->logDir;
    }
}
