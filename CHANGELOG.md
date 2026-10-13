# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Widened the Illuminate constraints to `^12.0 || ^13.0` so the package is
  installable on Laravel 13.x while remaining compatible with Laravel 12.x.
- Narrowed the development `phpunit/phpunit` constraint to `^11.5`, the only
  version range satisfiable by both Orchestra Testbench 10.x (Laravel 12) and
  11.x (Laravel 13).
- Documented Laravel 13.x support. Laravel 12.x continues to run on PHP 8.2+,
  while Laravel 13.x requires PHP 8.3+; the package itself still requires
  PHP 8.2+.

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