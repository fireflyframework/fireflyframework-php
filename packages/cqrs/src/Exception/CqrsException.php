<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Exception\FireflyException;

/**
 * The umbrella base of the cqrs exception taxonomy, so application code can `catch (CqrsException)` for any dispatch
 * fault. Inherits FireflyException's 6-arg constructor (message, errorCode, httpStatus, category, severity, previous)
 * so each concrete subclass declares its own stable code/category/severity and the web layer renders a consistent
 * RFC-7807 response. Mirrors how firefly/data rooted its own exceptions on the frozen kernel taxonomy.
 */
abstract class CqrsException extends FireflyException {}
