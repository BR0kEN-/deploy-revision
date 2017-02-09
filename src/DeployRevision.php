<?php

namespace DeployRevision;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class DeployRevision
{
    const SERVICE = 'deploy_revision.worker';

    /**
     * DI container.
     *
     * @var ContainerBuilder
     */
    private $container;

    public function __construct()
    {
        $this->container = new ContainerBuilder();

        $loader = new YamlFileLoader($this->container, new FileLocator(dirname(__DIR__)));
        $loader->load('services.yml');
    }

    /**
     * @return ContainerBuilder
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @see WorkerInterface::__construct()
     *
     * @param string $environment
     * @param string $versionFile
     *
     * @return WorkerInterface
     */
    public function getWorker($environment = null, $versionFile = null)
    {
        $parameters = func_get_args();

        foreach (['environment', 'version_file'] as $i => $parameter) {
            if (isset($parameters[$i])) {
                $this->container->setParameter("deploy_revision.$parameter", $parameters[$i]);
            }
        }

        return $this->getService(static::SERVICE, WorkerInterface::class);
    }

    /**
     * Verify a service instance.
     *
     * @param string $id
     *   Service ID.
     * @param string $interface
     *   FQN of interface which must be implemented by object.
     *
     * @return mixed
     *   An object which implements required interface.
     *
     * @throws \LogicException
     *   When object not implements required interface.
     */
    private function getService($id, $interface)
    {
        $service = $this->container->get($id);

        if ($service instanceof $interface) {
            return $service;
        }

        throw new \LogicException(sprintf('Service "%s" must implement the "%s" interface', $id, $interface));
    }
}
