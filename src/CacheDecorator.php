<?php

namespace Trm42\CacheDecorator;

// At least for now there's a Laravel dependency, if there's need, this can be
// converted to something more generic
use BackedEnum;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\ForwardsCalls;
use Stringable;
use Trm42\CacheDecorator\Exceptions\MissingDecoratedObjectException;
use Trm42\CacheDecorator\Exceptions\UndefinedMethodException;

/**
 * Magical Cache Decorator class. Meant to be sub classed.
 *
 * Base Cache Decorator class for transparently caching method calls on any
 * decorated object (services, repositories, API clients, query objects, etc.).
 * Sub class this (e.g. class CachedReportingService extends CacheDecorator) and
 * either pass an instance to the constructor or override decoratedClass() to
 * return the FQCN to default-instantiate.
 *
 * If you need to create method specific caching logic, you can create a new
 * method on the sub-classed decorator class. This class automatically forwards
 * uncached method calls to the decorated object (@see __call()) and caches the
 * result, removing the need to write boilerplate caching code for every method.
 *
 * For caching relationships in ORM scenarios, please make sure you're using
 * eager loading (e.g. Eloquent's with() or load() -methods).
 *
 * @author  Matias Mäki <matias.maki@gmail.com>
 *
 * @template TInner of object
 *
 * @todo    change method check case insensitive if it's possible everywhere
 * @todo    Add some kind of timer functionality to monitor result and cache speed
 * @todo    How to handle empty returns (maybe config whether to cache empty or not and the placeholder)
 * @todo    How to live without Laravel dependencies?
 */
abstract class CacheDecorator
{
    use ForwardsCalls;

    /** @var TInner */
    protected object $decorated;

    /** TTL in seconds (or DateInterval / DateTimeInterface). null bypasses both reads and writes. */
    protected int|DateInterval|DateTimeInterface|null $ttl = null;

    /** Beginning of the cache key (e.g. 'users' for a user-related decorator). */
    protected ?string $prefix_key = null;

    protected bool $enabled = true;

    /** @var list<string> */
    protected array $excludes = [];

    /**
     * Methods that flush the cache tags after running. Requires a tag-capable cache store.
     *
     * @var list<string>
     */
    protected array $tag_cleaners = [];

    /**
     * Cache tags applied to this decorator's entries. Requires a tag-capable cache store.
     *
     * @var list<string>
     */
    protected array $tags = [];

    protected bool $debug = false;

    /** Config namespace this decorator reads ttl / enabled / use_tags from. */
    protected string $config_key = 'cache_decorator';

    /** Sentinel used to distinguish true cache misses from stored falsy values. */
    private static ?object $missMarker = null;

    /**
     * Returns the shared sentinel object used to represent a cache miss. Using
     * an object lets callers tell a true miss apart from a legitimately cached
     * falsy value (`0`, `''`, `[]`, `false`, `null`).
     */
    protected function cacheMiss(): object
    {
        if (self::$missMarker === null) {
            self::$missMarker = new \stdClass;
        }

        return self::$missMarker;
    }

    /**
     * Override to return the FQCN of the class to resolve when no instance is
     * passed to the constructor. The FQCN is resolved through Laravel's service
     * container via resolve(), so the decorated class may declare auto-wired
     * constructor dependencies, and this may return an interface bound in the
     * container. Return null (the default) to require an instance via the
     * constructor.
     *
     * @return class-string<TInner>|null FQCN of the decorated class, or null
     */
    protected function decoratedClass(): ?string
    {
        return null;
    }

    /**
     * Constructor, accepts the decorated object as parameter
     *
     * @param  TInner|null  $decorated  Decorated object if you need to define it
     */
    public function __construct(?object $decorated = null)
    {
        $this->initExcludes();
        $this->initDecorated($decorated);
        $this->getConfig();
    }

    /**
     * Basically adds local methods to the excludes[] list
     */
    protected function initExcludes(): void
    {
        $defaults = ['decoratedClass', 'getConfig', 'initDecorated',
            'doesMethodClearTag', 'clearCacheTag', 'getCache', 'putCache',
            'isMethodCacheable', 'generateCacheKey', 'normalizeArgument', 'log', 'cacheMiss',
            'forwardCallTo', 'forwardDecoratedCallTo', 'throwBadMethodCallException',
            'ttl', 'enable', 'disable', 'prefix', 'withTags', 'tagCleaners', 'exclude', ];

        $this->excludes = array_merge($defaults, $this->excludes);
    }

    /**
     * Set the cache TTL for this instance.
     *
     * @param  int|DateInterval|DateTimeInterface|null  $ttl  Cache time-to-live in seconds, or null to skip cache.
     */
    public function ttl(int|DateInterval|DateTimeInterface|null $ttl): static
    {
        $this->ttl = $ttl;

        return $this;
    }

