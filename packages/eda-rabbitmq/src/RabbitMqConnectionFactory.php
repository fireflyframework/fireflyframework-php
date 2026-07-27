<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq;

use Firefly\Config\Config;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/** Opens an AMQP connection from firefly.eda.rabbitmq.* config. One place so publisher + consumer + health agree. */
final class RabbitMqConnectionFactory implements OpensConnection
{
    public function __construct(private readonly Config $config) {}

    public function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            $this->config->string('firefly.eda.rabbitmq.host', '127.0.0.1'),
            $this->config->int('firefly.eda.rabbitmq.port', 5672),
            $this->config->string('firefly.eda.rabbitmq.user', 'guest'),
            $this->config->string('firefly.eda.rabbitmq.password', 'guest'),
            $this->config->string('firefly.eda.rabbitmq.vhost', '/'),
        );
    }
}
