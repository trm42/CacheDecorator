<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

/**
 * Shared contract implemented by both the inner service and its decorator.
 *
 * Fluent methods are typed `: static` so a caller holding either the inner
 * instance or the decorator sees the same type — the decorator substituting
 * itself for the inner object on self-returning calls stays type-safe.
 */
interface FluentServiceContract
{
    public function withFlag(bool $flag): static;

    public function result(): string;
}
