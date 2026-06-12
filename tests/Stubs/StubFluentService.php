<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

/**
 * Service with a fluent, self-returning method to exercise the
 * forwardDecoratedCallTo() rewrite (inner `return $this;` becomes the
 * decorator).
 */
class StubFluentService implements FluentServiceContract
{
    public int $callCount = 0;

    protected bool $flag = false;

    public function withFlag(bool $flag): static
    {
        $this->callCount++;
        $this->flag = $flag;

        return $this;
    }

    public function result(): string
    {
        return $this->flag ? 'on' : 'off';
    }
}
