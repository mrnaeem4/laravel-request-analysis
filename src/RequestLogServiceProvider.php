<?php

namespace MrNaeem\LaravelRequestAnalysis;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use MrNaeem\LaravelRequestAnalysis\Commands\RequestLogRotate;
use MrNaeem\LaravelRequestAnalysis\Http\Middleware\RequestLogMiddleware;
use MrNaeem\LaravelRequestAnalysis\Services\RequestLogService;

class RequestLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/request-log.php',
            'request-log'
        );

        $this->app->singleton(RequestLogService::class, function ($app) {
            return new RequestLogService($app['config']->get('request-log', []));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/request-log.php' => config_path('request-log.php'),
        ], 'request-log-config');

        $this->commands([RequestLogRotate::class]);

        $this->app->make(Router::class)->aliasMiddleware('requestlog', RequestLogMiddleware::class);
    }
}