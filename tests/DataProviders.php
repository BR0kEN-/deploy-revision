<?php

namespace DeployRevision\Tests;

use DeployRevision\Tests\Parsers\Spyc;
use DeployRevision\Tests\Loggers\Throwable;

trait DataProviders
{
    public function providerConstructor()
    {
        return [
            ['test', '/tmp/deploy-revision', 99],
            ['awesome', '/tmp/wow', 01],
        ];
    }

    public function providerConstructorWithSpycYamlParser()
    {
        return [
            [Spyc::class, ['tasks.yml'], ['drush cc drush', 'print bla bla']],
            [Spyc::class, ['tasks/TASKS-1.yaml'], ['drush cc all']],
        ];
    }

    public function providerCodeVersions()
    {
        return [
            [['tasks.yml', 'file-without-commands.yml'], null, 121, 0],
            [['tasks', 'wrong-file-extension.ylm'], 'lush_website_de', 122, 0],
        ];
    }

    public function providerDeployReadingNonExistent()
    {
        return [
            [['missing-file.yml'], null],
            [['missing-directory'], Throwable::class],
        ];
    }

    public function providerDeployOrdering()
    {
        // Take tasks from "tasks.yml", then from "TASKS-1.yaml" and "TASKS-2.yml".
        $regularOrder = ['tasks.yml', 'tasks'];
        // Take tasks from "TASKS-1.yaml", then from "TASKS-2.yml" and "tasks.yml".
        $reverseOrder = array_reverse($regularOrder);

        return [
            [
                null,
                $regularOrder,
                [
                    'drush cc drush',
                    'print bla bla',
                    'drush cc all',
                    'drush cc css-js',
                ],
            ],
            [
                null,
                $reverseOrder,
                [
                    'drush cc all',
                    'drush cc css-js',
                    'drush cc drush',
                    'print bla bla',
                ],
            ],
            [
                'lush_website_de',
                $regularOrder,
                [
                    'drush updb',
                    'drush cc drush',
                    'print bla bla',
                    'drush cc all',
                    'drush cc css-js',
                ],
            ],
            [
                'lush_website_de',
                $reverseOrder,
                [
                    'drush updb',
                    'drush cc all',
                    'drush cc css-js',
                    'drush cc drush',
                    'print bla bla',
                ],
            ],
        ];
    }

    public function providerDeployFiltering()
    {
        // Take tasks from "tasks.yml", then from "TASKS-1.yaml" and "TASKS-2.yml".
        $regularOrder = ['tasks.yml', 'tasks'];
        // Take tasks from "TASKS-1.yaml", then from "TASKS-2.yml" and "tasks.yml".
        $reverseOrder = array_reverse($regularOrder);

        // - Remove "drush cc css-js" by virtue of "drush updb".
        // - Remove "drush cc all" by virtue of "drush updb".
        return [
            [
                'lush_website_de',
                $regularOrder,
                [
                    'drush updb',
                    'drush cc drush',
                    'print bla bla',
                ],
            ],
            [
                'lush_website_de',
                $reverseOrder,
                [
                    'drush updb',
                    'drush cc drush',
                    'print bla bla',
                ],
            ],
        ];
    }

    public function providerDeployCommit()
    {
        return [
            ['global', '/tmp/deploy-revision-commit'],
        ];
    }
}
