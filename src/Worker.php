<?php

namespace DeployRevision;

class Worker implements WorkerInterface
{
    /**
     * Indicates whether deployment was executed.
     *
     * @var bool
     */
    private $deployed = false;
    /**
     * Environment ID.
     *
     * @var string
     */
    protected $environment = '';
    /**
     * List of collected commands from playbooks.
     *
     * @var array[]
     */
    protected $commands = [];
    /**
     * Path to file for storing code revision.
     *
     * @var string
     */
    protected $versionFile = '';
    /**
     * Current version of code.
     *
     * @var int
     */
    protected $currentCodeVersion = 0;
    /**
     * New version of code.
     *
     * @var int
     */
    protected $newCodeVersion = 0;
    /**
     * YAML parser.
     *
     * @var YamlInterface
     */
    protected $yaml;

    /**
     * {@inheritdoc}
     */
    public function __construct(YamlInterface $yaml, $environment, $versionFile)
    {
        if (!$yaml->isAvailable()) {
            throw new \RuntimeException(sprintf('YAML parser "%s" is not available', get_class($yaml)));
        }

        $this->yaml = $yaml;
        $this->environment = $environment;
        $this->versionFile = "$versionFile-$environment";

        if (file_exists($this->versionFile)) {
            $this->newCodeVersion = $this->currentCodeVersion = (int) file_get_contents($this->versionFile);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $path => $file) {
                $this->processPlaybook($path);
            }
        } elseif (file_exists($path)) {
            $this->processPlaybook($path);
        }
        // @todo Add reporting for non existent files.
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentCodeVersion()
    {
        return $this->currentCodeVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function getNewCodeVersion()
    {
        return $this->newCodeVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function filter(callable $processor)
    {
        $commands = [];

        ksort($this->commands);

        foreach ($this->commands as $list) {
            foreach ($list as $command) {
                // Deployment cannot continue.
                if (!is_string($command)) {
                    throw new \RuntimeException(sprintf(
                        'Complex value cannot be a command: %s',
                        var_export($command, true)
                    ));
                }

                $commands[$command] = $command;

                unset($commands[$processor($command, $commands, function (
                    $return_previous,
                    array $current_commands,
                    array $existing_commands
                ) use (
                    $command,
                    $commands
                ) {
                    // Ensure that current command is candidate for filtering.
                    if (in_array($command, $current_commands)) {
                        // Iterate over commands in the list.
                        foreach ($commands as $existing_command) {
                            // Match the command in diapason.
                            if (in_array($existing_command, $existing_commands)) {
                                // Remove existing command or do not add currently processed.
                                return $return_previous ? $existing_command : $command;
                            }
                        }
                    }

                    return '';
                })]);
            }
        }

        $this->commands = array_values($commands);
    }

    /**
     * {@inheritdoc}
     */
    public function deploy(callable $processor)
    {
        $command = reset($this->commands);

        // Filtering have not been performed and we dealing with array of arrays.
        if (is_array($command)) {
            $this->filter(function () {
                return '';
            });
        }

        array_map($processor, $this->commands);

        // Do not allow to deploy once again accidentally.
        $this->commands = [];
        $this->deployed = true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        if (!$this->deployed) {
            throw new \RuntimeException('Deployment has not been performed');
        }

        if (!@file_put_contents($this->versionFile, $this->newCodeVersion)) {
            throw new \RuntimeException(sprintf('Cannot save the version of code to "%s" file', $this->versionFile));
        }
    }

    protected function processPlaybook($path)
    {
        if (!in_array(pathinfo($path, PATHINFO_EXTENSION), ['yaml', 'yml'])) {
            return;
        }

        $contents = $this->yaml->parse(file_get_contents($path));

        if (empty($contents['commands'])) {
            return;
        }

        foreach ($contents['commands'] as $group => $commands_group) {
            // Get only actions for particular site or global ones.
            if (!in_array($group, ['global', $this->environment])) {
                continue;
            }

            foreach ($commands_group as $version => $commands) {
                // Skip code actions that were already run.
                if ($version <= $this->currentCodeVersion) {
                    continue;
                }

                // Group commands by version to guarantee exact order.
                $this->commands += [$version => []];
                $this->commands[$version] = array_merge($this->commands[$version], array_filter((array) $commands));
                $this->newCodeVersion = max($this->newCodeVersion, $version);
            }
        }
    }
}
