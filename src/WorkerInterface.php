<?php

namespace DeployRevision;

interface WorkerInterface
{
    /**
     * @param YamlInterface $yaml
     *   YAML parser.
     * @param LoggerInterface $logger
     *   Messages logger.
     * @param string $environment
     *   Unique environment ID to perform particular deployment.
     * @param string $versionFile
     *   Path to file where latest deployed version will be stored.
     */
    public function __construct(YamlInterface $yaml, LoggerInterface $logger, $environment, $versionFile);

    /**
     * @param string $path
     *   Path to single playbook or directory with them.
     */
    public function read($path);

    /**
     * @return int
     */
    public function getCurrentCodeVersion();

    /**
     * @return int
     */
    public function getNewCodeVersion();

    /**
     * @param callable $processor
     *   Function which returns the command to remove from the list.
     */
    public function filter(callable $processor);

    /**
     * @param callable $processor
     *   Function which should execute every particular command.
     */
    public function deploy(callable $processor);

    /**
     * Store new code revision.
     *
     * @throws \Exception
     *   When revision number cannot be saved.
     */
    public function commit();
}
