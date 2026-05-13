<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Trm42\CacheDecorator\CacheDecorator;

/**
 * Generic CacheDecorator subclass that uses decoratedClass() to default
 * instantiate StubService, allowing no-arg construction.
 */
class CachedAutoStubService extends CacheDecorator
{
    protected ?string $prefix_key = 'auto-svc';

    #[\Override]
    protected function decoratedClass(): ?string
    {
        return StubService::class;
    }
}
