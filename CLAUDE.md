# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`trm42/cache-decorator` is a Laravel package (currently targeting Laravel 12 and 13 on PHP 8.2+) that provides a magical caching decorator for repository classes. Consumers subclass `CacheDecorator` and declare a `repository()` method returning the wrapped repository's class name; method calls on the decorator are auto-cached via Laravel's `Cache` facade.

## Commands

- Install deps: `composer install`
- Run tests: `composer test` or `vendor/bin/phpunit`
- Run a single test file: `vendor/bin/phpunit src/tests/CachedStubRepositoryTest.php`
- Run a single test method: `vendor/bin/phpunit --filter testMethodName src/tests/CachedStubRepositoryTest.php`

PHPUnit ^11 is used (PHPUnit 12 also allowed); test bootstrap is `vendor/autoload.php`, and the suite lives under `src/tests/` (configured in `phpunit.xml`). The Laravel-integrating tests rely on Orchestra Testbench to boot a minimal Laravel app, register `Trm42\CacheDecorator\ServiceProvider`, and supply the `Cache` / `Config` facades.

## Architecture

The package is intentionally small — three production files plus tests.

- **`src/CacheDecorator.php`** — abstract base. The core mechanism is `__call($method, $arguments)`: if the method is cacheable (not in `$excludes`), it builds a key via `generateCacheKey()`, checks `Cache::get` (with `tags()` if `$tags` is set), and on miss forwards to the underlying repository via `callMethod()` (which uses `method_exists` + `call_user_func_array` and throws `BadMethodCallException` if missing). Methods listed in `$tag_cleaners` trigger `Cache::tags(...)->flush()` after the call.
- **`initExcludes()`** auto-adds the decorator's own protected/public method names to `$excludes` so they aren't intercepted by `__call`. If you add new helper methods on `CacheDecorator`, add them to the `$defaults` list in `initExcludes()` or `__call` will try to forward them to the repository.
- **Cache key format** is `{prefix_key}.{method}.{argKey}={argVal}...` built by `generateCacheKey()` using `Illuminate\Support\Arr::dot($arguments)`. Note: arguments that are objects are not supported (a known TODO in the class header).
- **TTL semantic**: `$ttl` is in **seconds** (Laravel 5.8+ `Cache::put` API) and may also be `DateInterval` / `DateTimeInterface`. `$ttl === false` is a sentinel that bypasses both reads and writes to the cache.
- **Config** is read in `getConfig()` from `repository_cache.*` (`ttl`, `enabled`, `use_tags`) plus `app.debug` for the `$debug` flag controlling `Log::debug` output.
- **`src/ServiceProvider.php`** — only publishes the default config (`config/repository_cache.php`) to the host app's `config/` directory under the `repository-cache-config` publish tag; `register()` is empty. The provider is auto-discovered via `extra.laravel.providers` in `composer.json`.

The class uses Laravel facades (`Cache`, `Config`, `Log` from `Illuminate\Support\Facades`), so this package is Laravel-coupled — the TODO in the header notes a future goal to decouple. When testing, the underlying repository class is instantiated via `new $class` inside `initRepository()` unless an instance is injected through the constructor.

## Conventions

- Subclasses set `$ttl`, `$prefix_key`, `$excludes`, `$tag_cleaners`, `$tags` as protected properties and implement `repository()` returning a FQCN string.
- To customize caching for a single method, override it in the subclass and use the protected helpers `generateCacheKey()`, `getCache()`, `putCache()` (see README example).
- TTL values throughout (subclass `$ttl`, `repository_cache.ttl` config, calls to `setTtl()`) are in seconds — this changed from "minutes" when upgrading from the Laravel 5.x line.
