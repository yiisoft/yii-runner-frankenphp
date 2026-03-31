<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\FrankenPHP\Tests;

use Psr\Http\Message\ResponseInterface;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\Yii\Runner\FrankenPHP\FrankenPHPApplicationRunner;

final class FrankenPHPApplicationRunnerTest extends TestCase
{
    public function testInstantiation(): void
    {
        $runner = new FrankenPHPApplicationRunner(__DIR__, false);
        $this->assertInstanceOf(FrankenPHPApplicationRunner::class, $runner);
    }

    public function testWithTemporaryErrorHandler(): void
    {
        $runner = new FrankenPHPApplicationRunner(__DIR__ . '/Support');
        
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $errorHandler = new ErrorHandler($logger, new \Yiisoft\ErrorHandler\Renderer\PlainTextRenderer());

        $newRunner = $runner->withTemporaryErrorHandler($errorHandler);

        $this->assertNotSame($runner, $newRunner);
        $this->assertInstanceOf(FrankenPHPApplicationRunner::class, $newRunner);
    }

    public function testConstructorWithCustomEmitter(): void
    {
        $emittedResponses = [];
        $customEmitter = new class ($emittedResponses) implements \Yiisoft\PsrEmitter\EmitterInterface {
            public function __construct(private array &$emittedResponses)
            {
            }

            public function emit(ResponseInterface $response, bool $withoutBody = false): void
            {
                $this->emittedResponses[] = $response;
            }
        };

        $runner = new FrankenPHPApplicationRunner(
            rootPath: __DIR__ . '/Support',
            debug: true,
            emitter: $customEmitter,
        );

        $this->assertInstanceOf(FrankenPHPApplicationRunner::class, $runner);
    }
}
