<?php

namespace Trm42\CacheDecorator;

/**
 * Repository-flavored Cache Decorator.
 *
 * Specializes CacheDecorator for the repository use case: subclasses implement
 * repository() returning the wrapped repository class name, the decorated
 * instance is also exposed as $this->repository for use in custom method
 * overrides, and config is read from the 'repository_cache.*' namespace.
 *
 * Example:
 *
 *     class CachedUserRepository extends RepositoryCacheDecorator {
 *         protected $prefix_key = 'users';
 *         protected $excludes = ['allWithoutCache'];
 *
 *         public function repository()
 *         {
 *             return UserRepository::class;
 *         }
 *     }
 */
abstract class RepositoryCacheDecorator extends CacheDecorator {

    protected string $config_key = 'repository_cache';

    protected $repository;

    /**
     * Implement this per sub-class.
     *
     * @return  string  Name of the repository class. Used for instantiating the repository
     */
    abstract public function repository();

    /**
     * Bridges the repository() abstract method into the generic
     * decoratedClass() hook used by the base constructor.
     */
    protected function decoratedClass(): ?string
    {
        return $this->repository();
    }

    /**
     * Exclude repository() (subclass-defined public method) from __call interception.
     */
    protected function initExcludes(): void
    {
        parent::initExcludes();

        $this->excludes = array_merge($this->excludes, ['repository', 'initRepository']);
    }

    /**
     * Initialize the wrapped repository, mirroring the instance into
     * $this->repository so custom method overrides can keep using the
     * familiar $this->repository->someMethod() idiom.
     *
     * @param   object|false    $repository
     */
    public function initDecorated($repository): void
    {
        parent::initDecorated($repository);

        $this->repository = $this->decorated;
    }

    /**
     * Backwards-compatible alias for initDecorated().
     *
     * @param   object|false    $repository
     */
    public function initRepository($repository): void
    {
        $this->initDecorated($repository);
    }

}
