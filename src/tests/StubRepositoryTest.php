<?php

namespace Trm42\CacheDecorator\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Trm42\CacheDecorator\Tests\Stubs\StubRepository;

/**
 *  Ensures the StubRepository works as intended
 */
class StubRepositoryTest extends TestCase
{
    protected $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new StubRepository;
    }

    #[Test]
    public function test_all()
    {
        $res = $this->repository->all();

        $this->assertEquals([1, 2, 3, 4, 5], $res);
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
}
