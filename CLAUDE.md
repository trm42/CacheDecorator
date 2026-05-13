# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`trm42/cache-decorator` is a Laravel package (currently targeting Laravel 12 and 13 on PHP 8.2+) that provides a magical caching decorator for any wrapped object. The generic base class `CacheDecorator` decorates an arbitrary object (services, API clients, query objects, etc.); the `RepositoryCacheDecorator` subclass specializes it for the original repository-flavored API. Method calls on the decorator are auto-cached via Laravel's `Cache` facade.

## Commands

- Install deps: `composer install`
- Run tests: `composer test` or `vendor/bin/phpunit`
- Run a single test file: `vendor/bin/phpunit src/tests/CachedStubRepositoryTest.php`
- Run a single test method: `vendor/bin/phpunit --filter testMethodName src/tests/CachedStubRepositoryTest.php`

PHPUnit ^11 is used (PHPUnit 12 also allowed); test bootstrap is `vendor/autoload.php`, and the suite lives under `src/tests/` (configured in `phpunit.xml`). The Laravel-integrating tests rely on Orchestra Testbench to boot a minimal Laravel app, register `Trm42\CacheDecorator\ServiceProvider`, and supply the `Cache` / `Config` facades.

## Architecture

The package is intentionally small — four production files plus tests.

- **`src/CacheDecorator.php`** — abstract base. The core mechanism is `__call($method, $arguments)`: if `$this->enabled === false` it forwards straight to the decorated object (no cache get/put, no tag flushing). Otherwise, if the method is cacheable (not in `$excludes`), it builds a key via `generateCacheKey()`, calls `getCache()` (which uses `Cache::get` with `tags()` if `$tags` is set, and passes the `cacheMiss()` sentinel as the default), and on miss (`$res === $this->cacheMiss()`) forwards to `$this->decorated` via `callMethod()` (which uses `method_exists` + variadic spread and throws `BadMethodCallException` if missing). Methods listed in `$tag_cleaners` trigger `Cache::tags(...)->flush()` after the call. Subclasses either pass an instance to the constructor or override `decoratedClass(): ?string` to return a FQCN for default instantiation; if neither is provided, `initDecorated()` throws `LogicException`.
- **`src/RepositoryCacheDecorator.php`** — repository-flavored subclass. Identical to `CacheDecorator` except `$config_key` is overridden to `'repository_cache'`, so it reads its config from a separate namespace.
- **`initExcludes()`** auto-adds the decorator's own protected/public method names to `$excludes` so they aren't intercepted by `__call`. If you add new helper methods on `CacheDecorator`, add them to the `$defaults` list in `initExcludes()` or `__call` will try to forward them to the decorated object.
- **`cacheMiss()` sentinel**: a shared `stdClass` instance (stored on `private static ?object $missMarker` and lazily initialised) returned by `getCache()` whenever the cache entry is absent or reads are bypassed (`ttl === null`). `__call()` compares the return of `getCache()` with `=== $this->cacheMiss()` to detect a true miss. This lets the decorator round-trip falsy cached values (`0`, `''`, `[]`, `false`, `null`) without refetching on every call. Custom per-method overrides in subclasses should follow the same pattern (`if ($res === $this->cacheMiss())`), not `if (!$res)`.
- **Cache key format** is `{prefix_key}.{method}.{argKey}={argVal}...` built by `generateCacheKey()` using `Illuminate\Support\Arr::dot($arguments)`. Note: arguments that are objects are not supported (a known TODO in the class header).
- **TTL semantic**: `$ttl` is in **seconds** (Laravel 5.8+ `Cache::put` API) and may also be `DateInterval` / `DateTimeInterface`. `$ttl === null` is a sentinel that bypasses both reads and writes to the cache — `getCache()` short-circuits to the `cacheMiss()` sentinel without touching the `Cache` facade so `__call()` proceeds to invoke the decorated method, and `putCache()` returns `false` without storing anything.
- **`enabled` flag**: when `false` (set via the property, `setEnabled(false)`, or `{$config_key}.enabled = false`), `__call()` returns `callMethod()` directly at the top, skipping `isMethodCacheable`, cache get/put, and tag flushing. This is the on/off switch for caching.
- **Config** is read in `getConfig()` from `{$this->config_key}.*` (`ttl`, `enabled`, `use_tags`) plus `app.debug` for the `$debug` flag controlling `Log::debug` output. The base default is `'cache_decorator'`; `RepositoryCacheDecorator` overrides it to `'repository_cache'`.
- **`src/ServiceProvider.php`** — publishes both config files: `config/cache_decorator.php` under the `cache-decorator-config` tag and `config/repository_cache.php` under the `repository-cache-config` tag; `register()` is empty. The provider is auto-discovered via `extra.laravel.providers` in `composer.json`.

The classes use Laravel facades (`Cache`, `Config`, `Log` from `Illuminate\Support\Facades`), so this package is Laravel-coupled — the TODO in the header notes a future goal to decouple. When testing the no-arg construction path, the decorated class is instantiated via `new $class` inside `initDecorated()` unless an instance is injected through the constructor.

## Conventions

- Generic subclasses extend `CacheDecorator` (or `RepositoryCacheDecorator` if you want the `repository_cache.*` config namespace), set `$ttl`, `$prefix_key`, `$excludes`, `$tag_cleaners`, `$tags` as protected properties, and either inject the decorated instance via the constructor or override `decoratedClass()` returning a FQCN string.
- Inside custom method overrides, reference the inner object as `$this->decorated`.
- To customize caching for a single method, override it in the subclass and use the protected helpers `generateCacheKey()`, `getCache()`, `putCache()` (see README example).
- TTL values throughout (subclass `$ttl`, `cache_decorator.ttl` / `repository_cache.ttl` config, calls to `setTtl()`) are in seconds — this changed from "minutes" when upgrading from the Laravel 5.x line.
