<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Tests\Support\ConformanceFiles;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;

it('resolves each shared context vector through the real context resolver', function (array $case): void {
    $ambientKey = $case['ambientKey'];
    $context = Json::members($case['context']);
    $expected = Json::members($case['expect']);
    if (! is_string($ambientKey)) {
        throw new UnexpectedValueException('A context vector needs an ambient key.');
    }
    $ambient = new class($ambientKey) implements EvaluationContextContributor
    {
        public function __construct(private readonly string $key) {}

        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->setTargetingKey($this->key);
        }
    };
    $logger = new RecordingLogger;
    $resolved = (new EvaluationContextResolver([$ambient], $logger))->resolve($context);

    expect($resolved->getTargetingKey())->toBe($expected['targetingKey'])
        ->and($resolved->getAttributes()->toArray())->toBe(Json::members($expected['attributes']))
        ->and($resolved->getAttributes()->toArray())->not->toHaveKey('targetingKey')
        ->and($logger->count('debug', 'targetingKey of type'))->toBe($expected['refused'] ? 1 : 0);
})->with(static function (): array {
    $document = Json::members(Json::decode((string) file_get_contents(ConformanceFiles::root().'/context-vectors.json')));
    $vectors = $document['cases'] ?? null;
    if (! is_array($vectors)) {
        throw new UnexpectedValueException('Context vectors must contain cases.');
    }
    expect($document['version'])->toBe(1)
        ->and($vectors)->toHaveCount(12);

    $cases = [];
    foreach ($vectors as $case) {
        $case = Json::members($case);
        $name = $case['name'];
        if (! is_string($name)) {
            throw new UnexpectedValueException('A context vector needs a name.');
        }
        $cases[$name] = [$case];
    }

    return $cases;
});
