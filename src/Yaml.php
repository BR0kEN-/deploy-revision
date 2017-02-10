<?php

namespace DeployRevision;

use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

class Yaml implements YamlInterface
{
    /**
     * {@inheritdoc}
     */
    public function isAvailable()
    {
        return class_exists(Parser::class);
    }

    /**
     * {@inheritdoc}
     */
    public function parse($content)
    {
        return (array) (new Parser)->parse($content);
    }

    /**
     * {@inheritdoc}
     */
    public function dump(array $content)
    {
        return (new Dumper)->dump($content, 10);
    }
}
