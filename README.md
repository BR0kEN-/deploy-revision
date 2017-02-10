# Deploy Revision

Create a YAML playbook, define a code revision, specify upgrade path as commands for performing and distribute them between environments.

[![Build Status](https://img.shields.io/travis/BR0kEN-/deploy-revision/master.svg?style=flat-square)](https://travis-ci.org/BR0kEN-/deploy-revision)
[![Code coverage](https://img.shields.io/scrutinizer/coverage/g/BR0kEN-/deploy-revision/master.svg?style=flat-square)](https://scrutinizer-ci.com/g/BR0kEN-/deploy-revision/?branch=master)
[![Quality Score](https://img.shields.io/scrutinizer/g/BR0kEN-/deploy-revision.svg?style=flat-square)](https://scrutinizer-ci.com/g/BR0kEN-/deploy-revision)
[![Coding standards](https://styleci.io/repos/81422463/shield?branch=master)](https://styleci.io/repos/81422463)
[![Total Downloads](https://img.shields.io/packagist/dt/deploy/deploy-revision.svg?style=flat-square)](https://packagist.org/packages/deploy/deploy-revision)
[![Latest Stable Version](https://poser.pugx.org/deploy/deploy-revision/v/stable?format=flat-square)](https://packagist.org/packages/deploy/deploy-revision)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://packagist.org/packages/deploy/deploy-revision)

## Installation

```shell
composer require deploy/deploy-revision:1.*
```

## Usage

A view of deployment playbook.

```yaml
commands:
  # Commands below will be executed only if environment ID will match.
  lush_website_at:
    140:
      - "drush updb"
  # It's predefined namespace for commands which should be run everywhere.
  global:
    89:
      - "drush cc all"
    121:
      - "drush cc drush"
      - "print bla bla"

# The order of commands for execution will looks like (only in case if current code version is lower than defined):
# - For "lush_website_at" environment:
#   - drush cc all - will be removed because we have "drush updb".
#   - drush cc drush
#   - print bla bla
#   - drush updb
#
# - For "global" environment:
#   - drush cc all
#   - drush cc drush
#   - print bla bla
```

Initialize the library at start.

```php
require_once 'vendor/autoload.php';

use DeployRevision\DeployRevision;

$deploy = new DeployRevision();
```

Use own YAML parser (if don't like Symfony).

```php
class SpycYaml implements YamlInterface
{
    /**
     * {@inheritdoc}
     */
    public function isAvailable()
    {
        return class_exists('Spyc');
    }
    
    /**
     * {@inheritdoc}
     */
    public function parse($content)
    {
        return Spyc::YAMLLoadString($content);
    }
}

$deploy
    ->getContainer()
    ->getDefinition('deploy_revision.yaml')
    ->setClass(SpycYaml::class);
```

Look for `*.yml` playbooks inside a directory and for tasks in particular file.

```php
$deployment = $deploy->getWorker();
$deployment->read('../lush_deploy.yml');
$deployment->read('../lush_deploy');
```

Set an environment ID and/or path to file where revision ID should be stored (or duplicated, from DB for instance).

```php
$deployment = $deploy->getWorker('lush_website_at', 'private://revisions/revision');
```

Filter commands. Callback should return the command for deletion.

```php
$deployment->filter(function ($command, array $commands, callable $resolver) {
    // Remove "drush cc css-js" since "drush updb" will do the job for it.
    if ('drush cc css-js' === $command && isset($commands['drush updb'])) {
        return $command; 
    }

    // Remove all previously added "drush cc all" from the list if "drush updb" exists.
    return $resolver(true, ['drush updb'], ['drush cc all'])
        // Remove newly added "drush cc all" if "drush updb" in the list.
        ?: $resolver(false, ['drush cc all'], ['drush updb']); 
});
```

Run deployment.

```php
$deployment->deploy(function ($command) {
    $arguments = explode(' ', $command);
    $program = array_shift($arguments);

    switch ($program) {
        case 'drush':
            drush_invoke($program, $arguments);
            break;

        default:
            printf('No handler found for the "%s" command.', $command);
  }
});
```

Save new revision ID.

```php
$deployment->commit();
```

## Notes

- All tasks from playbooks must be handled inside of deployment callback. This means that an implementation for recognizing the commands and for executing them should be done. Otherwise you'll not have any effect putting the commands there.
- Tasks collected in order you running the `->read()` method. If you are reading `test1.yml` and `test2.yml` and both files have the same revision number inside, then commands from first file will be located above ones from second.

  Reading of the directory will be done in alphabetical order. If multiple playbooks have the same revision numbers, then the only way you can affect on ordering - is to set file names in correct order.
