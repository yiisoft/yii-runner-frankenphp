<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\FrankenPHP\Tests;

use Exception;
use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use HttpSoft\Message\UriFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Yiisoft\Config\Config;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Config\ConfigPaths;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Factory\ThrowableResponseFactory;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\ErrorHandler\ThrowableRendererInterface;
use Yiisoft\ErrorHandler\ThrowableResponseFactoryInterface;
use Yiisoft\Middleware\Dispatcher\Event\AfterMiddleware;
use Yiisoft\Middleware\Dispatcher\Event\BeforeMiddleware;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\PsrEmitter\FakeEmitter;
use Yiisoft\Test\Support\EventDispatcher\SimpleEventDispatcher;
use Yiisoft\Test\Support\Log\SimpleLogger;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Event\InvalidEventConfigurationFormatException;
use Yiisoft\Yii\Http\Event\AfterEmit;
use Yiisoft\Yii\Http\Event\AfterRequest;
use Yiisoft\Yii\Http\Event\ApplicationShutdown;
use Yiisoft\Yii\Http\Event\ApplicationStartup;
use Yiisoft\Yii\Http\Event\BeforeRequest;
use Yiisoft\Yii\Http\Handler\NotFoundHandler;
use Yiisoft\Yii\Runner\FrankenPHP\FrankenPHPApplicationRunner;
use Yiisoft\Yii\Runner\ApplicationRunner;

use Throwable;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;
use function array_key_exists;
use function function_exists;

final class FrankenPHPApplicationRunnerTest extends TestCase
{
    public static int $frankenphpHandleRequestCalls = 0;
    public static int $frankenphpHandleRequestKeepRunningUntil = 1;
    public static array $frankenphpRequestServerParameters = [];
    public static bool $bootstrapExecuted = false;
    private static array $frankenphpRequestServerParameterKeys = [];