    /**
     * Turn caching on for this instance.
     */
    public function enable(): static
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * Turn caching off for this instance (forwards straight to the decorated object).
     */
    public function disable(): static
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * Set the cache key prefix at runtime.
     */
    public function prefix(?string $prefix): static
    {
        $this->prefix_key = $prefix;

        return $this;
    }

    /**
     * Set the cache tags applied to this decorator's entries. Requires a
     * tag-capable cache store.
     *
     * @param  list<string>  $tags
     */
    public function withTags(array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }

    /**
     * Set the methods that flush the cache tags after running. Requires a
     * tag-capable cache store.
     *
     * @param  list<string>  $methods
     */
    public function tagCleaners(array $methods): static
    {
        $this->tag_cleaners = $methods;

        return $this;
    }

    /**
     * Append one or more method names to the excludes list so they are never
     * cached (forwarded straight to the decorated object).
     */
    public function exclude(string ...$methods): static
    {
        $this->excludes = array_values([...$this->excludes, ...$methods]);

        return $this;
    }

    /**
     * Reads the config values from {config_key}.* values. Note debug listens app.debug.
     */
    protected function getConfig(): void
    {
        $this->ttl = Config::get("{$this->config_key}.ttl");
        $this->enabled = Config::get("{$this->config_key}.enabled");

        if (! Config::get("{$this->config_key}.use_tags")) {
            $this->tags = [];
            $this->tag_cleaners = [];
        }

        // How do you feel about this?
        $this->debug = (bool) Config::get('app.debug');
    }

    /**
     * Handles the initiating or setting of the decorated object
     *
     * @param  TInner|null  $decorated
     */
    public function initDecorated(?object $decorated): void
    {
        if ($decorated === null) {
            $class = $this->decoratedClass();

            if (! $class) {
                throw new MissingDecoratedObjectException(
                    'No decorated object provided and decoratedClass() returned null. '
                    .'Either pass an instance to the constructor or override decoratedClass().'
                );
            }

            $decorated = resolve($class);
        }

        $this->decorated = $decorated;
    }

    /**
     * This is where the magic happens :)
     *
     * If the method is not declared in the cache decorator class (e.g.
     * CachedReportingService), then this checks if it's declared in the
     * decorated object AND checks if the method call result can be cached and
     * if there's need for cache tag clean or not.
     *
     * @param  string  $method  Name of the method
     * @param  array<int|string, mixed>  $arguments  Arguments for the method and for generating cache key
     * @return mixed Decorated object's results
     */
    public function __call($method, $arguments)
    {
        $this->log('Starting __call: ', compact('method', 'arguments'));

        if ($this->enabled === false) {
            $this->log('Caching disabled, bypassing cache');

            return $this->callMethod($method, $arguments);
        }

        if ($this->isMethodCacheable($method)) {

            $key = $this->generateCacheKey($method, $arguments);

            $res = $this->getCache($key);

            if ($res === $this->cacheMiss()) {

                $this->log('Cache empty, asking from decorated object');

                $res = $this->callMethod($method, $arguments);

                $this->putCache($key, $res);

            }

        } else {
            $res = $this->callMethod($method, $arguments);
        }

        if ($this->doesMethodClearTag($method)) {
            $this->clearCacheTag();
        }

        return $res;

    }

    /**
     * Checks if we need to clear the tag cache
     *
     * @param  string  $method  Name of the method
     * @return bool True == clear tag cache, False == don't clear
     */
    protected function doesMethodClearTag(string $method): bool
    {
        if ($this->tag_cleaners &&
                in_array($method, $this->tag_cleaners)) {
            $this->log('Method clears tags');

            return true;
        }

        return false;
    }

    /**
     *  Handles the cache tag clearing if the tags are set, otherwise do nothing
     */
    protected function clearCacheTag(): bool
    {
        if ($this->tags) {
            $this->log('Clearing the Tag Cache');

            return Cache::tags($this->tags)->flush();
        }

        return false;
    }

    /**
     * Returns the results from the cache
     *
     * @param  string  $key  Cache key
     * @return mixed Results from the cache with or without tags, or the
     *               cacheMiss() sentinel if the entry is absent (or reads
     *               are bypassed via ttl === null).
     */
    protected function getCache(string $key)
    {
        $miss = $this->cacheMiss();

        if ($this->ttl === null) {
            return $miss;
        }

        if ($this->tags) {

            $this->log('Trying to get cache with tags');

            return Cache::tags($this->tags)->get($key, $miss);
        }

        $this->log('Trying to get cache without tags');

        return Cache::get($key, $miss);
    }

