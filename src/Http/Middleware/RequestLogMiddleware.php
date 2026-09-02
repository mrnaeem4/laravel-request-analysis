<?php

namespace MrNaeem\LaravelRequestAnalysis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MrNaeem\LaravelRequestAnalysis\Services\RequestLogService;

/**
 * Middleware that captures request metadata and writes one JSONL line to the
 * Laravel storage/logs directory.
 *
 * Usage:
 *   // In routes/web.php or routes/api.php:
 *   Route::middleware('requestlog')->group(function () {
 *       Route::post('/post', 'HomeController@post');
 *       Route::post('/upload', 'HomeController@upload');
 *   });
 *
 *   // Or register globally in bootstrap/app.php for Laravel 11+:
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->alias('requestlog', \MrNaeem\LaravelRequestAnalysis\Http\Middleware\RequestLogMiddleware::class);
 *   });
 */
class RequestLogMiddleware
{
    protected RequestLogService $service;

    public function __construct(RequestLogService $service)
    {
        $this->service = $service;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $this->service->isEnabled()) {
            return $next($request);
        }

        try {
            $ip = (string) $request->ip();
            if (! $this->service->isIpWhitelisted($ip)) {
                $data = $this->gather($request, $ip);
                $this->service->write($this->service->collect($data));
            }
        } catch (\Throwable $e) {
            logger()->error('RequestLogMiddleware: ' . $e->getMessage());
        }

        return $next($request);
    }

    /**
     * Extract raw request data from the Laravel Request object.
     */
    protected function gather(Request $request, string $ip): array
    {
        $contentType = (string) ($request->headers->get('Content-Type') ?? '');

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

        return [
            'content_type' => $contentType,
            'headers'      => $headers,
            'post'         => $request->request->all(),
            'files'        => array_values($request->files->all()),
            'raw_body'     => $isMultipart ? '' : (string) $request->getContent(),
            'domain'       => (string) ($request->getHttpHost() ?? ''),
            'path'         => (string) $request->getPathInfo(),
            'method'       => strtoupper($request->method()),
            'srcip'        => $ip,
            'user_agent'   => (string) ($request->userAgent() ?? ''),
            'query_string' => (string) ($request->getQueryString() ?? ''),
        ];
    }
}