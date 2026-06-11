<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Trm42\CacheDecorator\CacheDecorator;

/**
 * CacheDecorator subclass whose decoratedClass() returns a service with a
 * constructor dependency, exercising container-based resolution in
 * initDecorated().
 *
 * @extends CacheDecorator<StubServiceWithDependency>
 */
class CachedStubServiceWithDependency extends CacheDecorator
{
    protected ?string $prefix_key = 'dep-svc';

    #[\Override]
    protected function decoratedClass(): ?string
    {
        return StubServiceWithDependency::class;
    }
}
