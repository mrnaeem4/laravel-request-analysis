# laravel-request-analysis

Laravel middleware that intercepts incoming HTTP requests, extracts structured
metadata (headers, body, files, source IP, etc.), and writes one JSON Line per
request directly to the `storage/logs` directory — with sensitive-field
redaction, body truncation, IP whitelisting, and daily log rotation with gzip
compression.

> Local logging only. No external server, no queue, no Guzzle. This is the
> Laravel counterpart of [ci4-request-analysis](https://github.com/mrnaeem4/ci4-request-analysis).
> Laravel 12+.

## Features

- Attachable per-route via the `requestlog` middleware alias.
- Writes directly to `storage/logs/analysis.log` as JSON Lines (JSONL).
- Daily rotation: a file from a previous day is renamed and gzip-compressed
  automatically on the next write.
- Retention pruning: compressed logs older than `REQUEST_LOG_RETENTION_DAYS`
  (default 30) are deleted.
- Configurable sensitive-field redaction (default: `password`, `nik`, `Api-Key`, `no_telp`).
- Raw body truncation at 3 MB (configurable) with `... [truncated]` suffix.
- File upload metadata captured without binary content (name, size, MIME,
  extension, SHA-256 hash, double-extension detection).
- IP/CIDR whitelist to skip private/internal traffic.
- PHP 8.2+.
- Auto-discovery via `composer.json` `extra.laravel.providers`.

## Requirements

- PHP 8.2+
- Laravel 12.x

## Installation

```bash
composer require mrnaeem4/laravel-request-analysis
```

### 1. Publish the config (optional)

```bash
php artisan vendor:publish --tag=request-log-config
```

This copies `config/request-log.php` to your application config directory so
you can override defaults.

### 2. Register middleware alias

The service provider already registers the `requestlog` alias. For Laravel
11/12+, make sure `bootstrap/app.php` includes the middleware alias (auto-
discovery should handle it, but verify):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'requestlog' => \MrNaeem\LaravelRequestAnalysis\Http\Middleware\RequestLogMiddleware::class,
    ]);
})
```

### 3. Attach to routes

```php
Route::middleware('requestlog')->group(function () {
    Route::post('post', [HomeController::class, 'post']);
    Route::post('upload', [HomeController::class, 'upload']);
});
```

### 4. Set environment variables

```ini
REQUEST_LOG_ENABLED          = true
REQUEST_LOG_LOG_DIR          = ""
REQUEST_LOG_LOG_FILE         = "analysis.log"
REQUEST_LOG_REDACT_FIELDS    = "password,nik,Api-Key,no_telp"
REQUEST_LOG_MAX_BODY_SIZE    = 3145728
REQUEST_LOG_WHITELIST_IPS    = "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1"
REQUEST_LOG_TRUNCATE_SUFFIX  = "... [truncated]"
REQUEST_LOG_RETENTION_DAYS   = 30
```

### Config reference

| Variable | Default | Description |
|---|---|---|
| `REQUEST_LOG_ENABLED` | `false` | Master switch. |
| `REQUEST_LOG_LOG_DIR` | `""` (→ `storage/logs`) | Directory for the log file. |
| `REQUEST_LOG_LOG_FILE` | `analysis.log` | Log file name (daily rotated). |
| `REQUEST_LOG_REDACT_FIELDS` | `password,nik,Api-Key,no_telp` | Comma-separated sensitive fields. |
| `REQUEST_LOG_MAX_BODY_SIZE` | `3145728` (3 MB) | Body truncation length (bytes). |
| `REQUEST_LOG_WHITELIST_IPS` | RFC1918 + localhost | CIDR ranges to skip. |
| `REQUEST_LOG_TRUNCATE_SUFFIX` | `... [truncated]` | Truncation suffix. |
| `REQUEST_LOG_RETENTION_DAYS` | `30` | Days of compressed logs kept. |

### Artisan command

```bash
php artisan requestlog:rotate
```

Rotation happens automatically on write. Run this manually or via cron as a
guaranteed nightly pass.

## Log payload

Each line in `storage/logs/analysis.log` is a JSON object:

```json
{
  "log_data": {
    "timestamp": "2026-08-31T02:15:04+00:00",
    "domain": "app.example.com",
    "path": "/api/profile/update",
    "method": "POST",
    "srcip": "203.0.113.10",
    "user_agent": "Mozilla/5.0 ...",
    "query_string": "page=1",
    "headers": { "Content-Type": "application/json", ... },
    "raw_body": "{\"name\":\"User\",\"email\":\"user@example.com\",\"password\":\"***REDACTED***\"}",
    "file_count": 1,
    "file_names": ["shell.php.jpg"],
    "file_metadata": [
      {
        "original_name": "shell.php.jpg",
        "size": 20480,
        "mime_type": "image/jpeg",
        "extension": "jpg",
        "hash": "3c98...",
        "has_double_extension": true
      }
    ]
  },
  "retry_count": 0,
  "last_attempt": null,
  "created_at": "2026-08-31T02:15:04+00:00"
}
```

## How it works

```
Request → RequestLogMiddleware (handle)
  ├─ enabled? ──no──► done
  ├─ IP whitelisted? ──yes──► done
  ├─ collect: headers, body, files, srcip...
  │    ├─ redact sensitive fields (headers + body)
  │    ├─ truncate body at max size
  │    └─ extract file metadata (no binary)
  ├─ rotate if the active log is from a previous day (rename + gzip + prune)
  └─ append one JSONL line to storage/logs/analysis.log
```

## Security

`storage/logs/analysis.log` contains request headers and bodies. Ensure the
web server does not serve the `storage/` directory — Laravel already blocks
this by default. For extra protection, set `REQUEST_LOG_LOG_DIR` to a path
outside the document root.

## Sample app

Minimal sample code under `sample/`:

- `sample/routes/web.php` — route group with `requestlog` middleware
- `sample/app/Http/Controllers/HomeController.php` — `post` + `upload` demo

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)