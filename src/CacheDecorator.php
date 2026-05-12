<?php

namespace Trm42\CacheDecorator;

// At least for now there's a Laravel dependency, if there's need, this can be
// converted to something more generic
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

use BadMethodCallException;
use DateInterval;
use DateTimeInterface;
use LogicException;

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
 * @package Trm42\CacheDecorator;
 *
 * @property   object                                            $decorated      Decorated object whose method calls are cached
 * @property   int|false|DateInterval|DateTimeInterface|null    $ttl            Cache entry TTL in seconds (or DateInterval / DateTimeInterface). false to skip cache.
 * @property   bool                                              $enabled        To skip or to not to skip the caching, useful for dev envs
 * @property   string                                            $prefix_key     Beginning of the cache key (like 'users' for a user-related decorator)
 * @property   array                                             $excludes       List of methods that must not be cached (like inserts, setters, etc)
 * @property   array|false                                       $tag_cleaners   If using Cache implementation that supports tags, list of methods that clears the cache tags (like inserts, updates)
 * @property   array|false                                       $tags           List of caching tags associated with the decorator cache (like users, photos)
 * @property   bool                                              $debug          Defines whether we're logging, how the cache works, listens app.debug as default
 * @property   string                                            $config_key     Config namespace this decorator reads from (default 'cache_decorator')
 *
 *
 * @todo    change method check case insensitive if it's possible everywhere
 * @todo    Add some kind of timer functionality to monitor result and cache speed
 * @todo    How to handle empty returns (maybe config whether to cache empty or not and the placeholder)
 * @todo    How to live without Laravel dependencies?
 * @todo    What if the decorated method parameters are objects? O___O
 */
abstract class CacheDecorator {

    protected $decorated;

    protected $ttl;

    protected $prefix_key;

    protected $enabled = true;

    protected $excludes = [];

    protected $tag_cleaners = [];

    protected $tags = false;

    protected $debug = false;

    protected string $config_key = 'cache_decorator';

    /**
     * Override to return the FQCN of the class to default-instantiate when no
     * instance is passed to the constructor. Return null (the default) to
     * require an instance via the constructor.
     *
     * @return  string|null  FQCN of the decorated class, or null
     */
    protected function decoratedClass(): ?string
    {
        return null;
    }

    /**
     * Constructor, accepts the decorated object as parameter
     *
     * @param   object|false  $decorated     Decorated object if you need to define it
     *
     */
    public function __construct($decorated = false)
    {
        $this->initExcludes();
        $this->initDecorated($decorated);
        $this->getConfig();
    }

    /**
     * Basically adds local methods to the excludes[] list
     *
     */
    protected function initExcludes(): void
    {
        $defaults = [   'decoratedClass', 'setTtl', 'setEnabled', 'getConfig', 'initDecorated',
                        'doesMethodClearTag', 'clearCacheTag', 'getCache', 'putCache',
                        'isMethodCacheable', 'generateCacheKey', 'log',                      ];

        $this->excludes = array_merge($defaults, (array) $this->excludes);
    }

    /**
     * Set Cache TTL
     *
     * @param   int|false|DateInterval|DateTimeInterface|null   $ttl    Cache time-to-live in seconds, or false to skip cache.
     */
    public function setTtl($ttl): void
    {
        $this->ttl = $ttl;
    }

    /**
     * Enable or disable caching
     *
     * @param   bool    $bool   True == enable
     */
    public function setEnabled(bool $bool): void
    {
        $this->enabled = $bool;
    }


    /**
     * Reads the config values from {config_key}.* values. Note debug listens app.debug.
     *
     */
    protected function getConfig(): void
    {
        $this->ttl = Config::get("{$this->config_key}.ttl");
        $this->enabled = Config::get("{$this->config_key}.enabled");

        if (!Config::get("{$this->config_key}.use_tags")) {
            $this->tags = false;
            $this->tag_cleaners = false;
        }

        // How do you feel about this?
        $this->debug = (bool) Config::get('app.debug');
    }

    /**
     * Handles the initiating or setting of the decorated object
     *
     * @param   object|false    $decorated
     */
    public function initDecorated($decorated): void
    {
        if (!$decorated) {
            $class = $this->decoratedClass();

            if (!$class) {
                throw new LogicException(
                    'No decorated object provided and decoratedClass() returned null. '
                    . 'Either pass an instance to the constructor or override decoratedClass().'
                );
            }

            $decorated = new $class;
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
     * @param   string  $method     Name of the method
     * @param   array   $arguments  Arguments for the method and for generating cache key
     *
     * @return  mixed   Decorated object's results
     */
    public function __call($method, $arguments)
    {
        $this->log('Starting __call: ', compact('method', 'arguments'));

        if ($this->isMethodCacheable($method)) {

            $key = $this->generateCacheKey($method, $arguments);

            $res = $this->getCache($key);

            if (!$res) {

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
     * @param  string  $method     Name of the method
     *
     * @return  bool    True == clear tag cache, False == don't clear
     *
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
     *
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
     * @param   string  $key    Cache key
     *
     * @return  mixed   Results from the cache with or without tags or false if not found
     *
     */
    protected function getCache(string $key)
    {
        if ($this->ttl === false) {
            return false;
        }

        if ($this->tags) {

            $this->log('Trying to get cache with tags');

            return Cache::tags($this->tags)->get($key);
        }

        $this->log('Trying to get cache without tags');

        return Cache::get($key);
    }

    /**
     * Save decorated object's results to cache
     *
     * @param string    $key   Cache key
     * @param mixed     $res   Decorated method results
     *
     * @return bool     Did the save succeed?
     *
     */
    protected function putCache(string $key, $res): bool
    {
        if ($this->ttl === false) { // don't save if ttl is false
            $this->log('Skipping saving to cache as TTL is set to false');
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
     * @param   string  $method     Name of the method
     * @param   array   $arguments  Arguments for the method
     * @return  mixed   What ever the decorated method returns
     * @throws  BadMethodCallException  If the method doesn't exist on the decorated object
     */
    protected function callMethod(string $method, array $arguments)
    {
        if (method_exists($this->decorated, $method)) {
            $this->log('Calling method from the decorated object');
            return call_user_func_array([$this->decorated, $method], $arguments);
        }

        throw new BadMethodCallException("Method '{$method}' does not exist in the decorated object");
    }

    /**
     * Checks if the method belongs to excludes array or not
     *
     * @param   string  $method     Method name
     * @return  bool    True == method is cacheable, false == not
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
     * @param   string  $method  Name of the method to be cached
     * @param   array   $arguments  Arguments for the method
     * @return  string  Cache key as string
     */
    protected function generateCacheKey(string $method, array $arguments): string
    {
        $temp_params = Arr::dot($arguments);
        $params = '';

        foreach($temp_params as $k => $v) {
            $params .= ".{$k}={$v}";
        }

        $key = "{$this->prefix_key}.{$method}{$params}";

        $this->log('Cache Key: \'' .$key . '\'');

        return $key;
    }

    /**
     * Simple wrapper around the Log facade to get logging when necessary
     *
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
