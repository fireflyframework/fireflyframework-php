<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Data\DataSettings;
use Illuminate\Config\Repository;

it('defaults to translation on, no default timeout, statement timeouts on, transactional listeners on, paged projections on', function () {
    $settings = DataSettings::fromConfig(new Config(new Repository(['firefly' => []])));

    expect($settings->exceptionTranslation)->toBeTrue()
        ->and($settings->defaultTimeout)->toBe(0)
        ->and($settings->statementTimeout)->toBeTrue()
        ->and($settings->transactionalEventListeners)->toBeTrue()
        ->and($settings->pagedProjections)->toBeTrue();
});

it('reads every firefly.data key and clamps a negative timeout to none', function () {
    $settings = DataSettings::fromConfig(new Config(new Repository(['firefly' => ['data' => [
        'exception-translation' => ['enabled' => false],
        'transaction' => ['default-timeout' => -5, 'statement-timeout' => false],
        'transactional-event-listeners' => ['enabled' => false],
        'projection' => ['pageable' => false],
    ]]])));

    expect($settings->exceptionTranslation)->toBeFalse()
        ->and($settings->defaultTimeout)->toBe(0)
        ->and($settings->statementTimeout)->toBeFalse()
        ->and($settings->transactionalEventListeners)->toBeFalse()
        ->and($settings->pagedProjections)->toBeFalse()
        ->and(DataSettings::fromConfig(new Config(new Repository(['firefly' => ['data' => ['transaction' => ['default-timeout' => '30']]]])))->defaultTimeout)->toBe(30);
});
