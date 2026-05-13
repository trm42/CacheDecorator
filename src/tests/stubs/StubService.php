<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

/**
 * Plain non-repository service to exercise the generic CacheDecorator path.
 */
class StubService
{
    public int $callCount = 0;

    public function compute(int $x): int
    {
        $this->callCount++;

        return $x * 2;
    }

    public function findThing(int $id): array
    {
        $this->callCount++;

        return ['id' => $id, 'name' => "thing-{$id}"];
    }

    public function mutate(): bool
    {
        $this->callCount++;

        return true;
    }

    public function returnZero(): int
    {
        $this->callCount++;

        return 0;
    }

    public function returnEmptyString(): string
    {
        $this->callCount++;

        return '';
    }

    public function returnEmptyArray(): array
    {
        $this->callCount++;

        return [];
    }

    public function returnFalse(): bool
    {
        $this->callCount++;

        return false;
    }
}
