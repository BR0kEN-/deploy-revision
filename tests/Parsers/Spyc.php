<?php

namespace DeployRevision\Tests\Parsers;

use DeployRevision\YamlInterface;

class Spyc implements YamlInterface
{
    /**
     * {@inheritdoc}
     */
    public function isAvailable()
    {
        return class_exists(\Spyc::class);
    }

    /**
     * {@inheritdoc}
     */
    public function parse($content)
    {
        return \Spyc::YAMLLoadString($content);
    }

    /**
     * {@inheritdoc}
     */
    public function dump(array $content)
    {
        return \Spyc::YAMLDump($content);
    }
}
