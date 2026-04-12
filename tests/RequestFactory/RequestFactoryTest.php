<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Runner\FrankenPHP\Tests\RequestFactory;

use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use HttpSoft\Message\UriFactory;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Yiisoft\Yii\Runner\FrankenPHP\RequestFactory;

use function fopen;

final class RequestFactoryTest extends TestCase
{
    public static array|false $getAllHeadersResult = false;

    private array $globalServer = [];
    private array $globalPost = [];
    private array $globalFiles = [];

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('getallheaders')) {
            eval(<<<'PHP'
namespace {
    function getallheaders(): array|false
    {
        return \Yiisoft\Yii\Runner\FrankenPHP\Tests\RequestFactory\RequestFactoryTest::getAllHeadersStubResult();
    }
}
PHP);
        }
    }

    public static function getAllHeadersStubResult(): array|false
    {
        return self::$getAllHeadersResult;
    }

    protected function setUp(): void
    {
        $this->globalServer = $_SERVER;
        $this->globalPost = $_POST;
        $this->globalFiles = $_FILES;
        self::$getAllHeadersResult = false;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->globalServer;
        $_POST = $this->globalPost;
        $_FILES = $this->globalFiles;
    }

    public function testUploadedFiles(): void
    {
        $_SERVER = [
            'HTTP_HOST' => 'test',
            'REQUEST_METHOD' => 'GET',
        ];
        $_FILES = [
            'file1' => [
                'name' => $firstFileName = 'facepalm.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => __DIR__ . '/image',
                'error' => '0',
                'size' => '463',
            ],
            'file2' => [
                'name' => [$secondFileName = 'facepalm2.jpg', $thirdFileName = 'facepalm3.jpg'],
                'type' => ['image/jpeg', 'image/jpeg'],
                'tmp_name' => [__DIR__ . '/image2', __DIR__ . '/image3'],
                'error' => ['0', '0'],
                'size' => ['778', '1415'],
            ],
        ];

        $serverRequest = $this->createRequestFactory()->create();

        $firstUploadedFile = $serverRequest->getUploadedFiles()['file1'];
        $this->assertSame($firstFileName, $firstUploadedFile->getClientFilename());

        $secondUploadedFile = $serverRequest->getUploadedFiles()['file2'][0];
        $this->assertSame($secondFileName, $secondUploadedFile->getClientFilename());

        $thirdUploadedFile = $serverRequest->getUploadedFiles()['file2'][1];
        $this->assertSame($thirdFileName, $thirdUploadedFile->getClientFilename());
    }

    public function testUploadedFilesFallbackToEmptyStreamWhenTemporaryFileIsUnavailable(): void
    {
        $_SERVER = [
            'HTTP_HOST' => 'test',
            'REQUEST_METHOD' => 'GET',
        ];
        $_FILES = [
            'file1' => [
                'name' => 'facepalm.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/non-existent-file',
                'error' => '0',
                'size' => '463',
            ],
        ];

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory
            ->expects($this->once())
            ->method('createStreamFromFile')
            ->with('/non-existent-file')
            ->willThrowException(new RuntimeException('Temporary file is unavailable.'));
        $streamFactory
            ->expects($this->once())
            ->method('createStream')
            ->with('')
            ->willReturn((new StreamFactory())->createStream());

        $requestFactory = new RequestFactory(
            new ServerRequestFactory(),
            new UriFactory(),
            new UploadedFileFactory(),
            $streamFactory,
        );

        $serverRequest = $requestFactory->create();

        $uploadedFile = $serverRequest->getUploadedFiles()['file1'];
        $this->assertSame('facepalm.jpg', $uploadedFile->getClientFilename());
        $this->assertSame('image/jpeg', $uploadedFile->getClientMediaType());
        $this->assertSame(463, $uploadedFile->getSize());
        $this->assertSame(0, $uploadedFile->getError());
        $this->assertSame('', (string) $uploadedFile->getStream());
    }

    public function testHeadersParsing(): void
    {
        $_SERVER = [
            'HTTP_HOST' => 'example.com',
            'CONTENT_TYPE' => 'text/plain',
            'REQUEST_METHOD' => 'GET',
            'REDIRECT_STATUS' => '200',
            'REDIRECT_HTTP_HOST' => 'example.org',
            'REDIRECT_HTTP_CONNECTION' => 'keep-alive',
        ];

        $expected = [
            'Host' => ['example.com'],
            'Content-Type' => ['text/plain'],
            'Connection' => ['keep-alive'],
        ];

        $request = $this->createRequestFactory()->create();

        $this->assertSame($expected, $request->getHeaders());
    }

    public function testHeadersParsingFallsBackWhenGetAllHeadersReturnsFalse(): void
    {
        self::$getAllHeadersResult = false;
        $_SERVER = [
            'HTTP_HOST' => 'example.com',
            'CONTENT_TYPE' => 'text/plain',
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        $this->assertSame(
            [
                'Host' => ['example.com'],
                'Content-Type' => ['text/plain'],
            ],
            $request->getHeaders(),
        );
    }

    public function testHeadersAreTakenFromGetAllHeadersWhenAvailable(): void
    {
        self::$getAllHeadersResult = [
            'X-Test' => 'header-value',
            'X-Another' => 'another-value',
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        $this->assertSame(['header-value'], $request->getHeader('X-Test'));
        $this->assertSame(['another-value'], $request->getHeader('X-Another'));
    }

    public static function ipv6AuthorityDataProvider(): array
    {
        return [
            'withoutPort' => [
                '[::1]',
                'http://[::1]',
            ],
            'withPort' => [
                '[::1]:8080',
                'http://[::1]:8080',
            ],
        ];
    }

    #[DataProvider('ipv6AuthorityDataProvider')]
    public function testIpv6HostIsFormattedAsUriAuthority(string $hostHeader, string $expectedUri): void
    {
        $_SERVER = [
            'HTTP_HOST' => $hostHeader,
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        $this->assertSame($expectedUri, (string) $request->getUri());
    }

    public function testInvalidMethodException(): void
    {
        $_SERVER = [];

        $requestFactory = $this->createRequestFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to determine HTTP request method.');
        $requestFactory->create();
    }

    public static function bodyDataProvider(): array
    {
        return [
            'string' => ['content', 'content'],
            'null' => ['', null],
        ];
    }

    #[DataProvider('bodyDataProvider')]
    public function testBody(string $expected, ?string $body): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET'];

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create($this->createResource($body));

        $this->assertSame($expected, (string) $request->getBody());
    }

    public static function hostParsingDataProvider(): array
    {
        return [
            'host' => [
                [
                    'HTTP_HOST' => 'test',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'hostWithPort' => [
                [
                    'HTTP_HOST' => 'test:88',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => 88,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv4' => [
                [
                    'HTTP_HOST' => '127.0.0.1',
                    'REQUEST_METHOD' => 'GET',
                    'HTTPS' => true,
                ],
                [
                    'method' => 'GET',
                    'host' => '127.0.0.1',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'https',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv4WithPort' => [
                [
                    'HTTP_HOST' => '127.0.0.1:443',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '127.0.0.1',
                    'port' => 443,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv6' => [
                [
                    'HTTP_HOST' => '[::1]',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '[::1]',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv6WithPort' => [
                [
                    'HTTP_HOST' => '[::1]:443',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '[::1]',
                    'port' => 443,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'serverName' => [
                [
                    'SERVER_NAME' => 'test',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'hostAndServerName' => [
                [
                    'SERVER_NAME' => 'override',
                    'HTTP_HOST' => 'test',
                    'REQUEST_METHOD' => 'GET',
                    'SERVER_PORT' => 81,
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => 81,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'none' => [
                [
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'path' => [
                [
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/path/to/folder?param=1',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '/path/to/folder',
                    'query' => '',
                ],
            ],
            'query' => [
                [
                    'REQUEST_METHOD' => 'GET',
                    'QUERY_STRING' => 'path/to/folder?param=1',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => 'path/to/folder?param=1',
                ],
            ],
            'protocol' => [
                [
                    'REQUEST_METHOD' => 'GET',
                    'SERVER_PROTOCOL' => 'HTTP/1.0',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.0',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'post' => [
                [
                    'REQUEST_METHOD' => 'POST',
                ],
                [
                    'method' => 'POST',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'delete' => [
                [
                    'REQUEST_METHOD' => 'DELETE',
                ],
                [
                    'method' => 'DELETE',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'put' => [
                [
                    'REQUEST_METHOD' => 'PUT',
                ],
                [
                    'method' => 'PUT',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'https' => [
                [
                    'REQUEST_METHOD' => 'PUT',
                    'HTTPS' => 'on',
                ],
                [
                    'method' => 'PUT',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'https',
                    'path' => '',
                    'query' => '',
                ],
            ],
        ];
    }

    #[DataProvider('hostParsingDataProvider')]
    public function testHostParsingFromParameters(array $serverParams, array $expectParams): void
    {
        $_SERVER = $serverParams;

        $request = $this->createRequestFactory()->create();

        $this->assertSame($expectParams['host'], $request->getUri()->getHost());
        $this->assertSame($expectParams['port'], $request->getUri()->getPort());
        $this->assertSame($expectParams['method'], $request->getMethod());
        $this->assertSame($expectParams['protocol'], $request->getProtocolVersion());
        $this->assertSame($expectParams['scheme'], $request->getUri()->getScheme());
        $this->assertSame($expectParams['path'], $request->getUri()->getPath());
        $this->assertSame($expectParams['query'], $request->getUri()->getQuery());
    }

    #[DataProvider('hostParsingDataProvider')]
    #[BackupGlobals(true)]
    public function testHostParsingFromGlobals(array $serverParams, array $expectParams): void
    {
        $_SERVER = $serverParams;

        $request = $this->createRequestFactory()->create();

        $this->assertSame($expectParams['host'], $request->getUri()->getHost());
        $this->assertSame($expectParams['port'], $request->getUri()->getPort());
        $this->assertSame($expectParams['method'], $request->getMethod());
        $this->assertSame($expectParams['protocol'], $request->getProtocolVersion());
        $this->assertSame($expectParams['scheme'], $request->getUri()->getScheme());
        $this->assertSame($expectParams['path'], $request->getUri()->getPath());
        $this->assertSame($expectParams['query'], $request->getUri()->getQuery());
    }

    public static function dataPostInParsedBody(): array
    {
        return [
            [
                ['name' => 'test'],
                'application/x-www-form-urlencoded',
            ],
            [
                ['name' => 'test'],
                'multipart/form-data',
            ],
        ];
    }

    #[DataProvider('dataPostInParsedBody')]
    public function testPostInParsedBody(array $post, string $contentType): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => $contentType,
        ];
        $_POST = $post;

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create();

        $this->assertSame($post, $request->getParsedBody());
    }

    public function testPostBodyIsNotParsedForPrefixedFormUrlEncodedContentType(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'xapplication/x-www-form-urlencoded',
        ];
        $_POST = ['name' => 'test'];

        $request = $this->createRequestFactory()->create();

        $this->assertNull($request->getParsedBody());
    }

    public function testPostBodyIsNotParsedForPrefixedMultipartContentType(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'xmultipart/form-data',
        ];
        $_POST = ['name' => 'test'];

        $request = $this->createRequestFactory()->create();

        $this->assertNull($request->getParsedBody());
    }

    public function testNotParseUJsonWithoutBody(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
        ];

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create(false);

        $this->assertNull($request->getParsedBody());
    }

    public function testNotParseUnknownType(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/unknown',
        ];

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create($this->createResource('hello'));

        $this->assertNull($request->getParsedBody());
    }

    public function testMalformedHostWithLeadingNewlineIsNotParsedAsHostPortPair(): void
    {
        $_SERVER = [
            'HTTP_HOST' => "\nexample.com:8080",
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        $this->assertSame("\nexample.com:8080", $request->getUri()->getHost());
        $this->assertNull($request->getUri()->getPort());
    }

    private function createRequestFactory(): RequestFactory
    {
        return new RequestFactory(
            new ServerRequestFactory(),
            new UriFactory(),
            new UploadedFileFactory(),
            new StreamFactory(),
        );
    }

    /**
     * @return false|resource
     */
    private function createResource(?string $value)
    {
        return $value === null
            ? false
            : fopen('data://text/plain,' . $value, 'rb');
    }
}
