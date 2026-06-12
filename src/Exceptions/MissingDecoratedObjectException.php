<?php

namespace Trm42\CacheDecorator\Exceptions;

/**
 * Thrown when no decorated instance is passed to the constructor and
 * decoratedClass() returns null, so the decorator has nothing to wrap.
 */
class MissingDecoratedObjectException extends CacheDecoratorException {}
