<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Trm42\CacheDecorator\CacheDecorator;

/**
 * Generic CacheDecorator subclass that wraps a constructor-injected StubService
 * instance. Exercises the no-decoratedClass() path.
 */
class CachedStubService extends CacheDecorator {

    protected ?string $prefix_key = 'svc';

    protected array $excludes = ['mutate'];

}
