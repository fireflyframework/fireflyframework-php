<?php

declare(strict_types=1);

namespace Firefly\Security\Password;

/**
 * The `{id}`-prefix multi-encoder (Spring's DelegatingPasswordEncoder). encode() writes `{defaultId}<hash>`;
 * matches() parses the leading `{id}` and delegates to that encoder — an unknown or missing prefix fails
 * closed (returns false, never guesses). upgradeEncoding() is true whenever the stored id differs from the
 * current default, so an app can transparently re-encode on next login.
 */
final class DelegatingPasswordEncoder implements PasswordEncoder
{
    /**
     * @param  array<string,PasswordEncoder>  $encoders  id => encoder
     */
    public function __construct(
        private readonly string $defaultId,
        private readonly array $encoders,
    ) {}

    public function encode(string $rawPassword): string
    {
        return '{'.$this->defaultId.'}'.$this->delegate($this->defaultId)->encode($rawPassword);
    }

    public function matches(string $rawPassword, string $encodedPassword): bool
    {
        [$id, $encoded] = $this->extract($encodedPassword);
        if ($id === null || ! isset($this->encoders[$id])) {
            return false;
        }

        return $this->encoders[$id]->matches($rawPassword, $encoded);
    }

    public function upgradeEncoding(string $encodedPassword): bool
    {
        [$id, $encoded] = $this->extract($encodedPassword);
        if ($id === null || ! isset($this->encoders[$id])) {
            return true;
        }
        if ($id !== $this->defaultId) {
            return true;
        }

        return $this->encoders[$id]->upgradeEncoding($encoded);
    }

    private function delegate(string $id): PasswordEncoder
    {
        return $this->encoders[$id]
            ?? throw new \InvalidArgumentException("No password encoder registered for id [{$id}].");
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function extract(string $encodedPassword): array
    {
        if (! str_starts_with($encodedPassword, '{')) {
            return [null, $encodedPassword];
        }
        $end = strpos($encodedPassword, '}');
        if ($end === false) {
            return [null, $encodedPassword];
        }

        return [substr($encodedPassword, 1, $end - 1), substr($encodedPassword, $end + 1)];
    }
}
