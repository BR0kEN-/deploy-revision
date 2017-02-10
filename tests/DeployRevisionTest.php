<?php

namespace DeployRevision\Tests;

use PHPUnit\Framework\TestCase;
use DeployRevision\Yaml;
use DeployRevision\Worker;
use DeployRevision\DeployRevision;
use DeployRevision\LoggerInterface;
use DeployRevision\WorkerInterface;

class DeployRevisionTest extends TestCase
{
    use DataProviders;

    /**
     * @var DeployRevision
     */
    protected $deploy;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->deploy = new DeployRevision();
    }

    /**
     * Get Worker with loaded tasks for further deployment.
     *
     * @param string[] $fixtures
     * @param string|null $environment
     * @param string|null $versionFile
     *
     * @return WorkerInterface
     */
    protected function getWorker(array $fixtures, $environment = null, $versionFile = null)
    {
        $worker = $this->deploy->getWorker($environment, $versionFile);

        foreach ($fixtures as $fixture) {
            $worker->read(__DIR__ . "/fixtures/$fixture");
        }

        return $worker;
    }

    /**
     * @param string $environment
     * @param string $versionFile
     * @param bool $isYamlAvailable
     *
     * @return array<WorkerInterface, YamlInterface, LoggerInterface>
     */
    protected function getMockedWorker($environment, $versionFile, $isYamlAvailable = true)
    {
        /** @var \PHPUnit_Framework_MockObject_MockObject[] $mocks */
        $mocks = [];

        foreach ([
            Yaml::class => ['isAvailable'],
            LoggerInterface::class => ['log'],
        ] as $class => $methods) {
            $mocks[$class] = $this
                ->getMockBuilder($class)
                ->setMethods($methods)
                ->getMock();
        }

        $mocks[Yaml::class]
            ->method('isAvailable')
            ->willReturn($isYamlAvailable);

        $worker = $this
            ->getMockBuilder(Worker::class)
            ->setConstructorArgs([
                $mocks[Yaml::class],
                $mocks[LoggerInterface::class],
                $environment,
                $versionFile,
            ]);

        return [$worker->getMock(), $mocks[Yaml::class], $mocks[LoggerInterface::class]];
    }

    /**
     * Get list of deployment tasks to be performed and worker instance.
     *
     * @param string[] $fixtures
     * @param string|null $environment
     * @param string|null $versionFile
     *
     * @return array
     */
    protected function getDeploymentCommands(array $fixtures, $environment = null, $versionFile = null)
    {
        $worker = $this->getWorker($fixtures, $environment, $versionFile);
        $actual = [];

        $worker->deploy(function ($command) use (&$actual) {
            $actual[] = $command;
        });

        return [$actual, $worker];
    }

    /**
     * Ensure that deployment worker implements WorkerInterface.
     *
     * @expectedException \LogicException
     * @expectedExceptionMessageRegExp /^Service ".*" must implement the ".*" interface$/
     */
    public function testServiceIntegrity()
    {
        $this->deploy
            ->getContainer()
            ->getDefinition(DeployRevision::SERVICE)
            ->setClass(\stdClass::class);

        // This test guarantees that arguments for worker constructor will always match required types.
        $this->deploy->getWorker();
    }

    /**
     * Ensure that deployment worker instantiates correctly.
     *
     * @param string $environment
     * @param string $versionFile
     * @param int $version
     *
     * @dataProvider providerConstructor
     */
    public function testConstructor($environment, $versionFile, $version)
    {
        $versionFilePath = "$versionFile-$environment";

        // 25 - is an approximate amout of bytes which should be written by "file_put_contents()".
        // @see Worker:commit()
        static::assertGreaterThan(25, file_put_contents($versionFilePath, (new Yaml)->dump([
            'version' => $version,
            'completed' => [],
        ])));

        list($worker, $yaml, $logger) = $this->getMockedWorker($environment, $versionFile);

        foreach ([
            'yaml' => $yaml,
            'logger' => $logger,
            'environment' => $environment,
            'versionFile' => $versionFilePath,
            'newCodeVersion' => $version,
            'currentCodeVersion' => $version,
        ] as $propertyName => $expectedValue) {
            static::assertAttributeSame($expectedValue, $propertyName, $worker);
        }

        static::assertTrue(unlink($versionFilePath), 'Remove temporary version marker');
    }

    /**
     * Ensure that DI container allows to override YAML parser.
     *
     * @param string $parser
     * @param string[] $fixtures
     * @param string[] $expected
     *
     * @dataProvider providerConstructorWithSpycYamlParser
     */
    public function testConstructorWithSpycYamlParser($parser, array $fixtures, array $expected)
    {
        $this->deploy
            ->getContainer()
            ->getDefinition('deploy_revision.yaml')
            ->setClass($parser);

        $this->testDeployOrdering(null, $fixtures, $expected);
    }

    /**
     * Ensure that an exception will be thrown if YAML parser is not available.
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /YAML parser "\w+" is not available/
     */
    public function testConstructorWithBrokenYamlParser()
    {
        // Programmatically make YAML parser unavailable to make sure that exception will be thrown.
        // Doesn't matter that environment and version file are set to "null" since YAML availability
        // is (and should be) the very first check.
        $this->getMockedWorker(null, null, false);
    }

    /**
     * Ensure integrity of code versions.
     *
     * @param string[] $fixtures
     * @param string $environment
     * @param int $expectedNewVersion
     * @param int $expectedCurrentVersion
     *
     * @dataProvider providerCodeVersions
     */
    public function testCodeVersions(array $fixtures, $environment, $expectedNewVersion, $expectedCurrentVersion)
    {
        $worker = $this->getWorker($fixtures, $environment);
        $newCodeVersion = $worker->getNewCodeVersion();
        $currentCodeVersion = $worker->getCurrentCodeVersion();

        static::assertSame($expectedNewVersion, $newCodeVersion);
        static::assertSame($expectedCurrentVersion, $currentCodeVersion);
        static::assertAttributeSame($newCodeVersion, 'newCodeVersion', $worker);
        static::assertAttributeSame($currentCodeVersion, 'currentCodeVersion', $worker);
    }

    /**
     * Ensure that deployment tasks are plain strings.
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /Complex value cannot be a command: .+?/
     */
    public function testDeployBrokenPlaybook()
    {
        $this->getDeploymentCommands(['disallowed-structure.yml']);
    }

    /**
     * Ensure that deployment tasks will be skipped if current version of code is greater than specified by tasks.
     */
    public function testDeploySkipVersion()
    {
        $worker = $this->deploy->getWorker();
        $playbook = __DIR__ . '/fixtures/tasks.yml';
        $reflection = new \ReflectionClass($worker);
        $spoofedVersion = 2000;
        $commands = [];

        // Set current code version to extremely big value to skip tasks with lower version.
        foreach ([
            'completed' => [$playbook => $spoofedVersion],
            'currentCodeVersion' => $spoofedVersion,
        ] as $property => $value) {
            $property = $reflection->getProperty($property);
            $property->setAccessible(true);
            $property->setValue($worker, $value);
            $property->setAccessible(false);
        }

        $worker->read($playbook);

        $worker->deploy(function ($command) use (&$commands) {
            $commands[] = $command;
        });

        // Verify current version of code.
        static::assertSame($spoofedVersion, $worker->getCurrentCodeVersion());
        // Ensure that no tasks will be deployed.
        static::assertEmpty($commands);
    }

    /**
     * Ensure that messages about trying to load tasks from non-existent files/directories will be printed.
     *
     * @param string[] $fixtures
     * @param string|null $loggerClass
     *
     * @throws \Exception
     *
     * @expectedException \Exception
     * @expectedExceptionMessageRegExp /^Not file ".*" nor directory exists$/
     *
     * @dataProvider providerDeployReadingNonExistent
     */
    public function testDeployReadingNonExistent(array $fixtures, $loggerClass)
    {
        $useDefaultLogger = null === $loggerClass;

        // Default logger just prints a message. Put it into the buffer to throw as an exception.
        if ($useDefaultLogger) {
            ob_start();
        } else {
            // Ensure that DI container allows to override logger.
            $this->deploy
                ->getContainer()
                ->getDefinition('deploy_revision.logger')
                ->setClass($loggerClass);
        }

        $this->getWorker($fixtures);

        if ($useDefaultLogger) {
            throw new \Exception(ob_get_clean());
        }
    }

    /**
     * Ensure that order of deployment tasks builds correctly.
     *
     * @param string $environment
     * @param string[] $fixtures
     * @param string[] $expected
     *
     * @dataProvider providerDeployOrdering
     */
    public function testDeployOrdering($environment, array $fixtures, array $expected)
    {
        list($actual) = $this->getDeploymentCommands($fixtures, $environment);

        static::assertSame($expected, $actual);
    }

    /**
     * Ensure that filtering of deployment tasks and resolver works properly.
     *
     * @param string $environment
     * @param string[] $fixtures
     * @param string[] $expected
     *
     * @dataProvider providerDeployFiltering
     */
    public function testDeployFiltering($environment, array $fixtures, array $expected)
    {
        $worker = $this->getWorker($fixtures, $environment);
        $actual = [];

        $worker->filter(function ($command, array $commands, callable $resolver) {
            // Do not add "drush cc css-js" since "drush updb" will do the job for it.
            if ('drush cc css-js' === $command && isset($commands['drush updb'])) {
                return $command;
            }

            // Remove all previously added "drush cc all" from the list if "drush updb" exists.
            return $resolver(true, ['drush updb'], ['drush cc all'])
                // Remove newly added "drush cc all" if "drush updb" in the list.
                ?: $resolver(false, ['drush cc all'], ['drush updb']);
        });

        $worker->deploy(function ($command) use (&$actual) {
            $actual[] = $command;
        });

        static::assertSame($expected, $actual);
    }

    /**
     * Ensure that version of code cannot be saved without deployment.
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /^Deployment has not been performed\. Saving the revision will cause problems$/
     */
    public function testDeployCommitWithoutDeploy()
    {
        $this->getWorker(['tasks.yml'])->commit();
    }

    /**
     * Ensure exception will be thrown when version of code cannot be saved.
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /^Cannot save the version of code to "\/etc\/deploy-revision-global" file$/
     */
    public function testDeployCommitNonWritableVersionFile()
    {
        list(, $worker) = $this->getDeploymentCommands(['tasks.yml'], null, '/etc/deploy-revision');

        $worker->commit();
    }

    /**
     * Ensure that version of code will be accurately saved.
     *
     * @param string $environment
     * @param string $versionFile
     *
     * @dataProvider providerDeployCommit
     */
    public function testDeployCommit($environment, $versionFile)
    {
        list(, $worker) = $this->getDeploymentCommands(['tasks.yml'], $environment, $versionFile);

        $worker->commit();
    }
}
