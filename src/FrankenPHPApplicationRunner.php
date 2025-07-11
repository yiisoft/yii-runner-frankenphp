<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\FrankenPHP;

use ErrorException;
use JsonException;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Throwable;
use Yiisoft\Definitions\Exception\CircularReferenceException;
use Yiisoft\Definitions\Exception\InvalidConfigException;
use Yiisoft\Definitions\Exception\NotInstantiableException;
use Yiisoft\Di\NotFoundException;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\Renderer\HtmlRenderer;
use Yiisoft\PsrEmitter\EmitterInterface;
use Yiisoft\PsrEmitter\FakeEmitter;
use Yiisoft\PsrEmitter\HeadersHaveBeenSentException;
use Yiisoft\PsrEmitter\SapiEmitter;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;

use function frankenphp_handle_request;
use function gc_collect_cycles;

// Prevent worker script termination when a client connection is interrupted.
ignore_user_abort(true);

/**
 * `FrankenPHPApplicationRunner` runs the Yii HTTP application using FrankenPHP worker mode.
 */
final class FrankenPHPApplicationRunner extends ApplicationRunner
{

    private readonly EmitterInterface $emitter;
    private ?FakeEmitter $fakeEmitter = null;

    /**
     * @param string $rootPath The absolute path to the project root.
     * @param bool $debug Whether the debug mode is enabled.
     * @param bool $checkEvents Whether to check events' configuration.
     * @param string|null $environment The environment name.
     * @param string $bootstrapGroup The bootstrap configuration group name.
     * @param string $eventsGroup The events' configuration group name.
     * @param string $diGroup The container definitions' configuration group name.
     * @param string $diProvidersGroup The container providers' configuration group name.
     * @param string $diDelegatesGroup The container delegates' configuration group name.
     * @param string $diTagsGroup The container tags' configuration group name.
     * @param string $paramsGroup The configuration parameters group name.
     * @param array $nestedParamsGroups Configuration group names that are included in a configuration parameters group.
     * This is needed for recursive merging of parameters.
     * @param array $nestedEventsGroups Configuration group names that are included in events' configuration group.
     * This is needed for the reverse and recursive merge of events' configurations.
     * @param object[] $configModifiers Modifiers for {@see Config}.
     * @param string $configDirectory The relative path from {@see $rootPath} to the configuration storage location.
     * @param string $vendorDirectory The relative path from {@see $rootPath} to the vendor directory.
     * @param string $configMergePlanFile The relative path from {@see $configDirectory} to merge plan.
     * @param ErrorHandler|null $temporaryErrorHandler The temporary error handler instance that used to handle
     * the creation of configuration and container instances, then the error handler configured in your application
     * configuration will be used.
     * @param EmitterInterface|null $emitter The emitter instance to send the response with. By default, it uses
     * {@see SapiEmitter}.
     *
     * @psalm-param list<string> $nestedParamsGroups
     * @psalm-param list<string> $nestedEventsGroups
     * @psalm-param list<object> $configModifiers
     */
    public function __construct(
        string $rootPath,
        bool $debug = false,
        bool $checkEvents = false,
        ?string $environment = null,
        string $bootstrapGroup = 'bootstrap-web',
        string $eventsGroup = 'events-web',
        string $diGroup = 'di-web',
        string $diProvidersGroup = 'di-providers-web',
        string $diDelegatesGroup = 'di-delegates-web',
        string $diTagsGroup = 'di-tags-web',
        string $paramsGroup = 'params-web',
        array $nestedParamsGroups = ['params'],
        array $nestedEventsGroups = ['events'],
        array $configModifiers = [],
        string $configDirectory = 'config',
        string $vendorDirectory = 'vendor',
        string $configMergePlanFile = '.merge-plan.php',
        private ?ErrorHandler $temporaryErrorHandler = null,
        ?EmitterInterface $emitter = null,
    ) {
        $this->emitter = $emitter ?? new SapiEmitter();

        parent::__construct(
            $rootPath,
            $debug,
            $checkEvents,
            $environment,
            $bootstrapGroup,
            $eventsGroup,
            $diGroup,
            $diProvidersGroup,
            $diDelegatesGroup,
            $diTagsGroup,
            $paramsGroup,
            $nestedParamsGroups,
            $nestedEventsGroups,
            $configModifiers,
            $configDirectory,
            $vendorDirectory,
            $configMergePlanFile,
        );
    }

