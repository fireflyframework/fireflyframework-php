<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

/**
 * A concrete FlushDefinitionsPass fixture whose definition is REMOVED by ConditionPassOnePass
 * before FlushDefinitionsPass ever runs. Proves the flush only ever registers the
 * condition-FILTERED manifest: this class must end up neither bound in the container nor tagged
 * for Cache in the facade's getAll().
 */
final class RemovedWidget implements Cache {}
