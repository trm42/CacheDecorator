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

/**
 * Magical Cache Decorator class for Repositories. Meant to be sub classed.
 *
 * Base Cache Decorator class for repositories. Sub class this (e.g.
 * class CachedUserRepository extends CacheDecorator and input repository
 * specific settings as class properties and create the 'repository()' method.
 * That method should return only the name of the repository class.
 *
 * This class isn't interested in the implementation of the models or the repository.
 *
 * If you need to create repository method specific caching logic, you can create
 * new method to the sub-classed cache class (Like CachedUserRepository->all()) .
 * This class automatically calls repository methods and caches them (@see __call())
 * thus lessening the need to write boilerplate caching code for every repository
 * method.
 *
 * For caching relationships, please make sure you're using eager loading (e.g.
 * Eloquent's with() or load() -methods)
 *
 * @author  Matias Mäki <matias.maki@gmail.com>
 * @package Trm42\CacheDecorator;
 *
 * @property   object                                            $repository     Repository object
 * @property   int|false|DateInterval|DateTimeInterface|null    $ttl            Cache entry TTL in seconds (or DateInterval / DateTimeInterface). false to skip cache.
 * @property   bool                                              $enabled        To skip or to not to skip the caching, useful for dev envs
 * @property   string                                            $prefix_key     Beginning of the cache key (like 'users' for user repo)
 * @property   array                                             $excludes       List of repository that must not be cached (like inserts, setters, etc)
 * @property   array|false                                       $tag_cleaners   If using Cache implementation that supports tags, list of methods that clears the cache tags (like inserts, updates)
 * @property   array|false                                       $tags           List of caching tags associated with the repository cache (like users, photos)
 * @property   bool                                              $debug          Defines whether we're logging, how the cache works, listens app.debug as default
 *
 *
 * @todo    change method check case insensitive if it's possible everywhere
 * @todo    Add some kind of timer functionality to monitor result and cache speed
 * @todo    How to handle empty returns (maybe config whether to cache empty or not and the placeholder)
 * @todo    How to live without Laravel dependencies?
 * @todo    What if the repository parameters are objects? O___O
 */
abstract class CacheDecorator {

    protected $repository;

    protected $ttl;

    protected $prefix_key;

    protected $enabled = true;

    protected $excludes = [];

    protected $tag_cleaners = [];

    protected $tags = false;

    protected $debug = false;

    /**
     * You need to implement this per sub-class.
     *
     * @return  string  Name of the repository class. Used for instiating the repository
     *
     */
    abstract public function repository();

    /**
     * Constructor, accepts the repository object as parameters
     *
     * @param   object|false  $repository     Repository object if you need to define it
     *
     */
    public function __construct($repository = false)
    {
        $this->initExcludes();
        $this->initRepository($repository);
        $this->getConfig();
    }

    /**
     * Basically adds local methods to the excludes[] list
     *
     */
    protected function initExcludes(): void
    {
        $defaults = [   'repository', 'setTtl', 'setEnabled', 'getConfig', 'initRepository',
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
     * Reads the config values from repository_cache.* values. Note debug listens app.debug.
     *
     */
    protected function getConfig(): void
    {
        $this->ttl = Config::get('repository_cache.ttl');
        $this->enabled = Config::get('repository_cache.enabled');

        if (!Config::get('repository_cache.use_tags')) {
            $this->tags = false;
            $this->tag_cleaners = false;
        }

        // How do you feel about this?
        $this->debug = (bool) Config::get('app.debug');
    }

    /**
     * Handles the initiating or setting of the repository
     *
     * @param   object|false    $repository
     */
    public function initRepository($repository): void
    {
        if (!$repository) {
            $class = $this->repository();
            $repository = new $class;
        }

        $this->repository = $repository;
    }

    /**
     * This is where the magic happens :)
     *
     * If the method is not declared in the cache decorator class (e.g.
     * CachedUserRepository), then this checks if it's declared in the actual repository
     * class AND checks if the method call result can be cached and if there's need for
     * cache tag clean or not
     *
     * @param   string  $method     Name of the method
     * @param   array   $arguments  Arguments for the method and for generating cache key
     *
     * @return  mixed   Repository results
     */
    public function __call($method, $arguments)
    {
        $this->log('Starting __call: ', compact('method', 'arguments'));

        if ($this->isMethodCacheable($method)) {

            $key = $this->generateCacheKey($method, $arguments);

            $res = $this->getCache($key);

            if (!$res) {

                $this->log('Cache empty, asking from Repository');

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
     * Save repository results to cache
     *
     * @param string    $key   Cache key
     * @param mixed     $res   Repository results
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
     * Method for making calls to the repository
     *
     * @param   string  $method     Name of the method
     * @param   array   $arguments  Arguments for the method
     * @return  mixed   What ever the repository method returns
     * @throws  BadMethodCallException  If the method doesn't exist in the repository
     */
    protected function callMethod(string $method, array $arguments)
    {
        if (method_exists($this->repository, $method)) {
            $this->log('Calling method from the repository');
            return call_user_func_array([$this->repository, $method], $arguments);
        }

        throw new BadMethodCallException("Method '{$method}' does not exist in the repository");
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
