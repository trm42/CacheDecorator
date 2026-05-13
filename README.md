# (Magical) Cache Decorator for Laravel

A transparent caching decorator for any Laravel-side class — services, API clients, query objects, repositories, you name it. Sub-class `CacheDecorator`, point it at the object you want to cache, and every public method call is automatically cached on first run and served from the cache on subsequent calls.

Stop writing boilerplate like this for every class whose results you want to cache:

```PHP
namespace something\nice;

class CachedReportingService {

    protected $service;
    protected $cache;

    public function __construct(ReportingService $service, Cache $cache) {
        $this->service = $service;
        $this->cache = $cache;
    }

    public function dailyTotals($date)
    {
        $key = 'daily-totals-' . $date;
        if (!$this->cache->has($key)) {
            $results = $this->service->dailyTotals($date);
            $this->cache->save($key, $results);
        } else {
            $results = $this->cache->get($key);
        }

        return $results;
    }

    // ... and the same for every other method
}
```

With `CacheDecorator` the above shrinks to:

```PHP
namespace My\Services;

use Trm42\CacheDecorator\CacheDecorator;

class CachedReportingService extends CacheDecorator {

    protected $ttl = 300; // cache ttl in seconds (or a DateInterval / DateTimeInterface)
    protected $prefix_key = 'reports';
    protected $excludes = ['recompute']; // methods listed here are never cached
}
```

…and use it like this:

```PHP
$cached = new CachedReportingService(new ReportingService);

$cached->dailyTotals('2026-05-12'); // cache miss → calls ReportingService::dailyTotals
$cached->dailyTotals('2026-05-12'); // cache hit  → returns the cached value
```

The decorator forwards any method not listed in `$excludes` to the underlying object via `__call()` and caches the result. *The current version doesn't support objects as method arguments — coming in v1.0.0.*

### Optional: have the decorator instantiate the inner class for you

If you don't want to wire the inner instance yourself, override `decoratedClass()` to return its FQCN and you can construct the decorator with no arguments:

```PHP
class CachedReportingService extends CacheDecorator {
    protected $prefix_key = 'reports';

    protected function decoratedClass(): ?string
    {
        return ReportingService::class;
    }
}

$cached = new CachedReportingService;
```

### Custom caching logic for a single method

If a particular method needs hand-tuned caching, override it in the subclass and use the protected helpers:

```PHP
public function findByX($x)
{
    $key = $this->generateCacheKey(__FUNCTION__, compact('x'));

    $res = $this->getCache($key);

    if (!$res) {
        $res = $this->decorated->findX($x);

        $this->putCache($key, $res);
    }

    return $res;
}
```

### Cache tags

If your cache driver supports tags, declare which methods invalidate the tag bucket:

```PHP
protected $tag_cleaners = ['recompute'];
protected $tags = ['reports'];
```

## Using with repositories

For repository-flavored use cases the package ships `RepositoryCacheDecorator`, which preserves the original repository API: subclasses implement `repository()` returning the wrapped repository class name, and the instance is exposed as `$this->repository`.

```PHP
namespace My\Repositories;

use Trm42\CacheDecorator\RepositoryCacheDecorator;

class CachedUserRepository extends RepositoryCacheDecorator {

    protected $ttl = 300;
    protected $prefix_key = 'users';
    protected $excludes = ['allWithoutCache'];
    protected $tag_cleaners = ['create'];
    protected $tags = ['users'];

    public function repository()
    {
        return UserRepository::class;
    }

    // optional per-method override
    public function findByX($x)
    {
        $key = $this->generateCacheKey(__FUNCTION__, compact('x'));

        $res = $this->getCache($key);

        if (!$res) {
            $res = $this->repository->findX($x);
            $this->putCache($key, $res);
        }

        return $res;
    }
}
```

`RepositoryCacheDecorator` reads its config from the `repository_cache.*` namespace, while the generic `CacheDecorator` reads from `cache_decorator.*`. Both work side-by-side.

## Install

Install with composer:
```bash
composer require trm42/cache-decorator
```

Publish whichever config you need (or both):
```bash
# Generic CacheDecorator config
php artisan vendor:publish --tag=cache-decorator-config

# Repository-flavored config
php artisan vendor:publish --tag=repository-cache-config
```

Environment variables:

| Config                          | Env var                    | Default |
| ------------------------------- | -------------------------- | ------- |
| `cache_decorator.enabled`       | `CACHE_DECORATOR_ENABLED`  | `true`  |
| `cache_decorator.ttl`           | `CACHE_DECORATOR_TTL`      | `300`   |
| `cache_decorator.use_tags`      | `CACHE_DECORATOR_TAGS`     | `true`  |
| `repository_cache.enabled`      | `REPOSITORY_CACHE`         | `true`  |
| `repository_cache.ttl`          | `REPOSITORY_CACHE_TTL`     | `300`   |
| `repository_cache.use_tags`     | `REPOSITORY_CACHE_TAGS`    | `true`  |

## Upgrading

### From the repository-only version

The base class is now generic. If your existing cached repository classes `extends CacheDecorator`, switch them to `extends RepositoryCacheDecorator` — your `repository()` method, the `$this->repository` property in custom overrides, and the `repository_cache.*` config all keep working.

```diff
-use Trm42\CacheDecorator\CacheDecorator;
+use Trm42\CacheDecorator\RepositoryCacheDecorator;

-class CachedUserRepository extends CacheDecorator {
+class CachedUserRepository extends RepositoryCacheDecorator {
```

### From Laravel 5.x versions

This release targets Laravel 12 and 13 on PHP 8.2+. A few breaking changes:

- **TTL semantics changed from minutes to seconds** (matching Laravel 5.8+'s `Cache::put` API). Update any `$ttl` property and the `repository_cache.ttl` config value accordingly — e.g. `5` (minutes) becomes `300` (seconds).
- `$ttl` may now also be a `DateInterval` or `DateTimeInterface`, in addition to `int` and `false` (which still bypasses the cache entirely).
- Minimum PHP version is 8.2.

*Tested with Laravel 12 and Laravel 13.*
