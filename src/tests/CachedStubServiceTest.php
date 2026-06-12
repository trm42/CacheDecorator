<?php

namespace Trm42\CacheDecorator\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Trm42\CacheDecorator\ServiceProvider;
use Trm42\CacheDecorator\Tests\Stubs\CachedAutoStubService;
use Trm42\CacheDecorator\Tests\Stubs\CachedFluentService;
use Trm42\CacheDecorator\Tests\Stubs\CachedMagicService;
use Trm42\CacheDecorator\Tests\Stubs\CachedStubService;
use Trm42\CacheDecorator\Tests\Stubs\CachedStubServiceWithDependency;
use Trm42\CacheDecorator\Tests\Stubs\StubFluentService;
use Trm42\CacheDecorator\Tests\Stubs\StubMagicService;
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
    public function test_cache_hit_on_second_call()
    {
        $first = $this->service->compute(21);
        $second = $this->service->compute(21);

        $this->assertEquals(42, $first);
        $this->assertEquals(42, $second);
        $this->assertEquals(1, $this->inner->callCount, 'Second call should hit cache, not underlying service');
    }

    #[Test]
    public function test_cache_keys_vary_by_argument()
    {
        $this->service->compute(5);
        $this->service->compute(7);

        $this->assertEquals(2, $this->inner->callCount);
    }

    #[Test]
    public function test_excluded_method_bypasses_cache()
    {
        $this->service->mutate();
        $this->service->mutate();
        $this->service->mutate();

        $this->assertEquals(3, $this->inner->callCount);
    }

    #[Test]
    public function test_missing_method_throws()
    {
        $this->expectException(\BadMethodCallException::class);

        $this->service->doesNotExist();
    }

    #[Test]
    public function test_set_ttl_to_null_bypasses_cache()
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();

        $this->service->setTtl(null);

        $this->service->compute(3);
    }

    #[Test]
    public function test_no_arg_construction_via_decorated_class()
    {
        $service = new CachedAutoStubService;
        $service->setTtl(300);

        $result = $service->findThing(7);

        $this->assertEquals(['id' => 7, 'name' => 'thing-7'], $result);
    }

    #[Test]
    public function test_decorated_class_is_resolved_through_container_with_dependencies()
    {
        // StubServiceWithDependency requires a StubCollaborator constructor
        // argument, so `new $class` would fail. Resolving through the container
        // auto-wires the dependency.
        $service = new CachedStubServiceWithDependency;
        $service->setTtl(300);

        $this->assertEquals('hello from collaborator', $service->delegatedGreeting());
    }

    #[Test]
    public function test_constructor_without_instance_or_decorated_class_throws()
    {
        $this->expectException(\LogicException::class);

        new CachedStubService;
    }

    #[Test]
    public function test_array_argument_caches()
    {
        $this->service->findThing(1);
        $this->service->findThing(1);

        $this->assertEquals(1, $this->inner->callCount);
    }

    public static function falsyMethodProvider(): array
    {
        return [
            'zero int' => ['returnZero', 0],
            'empty string' => ['returnEmptyString', ''],
            'empty array' => ['returnEmptyArray', []],
            'false bool' => ['returnFalse', false],
        ];
    }

    #[Test]
    #[DataProvider('falsyMethodProvider')]
    public function test_falsy_return_values_are_cached_not_refetched(string $method, $expected)
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

    #[Test]
    public function test_magic_method_is_forwarded_and_cached()
    {
        $magicInner = new StubMagicService;
        $service = new CachedMagicService($magicInner);
        $service->setEnabled(true);
        $service->setTtl(300);

        // magicCompute() only exists via StubMagicService::__call(), so the old
        // method_exists() gate would have thrown BadMethodCallException.
        $first = $service->magicCompute(4);
        $second = $service->magicCompute(4);

        $this->assertEquals(12, $first);
        $this->assertEquals(12, $second);
        $this->assertEquals(1, $magicInner->callCount, 'Second call should hit cache, not the inner __call()');
    }

    #[Test]
    public function test_fluent_method_returns_decorator_not_inner()
    {
        $fluentInner = new StubFluentService;
        $service = new CachedFluentService($fluentInner);
        $service->setEnabled(true);
        $service->setTtl(300);

        // withFlag() returns `$this` (the inner); forwardDecoratedCallTo()
        // rewrites that to the decorator so chaining stays on the cached surface.
        $returned = $service->withFlag(true);

        $this->assertSame($service, $returned, 'Fluent call should return the decorator, not the inner object');
        $this->assertEquals('on', $returned->result());
    }
}
