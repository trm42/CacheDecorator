<?php

namespace Trm42\CacheDecorator\Tests\Stubs;

/**
 * A simple collaborator that gets injected into StubServiceWithDependency to
 * prove constructor dependencies are auto-wired by the container.
 */
class StubCollaborator
{
    public function greeting(): string
    {
        return 'hello from collaborator';
    }
}
