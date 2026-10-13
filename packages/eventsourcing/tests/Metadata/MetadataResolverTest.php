<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Metadata\AuthenticatedUserMetadataContributor;
use Firefly\EventSourcing\Metadata\CorrelationIdMetadataContributor;
use Firefly\EventSourcing\Metadata\EventMetadataContributor;
use Firefly\EventSourcing\Metadata\MetadataResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\Repository as ContextRepository;

it('runs the contributors in order and lets the metadata a caller passes win', function (): void {
    $context = new ContextRepository(new Dispatcher);
    $context->add('firefly.correlation_id', 'corr-request');
    $tenant = new class implements EventMetadataContributor
    {
        public function contribute(EventMetadata $metadata): EventMetadata
        {
            return $metadata->withTenantId('acme');
        }
    };
    $resolver = new MetadataResolver([new CorrelationIdMetadataContributor($context), $tenant]);

    expect($resolver->resolve())->toEqual(new EventMetadata('corr-request', tenantId: 'acme'))
        ->and($resolver->resolve(new EventMetadata(causationId: 'evt-1', extra: ['x-source' => 'import'])))->toEqual(new EventMetadata('corr-request', 'evt-1', 'acme', null, ['x-source' => 'import']))
        ->and((new MetadataResolver([new CorrelationIdMetadataContributor(null)]))->resolve())->toEqual(new EventMetadata);
});

it('stamps the authenticated user of the default guard and adds nothing without one', function (): void {
    $guard = new class implements Guard
    {
        public int|string|null $id = null;

        public bool $broken = false;

        public function check(): bool
        {
            return $this->id !== null;
        }

        public function guest(): bool
        {
            return $this->id === null;
        }

        public function user(): ?Authenticatable
        {
            return null;
        }

        public function id(): int|string|null
        {
            return $this->id;
        }

        /** @param  array<string, mixed>  $credentials */
        public function validate(array $credentials = []): bool
        {
            return false;
        }

        public function hasUser(): bool
        {
            if ($this->broken) {
                throw new RuntimeException('no session store');
            }

            return $this->id !== null;
        }

        public function setUser(Authenticatable $user): static
        {
            return $this;
        }
    };
    $auth = new class($guard) implements Factory
    {
        public function __construct(private readonly Guard $guard) {}

        public function guard($name = null): Guard
        {
            return $this->guard;
        }

        public function shouldUse($name): void {}
    };
    $contributor = new AuthenticatedUserMetadataContributor($auth);

    expect($contributor->contribute(new EventMetadata))->toEqual(new EventMetadata);

    $guard->id = 42;
    expect($contributor->contribute(new EventMetadata))->toEqual(new EventMetadata(userId: '42'))
        ->and($contributor->contribute(new EventMetadata(userId: 'import-bot')))->toEqual(new EventMetadata(userId: 'import-bot'));

    $guard->broken = true;
    expect($contributor->contribute(new EventMetadata('corr-1')))->toEqual(new EventMetadata('corr-1'));
});
