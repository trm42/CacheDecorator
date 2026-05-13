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

    if ($res === $this->cacheMiss()) {
        $res = $this->decorated->findX($x);

        $this->putCache($key, $res);
    }

    return $res;
}
```

`getCache()` returns the `cacheMiss()` sentinel (a shared `stdClass` instance) when the entry is absent — comparing with `===` against `cacheMiss()` lets the override round-trip falsy payloads (`0`, `''`, `[]`, `false`, `null`) correctly. Don't use truthiness checks like `if (!$res)`: they would treat a legitimately cached `false`/`0`/`[]` as a miss and refetch on every call.

### Cache tags

If your cache driver supports tags, declare which methods invalidate the tag bucket:

```PHP
protected $tag_cleaners = ['recompute'];
protected $tags = ['reports'];
```

## Using with repositories

For repository-flavored use cases the package ships `RepositoryCacheDecorator`. It behaves exactly like `CacheDecorator` but reads its config from the `repository_cache.*` namespace instead of `cache_decorator.*`, so repository caches can be tuned independently of other decorators.

```PHP
namespace My\Repositories;

use Trm42\CacheDecorator\RepositoryCacheDecorator;

class CachedUserRepository extends RepositoryCacheDecorator {

    protected $ttl = 300;
    protected $prefix_key = 'users';
    protected $excludes = ['allWithoutCache'];
    protected $tag_cleaners = ['create'];
    protected $tags = ['users'];

    protected function decoratedClass(): ?string
    {
        return UserRepository::class;
    }

    // optional per-method override
    public function findByX($x)
    {
        $key = $this->generateCacheKey(__FUNCTION__, compact('x'));

        $res = $this->getCache($key);

        if ($res === $this->cacheMiss()) {
            $res = $this->decorated->findX($x);
            $this->putCache($key, $res);
        }

        return $res;
    }
}
```

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

### From the previous 0.x line

A few breaking changes tightened the public contract:

- **TTL bypass uses `null`, not `false`.** The "skip the cache" sentinel for `$ttl` is now `null`. The property type is `int|DateInterval|DateTimeInterface|null` (default `null`) and `setTtl()` has the same typed signature — replace any `protected $ttl = false;` with `protected $ttl = null;` and any `setTtl(false)` with `setTtl(null)`.
- **`$tags` and `$tag_cleaners` are plain arrays.** Both default to `[]` (no longer `array|false`). If the cache driver doesn't support tags (or `use_tags` is disabled in config), they are reset to `[]` rather than `false`. Custom subclasses that initialized either property to `false` should switch to `[]`.
- **Falsy cached values round-trip correctly.** Previously a method returning `0`, `''`, `[]`, or `false` would look like a cache miss and be refetched on every call. `getCache()` now returns a `cacheMiss()` sentinel (a shared `stdClass`) on a true miss, and `__call()` compares with `===` — so falsy results are cached and served from cache as expected. If you wrote a custom method override with `if (!$res)` around `getCache()`, switch it to `if ($res === $this->cacheMiss())` (see the override example above).
- **The `enabled` flag now actually short-circuits caching.** Setting `$enabled = false` (via the property, `setEnabled(false)`, or `{$config_key}.enabled = false`) now causes `__call()` to forward straight to the decorated object, skipping cache reads, writes, and tag flushing.

### From the repository-only version

The base class is now generic and the repository-specific glue has been removed in favor of the generic hooks:

- Replace `extends CacheDecorator` with `extends RepositoryCacheDecorator` if you want to keep reading config from the `repository_cache.*` namespace.
- Rename your `repository()` method to `decoratedClass()` (return type `?string`). The old `repository()` abstract has been removed.
- In custom method overrides, replace `$this->repository->…` with `$this->decorated->…`. The `$this->repository` alias has been removed.
- `initRepository()` has been removed; pass the instance via the constructor, or override `decoratedClass()`.

```diff
-use Trm42\CacheDecorator\CacheDecorator;
+use Trm42\CacheDecorator\RepositoryCacheDecorator;

-class CachedUserRepository extends CacheDecorator {
+class CachedUserRepository extends RepositoryCacheDecorator {

-    public function repository()
+    protected function decoratedClass(): ?string
     {
         return UserRepository::class;
     }

-    $res = $this->repository->findX($x);
+    $res = $this->decorated->findX($x);
```

### From Laravel 5.x versions

This release targets Laravel 12 and 13 on PHP 8.2+. A few breaking changes:

- **TTL semantics changed from minutes to seconds** (matching Laravel 5.8+'s `Cache::put` API). Update any `$ttl` property and the `repository_cache.ttl` config value accordingly — e.g. `5` (minutes) becomes `300` (seconds).
- `$ttl` may now also be a `DateInterval` or `DateTimeInterface`, in addition to `int` and `null` (which bypasses the cache entirely).
- Minimum PHP version is 8.2.

*Tested with Laravel 12 and Laravel 13.*
