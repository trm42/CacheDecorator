<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Trm42\CacheDecorator\CacheDecorator;

/**
 * Decorator over StubFluentService. The fluent `withFlag` is excluded so it
 * stays on the always-forward path: forwardDecoratedCallTo() rewrites the
 * inner `return $this;` to this decorator instead of caching a decorator
 * instance.
 *
 * @extends CacheDecorator<StubFluentService>
 */
class CachedFluentService extends CacheDecorator
{
    protected ?string $prefix_key = 'fluent';

    protected array $excludes = ['withFlag'];
}
