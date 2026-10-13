# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-13

### Added

- PHPUnit test suite (Orchestra Testbench) covering the middleware, service,
  service provider, artisan command, redaction, truncation, CIDR whitelisting,
  rotation and file-metadata extraction. Verified green on Laravel 12.x
  (PHP 8.2) and Laravel 13.x (PHP 8.3–8.5).
- GitHub Actions `Tests` workflow with a PHP / Laravel version matrix, running
  with least-privilege permissions (`contents: read`) and without persisting
  checkout credentials.

### Changed

- Widened the Illuminate constraints to `^12.0 || ^13.0` so the package is
  installable on Laravel 13.x while remaining compatible with Laravel 12.x.
- Narrowed the development `phpunit/phpunit` constraint to `^11.5`, the only
  version range satisfiable by both Orchestra Testbench 10.x (Laravel 12) and
  11.x (Laravel 13).
- Documented Laravel 13.x support. Laravel 12.x continues to run on PHP 8.2+,
  while Laravel 13.x requires PHP 8.3+; the package itself still requires
  PHP 8.2+.

### Fixed

- `hasDoubleExtension()` now inspects every dot-separated segment of a
  filename against the dangerous-extension list, so it detects both a
  dangerous extension hidden before a harmless one (`shell.php.jpg`) and a
  dangerous extension in the final, executable position (`evil.jpg.php`).
  Previously only the final extension was checked, which missed
  `shell.php.jpg`; an intermediate revision that checked only non-final
  segments missed `evil.jpg.php`.

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
[1.1.0]: https://github.com/mrnaeem4/laravel-request-analysis/releases/tag/v1.1.0