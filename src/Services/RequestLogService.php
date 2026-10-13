<?php

namespace MrNaeem\LaravelRequestAnalysis\Services;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Core service: collects request metadata, applies redaction/truncation and
 * writes one JSON line (JSONL) to the Laravel logs directory with daily
 * rotation + gzip compression + retention pruning.
 *
 * The middleware extracts raw request data into a plain array and hands it to
 * collect(), so this class stays framework-agnostic.
 */
class RequestLogService
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled'         => false,
            'log_dir'         => '',
            'log_file'        => 'analysis.log',
            'redact_fields'   => ['password', 'nik', 'Api-Key', 'no_telp'],
            'max_body_size'   => 3145728,
            'whitelist_ips'   => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1'],
            'truncate_suffix' => '... [truncated]',
            'retention_days'  => 30,
        ], $config);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    public function isIpWhitelisted(string $ip): bool
    {
        foreach ((array) $this->config['whitelist_ips'] as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the log array from raw request data collected by the middleware.
     *
     * @param array $data Keys: content_type, headers, post, files, domain,
     *                    path, method, srcip, user_agent, query_string, raw_body
     */
    public function collect(array $data): array
    {
        $contentType = (string) ($data['content_type'] ?? '');

        $isJson      = strpos($contentType, 'application/json') !== false;
        $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
        $isFormUrl   = strpos($contentType, 'application/x-www-form-urlencoded') !== false;

        $rawBody = $this->buildBody($data, $isJson, $isMultipart, $isFormUrl);
        $rawBody = $this->truncate($rawBody, (int) $this->config['max_body_size'], (string) $this->config['truncate_suffix']);

        $headers = $this->redact((array) ($data['headers'] ?? []), (array) $this->config['redact_fields']);

        $fileMeta  = $this->extractFileMetadata((array) ($data['files'] ?? []));
        $fileNames = [];
        foreach ($fileMeta as $meta) {
            $fileNames[] = $meta['original_name'];
        }

        return [
            'timestamp'     => date('c'),
            'domain'        => (string) ($data['domain'] ?? ''),
            'path'          => (string) ($data['path'] ?? ''),
            'method'        => (string) ($data['method'] ?? ''),
            'srcip'         => (string) ($data['srcip'] ?? ''),
            'user_agent'    => (string) ($data['user_agent'] ?? ''),
            'query_string'  => (string) ($data['query_string'] ?? ''),
            'headers'       => $headers,
            'raw_body'      => $rawBody,
            'file_count'    => count($fileMeta),
            'file_names'    => $fileNames,
            'file_metadata' => $fileMeta,
        ];
    }

    /**
     * Append one JSONL line to the log file, rotating first if needed.
     */
    public function write(array $logData): bool
    {
        $this->rotateIfNeeded();

        $line = json_encode([
            'log_data'     => $logData,
            'retry_count'  => 0,
            'last_attempt' => null,
            'created_at'   => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $path = $this->logPath();
        $dir  = dirname($path);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return false;
            }
        }

        return @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Rotate the active log file when it belongs to a previous day, compress
     * it with gzip and prune logs older than the retention window.
     */
    public function rotate(): void
    {
        $this->rotateIfNeeded();
    }

    public function redact(array $data, array $fields): array
    {
        $lowerFields = array_map('strtolower', $fields);

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $lowerFields, true)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value, $fields);
            }
        }

        return $data;
    }

    public function truncate(string $body, int $maxLength, string $suffix): string
    {
        if (strlen($body) > $maxLength) {
            $body = substr($body, 0, $maxLength) . $suffix;
        }
        return $body;
    }

    /**
     * Extract metadata from a (possibly nested) array of UploadedFile.
     *
     * @param array $files
     */
    public function extractFileMetadata(array $files): array
    {
        $flattened = [];
        $this->flattenFiles($files, $flattened);

        $metadata = [];
        foreach ($flattened as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (! $file->isValid()) {
                continue;
            }

            $name = (string) $file->getClientOriginalName();
            $tmp  = (string) $file->getRealPath();

            $metadata[] = [
                'original_name'        => $name,
                'size'                 => (int) $file->getSize(),
                'mime_type'            => $this->detectMimeType($file),
                'extension'            => strtolower((string) $file->getClientOriginalExtension()),
                'hash'                 => ($tmp !== '' && is_file($tmp)) ? hash_file('sha256', $tmp) : '',
                'has_double_extension' => $this->hasDoubleExtension($name),
            ];
        }

        return $metadata;
    }

    protected function rotateIfNeeded(): void
    {
        $path = $this->logPath();

        if (! file_exists($path)) {
            return;
        }

        $day = date('Y-m-d', filemtime($path));
        if ($day !== date('Y-m-d')) {
            $rotated = $this->buildRotatedName($path, $day);
            if (@rename($path, $rotated)) {
                $this->compress($rotated);
            }
        }

        $this->prune();
    }

    protected function compress(string $path): bool
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return false;
        }

        if (@file_put_contents($path . '.gz', gzencode($data, 6)) === false) {
            return false;
        }

        @unlink($path);
        return true;
    }

    protected function prune(): void
    {
        $dir    = dirname($this->logPath());
        $cutoff = strtotime('-' . (int) $this->config['retention_days'] . ' days');

        if ($cutoff === false) {
            return;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . 'analysis-*.log.gz') as $file) {
            if (@filemtime($file) !== false && @filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    protected function buildRotatedName(string $path, string $day): string
    {
        $info = pathinfo($path);
        $ext  = isset($info['extension']) ? $info['extension'] : 'log';

        return $info['dirname'] . DIRECTORY_SEPARATOR
            . $info['filename'] . '-' . $day . '.' . $ext;
    }

    protected function logPath(): string
    {
        $dir  = $this->logDirectory();
        $file = ltrim((string) $this->config['log_file'], '/\\');

        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    protected function logDirectory(): string
    {
        if ((string) $this->config['log_dir'] !== '') {
            return (string) $this->config['log_dir'];
        }

        if (function_exists('storage_path')) {
            return rtrim(storage_path('logs'), '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\') . '/logs';
    }

    protected function ipInCidr(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        $parts = explode('/', $range, 2);
        $subnet = $parts[0];
        $bits   = (int) $parts[1];

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            $ipLong     = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $mask       = -1 << (32 - $bits);
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        return $ip === $range;
    }

    protected function hasDoubleExtension(string $filename): bool
    {
        if (substr_count($filename, '.') < 2) {
            return false;
        }

        $parts = explode('.', $filename);

        // A dotfile such as ".htaccess" has an empty leading segment; its real
        // extension is the segment after the dot, so drop the empty part.
        if ($parts !== [] && $parts[0] === '') {
            array_shift($parts);
        }

        // A filename with fewer than two dot-separated parts has no hidden
        // extension to confuse a server with.
        if (count($parts) < 2) {
            return false;
        }

        // Any segment except the final one is a candidate "hidden" extension
        // (e.g. shell.php.jpg). The final segment is the extension the server
        // may actually execute (e.g. evil.jpg.php). Both are suspicious.
        foreach ($parts as $segment) {
            if ($segment === '') {
                continue;
            }

            if (in_array(strtolower($segment), $this->dangerousExtensions(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extensions commonly associated with server-side code execution.
     *
     * @return list<string>
     */
    protected function dangerousExtensions(): array
    {
        return [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7',
            'phps', 'phar', 'pht', 'shtml', 'cgi', 'pl', 'py',
            'jsp', 'asp', 'aspx', 'htaccess', 'inc',
        ];
    }

    protected function buildBody(array $data, bool $isJson, bool $isMultipart, bool $isFormUrl): string
    {
        if ($isMultipart) {
            $postFields = (array) ($data['post'] ?? []);
            $redacted   = $this->redact($postFields, (array) $this->config['redact_fields']);
            return json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $rawBody = (string) ($data['raw_body'] ?? '');

        if ($rawBody === '') {
            return '';
        }

        if ($isJson) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $redacted = $this->redact($decoded, (array) $this->config['redact_fields']);
                return json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return $rawBody;
        }

        if ($isFormUrl) {
            parse_str($rawBody, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                $redacted = $this->redact($parsed, (array) $this->config['redact_fields']);
                return http_build_query($redacted, '', '&');
            }
        }

        return $rawBody;
    }

    protected function flattenFiles(array $files, array &$flattened): void
    {
        foreach ($files as $item) {
            if ($item instanceof UploadedFile) {
                $flattened[] = $item;
            } elseif (is_array($item)) {
                $this->flattenFiles($item, $flattened);
            }
        }
    }

    protected function detectMimeType(UploadedFile $file): string
    {
        $tmp = (string) $file->getRealPath();

        if (function_exists('finfo_open') && is_file($tmp)) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_file($finfo, $tmp);
                @finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return (string) $file->getMimeType();
    }
}