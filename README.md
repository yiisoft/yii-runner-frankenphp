<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px" alt="Yii">
    </a>
    <h1 align="center">Yii FrankenPHP worker runner</h1>
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

> Note: If you do not want to run Yii3 in worker mode, please use [yiisoft/yii-runner-http](https://github.com/yiisoft/yii-runner-http) which is default for [yiisoft/app](https://github.com/yiisoft/app) and [yiisoft/app-api](https://github.com/yiisoft/app-api).

## Requirements

- PHP 8.1 - 8.5.

## Installation

The package could be installed with [Composer](https://getcomposer.org):

```shell
composer require yiisoft/yii-runner-frankenphp
```

## General usage

In your application root create `worker.php`:

```php
<?php

declare(strict_types=1);

use App\Environment;
use Psr\Log\LogLevel;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\HtmlRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Runner\FrankenPHP\FrankenPHPApplicationRunner;

$root = __DIR__;

require_once $root . '/src/bootstrap.php';

if (Environment::appC3()) {
    $c3 = $root . '/c3.php';
    if (file_exists($c3)) {
        require_once $c3;
    }
}

$runner = new FrankenPHPApplicationRunner(
    rootPath: $root,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    temporaryErrorHandler: new ErrorHandler(
        new Logger(
            [
                (new StreamTarget())->setLevels([
                    LogLevel::EMERGENCY,
                    LogLevel::ERROR,
                    LogLevel::WARNING,
                ]),
            ],
        ),
        new HtmlRenderer(),
    ),
);
$runner->run();
```

Then edit `Caddyfile`s. For production it would be `docker/Caddyfile`:

```
# Production mode config
# https://frankenphp.dev/docs/config
# https://caddyserver.com/docs/caddyfile

{
    skip_install_trust

    frankenphp {

    }
}

{$SERVER_NAME::80} {
    encode zstd br gzip
    php_server {
        root /app/public
        worker {
            match *
            file /app/worker.php
        }
    }
}
```

For development it would be `docker/dev/Caddyfile`:

```
# Development mode config
# https://frankenphp.dev/docs/config
# https://caddyserver.com/docs/caddyfile

{
    skip_install_trust

    frankenphp {

    }
}

{$SERVER_NAME::80} {
    encode zstd br gzip
    php_server {
        root /app/public
        worker {
            match *
            file /app/worker.php
            watch /app/**/*.php
        }
    }
}
```

Development configuration has `watch` directive that makes FrankenPHP to reload changes when `.php` files are edited so you don't have to restart it manually.

Feel free to delete `public/index.php` and remove `yiisoft/yii-runner-http` from your `composer.json`. These are used
for classic non-worker mode only.

Don't forget to rebuild images with new configuration files using `make build`.

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

`$configModifiers` — [configuration modifiers](https://github.com/yiisoft/config#configuration-modifiers).

`$configDirectory` — the relative path from `$rootPath` to the configuration storage location.

`$vendorDirectory` — the relative path from `$rootPath` to the vendor directory.

`$configMergePlanFile` — the relative path from `$configDirectory` to merge plan.

`$temporaryErrorHandler` — a temporary error handler that is needed to handle creating of configuration and container 
instances.

`$emitter` — an emitter to send the response.

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

By default, the temporary error handler uses HTML renderer and logs to stdout. You can override this as follows:

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

The built-in default is equivalent to:

```php
$runner = $runner->withTemporaryErrorHandler(
    new Yiisoft\ErrorHandler\ErrorHandler(
        new Yiisoft\Log\Logger([
            new Yiisoft\Log\StreamTarget('php://stdout'),
        ]),
        new Yiisoft\ErrorHandler\Renderer\HtmlRenderer(),
    ),
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

The Yii FrankenPHP worker Runner is free software. It is released under the terms of the BSD License.
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
