<?php

namespace DeployRevision;

class Logger implements LoggerInterface
{
    /**
     * {@inheritdoc}
     */
    public function log($message)
    {
        echo $message;
    }
}
