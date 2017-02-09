<?php

namespace DeployRevision\Tests;

use DeployRevision\DeployRevision;

class WorkerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var DeployRevision
     */
    protected $deployRevision;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->deployRevision = new DeployRevision();
    }
}
