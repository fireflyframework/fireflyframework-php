<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Context\ApplicationEvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Tests\Support\ProfileVariables;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Illuminate\Config\Repository;

function featureFlagsApplicationContributor(): ApplicationEvaluationContextContributor
{
    return new ApplicationEvaluationContextContributor(new Repository([
        'app' => ['name' => 'orders', 'env' => 'staging'],
        'firefly' => ['profiles' => ['active' => 'staging,eu']],
    ]));
}

it('adds the application name and the active profiles', function (): void {
    ProfileVariables::absent(function (): void {
        $context = (new EvaluationContextResolver([featureFlagsApplicationContributor()]))->ambient();
        $unnamed = (new EvaluationContextResolver([new ApplicationEvaluationContextContributor(new Repository([
            'app' => ['name' => '', 'env' => 'production'],
        ]))]))->ambient();

        expect($context->get('application'))->toBe('orders')
            ->and($context->get('profiles'))->toBe(['staging', 'eu'])
            ->and($context->targetingKey())->toBeNull()
            ->and($unnamed->has('application'))->toBeFalse()
            ->and($unnamed->get('profiles'))->toBe(['production']);
    });
});

it('runs contributors in order and lets the caller win', function (): void {
    $principal = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->setTargetingKey('ada')->set('roles', ['beta'])->set('plan', 'free');
        }
    };
    $plans = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->set('plan', 'pro');
        }
    };

    $resolved = (new EvaluationContextResolver([$principal, $plans]))->resolve(['roles' => ['admin'], 'region' => 'es']);
    $explicitKey = (new EvaluationContextResolver([$principal]))->resolve(['targetingKey' => 'grace']);
    $argumentKey = (new EvaluationContextResolver([$principal]))->resolve(['targetingKey' => 'grace'], 'linus');

    expect($resolved->getTargetingKey())->toBe('ada')
        ->and($resolved->getAttributes()->toArray())->toBe(['roles' => ['admin'], 'plan' => 'pro', 'region' => 'es'])
        ->and($explicitKey->getTargetingKey())->toBe('grace')
        ->and($explicitKey->getAttributes()->toArray())->not->toHaveKey('targetingKey')
        ->and($argumentKey->getTargetingKey())->toBe('linus');
});

it('treats an empty targeting key as none, and a later contributor may remove what an earlier one set', function (): void {
    $anonymous = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->setTargetingKey('')->set('roles', ['beta'])->set('tier', 'gold');
        }
    };
    $scrubber = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->remove('tier');
        }
    };

    $resolver = new EvaluationContextResolver([$anonymous, $scrubber]);

    expect($resolver->resolve()->getTargetingKey())->toBeNull()
        ->and($resolver->resolve(['targetingKey' => ''])->getTargetingKey())->toBeNull()
        ->and($resolver->resolve([], '')->getTargetingKey())->toBeNull()
        ->and($resolver->resolve()->getAttributes()->toArray())->toBe(['roles' => ['beta']]);
});

it('skips a contributor that throws and keeps the others', function (): void {
    $logger = new RecordingLogger;
    $broken = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            throw new RuntimeException('tenant service down');
        }
    };
    $fine = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->set('plan', 'pro');
        }
    };

    $attributes = (new EvaluationContextResolver([$broken, $fine], $logger))->resolve()->getAttributes()->toArray();

    expect($attributes)->toBe(['plan' => 'pro'])
        ->and($logger->count('debug', 'tenant service down'))->toBe(1);
});

it('drops values OpenFeature cannot carry', function (): void {
    expect((new EvaluationContextResolver)->resolve(['ok' => 1, 'object' => new stdClass, 'list' => [1]])->getAttributes()->toArray())
        ->toBe(['ok' => 1, 'list' => [1]]);
});

it('drops names OpenFeature cannot carry: a numeric-looking name is an int key in PHP', function (): void {
    /** @var array<string, mixed> $explicit */
    $explicit = json_decode('{"2024":"leap","plan":"pro","7":"seven"}', true, 512, JSON_THROW_ON_ERROR);

    expect((new EvaluationContextResolver)->resolve($explicit)->getAttributes()->toArray())->toBe(['plan' => 'pro']);
});

it('passes date-times through unconverted, as the DateTime OpenFeature carries', function (): void {
    $signup = new DateTime('2025-01-01T10:00:00.123456+02:00');
    $renewal = new DateTimeImmutable('2025-06-30T23:59:59.999999-05:00');

    $attributes = (new EvaluationContextResolver)->resolve(['signupAt' => $signup, 'renewedAt' => $renewal, 'history' => [$renewal]])->getAttributes()->toArray();
    $renewed = $attributes['renewedAt'] ?? null;

    expect($attributes['signupAt'] ?? null)->toBe($signup)
        ->and($attributes['history'] ?? null)->toBe([$renewal])
        ->and($renewed)->toBeInstanceOf(DateTime::class);
    assert($renewed instanceof DateTime);
    expect($renewed->format('Y-m-d\TH:i:s.uP'))->toBe('2025-06-30T23:59:59.999999-05:00');
});

it('keeps only the process attributes and the caller\'s context when not ambient', function (): void {
    ProfileVariables::absent(function (): void {
        $principal = new class implements EvaluationContextContributor
        {
            public int $runs = 0;

            public function contribute(EvaluationContextBuilder $context): void
            {
                $this->runs++;
                $context->setTargetingKey('ada')->set('roles', ['admin'])->set('tenant', 'acme');
            }
        };
        $resolver = new EvaluationContextResolver([featureFlagsApplicationContributor(), $principal]);

        $preview = $resolver->resolve(['plan' => 'pro'], ambient: false);
        $keyed = $resolver->resolve(['targetingKey' => 'grace', 'application' => 'what-if'], ambient: false);
        $argument = $resolver->resolve([], 'linus', ambient: false);

        expect($preview->getTargetingKey())->toBeNull()
            ->and($preview->getAttributes()->toArray())->toBe(['application' => 'orders', 'profiles' => ['staging', 'eu'], 'plan' => 'pro'])
            ->and($keyed->getTargetingKey())->toBe('grace')
            ->and($keyed->getAttributes()->toArray())->toBe(['application' => 'what-if', 'profiles' => ['staging', 'eu']])
            ->and($argument->getTargetingKey())->toBe('linus')
            ->and($resolver->process()->attributes())->toBe(['application' => 'orders', 'profiles' => ['staging', 'eu']])
            ->and($principal->runs)->toBe(0);

        $ambient = $resolver->resolve();

        expect($ambient->getTargetingKey())->toBe('ada')
            ->and($ambient->getAttributes()->toArray())->toBe(['application' => 'orders', 'profiles' => ['staging', 'eu'], 'roles' => ['admin'], 'tenant' => 'acme'])
            ->and($principal->runs)->toBe(1);
    });
});
