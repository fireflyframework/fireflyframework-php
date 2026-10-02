<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Context\ApplicationEvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Tests\Support\ProfileVariables;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

it('reads an int or a Stringable explicit targeting key as text, and refuses any other type with a DEBUG line', function (): void {
    $logger = new RecordingLogger;
    $principal = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->setTargetingKey('ada');
        }
    };
    $resolver = new EvaluationContextResolver([$principal], $logger);
    $user = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'grace';
        }
    };
    $broken = new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('no id yet');
        }
    };

    expect($resolver->resolve(['targetingKey' => 42])->getTargetingKey())->toBe('42')
        ->and($resolver->resolve(['targetingKey' => 42], ambient: false)->getTargetingKey())->toBe('42')
        ->and($resolver->resolve(['targetingKey' => $user])->getTargetingKey())->toBe('grace')
        ->and($resolver->resolve(['targetingKey' => $user], ambient: false)->getTargetingKey())->toBe('grace')
        ->and($resolver->resolve(['targetingKey' => 42], 'linus')->getTargetingKey())->toBe('linus')
        ->and($resolver->resolve(['targetingKey' => 42])->getAttributes()->toArray())->toBe([])
        ->and($logger->lines)->toBe([]);

    $float = $resolver->resolve(['targetingKey' => 4.2]);
    $preview = $resolver->resolve(['targetingKey' => true], ambient: false);
    $failing = $resolver->resolve(['targetingKey' => $broken]);

    expect($float->getTargetingKey())->toBe('ada')
        ->and($float->getAttributes()->toArray())->toBe([])
        ->and($preview->getTargetingKey())->toBeNull()
        ->and($failing->getTargetingKey())->toBe('ada')
        ->and($logger->count('debug', 'targetingKey of type float was refused'))->toBe(1)
        ->and($logger->count('debug', 'targetingKey of type bool was refused'))->toBe(1)
        ->and($logger->count('debug', 'no id yet'))->toBe(1)
        ->and($logger->lines)->toHaveCount(3);
});

it('logs one DEBUG line for each attribute it drops, naming it and why', function (): void {
    $logger = new RecordingLogger;
    $tier = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'gold';
        }
    };
    $nested = new stdClass;
    $deep = [new stdClass, (object) ['x', 'y']];
    $explicit = [
        2024 => 'leap',
        'type' => FlagType::Boolean,
        'logger' => new RecordingLogger,
        'empty' => new stdClass,
        'listy' => (object) ['a', 'b'],
        'tier' => $tier,
        'address' => (object) ['city' => 'Madrid', 'tags' => $nested],
        'deep' => $deep,
    ];

    $attributes = (new EvaluationContextResolver([], $logger))->resolve($explicit)->getAttributes()->toArray();

    // A JSON object at the top level is kept in Json's faithful form (an array) when that form exists; `{}` and a
    // list-like object have none there (as arrays they would read as lists). Below the top level nothing changes.
    expect($attributes)->toBe(['tier' => 'gold', 'address' => ['city' => 'Madrid', 'tags' => $nested], 'deep' => $deep])
        ->and($logger->lines)->toHaveCount(5)
        ->and($logger->count('debug', 'attribute [2024] was dropped: its name is numeric-looking'))->toBe(1)
        ->and($logger->count('debug', 'attribute [type] was dropped: an enum'))->toBe(1)
        ->and($logger->count('debug', 'attribute [logger] was dropped: an object of class '.RecordingLogger::class))->toBe(1)
        ->and($logger->count('debug', 'attribute [empty] was dropped: an empty or list-like JSON object'))->toBe(1)
        ->and($logger->count('debug', 'attribute [listy] was dropped: an empty or list-like JSON object'))->toBe(1);
});

it('never reads an Eloquent model or a collection as its JSON text, and still reads a UUID as its string', function (): void {
    $logger = new RecordingLogger;
    $user = new class extends Model {};
    $user->forceFill(['id' => 42, 'plan' => 'pro']);
    $teams = new Collection(['core', 'ops']);
    $uuid = Str::uuid();
    $principal = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->setTargetingKey('ada');
        }
    };
    $resolver = new EvaluationContextResolver([$principal], $logger);

    // Both are Stringable (their JSON text), which a targeting key or an attribute would otherwise be read as.
    expect((string) $user)->toBe('{"id":42,"plan":"pro"}')
        ->and((string) $teams)->toBe('["core","ops"]');

    $byModel = $resolver->resolve(['targetingKey' => $user]);
    $byCollection = $resolver->resolve(['targetingKey' => $teams], ambient: false);
    $byUuid = $resolver->resolve(['targetingKey' => $uuid, 'user' => $user, 'teams' => $teams, 'session' => $uuid]);

    expect($byModel->getTargetingKey())->toBe('ada')
        ->and($byCollection->getTargetingKey())->toBeNull()
        ->and($byUuid->getTargetingKey())->toBe($uuid->toString())
        ->and($byUuid->getAttributes()->toArray())->toBe(['session' => $uuid->toString()])
        ->and($logger->lines)->toHaveCount(4)
        ->and($logger->count('debug', 'targetingKey of type '.get_debug_type($user).' was refused (an Eloquent model or a collection'))->toBe(1)
        ->and($logger->count('debug', 'targetingKey of type '.Collection::class.' was refused (an Eloquent model or a collection'))->toBe(1)
        ->and($logger->count('debug', 'pass $user->id'))->toBe(2)
        ->and($logger->count('debug', 'attribute [user] was dropped: an Eloquent model or a collection'))->toBe(1)
        ->and($logger->count('debug', 'attribute [teams] was dropped: an Eloquent model or a collection'))->toBe(1);
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
