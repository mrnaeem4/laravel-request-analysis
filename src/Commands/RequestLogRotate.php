<?php

namespace MrNaeem\LaravelRequestAnalysis\Commands;

use Illuminate\Console\Command;
use MrNaeem\LaravelRequestAnalysis\Services\RequestLogService;

class RequestLogRotate extends Command
{
    protected $signature = 'requestlog:rotate';

    protected $description = 'Rotate and compress the request analysis log (daily, gzip, retention).';

    protected RequestLogService $service;

    public function __construct(RequestLogService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $this->service->rotate();

        $this->info('requestlog:rotate done.');

        return self::SUCCESS;
    }
}