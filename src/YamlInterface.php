<?php

namespace DeployRevision;

interface YamlInterface
{
    /**
     * @return bool
     *   Whether YAML parser is ready to parse.
     */
    public function isAvailable();

    /**
     * @param string $content
     *   Data to parse.
     *
     * @return array
     *   Structured data.
     */
    public function parse($content);
}