    private FrankenPHPApplicationRunner $runner;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('frankenphp_handle_request')) {
            eval(<<<'PHP_WRAP'
            namespace {
                function frankenphp_handle_request(callable $handler): bool
                {
                    return \Yiisoft\Yii\Runner\FrankenPHP\Tests\FrankenPHPApplicationRunnerTest::handleFrankenPhpRequest($handler);
                }
            }
            PHP_WRAP);
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['MAX_REQUESTS']);
        foreach (self::$frankenphpRequestServerParameterKeys as $key) {
            unset($_SERVER[$key]);
        }
        self::$frankenphpHandleRequestCalls = 0;
        self::$frankenphpHandleRequestKeepRunningUntil = 1;
        self::$frankenphpRequestServerParameters = [];
        self::$frankenphpRequestServerParameterKeys = [];
        self::$bootstrapExecuted = false;
        $this->runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: true,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public static function handleFrankenPhpRequest(callable $handler): bool
    {
        foreach (self::$frankenphpRequestServerParameterKeys as $key) {
            unset($_SERVER[$key]);
        }

        self::$frankenphpRequestServerParameterKeys = [];
        foreach (self::$frankenphpRequestServerParameters[self::$frankenphpHandleRequestCalls] ?? [] as $key => $value) {
            $_SERVER[$key] = $value;
            self::$frankenphpRequestServerParameterKeys[] = $key;
        }

        self::$frankenphpHandleRequestCalls++;

        return $handler() && self::$frankenphpHandleRequestCalls < self::$frankenphpHandleRequestKeepRunningUntil;
    }

    public function testRun(): void
    {
        $this->expectOutputString('OK');

        $this->runner->run();
    }

    public function testRunWithoutBootstrapAndCheckEvents(): void
    {
        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: true,
            checkEvents: false,
        );

        $this->expectOutputString('OK');

        $runner->run();
    }

    public function testConstructorDefaultsAreConfiguredAsExpected(): void
    {
        $runner = new FrankenPHPApplicationRunner(__DIR__ . '/Support');

        $this->assertFalse($this->getPropertyValue($runner, 'debug', ApplicationRunner::class));
        $this->assertFalse($this->getPropertyValue($runner, 'checkEvents', ApplicationRunner::class));
        $this->assertSame(
            ['params'],
            $this->getPropertyValue($runner, 'nestedParamsGroups', ApplicationRunner::class),
        );
        $this->assertSame(
            ['events'],
            $this->getPropertyValue($runner, 'nestedEventsGroups', ApplicationRunner::class),
        );
    }

    public function testRunWithCustomizedConfiguration(): void
    {
        $container = $this->createContainer();

        $runner = $this->runner
            ->withContainer($container)
            ->withConfig($this->createConfig());

        $runner->run();

        /** @var SimpleEventDispatcher $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);

        $this->assertSame(
            [
                ApplicationStartup::class,
                BeforeRequest::class,
                BeforeMiddleware::class,
                AfterMiddleware::class,
                AfterRequest::class,
                AfterEmit::class,
                ApplicationShutdown::class,
            ],
            $dispatcher->getEventClasses(),
        );
    }

    public function testRunWithFailureDuringProcess(): void
    {
        $runner = $this->runner->withContainer($this->createContainer(true));

        $this->expectOutputRegex('/^Exception with message "Failure"/');

        $runner->run();
    }

    public function testRunExecutesBootstrapCallbacks(): void
    {
        $runner = (new FrankenPHPApplicationRunner(__DIR__ . '/Support', false))
            ->withContainer($this->createContainer())
            ->withConfig($this->createStubConfig([
                'bootstrap-web' => [
                    static function (ContainerInterface $container): void {
                        FrankenPHPApplicationRunnerTest::$bootstrapExecuted = $container instanceof ContainerInterface;
                    },
                ],
            ]));

        $runner->run();

        $this->assertTrue(self::$bootstrapExecuted);
    }

    public function testRunChecksEventsConfigurationWhenEnabled(): void
    {
        $runner = (new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: false,
            checkEvents: true,
        ))
            ->withContainer($this->createContainer())
            ->withConfig($this->createStubConfig([
                'events-web' => ['not-an-event-class' => [static fn() => null]],
            ]));

        $this->expectException(InvalidEventConfigurationFormatException::class);

        $runner->run();
    }

    public function testRunRethrowsWhenErrorResponseCreationFails(): void
    {
        $runner = $this->runner->withContainer($this->createContainer(true, true));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failure while creating error response');

        $runner->run();
    }

    public function testConfigMergePlanFile(): void
    {
        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            configMergePlanFile: 'test-merge-plan.php',
        );

        $params = $runner->getConfig()->get('params-web');

        $this->assertSame(['a' => 42,], $params);
    }

    public function testConfigDirectory(): void
    {
        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            configDirectory: 'custom-config',
        );

        $params = $runner->getConfig()->get('params-web');

        $this->assertSame(['age' => 22], $params);
    }

    public function testImmutability(): void
    {
        $this->assertNotSame($this->runner, $this->runner->withConfig($this->createConfig()));
        $this->assertNotSame($this->runner, $this->runner->withContainer($this->createContainer()));
    }

    public function testDoNotModifyExistsContentLength(): void
    {
        $emitter = new FakeEmitter();
        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            environment: 'do-not-modify-exists-content-length',
            emitter: $emitter,
        );

        $runner->run();

        $response = $emitter->getLastResponse();
        assertInstanceOf(ResponseInterface::class, $response);
        assertSame(
            ['Content-Length' => ['100']],
            $response->getHeaders(),
        );
    }

    public function testDoNotAddContentMiddlewareWithContinueStatus(): void
    {
        $emitter = new FakeEmitter();
        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            environment: 'do-not-add-content-middleware-with-continue-status',
            emitter: $emitter,
        );

        $runner->run();

        $response = $emitter->getLastResponse();
        assertInstanceOf(ResponseInterface::class, $response);
        assertSame(
            [],
            $response->getHeaders(),
        );
    }

    public function testRunAndGetResponse(): void
    {
        $runner = new FrankenPHPApplicationRunner(__DIR__ . '/Support', false);

        $response = $runner->runAndGetResponse();

        assertSame(200, $response->getStatusCode());
        $this->expectOutputString('');
    }

    public function testRunAndGetResponseWithRequest(): void
    {
        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            environment: 'run-without-emit-with-request',
        );

        $request = (new ServerRequest(headers: ['X-CONTENT' => ['Test content']]));
        $response = $runner->runAndGetResponse($request);

        assertSame(200, $response->getStatusCode());
        assertSame('Test content', $response->getBody()->getContents());
        $this->expectOutputString('');
    }

    public function testRunAndGetResponseReusesFakeEmitter(): void
    {
        $runner = new FrankenPHPApplicationRunner(__DIR__ . '/Support', false);

        $runner->runAndGetResponse();
        $firstEmitter = $this->getPropertyValue($runner, 'fakeEmitter');

        $runner->runAndGetResponse();
        $secondEmitter = $this->getPropertyValue($runner, 'fakeEmitter');

        $this->assertSame($firstEmitter, $secondEmitter);
    }

    public function testWorkerModeRespectsMaxRequestsAndResetsStateBetweenRequests(): void
    {
        $_SERVER['MAX_REQUESTS'] = '2';
        self::$frankenphpHandleRequestKeepRunningUntil = 5;

        $emitter = new FakeEmitter();
        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: false,
            emitter: $emitter,
        );
        $runner = $runner->withContainer($this->createWorkerModeContainer());

        $runner->run();

        $response = $emitter->getLastResponse();
        assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('1', (string) $response->getBody());
        $this->assertSame(2, self::$frankenphpHandleRequestCalls);
    }

    public function testWorkerModeWithoutMaxRequestsContinuesUntilHandlerStops(): void
    {
        unset($_SERVER['MAX_REQUESTS']);
        self::$frankenphpHandleRequestKeepRunningUntil = 2;

        $runner = (new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: false,
        ))->withContainer($this->createWorkerModeContainer());

        $runner->run();

        $this->assertSame(2, self::$frankenphpHandleRequestCalls);
    }

    public function testWorkerModeCastsMaxRequestsToInt(): void
    {
        $_SERVER['MAX_REQUESTS'] = '2foo';
        self::$frankenphpHandleRequestKeepRunningUntil = 5;

        $runner = (new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: false,
        ))->withContainer($this->createWorkerModeContainer());

        $runner->run();

        $this->assertSame(2, self::$frankenphpHandleRequestCalls);
    }

    public function testWorkerModeDoesNotLeakAuthenticatedUserToNextRequest(): void
    {
        $_SERVER['MAX_REQUESTS'] = '2';
        self::$frankenphpHandleRequestKeepRunningUntil = 5;
        self::$frankenphpRequestServerParameters = [
            ['HTTP_X_USER_ID' => 'alice'],
            [],
        ];

        $emitter = new FakeEmitter();
        $runner = (new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: false,
            emitter: $emitter,
        ))->withContainer($this->createAuthenticatedUserWorkerModeContainer());

        $runner->run();

        $response = $emitter->getLastResponse();
        assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('guest', (string) $response->getBody());
        $this->assertSame(2, self::$frankenphpHandleRequestCalls);
    }

    private function createContainer(
        bool $throwException = false,
        bool $throwOnErrorResponseCreation = false,
    ): ContainerInterface {
        $containerConfig = ContainerConfig::create()
            ->withDefinitions($this->createDefinitions($throwException, $throwOnErrorResponseCreation));
        return new Container($containerConfig);
    }

    private function createConfig(): Config
    {
        return new Config(new ConfigPaths(__DIR__ . '/Support', 'config'), paramsGroup: 'params-web');
    }

    private function createStubConfig(array $configurations): ConfigInterface
    {
        return new class ($configurations) implements ConfigInterface {
            public function __construct(private readonly array $configurations) {}

            public function has(string $group): bool
            {
                return array_key_exists($group, $this->configurations);
            }

            public function get(string $group): array
            {
                return $this->configurations[$group];
            }
        };
    }

    private function createDefinitions(bool $throwException, bool $throwOnErrorResponseCreation): array
    {
        return [
            EventDispatcherInterface::class => SimpleEventDispatcher::class,
            LoggerInterface::class => SimpleLogger::class,
            ResponseFactoryInterface::class => ResponseFactory::class,
            ServerRequestFactoryInterface::class => ServerRequestFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
            ThrowableRendererInterface::class => PlainTextRenderer::class,
            UriFactoryInterface::class => UriFactory::class,
            UploadedFileFactoryInterface::class => UploadedFileFactory::class,

            ThrowableResponseFactoryInterface::class => $throwOnErrorResponseCreation
                ? static fn() => new class implements ThrowableResponseFactoryInterface {
                    public function create(
                        Throwable $throwable,
                        ServerRequestInterface $request,
                    ): ResponseInterface {
                        throw new Exception('Failure while creating error response', previous: $throwable);
                    }
                }
                : [
                    'class' => ThrowableResponseFactory::class,
                    'forceContentType()' => ['text/plain'],
                ],

            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to(
                        static function (ContainerInterface $container) use ($throwException) {
                            return $container
                                ->get(MiddlewareDispatcher::class)
                                ->withMiddlewares([
                                    static fn() => new class ($throwException) implements MiddlewareInterface {
                                        public function __construct(private bool $throwException) {}

                                        public function process(
                                            ServerRequestInterface $request,
                                            RequestHandlerInterface $handler,
                                        ): ResponseInterface {
                                            if ($this->throwException) {
                                                throw new Exception('Failure');
                                            }

                                            return (new ResponseFactory())->createResponse();
                                        }
                                    },
                                ]);
                        },
                    ),
                    'fallbackHandler' => Reference::to(NotFoundHandler::class),
                ],
            ],
        ];
    }

    private function createWorkerModeContainer(): ContainerInterface
    {
        $containerConfig = ContainerConfig::create()->withDefinitions([
            EventDispatcherInterface::class => SimpleEventDispatcher::class,
            LoggerInterface::class => SimpleLogger::class,
            ResponseFactoryInterface::class => ResponseFactory::class,
            ServerRequestFactoryInterface::class => ServerRequestFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
            ThrowableRendererInterface::class => PlainTextRenderer::class,
            UriFactoryInterface::class => UriFactory::class,
            UploadedFileFactoryInterface::class => UploadedFileFactory::class,
            'requestCounter' => static fn() => new class {
                public int $value = 0;
            },

            ThrowableResponseFactoryInterface::class => [
                'class' => ThrowableResponseFactory::class,
                'forceContentType()' => ['text/plain'],
            ],

            StateResetter::class => static function (ContainerInterface $container): StateResetter {
                $resetter = new StateResetter($container);
                $resetter->setResetters([
                    'requestCounter' => function (): void {
                        $this->value = 0;
                    },
                ]);

                return $resetter;
            },

            'applicationMiddleware' => static fn(ContainerInterface $container) => new class (
                $container->get('requestCounter'),
            ) implements MiddlewareInterface {
                public function __construct(private readonly object $counter) {}

                public function process(
                    ServerRequestInterface $request,
                    RequestHandlerInterface $handler,
                ): ResponseInterface {
                    $this->counter->value++;

                    return (new Response())->withBody(
                        (new StreamFactory())->createStream((string) $this->counter->value),
                    );
                }
            },

            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to(
                        static fn(ContainerInterface $container) => $container
                            ->get(MiddlewareDispatcher::class)
                            ->withMiddlewares([
                                static fn(ContainerInterface $container) => $container->get('applicationMiddleware'),
                            ]),
                    ),
                    'fallbackHandler' => Reference::to(NotFoundHandler::class),
                ],
            ],
        ]);

        return new Container($containerConfig);
    }

    private function createAuthenticatedUserWorkerModeContainer(): ContainerInterface
    {
        $containerConfig = ContainerConfig::create()->withDefinitions([
            EventDispatcherInterface::class => SimpleEventDispatcher::class,
            LoggerInterface::class => SimpleLogger::class,
            ResponseFactoryInterface::class => ResponseFactory::class,
            ServerRequestFactoryInterface::class => ServerRequestFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
            ThrowableRendererInterface::class => PlainTextRenderer::class,
            UriFactoryInterface::class => UriFactory::class,
            UploadedFileFactoryInterface::class => UploadedFileFactory::class,

            ThrowableResponseFactoryInterface::class => [
                'class' => ThrowableResponseFactory::class,
                'forceContentType()' => ['text/plain'],
            ],

            CurrentUser::class => [
                'class' => CurrentUser::class,
                'reset' => function (): void {
                    $this->logout();
                },
            ],

            Application::class => [
                '__construct()' => [
                    'dispatcher' => DynamicReference::to(
                        static fn(ContainerInterface $container) => $container
                            ->get(MiddlewareDispatcher::class)
                            ->withMiddlewares([
                                CurrentUserMiddleware::class,
                            ]),
                    ),
                    'fallbackHandler' => Reference::to(NotFoundHandler::class),
                ],
            ],
        ]);

        return new Container($containerConfig);
    }

    private function createErrorHandler(): ErrorHandler
    {
        return new ErrorHandler(new SimpleLogger(), new PlainTextRenderer());
    }

    private function getPropertyValue(
        object $object,
        string $property,
        string $class = FrankenPHPApplicationRunner::class,
    ): mixed {
        return (new ReflectionProperty($class, $property))->getValue($object);
    }

    private function invokeMethod(object $object, string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
    }
}

final class CurrentUser
{
    private ?string $id = null;

    public function authenticate(string $id): void
    {
        $this->id = $id;
    }

    public function logout(): void
    {
        $this->id = null;
    }

    public function name(): string
    {
        return $this->id ?? 'guest';
    }
}

final class CurrentUserMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $userId = $request->getHeaderLine('X-User-Id');
        if ($userId !== '') {
            $this->currentUser->authenticate($userId);
        }

        return (new Response())->withBody(
            (new StreamFactory())->createStream($this->currentUser->name()),
        );
    }
}
