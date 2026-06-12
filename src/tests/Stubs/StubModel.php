<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

use Illuminate\Contracts\Routing\UrlRoutable;

/**
 * Minimal UrlRoutable stub standing in for an Eloquent model, used to exercise
 * object arguments in cache keys via getRouteKey().
 */
class StubModel implements UrlRoutable
{
    public function __construct(public int $id) {}

    public function getRouteKey()
    {
        return $this->id;
    }

    public function getRouteKeyName()
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field = null)
    {
        return null;
    }
}
