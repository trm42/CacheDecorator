<?php

namespace Trm42\CacheDecorator\Exceptions;

/**
 * Base type for every exception thrown by the cache-decorator package.
 *
 * Catch this to handle any cache-decorator-specific failure with a single
 * catch block, while still being able to distinguish the concrete subclasses
 * ({@see MissingDecoratedObjectException}, {@see UndefinedMethodException})
 * when needed.
 */
abstract class CacheDecoratorException extends \Exception {}
