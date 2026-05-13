<?php

namespace Trm42\CacheDecorator\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Trm42\CacheDecorator\ServiceProvider;
use Trm42\CacheDecorator\Tests\Stubs\CachedAutoStubService;
use Trm42\CacheDecorator\Tests\Stubs\CachedStubService;
use Trm42\CacheDecorator\Tests\Stubs\StubService;

/**
 * Exercises the generic CacheDecorator path with a non-repository service stub.
 */
class CachedStubServiceTest extends TestCase
{

    protected $service;

    protected $inner;

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache_decorator.enabled', true);
        $app['config']->set('cache_decorator.ttl', 300);
        $app['config']->set('cache_decorator.use_tags', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->inner = new StubService;
        $this->service = new CachedStubService($this->inner);
        $this->service->setEnabled(true);
        $this->service->setTtl(300);
    }

    #[Test]
    public function testCacheHitOnSecondCall()
    {
        $first = $this->service->compute(21);
        $second = $this->service->compute(21);

        $this->assertEquals(42, $first);
        $this->assertEquals(42, $second);
        $this->assertEquals(1, $this->inner->callCount, 'Second call should hit cache, not underlying service');
    }

    #[Test]
    public function testCacheKeysVaryByArgument()
    {
        $this->service->compute(5);
        $this->service->compute(7);

        $this->assertEquals(2, $this->inner->callCount);
    }

    #[Test]
    public function testExcludedMethodBypassesCache()
    {
        $this->service->mutate();
        $this->service->mutate();
        $this->service->mutate();

        $this->assertEquals(3, $this->inner->callCount);
    }

    #[Test]
    public function testMissingMethodThrows()
    {
        $this->expectException(\BadMethodCallException::class);

        $this->service->doesNotExist();
    }

    #[Test]
    public function testSetTtlToNullBypassesCache()
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();

        $this->service->setTtl(null);

        $this->service->compute(3);
    }

    #[Test]
    public function testNoArgConstructionViaDecoratedClass()
    {
        $service = new CachedAutoStubService;
        $service->setTtl(300);

        $result = $service->findThing(7);

        $this->assertEquals(['id' => 7, 'name' => 'thing-7'], $result);
    }

    #[Test]
    public function testConstructorWithoutInstanceOrDecoratedClassThrows()
    {
        $this->expectException(\LogicException::class);

        new CachedStubService;
    }

    #[Test]
    public function testArrayArgumentCaches()
    {
        $this->service->findThing(1);
        $this->service->findThing(1);

        $this->assertEquals(1, $this->inner->callCount);
    }

    public static function falsyMethodProvider(): array
    {
        return [
            'zero int'     => ['returnZero', 0],
            'empty string' => ['returnEmptyString', ''],
            'empty array'  => ['returnEmptyArray', []],
            'false bool'   => ['returnFalse', false],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('falsyMethodProvider')]
    public function testFalsyReturnValuesAreCachedNotRefetched(string $method, $expected)
    {
        $first = $this->service->{$method}();
        $second = $this->service->{$method}();

        $this->assertSame($expected, $first);
        $this->assertSame($expected, $second);
        $this->assertEquals(
            1,
            $this->inner->callCount,
            "Falsy return from {$method}() should round-trip via cache instead of re-invoking the inner service"
        );
    }

}
