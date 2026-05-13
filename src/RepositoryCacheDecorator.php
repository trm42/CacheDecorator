<?php

namespace Trm42\CacheDecorator;

/**
 * Repository-flavored Cache Decorator.
 *
 * Same behavior as CacheDecorator, but reads config from the
 * 'repository_cache.*' namespace instead of 'cache_decorator.*'. Subclasses
 * either pass an instance to the constructor or override decoratedClass().
 *
 * Example:
 *
 *     class CachedUserRepository extends RepositoryCacheDecorator {
 *         protected $prefix_key = 'users';
 *         protected $excludes = ['allWithoutCache'];
 *
 *         protected function decoratedClass(): ?string
 *         {
 *             return UserRepository::class;
 *         }
 *     }
 *
 * @template TInner of object
 *
 * @extends CacheDecorator<TInner>
 */
abstract class RepositoryCacheDecorator extends CacheDecorator
{
    protected string $config_key = 'repository_cache';
}
