<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

/**
 * Service that exposes methods through its own __call() magic instead of
 * declaring them. Used to prove the decorator forwards (and caches) calls to
 * magic methods that method_exists() would not see.
 */
class StubMagicService
{
    public int $callCount = 0;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if ($method === 'magicCompute') {
            $this->callCount++;

            return $arguments[0] * 3;
        }

        throw new \BadMethodCallException("Method '{$method}' does not exist");
    }
}
