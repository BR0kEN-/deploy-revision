<?php

namespace DeployRevision\Tests;

use DeployRevision\Worker;
use DeployRevision\YamlInterface;
use DeployRevision\WorkerInterface;
use DeployRevision\DeployRevision;

class WorkerTest extends \PHPUnit_Framework_TestCase
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

    protected function getWorkerMock(array $arguments)
    {
        $workerMockBuilder = $this->getMockBuilder(Worker::class);
        $workerMockBuilder->setConstructorArgs($arguments);

        return $workerMockBuilder->getMock();
    }

    protected function getYamlInterfaceMock()
    {
        return $this
            ->getMockBuilder(YamlInterface::class)
            ->setMethods(['isAvailable', 'parse'])
            ->getMock();
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessageRegExp /^Service ".*" must implement the ".*" interface$/
     */
    public function testServiceIntegrity()
    {
        $this->deploy
            ->getContainer()
            ->getDefinition(DeployRevision::SERVICE)
            ->setClass(\stdClass::class);

        $this->deploy->getWorker();
    }

    /**
     * @param string $environment
     * @param string $versionFile
     * @param int $version
     *
     * @dataProvider providerConstructor
     */
    public function testConstructor($environment, $versionFile, $version)
    {
        $versionFilePath = "$versionFile-$environment";

        static::assertSame(strlen($version), file_put_contents($versionFilePath, $version), 'Save dummy version');

        $yaml = $this->getYamlInterfaceMock();
        $yaml->method('isAvailable')->willReturn(true);

        $worker = $this->getWorkerMock([$yaml, $environment, $versionFile]);

        foreach ([
            'yaml' => $yaml,
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
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /YAML parser "\w+" is not available/
     */
    public function testConstructorWithBrokenYamlParser()
    {
        $yaml = $this->getYamlInterfaceMock();
        $yaml->method('isAvailable')->willReturn(false);

        $this->getWorkerMock([$yaml, 'global', '/tmp/deploy-revision']);
    }

    /**
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
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /Complex value cannot be a command: .+?/
     */
    public function testDeployBrokenPlaybook()
    {
        $this->getWorker(['disallowed-structure.yml'])->deploy(function ($command) {
        });
    }

    public function testDeploySkipVersion()
    {
        $worker = $this->deploy->getWorker();
        $reflection = new \ReflectionClass($worker);
        $commands = [];

        // Set current code version to extremely big value to skip tasks with lower version.
        $currentCodeVersion = $reflection->getProperty('currentCodeVersion');
        $currentCodeVersion->setAccessible(true);
        $currentCodeVersion->setValue($worker, 2000);
        $currentCodeVersion->setAccessible(false);

        $worker->read(__DIR__ . '/fixtures/tasks.yml');

        $worker->deploy(function ($command) use (&$commands) {
            $commands[] = $command;
        });

        // Verify current version of code.
        static::assertSame(2000, $worker->getCurrentCodeVersion());
        // Ensure that no tasks will be deployed.
        static::assertEmpty($commands);
    }

    /**
     * @param string $environment
     * @param string[] $fixtures
     * @param string[] $expected
     *
     * @dataProvider providerDeployOrdering
     */
    public function testDeployOrdering($environment, array $fixtures, array $expected)
    {
        $worker = $this->getWorker($fixtures, $environment);
        $actual = [];

        $worker->deploy(function ($command) use (&$actual) {
            $actual[] = $command;
        });

        static::assertSame($expected, $actual);
    }

    /**
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
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /^Deployment has not been performed$/
     */
    public function testDeployCommitWithoutDeploy()
    {
        $this->getWorker(['tasks.yml'])->commit();
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /^Cannot save the version of code to "\/etc\/deploy-revision-global" file$/
     */
    public function testDeployCommitNonWritableVersionFile()
    {
        $worker = $this->getWorker(['tasks.yml'], null, '/etc/deploy-revision');

        $worker->deploy(function ($command) {
        });

        $worker->commit();
    }

    /**
     * @param string $environment
     * @param string $versionFile
     *
     * @dataProvider providerDeployCommit
     */
    public function testDeployCommit($environment, $versionFile)
    {
        $file = "$versionFile-$environment";

        if (file_exists($file)) {
            static::assertTrue(unlink($file));
        }

        $worker = $this->getWorker(['tasks.yml'], $environment, $versionFile);

        $worker->deploy(function ($command) {
        });

        $worker->commit();
    }
}
