# (Magical) Cache Decorator for Laravel

[![Tests](https://github.com/trm42/CacheDecorator/actions/workflows/run-tests.yml/badge.svg)](https://github.com/trm42/CacheDecorator/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/trm42/CacheDecorator/actions/workflows/phpstan.yml/badge.svg)](https://github.com/trm42/CacheDecorator/actions/workflows/phpstan.yml)
[![Code style](https://github.com/trm42/CacheDecorator/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/trm42/CacheDecorator/actions/workflows/fix-php-code-style-issues.yml)

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

/** @extends CacheDecorator<ReportingService> */
class CachedReportingService extends CacheDecorator {

    protected ?string $prefix_key = 'reports';
    protected array $excludes = ['recompute']; // methods listed here are never cached
}
```

> **TTL is read from config**, not from a `$ttl` property. The constructor calls `getConfig()`, which overwrites `$ttl` from `cache_decorator.ttl` (default `300` seconds; `repository_cache.ttl` for `RepositoryCacheDecorator`). To override it per-instance, call `setTtl(...)` after construction (e.g. in your subclass constructor) — it accepts `int` seconds, a `DateInterval`, a `DateTimeInterface`, or `null` to bypass the cache entirely.

…and use it like this:

```PHP
$cached = new CachedReportingService(new ReportingService);

$cached->dailyTotals('2026-05-12'); // cache miss → calls ReportingService::dailyTotals
$cached->dailyTotals('2026-05-12'); // cache hit  → returns the cached value
```

The decorator forwards any method not listed in `$excludes` to the underlying object via `__call()` and caches the result. Forwarding goes through Laravel's `ForwardsCalls` trait, so calls also reach methods the decorated object exposes through *its own* `__call()` magic — not just declared methods. Calling a method that exists nowhere on the decorated object throws `UndefinedMethodException` (see [Exceptions](#exceptions)) with the message `Call to undefined method {Decorator}::{method}()`. *The current version doesn't support objects as method arguments — coming in v1.0.0.*

### Fluent / self-returning methods

`forwardDecoratedCallTo()` rewrites a fluent `return $this;` from the inner object back to the **decorator**, so method chaining stays on the cached surface instead of escaping to the bare inner instance.

Two conventions make this transparent and type-safe:

- **List fluent methods in `$excludes`.** They aren't cache candidates anyway, and excluding them keeps them on the always-forward path. (On a cache *miss* `__call()` stores `callMethod()`'s return — now the decorator instance — and a later *hit* would return that cached decorator directly, bypassing the rewrite. Excluding avoids caching a decorator object.)
- **Type fluent methods `: static` behind a shared interface.** Have the inner class implement an interface whose fluent methods return `static`. A caller then sees the same type whether it holds the inner instance or the decorator, and the decorator standing in for `$this` on self-returning calls is type-coherent.

```PHP
interface ReportingContract {
    public function forMonth(string $month): static; // fluent
    public function totals(): array;                  // cacheable
}

class ReportingService implements ReportingContract { /* ... */ }

/** @extends CacheDecorator<ReportingService> */
class CachedReportingService extends CacheDecorator {
    protected ?string $prefix_key = 'reports';
    protected array $excludes = ['forMonth']; // fluent method stays on the forward path
}

$cached = new CachedReportingService(new ReportingService);
$cached->forMonth('2026-05')->totals(); // forMonth() returns the decorator; totals() is cached
```

### Optional: have the decorator instantiate the inner class for you

If you don't want to wire the inner instance yourself, override `decoratedClass()` to return its FQCN and you can construct the decorator with no arguments:

```PHP
/** @extends CacheDecorator<ReportingService> */
class CachedReportingService extends CacheDecorator {
    protected ?string $prefix_key = 'reports';

    #[\Override]
    protected function decoratedClass(): ?string
    {
        return ReportingService::class;
    }
}

$cached = new CachedReportingService;
```

The FQCN returned by `decoratedClass()` is resolved through Laravel's service container (via `resolve()`), so the decorated class may declare constructor dependencies (they are auto-wired), and `decoratedClass()` may return an interface that's bound in the container.

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
protected array $tag_cleaners = ['recompute'];
protected array $tags = ['reports'];
```

## Exceptions

All errors thrown by the package live in the `Trm42\CacheDecorator\Exceptions` namespace and extend a single abstract base, `CacheDecoratorException`. Catch that base type to handle any cache-decorator-specific failure in one place, or catch a concrete subclass to distinguish the failure mode:

| Exception                          | Thrown when                                                                                                   |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `CacheDecoratorException`          | *(abstract base — never thrown directly; catch it to handle every error below)*                               |
| `MissingDecoratedObjectException`  | No instance was passed to the constructor **and** `decoratedClass()` returned `null`, so there is nothing to wrap. |
| `UndefinedMethodException`         | A forwarded call targets a method that exists nowhere on the decorated object.                                |

```PHP
use Trm42\CacheDecorator\Exceptions\CacheDecoratorException;

try {
    $cached->dailyTotals('2026-05-12');
} catch (CacheDecoratorException $e) {
    // catches MissingDecoratedObjectException and UndefinedMethodException alike
    report($e);
}
```

> **Breaking change.** These types previously surfaced as the SPL exceptions `LogicException` (missing decorated object) and `BadMethodCallException` (undefined method). They now extend `\Exception` via `CacheDecoratorException` and are **not** instances of those SPL classes — update any `catch (LogicException ...)` / `catch (BadMethodCallException ...)` blocks that relied on the old types.

## Using with repositories

For repository-flavored use cases the package ships `RepositoryCacheDecorator`. It behaves exactly like `CacheDecorator` but reads its config from the `repository_cache.*` namespace instead of `cache_decorator.*`, so repository caches can be tuned independently of other decorators.

```PHP
namespace My\Repositories;

use Trm42\CacheDecorator\RepositoryCacheDecorator;

/** @extends RepositoryCacheDecorator<UserRepository> */
class CachedUserRepository extends RepositoryCacheDecorator {

    protected ?string $prefix_key = 'users';
    protected array $excludes = ['allWithoutCache'];
    protected array $tag_cleaners = ['create'];
    protected array $tags = ['users'];

    #[\Override]
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
