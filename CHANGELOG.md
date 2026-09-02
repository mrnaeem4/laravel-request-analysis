# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-02

### Added

- Initial release: `RequestLogMiddleware` for Laravel 12+ that captures request
  metadata — headers, raw body, file uploads, client IP, user agent, query
  string — and writes one JSONL line per request to `storage/logs/analysis.log`.
- `RequestLogService` with sensitive-field redaction, body truncation (3 MB
  default), file metadata extraction (name, size, MIME, SHA-256 hash,
  double-extension detection), and IP/CIDR whitelist.
- Publishable config file `config/request-log.php` read from `REQUEST_LOG_*`
  environment variables.
- `requestlog:rotate` Artisan command for manual/cron log rotation.
- Auto-discovery via `composer.json` `extra.laravel.providers`.
- Service provider registers the `requestlog` middleware alias.
- Sample routes and controller under `sample/`.

[1.0.0]: https://github.com/mrnaeem4/laravel-request-analysis/releases/tag/v1.0.0