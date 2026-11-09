<?php

declare(strict_types=1);

namespace Slim\Tests\System;

use ArrayIterator;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Slim\CallableResolver;
use Slim\Factory\AppFactory;
use Slim\Logger;
use Slim\Tests\TestCase;
use Stringable;
use Symfony\Polyfill\Php84\Php84;

use function array_any;
use function array_all;
use function array_find;
use function bcdivmod;
use function fpow;
use function file_exists;
use function file_get_contents;
use function ini_get;
use function ini_set;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ClusterPackageExecutionTest extends TestCase
{
    public function testPsrHttpStackThroughAppHandle(): void
    {
        $app = AppFactory::create();

        $app->get(
            '/cluster-probe',
            function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
                $response->getBody()->write('cluster-http-stack');
                return $response;
            }
        );

        $probe = new class () implements MiddlewareInterface {
            public bool $processed = false;

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $this->processed = true;
                return $handler->handle($request);
            }
        };

        $app->add($probe);

        $response = $app->handle(
            $this->getServerRequestFactory()->createServerRequest('GET', 'http://example.test/cluster-probe')
        );

        $this->assertTrue($probe->processed);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cluster-http-stack', (string) $response->getBody());
    }

    public function testPsrContainerThroughCallableResolver(): void
    {
        $service = new class () {
            public function __invoke(): string
            {
                return 'container-service';
            }
        };

        $container = new class ($service) implements ContainerInterface {
            public function __construct(private object $service)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'service.id') {
                    return $this->service;
                }
                throw new class () extends RuntimeException implements NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === 'service.id';
            }
        };

        $resolver = new CallableResolver($container);
        $callable = $resolver->resolve('service.id');

        $this->assertSame('container-service', $callable());
    }

    public function testPsrLogThroughSlimLogger(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'slim-logger-');
        $this->assertNotFalse($logFile);

        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);
        try {
            $logger = new Logger();
            $logger->error('cluster-log-error');
            $logger->info(new class () implements Stringable {
                public function __toString(): string
                {
                    return 'cluster-log-info';
                }
            });
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $contents = file_get_contents($logFile);
        unlink($logFile);

        $this->assertIsString($contents);
        $this->assertStringContainsString('cluster-log-error', $contents);
        $this->assertStringContainsString('cluster-log-info', $contents);
    }

    public function testPsrSimpleCacheContract(): void
    {
        $cache = new class () implements CacheInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->data[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->data = [];
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $out = [];
                foreach ($keys as $key) {
                    $out[$key] = $this->get($key, $default);
                }
                return new ArrayIterator($out);
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string) $key, $value, $ttl);
                }
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete((string) $key);
                }
                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        $this->assertTrue($cache->set('k', 'v'));
        $this->assertSame('v', $cache->get('k'));
        $this->assertTrue($cache->has('k'));
        $this->assertSame(['a' => 1, 'b' => 1], iterator_to_array($cache->getMultiple(['a', 'b'], 1)));
        $this->assertTrue($cache->delete('k'));
        $this->assertTrue($cache->clear());

        $ifacePath = (new \ReflectionClass(CacheInterface::class))->getFileName();
        $this->assertIsString($ifacePath);
        $this->assertStringContainsString('/vendor/psr/simple-cache/', $ifacePath);

        $exception = new class () extends \Exception implements InvalidArgumentException {
        };
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
    }

    public function testSymfonyPolyfillPhp84OnPhp81(): void
    {
        $this->assertSame(3, array_find([1, 2, 3], static fn (int $v): bool => $v > 2));
        $this->assertNull(array_find([1, 2, 3], static fn (int $v): bool => $v > 9));
        $this->assertTrue(array_any([1, 2, 3], static fn (int $v): bool => $v === 2));
        $this->assertFalse(array_all([1, 2, 3], static fn (int $v): bool => $v > 2));
        $this->assertSame(8.0, fpow(2.0, 3.0));
        $this->assertSame('foo', Php84::mb_trim(" \tfoo\n "));
        $this->assertSame('abc', Php84::mb_trim('abc', ''));
        $this->assertSame(['3', '1'], bcdivmod('10', '3'));

        try {
            Php84::grapheme_str_split('ab', 0);
            $this->fail('Expected ValueError for grapheme_str_split length 0.');
        } catch (\ValueError) {
        }

        $polyfillLoaded = file_exists(
            __DIR__ . '/../../vendor/symfony/polyfill-php84/bootstrap.php'
        );
        $this->assertTrue($polyfillLoaded);
    }
}
