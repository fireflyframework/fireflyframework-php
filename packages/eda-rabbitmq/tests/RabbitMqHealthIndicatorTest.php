<?php

declare(strict_types=1);

use Firefly\Actuator\Health\Status;
use Firefly\Eda\Rabbitmq\OpensConnection;
use Firefly\Eda\Rabbitmq\RabbitMqHealthIndicator;

it('reports DOWN when the connection factory throws', function () {
    $factory = new class implements OpensConnection
    {
        public function connect(): never
        {
            throw new RuntimeException('connection refused');
        }
    };

    $health = (new RabbitMqHealthIndicator($factory))->health();

    expect($health->status)->toBe(Status::Down)
        ->and($health->details)->toHaveKey('error');
});