    /**
     * Returns a new instance with the specified temporary error handler instance {@see ErrorHandler}.
     *
     * A temporary error handler is needed to handle the creation of configuration and container instances,
     * then the error handler configured in your application configuration will be used.
     *
     * @param ErrorHandler $temporaryErrorHandler The temporary error handler instance.
     */
    public function withTemporaryErrorHandler(ErrorHandler $temporaryErrorHandler): self
    {
        $new = clone $this;
        $new->temporaryErrorHandler = $temporaryErrorHandler;
        return $new;
    }

    /**
     * {@inheritDoc}
     *
     * @throws CircularReferenceException|ErrorException|InvalidConfigException|JsonException
     * @throws ContainerExceptionInterface|NotFoundException|NotFoundExceptionInterface|NotInstantiableException
     */
    public function run(): void
    {
        $this->runInternal($this->emitter);
    }

    /**
     * Runs the application and gets the response instead of emitting it.
     * This method is useful for testing purposes or when you want to handle the response.
     *
     * @param ServerRequestInterface|null $request The server request to handle (optional).
     * @throws CircularReferenceException|ErrorException|HeadersHaveBeenSentException|InvalidConfigException
     * @throws ContainerExceptionInterface|NotFoundException|NotFoundExceptionInterface|NotInstantiableException
     * @return ResponseInterface The response generated by the application.
     */
    public function runAndGetResponse(?ServerRequestInterface $request = null): ResponseInterface
    {
        $this->runInternal(
            $this->fakeEmitter ??= new FakeEmitter(),
            $request,
        );
        return $this->fakeEmitter->getLastResponse()
            ?? throw new LogicException('No response was emitted.');
    }

    /**
     * @throws CircularReferenceException|ErrorException|HeadersHaveBeenSentException|InvalidConfigException
     * @throws ContainerExceptionInterface|NotFoundException|NotFoundExceptionInterface|NotInstantiableException
     */
    private function runInternal(EmitterInterface $emitter, ?ServerRequestInterface $request = null): void
    {
        // Register temporary error handler to catch error while the container is building.
        $temporaryErrorHandler = $this->createTemporaryErrorHandler();
        $this->registerErrorHandler($temporaryErrorHandler);

        $container = $this->getContainer();

        // Register error handler with real container-configured dependencies.
        /** @var ErrorHandler $actualErrorHandler */
        $actualErrorHandler = $container->get(ErrorHandler::class);
        $this->registerErrorHandler($actualErrorHandler, $temporaryErrorHandler);

        $this->runBootstrap();
        $this->checkEvents();

        /** @var Application $application */
        $application = $container->get(Application::class);
        $application->start();

        /** @var RequestFactory $requestFactory */
        $requestFactory = $container->get(RequestFactory::class);

        $handler = function () use ($application, $container, $requestFactory, $emitter, $request): bool {
            $startTime = microtime(true);

            if ($request === null) {
                $request = $requestFactory->create();
            }

            $request = $request->withAttribute('applicationStartTime', $startTime);

            try {
                $response = $application->handle($request);
            } catch (Throwable $throwable) {
                $handler = new ThrowableHandler($throwable);
                /**
                 * @var $response ResponseInterface
                 * @psalm-suppress MixedMethodCall
                 */
                $response = $container
                    ->get(ErrorCatcher::class)
                    ->process($request, $handler);

            } finally {
                $emitter->emit($response);
                $this->afterRespond($application, $container, $response);
                return true;
            }
        };


        $maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);

        for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
            $keepRunning = frankenphp_handle_request($handler);
            if (!$keepRunning) {
                break;
            }
        }

        $application->shutdown();
    }

    private function createTemporaryErrorHandler(): ErrorHandler
    {
        return $this->temporaryErrorHandler ??
            new ErrorHandler(
                $this->logger ?? new NullLogger(),
                new HtmlRenderer(),
            );
    }

    /**
     * @throws ErrorException
     */
    private function registerErrorHandler(ErrorHandler $registered, ?ErrorHandler $unregistered = null): void
    {
        $unregistered?->unregister();

        if ($this->debug) {
            $registered->debug();
        }

        $registered->register();
    }

    private function afterRespond(
        Application $application,
        ContainerInterface $container,
        ?ResponseInterface $response,
    ): void
    {
        $application->afterEmit($response);
        /** @psalm-suppress MixedMethodCall */
        $container
            ->get(StateResetter::class)
            ->reset(); // We should reset the state of such services every request.
        gc_collect_cycles();
    }
}
