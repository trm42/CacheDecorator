<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Trm42\CacheDecorator\RepositoryCacheDecorator;

/**
 * Nothing fancy, just really simple array class to mock repository
 *
 *
 */
class CachedStubRepository extends RepositoryCacheDecorator {

	protected $excludes = ['allWithoutCache', 'insert'];

    public function repository()
    {
        return StubRepository::class;
    }

}
