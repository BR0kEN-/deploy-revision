<?php

namespace DeployRevision\Tests\Loggers;

use DeployRevision\LoggerInterface;

class Throwable implements LoggerInterface
{
    /**
     * {@inheritdoc}
     */
    public function log($message)
    {
        throw new \Exception($message);
    }
}
