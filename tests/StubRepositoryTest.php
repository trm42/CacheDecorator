<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

use PHPUnit_Framework_TestCase as TestCase;

use Trm42\CacheDecorator\Tests\Stubs\StubRepository;

/**
 *  Ensures the StubRepository works as intended
 */
class StubRepositoryTest extends TestCase
{

    protected $repository;

    public function setUp()
    {
        parent::setUp();
        $this->repository = new StubRepository;
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



}
