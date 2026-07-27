<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * The connection-opening seam RabbitMqHealthIndicator programs to (not the concrete RabbitMqConnectionFactory), so
 * a unit test can inject a throwing fake without a real socket. RabbitMqConnectionFactory implements it.
 */
interface OpensConnection
{
    public function connect(): AMQPStreamConnection;
}
