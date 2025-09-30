<?php

namespace App\Logging;

use Monolog\Logger;

class RequestContextTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RequestContextProcessor());
    }
}
