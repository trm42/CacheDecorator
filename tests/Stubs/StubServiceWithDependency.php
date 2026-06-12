<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

/**
 * Service with a constructor dependency, used to prove that decoratedClass()
 * resolution goes through Laravel's container and auto-wires the dependency.
 */
class StubServiceWithDependency
{
    public function __construct(protected StubCollaborator $collaborator) {}

    public function delegatedGreeting(): string
    {
        return $this->collaborator->greeting();
    }
}
