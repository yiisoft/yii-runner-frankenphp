<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px" alt="Yii">
    </a>
    <h1 align="center">Yii FrankenPHP Runner</h1>
    <br>
</p>

[![Latest Stable Version](https://poser.pugx.org/yiisoft/yii-runner-frankenphp/v)](https://packagist.org/packages/yiisoft/yii-runner-frankenphp)
[![Total Downloads](https://poser.pugx.org/yiisoft/yii-runner-frankenphp/downloads)](https://packagist.org/packages/yiisoft/yii-runner-frankenphp)
[![Build status](https://github.com/yiisoft/yii-runner-frankenphp/actions/workflows/build.yml/badge.svg)](https://github.com/yiisoft/yii-runner-frankenphp/actions/workflows/build.yml)
[![Code Coverage](https://codecov.io/gh/yiisoft/yii-runner-frankenphp/branch/master/graph/badge.svg)](https://codecov.io/gh/yiisoft/yii-runner-frankenphp)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fyiisoft%2Fyii-runner-frankenphp%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/yiisoft/yii-runner-frankenphp/master)
[![static analysis](https://github.com/yiisoft/yii-runner-frankenphp/workflows/static%20analysis/badge.svg)](https://github.com/yiisoft/yii-runner-frankenphp/actions?query=workflow%3A%22static+analysis%22)
[![type-coverage](https://shepherd.dev/github/yiisoft/yii-runner-frankenphp/coverage.svg)](https://shepherd.dev/github/yiisoft/yii-runner-frankenphp)

The package contains a bootstrap for running Yii3 applications using [FrankenPHP](https://frankenphp.dev/) worker mode.

> Note: If you do not want to run Yii3 in worker mode, please use [yiisoft/yii-runner-http](https://github.com/yiisoft/yii-runner-http).

## Requirements

- PHP 8.1 or higher.

## Installation

The package could be installed with [Composer](https://getcomposer.org):

```shell
composer require yiisoft/yii-runner-frankenphp
```

## General usage

Create `worker.php` in your application root directory:

```php
use Yiisoft\Yii\Runner\FrankenPHP\FrankenPHPApplicationRunner;

ini_set('display_errors', 'stderr');

require_once __DIR__ . '/autoload.php';

(new FrankenPHPApplicationRunner(
    rootPath: __DIR__, 
    debug: $_ENV['YII_DEBUG'], 
    checkEvents: $_ENV['YII_DEBUG'], 
    environment: $_ENV['YII_ENV']
))->run();
```


Run FrankenPHP with the specified config [using Docker](https://frankenphp.dev/docs/docker/):

```sh
docker run \
    -e FRANKENPHP_CONFIG="worker /app/path/to/your/worker.php" \
    -v $PWD:/app \
    -p 80:80 -p 443:443 -p 443:443/udp \
    dunglas/frankenphp
```

or, if you prefer [standalone binaries](https://frankenphp.dev/docs/embed/):

```sh
frankenphp php-server --worker /app/path/to/your/worker.php
```

You can add `--watch="/path/to/your/app/**/*.php"` to make the worker restart on source code changes.

### Additional configuration

By default, the `FrankenPHPApplicationRunner` is configured to work with Yii application templates and follows the
[config groups convention](https://github.com/yiisoft/docs/blob/master/022-config-groups.md).

You can override the default configuration using constructor parameters and immutable setters.

#### Constructor parameters

`$rootPath` — the absolute path to the project root.

`$debug` — whether the debug mode is enabled.

`$checkEvents` — whether check events' configuration.

`$environment` — the environment name.

`$bootstrapGroup` — the bootstrap configuration group name.

`$eventsGroup` — the events' configuration group name.

`$diGroup` — the container definitions' configuration group name.

`$diProvidersGroup` — the container providers' configuration group name.

`$diDelegatesGroup` — the container delegates' configuration group name.

`$diTagsGroup` — the container tags' configuration group name.

`$paramsGroup` — the config parameters group name.

`$nestedParamsGroups` — configuration group names that are included in a config parameters group. This is needed for
recursive merge parameters.

`$nestedEventsGroups` — configuration group names that are included in events' configuration group. This is needed for
reverse and recursive merge events' configurations.

#### Immutable setters

If the configuration instance settings differ from the default, you can specify a customized configuration instance:

```php
/**
 * @var Yiisoft\Config\ConfigInterface $config
 * @var Yiisoft\Yii\Runner\FrankenPHP\FrankenPHPApplicationRunner $runner
 */

$runner = $runner->withConfig($config);
```

The default container is `Yiisoft\Di\Container`. But you can specify any implementation
of the `Psr\Container\ContainerInterface`:

```php
/**
 * @var Psr\Container\ContainerInterface $container
 * @var Yiisoft\Yii\Runner\FrankenPHP\FrankenPHPApplicationRunner $runner
 */

$runner = $runner->withContainer($container);
```

In addition to the error handler that is defined in the container, the runner uses a temporary error handler.
A temporary error handler is needed to handle the creation of configuration and container instances,
then the error handler configured in your application configuration will be used.

By default, the temporary error handler uses HTML renderer and logging to a file. You can override this as follows:

```php
/**
 * @var Psr\Log\LoggerInterface $logger
 * @var Yiisoft\ErrorHandler\Renderer\PlainTextRenderer $renderer
 * @var Yiisoft\Yii\Runner\FrankenPHP\FrankenPHPApplicationRunner $runner
 */

$runner = $runner->withTemporaryErrorHandler(
    new Yiisoft\ErrorHandler\ErrorHandler($logger, $renderer),
);
```

## Testing

### Unit testing

The package is tested with [PHPUnit](https://phpunit.de/). To run tests:
## Documentation

- [Internals](docs/internals.md)

If you need help or have a question, the [Yii Forum](https://forum.yiiframework.com/c/yii-3-0/63) is a good place for
that. You may also check out other [Yii Community Resources](https://www.yiiframework.com/community).

## License

The Yii FrankenPHP Runner is free software. It is released under the terms of the BSD License.
Please see [`LICENSE`](./LICENSE.md) for more information.

Maintained by [Yii Software](https://www.yiiframework.com/).

## Support the project

[![Open Collective](https://img.shields.io/badge/Open%20Collective-sponsor-7eadf1?logo=open%20collective&logoColor=7eadf1&labelColor=555555)](https://opencollective.com/yiisoft)

## Follow updates

[![Official website](https://img.shields.io/badge/Powered_by-Yii_Framework-green.svg?style=flat)](https://www.yiiframework.com/)
[![Twitter](https://img.shields.io/badge/twitter-follow-1DA1F2?logo=twitter&logoColor=1DA1F2&labelColor=555555?style=flat)](https://twitter.com/yiiframework)
[![Telegram](https://img.shields.io/badge/telegram-join-1DA1F2?style=flat&logo=telegram)](https://t.me/yii3en)
[![Facebook](https://img.shields.io/badge/facebook-join-1DA1F2?style=flat&logo=facebook&logoColor=ffffff)](https://www.facebook.com/groups/yiitalk)
[![Slack](https://img.shields.io/badge/slack-join-1DA1F2?style=flat&logo=slack)](https://yiiframework.com/go/slack)
