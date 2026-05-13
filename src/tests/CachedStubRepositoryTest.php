<?php

namespace Trm42\CacheDecorator\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Trm42\CacheDecorator\ServiceProvider;
use Trm42\CacheDecorator\Tests\Stubs\CachedStubRepository;
use Trm42\CacheDecorator\Tests\Stubs\StubRepository;

/**
 * Ensures the CachedStubRepository works as intended.
 *
 * @todo Add somekind of support for the Laravel event listener to watch what the Cache does
 * @todo Invent a way to test tag clearing
 */
class CachedStubRepositoryTest extends TestCase
{
    protected $repository;

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('repository_cache.enabled', true);
        $app['config']->set('repository_cache.ttl', 300);
        $app['config']->set('repository_cache.use_tags', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->repository = new CachedStubRepository(new StubRepository);
        $this->repository->setEnabled(true);
        $this->repository->setTtl(300);
    }

    #[Test]
    public function test_all()
    {
        $res = $this->repository->all();

        $exp = [1, 2, 3, 4, 5];

        $this->assertEquals($exp, $res);
    }

    #[Test]
    public function test_all_with_insert_and_getting_old_results_because_of_caching()
    {
        // Refresh the cache
        $this->repository->all();

        // Mutate underlying repo but do not clear cache
        $this->repository->insert();

        // Should still get the cached (old) results
        $res = $this->repository->all();

        $exp = [1, 2, 3, 4, 5];

        $this->assertEquals($exp, $res);
    }

    #[Test]
    public function test_all_with_insert_and_getting_new_results()
    {
        // Refresh the cache
        $this->repository->all();

        // Mutate underlying repo
        $this->repository->insert();

        // Flush the cache so the next call hits the repository again
        Cache::flush();

        $res = $this->repository->all();

        $exp = [1, 2, 3, 4, 5, 6];

        $this->assertEquals($exp, $res);
    }

    #[Test]
    public function test_find()
    {
        $res = $this->repository->find(3);

        $this->assertEquals(4, $res);
    }

    #[Test]
    public function test_delete()
    {
        $this->repository->delete(2);

        $exp = [
            0 => 1,
            1 => 2,
            3 => 4,
            4 => 5,
        ];

        $res = $this->repository->all();

        $this->assertEquals($exp, $res);
    }

    #[Test]
    public function test_insert()
    {
        $this->repository->insert();

        $exp = [1, 2, 3, 4, 5, 6];

        $res = $this->repository->all();

        $this->assertEquals($exp, $res);
    }

    #[Test]
    public function test_missing_function()
    {
        $this->expectException(\BadMethodCallException::class);

        $this->repository->foobar();
    }

    #[Test]
    public function test_exclude_method()
    {
        $res1 = $this->repository->allWithoutCache();

        $exp1 = [1, 2, 3, 4, 5];

        $this->assertEquals($exp1, $res1);

        $this->repository->insert();
        $this->repository->insert();
        $this->repository->insert();
        $this->repository->insert();

        $res2 = $this->repository->allWithoutCache();

        $exp2 = [1, 2, 3, 4, 5, 6, 7, 8, 9];

        $this->assertEquals($exp2, $res2);
    }

    #[Test]
    public function test_array_as_parameter()
    {
        $res = $this->repository->findMany([1, 4]);

        $this->assertEquals([2, 5], $res);
    }

    #[Test]
    public function test_multi_dimensional_arrays_as_parameter()
    {
        $res = $this->repository->findManyWithout(['with' => [1, 4], 'without' => [0, 1]]);

        $this->assertEquals([5], $res);
    }

    #[Test]
    public function test_set_enabled_false_bypasses_cache()
    {
        $this->repository->setEnabled(false);

        $first = $this->repository->all();
        $this->assertEquals([1, 2, 3, 4, 5], $first);

        // Mutate the underlying repo; with caching disabled the next call
        // should reflect the mutation (i.e. the decorated object was hit again).
        $this->repository->insert();

        $second = $this->repository->all();
        $this->assertEquals([1, 2, 3, 4, 5, 6], $second);
    }

    #[Test]
    public function test_set_ttl_to_null_skips_cache()
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();

        $this->repository->setTtl(null);

        $this->repository->find(3);
    }
}
