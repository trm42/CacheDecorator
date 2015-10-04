<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

use PHPUnit_Framework_TestCase as TestCase;

use Trm42\CacheDecorator\Tests\Stubs\StubRepository;
use Trm42\CacheDecorator\Tests\Stubs\CachedStubRepository;

/**
 * Ensures the StubRepository works as intended
 * 
 * @todo Add somekind of support for the Laravel event listener to watch what the Cache does
 * 
 * @todo Invent a way to test tag clearing
 *
 *
 */
class CachedStubRepositoryTest extends TestCase
{

    protected $repository;

    public function setUp()
    {
        parent::setUp();

        Cache::flush();

        $this->repository = new CachedStubRepository(new StubRepository);
        $this->repository->setEnabled(true);
        $this->repository->setTtl(5);
    }

    /**
     * A basic functional test example.
     *
     * @return void
     */
    public function testAll()
    {
        $res = $this->repository->all();

        $exp = [
            1,
            2,
            3,
            4,
            5,
        ];

        $this->assertEquals($exp, $res);
    }

    public function testAllWithInsertAndGettingOldResultsBecauseOfCaching()
    {
        // Refreshed the cache
        $res = $this->repository->all();

        // Add to Cache but don't clear cache
        $this->repository->insert();

        // Get the old results
        $res = $this->repository->all();

        $exp = [
            1,
            2,
            3,
            4,
            5,
        ];

        $this->assertEquals($exp, $res);

    }

    public function testAllWithInsertAndGettingNewResults()
    {
        // Refreshed the cache
        $res = $this->repository->all();

        // Add to Cache
        $this->repository->insert();

        // Crude way to flush the cache for the first test
        Cache::flush();

        // Get the new results
        $res = $this->repository->all();

        $exp = [
            1,
            2,
            3,
            4,
            5,
            6,
        ];

        $this->assertEquals($exp, $res);

    }

    public function testFind()
    {
        $res = $this->repository->find(3);

        $exp = 4;

        $this->assertEquals($exp, $res);
    }

    public function testDelete()
    {
        $this->repository->delete(2);

        $exp = [
            0 => 1,
            1 => 2,
            3 => 4,
            4 => 5
            ];

        $res = $this->repository->all();

        $this->assertEquals($exp, $res);
    }

    public function testInsert()
    {
        $this->repository->insert();

        $exp = [
            1,
            2,
            3,
            4,
            5,
            6,
        ];

        $res = $this->repository->all();

        $this->assertEquals($exp, $res);
    }

    /**
     *  @expectedException \BadMethodCallException
     *
     */
    public function testMissingFunction()
    {
        $this->repository->foobar();
    }

    public function testExcludeMethod()
    {
        $res1 = $this->repository->allWithoutCache();

        $exp1 = [
            1,
            2,
            3,
            4,
            5,
        ];

        $this->assertEquals($exp1, $res1);

        $this->repository->insert();
        $this->repository->insert();
        $this->repository->insert();
        $this->repository->insert();

        $res2 = $this->repository->allWithoutCache();

        $exp2 = [
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
            9,

        ];

        $this->assertEquals($exp2, $res2);
    }

    public function testArrayAsParameter()
    {
        $res = $this->repository->findMany([1,4]);

        $exp = [2, 5];

        $this->assertEquals($exp, $res);
    }

    public function testMultiDimensionalArraysAsParameter()
    {
        $res = $this->repository->findManyWithout(['with' => [1,4], 'without' => [0, 1]]);

        $exp = [5];

        $this->assertEquals($exp, $res);
    }

}
