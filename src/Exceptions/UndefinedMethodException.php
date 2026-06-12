<?php

namespace Trm42\CacheDecorator\Exceptions;

/**
 * Thrown when a forwarded method call targets a method that does not exist on
 * the decorated object.
 */
class UndefinedMethodException extends CacheDecoratorException {}
