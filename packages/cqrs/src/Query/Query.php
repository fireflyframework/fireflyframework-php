<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Query;

/**
 * Marker interface for a query — a read-model request that never mutates state. Any object implementing this is a
 * query; no base class is imposed. A query MAY additionally implement Firefly\Cqrs\Cache\Cacheable to opt into the
 * query-cache seam.
 */
interface Query {}