    /**
     * Save decorated object's results to cache
     *
     * @param  string  $key  Cache key
     * @param  mixed  $res  Decorated method results
     * @return bool Did the save succeed?
     */
    protected function putCache(string $key, $res): bool
    {
        if ($this->ttl === null) { // don't save if ttl is null
            $this->log('Skipping saving to cache as TTL is set to null');

            return false;
        }

        if ($this->tags) {
            $this->log('Saving to cache with tags');

            return (bool) Cache::tags($this->tags)->put($key, $res, $this->ttl);
        }

        $this->log('Saving to cache without tags');

        return (bool) Cache::put($key, $res, $this->ttl);
    }

    /**
     * Method for making calls to the decorated object
     *
     * Delegates through Laravel's ForwardsCalls trait so calls also reach
     * methods the decorated object exposes via its own __call() magic, not just
     * declared methods. When the inner method returns the inner object (a fluent
     * `return $this;`), forwardDecoratedCallTo() returns this decorator instead,
     * so chaining stays on the cached surface. A genuinely undefined method is
     * converted to an UndefinedMethodException reading
     * "Call to undefined method {Decorator}::{method}()".
     *
     * @param  string  $method  Name of the method
     * @param  array<int|string, mixed>  $arguments  Arguments for the method
     * @return mixed What ever the decorated method returns
     *
     * @throws UndefinedMethodException If the method doesn't exist on the decorated object
     */
    protected function callMethod(string $method, array $arguments)
    {
        $this->log('Calling method from the decorated object');

        return $this->forwardDecoratedCallTo($this->decorated, $method, $arguments);
    }

    /**
     * Throw a package-specific exception for an undefined forwarded method.
     *
     * Overrides the ForwardsCalls trait helper so that calls to methods missing
     * on the decorated object surface as an UndefinedMethodException (a
     * CacheDecoratorException) instead of a raw BadMethodCallException. The
     * message is kept identical to the trait's so behavior other than the
     * thrown type is unchanged.
     *
     * @param  string  $method  Name of the undefined method
     * @return never
     *
     * @throws UndefinedMethodException
     */
    protected static function throwBadMethodCallException($method)
    {
        throw new UndefinedMethodException(sprintf(
            'Call to undefined method %s::%s()', static::class, $method
        ));
    }

    /**
     * Checks if the method belongs to excludes array or not
     *
     * @param  string  $method  Method name
     * @return bool True == method is cacheable, false == not
     */
    protected function isMethodCacheable(string $method): bool
    {
        if ($this->excludes && in_array($method, $this->excludes)) {

            $this->log('Method excluded from cache');

            return false;
        }

        $this->log('Method '.$method.' cacheable');

        return true;
    }

    /**
     * Used for generating the cache key based on the method and method arguments
     *
     * @param  string  $method  Name of the method to be cached
     * @param  array<int|string, mixed>  $arguments  Arguments for the method
     * @return string Cache key as string
     */
    protected function generateCacheKey(string $method, array $arguments): string
    {
        $temp_params = Arr::dot($arguments);
        $params = '';

        foreach ($temp_params as $k => $v) {
            $params .= ".{$k}=".$this->normalizeArgument($v);
        }

        $key = "{$this->prefix_key}.{$method}{$params}";

        $this->log('Cache Key: \''.$key.'\'');

        return $key;
    }

    /**
     * Normalize a single (already dotted) argument value into a stable string
     * token for the cache key. Override this in a subclass to customize how a
     * given argument type contributes to the key.
     *
     * Resolution order:
     *  1. Scalars / null / bool  → cast as-is (keeps existing keys byte-identical).
     *  2. UrlRoutable (Eloquent models, etc.) → getRouteKey() — natural, stable identity.
     *  3. BackedEnum → its ->value; Stringable / __toString → string cast.
     *  4. Anything else (plain objects, closures-as-data) → a bounded, stable
     *     hash (json_encode when encodable, otherwise md5(serialize())).
     *
     * @param  mixed  $value  A leaf argument value to fold into the cache key
     * @return string Stable string token representing the value
     */
    protected function normalizeArgument(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof UrlRoutable) {
            return (string) $value->getRouteKey();
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        $json = json_encode($value);

        return $json !== false ? $json : md5(serialize($value));
    }

    /**
     * Simple wrapper around the Log facade to get logging when necessary
     *
     * @param  array<string, mixed>|null  $arr  Optional context data for the log entry
     */
    protected function log(string $str, ?array $arr = null): void
    {
        if ($this->debug) {
            if (is_array($arr)) {
                Log::debug($str, $arr);
            } else {
                Log::debug($str);
            }
        }
    }
}
