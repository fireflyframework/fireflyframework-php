<?php

declare(strict_types=1);

use Firefly\Admin\Data\DatasourceReport;
use Firefly\Data\DataSettings;

it('reports the data layer\'s settings when firefly/data is booted, and nothing when it is not', function () {
    $with = new DatasourceReport(null, null, ['default' => 'x', 'connections' => []], true, new DataSettings(exceptionTranslation: false, defaultTimeout: 30, statementTimeout: false, transactionalEventListeners: true));
    $without = new DatasourceReport(null, null, ['default' => 'x', 'connections' => []]);

    expect($with->dataLayer())->toBe([
        'exceptionTranslation' => false,
        'defaultTimeout' => 30,
        'statementTimeout' => false,
        'transactionalEventListeners' => true,
    ])
        ->and($without->dataLayer())->toBeNull();
});
