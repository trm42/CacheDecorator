<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Trm42\CacheDecorator\RepositoryCacheDecorator;

/**
 * Nothing fancy, just really simple array class to mock repository
 *
 *
 */
class CachedStubRepository extends RepositoryCacheDecorator {

	protected array $excludes = ['allWithoutCache', 'insert'];

    #[\Override]
    protected function decoratedClass(): ?string
    {
        return StubRepository::class;
    }

}
