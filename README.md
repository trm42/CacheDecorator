# (Magical) Cache Decorator for Laravel Repositories

Repositories are really, really nice thing that solves real-world issues and follows the idea of DRY (Don't Repeat Yourself), but making similar classes for repository caching and repeating yourself over and over again for code like this: 

```PHP
namespace something\nice;

class CachedUserRepository {
	
	protected $repository;
	protected $cache;

	public function __construct(UserRepository $users, Cache $cache) {
		$this->repository = $users;
		$this->cache = $cache;
	}

	function all()
	{
		if (!$this->cache->has('all')) {
			$results = $this->repository->all();
			$this->cache->save('all', $results);
		} else {
			$results = $this->cache->get('all');
		}

		return $results;

	}

	function findByX($x)
	{
		$key = 'find-' . $x;
		if (!$this->cache->has($key)) {
			$results = $this->repository->findByX($x);
			$this->cache->save($key, $results);
		} else {
			$results = $this->cache->get($key);
		}

		return $results;
	}
}
```

And repeat this for every repository class in your project. Lots of dull repetition. Also, it doesn't help to make the caching code as part of your repository base class as it violates the single responsibility principle.

Cue (Magical) Cache Decorator which handles these things automatically for you with just few lines of class declaration:

```PHP
namespace My\Repositories;

use Trm42\CacheDecorator\CacheDecorator;

class CachedUserRepository extends CacheDecorator {

	protected $ttl = 300; // cache ttl in seconds (or a DateInterval / DateTimeInterface)
	protected $prefix_key = 'users';
	protected $excludes = ['all']; // these methods are not cached

	public function repository()
	{
		return UserRepository::class;
	}

}

```

Aand you're set! The Cache Decorator caches every method call not in the $excludes array. 

*Please note this the current version doesn't support objects as part of the method call. It will be added to v1.0.0.*

If you need something really special handling for some methods you can always override them in the Cached Repository class like this (simple example):

```PHP
public function findByX($x)
{

	$key = $this->generateCacheKey(__FUNCTION__, compact($x));

	$res = $this->getCache($key);

	if (!$res) {
		$results = $this->repository->findX($x);
		
		$this->putCache($key, $results);
	}

	return $res;

}
```

If you happen to use a cache driver that enables you to use cache tags, you can clear the cache automatically when the data changes:
```PHP
// Additional properties to add to the earlier example
// with class decoration
protected $tag_cleaners = ['create'];
protected $tags = ['users'];
```

Aaand you're set! 

## Install

Install with composer:
```bash
composer require trm42/cache-decorator
```

Copy the default configuration:
```bash
cp vendor/trm42/cache-decorator/config/repository_cache.php ./config/
```

## Upgrading from Laravel 5.x versions

This release targets Laravel 12 and 13 on PHP 8.2+. A few breaking changes:

- **TTL semantics changed from minutes to seconds** (matching Laravel 5.8+'s `Cache::put` API). Update any `$ttl` property and the `repository_cache.ttl` config value accordingly — e.g. `5` (minutes) becomes `300` (seconds).
- `$ttl` may now also be a `DateInterval` or `DateTimeInterface`, in addition to `int` and `false` (which still bypasses the cache entirely).
- Minimum PHP version is 8.2.

*Tested with Laravel 12 and Laravel 13.*
