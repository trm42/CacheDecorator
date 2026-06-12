<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Trm42\CacheDecorator\CacheDecorator;

/**
 * Decorator over StubMagicService, whose methods only exist via its own
 * __call() magic. Proves the decorator forwards (and caches) calls that
 * method_exists() would never see.
 *
 * @extends CacheDecorator<StubMagicService>
 */
class CachedMagicService extends CacheDecorator
{
    protected ?string $prefix_key = 'magic';
}
