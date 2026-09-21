<?php

declare(strict_types=1);

namespace Firefly\Security\User;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Eloquent\Model;

/**
 * `firefly.security.users`: which store answers loadUserByUsername(), and how.
 *
 * THE MAP IS STILL THE MAP. The memory driver's shape — `users => {username: {password, authorities, enabled,
 * locked}}` — is unchanged and remains the default; `driver` and the eloquent settings live in the same block
 * as RESERVED keys, stripped before the map is read as accounts. A username that collides with a reserved
 * word would be one of `driver`, `model`, `username_column`, `password_column`, `enabled_column`,
 * `locked_column` or `authorities`, none of which is an email address.
 *
 * `authorities` is either a column holding a JSON list (or an `array` cast) or `relation.attribute` — a
 * relation to pluck the attribute from, for a roles table. An empty `enabled_column`/`locked_column` means
 * the model has no such flag: every account is enabled, none is locked. An empty `authorities` means the
 * model carries no authorities: every account authenticates with none (`[]`, the memory driver's default
 * for the same optional key). That last one is what Laravel's stock `users` table needs — `id`, `name`,
 * `email`, `password`, no authorities column — because the default `authorities` names a column the driver
 * refuses on the lookup when the row does not carry it. The empty string is the only opt-out: a name that
 * is configured and missing is a typo, and is refused rather than read as NULL.
 *
 * fromConfig() is where the refusals live — an unknown driver, an eloquent driver naming no model, a model
 * class that does not exist or is not an Eloquent model — and SecurityWiringPass resolves the UserDetailsService
 * bean at boot precisely so those refusals are startup failures rather than the first login's 500. The
 * Model check belongs here and not in the service's lookup: class_exists() has already autoloaded the class,
 * so asking is_a() at boot costs nothing, and a class that exists but is no model (a repository, a DTO, the
 * wrong ::class in a copied line) would otherwise boot cleanly and refuse on the first login attempt — the
 * one outcome the eager resolution exists to prevent.
 */
final readonly class UserStoreSettings
{
    public const array RESERVED = ['driver', 'model', 'username_column', 'password_column', 'enabled_column', 'locked_column', 'authorities'];

    /**
     * @param  array<string, array{password: string, authorities?: list<string>, enabled?: bool, locked?: bool}>  $accounts
     */
    public function __construct(
        public string $driver,
        public array $accounts,
        public ?string $model,
        public string $usernameColumn,
        public string $passwordColumn,
        public string $enabledColumn,
        public string $lockedColumn,
        public string $authorities,
    ) {}

    public static function fromConfig(Config $config): self
    {
        /** @var array<string, mixed> $block */
        $block = $config->has('firefly.security.users') ? $config->array('firefly.security.users') : [];

        $driver = $config->string('firefly.security.users.driver', 'memory');
        if (! in_array($driver, ['memory', 'eloquent'], true)) {
            throw new ConfigurationException("firefly.security.users.driver must be `memory` or `eloquent`; got `{$driver}`.");
        }

        $model = $config->string('firefly.security.users.model', '');
        if ($driver === 'eloquent') {
            if ($model === '') {
                throw new ConfigurationException('firefly.security.users.driver is `eloquent` but firefly.security.users.model names no model class.');
            }
            if (! class_exists($model)) {
                throw new ConfigurationException("Refusing to boot: firefly.security.users.model names {$model}, which does not exist.");
            }
            if (! is_a($model, Model::class, true)) {
                throw new ConfigurationException("Refusing to boot: firefly.security.users.model names {$model}, which is not an Eloquent model.");
            }
        }

        /** @var array<string, array{password: string, authorities?: list<string>, enabled?: bool, locked?: bool}> $accounts */
        $accounts = array_diff_key($block, array_fill_keys(self::RESERVED, true));

        return new self(
            driver: $driver,
            accounts: $accounts,
            model: $model === '' ? null : $model,
            usernameColumn: $config->string('firefly.security.users.username_column', 'email'),
            passwordColumn: $config->string('firefly.security.users.password_column', 'password'),
            enabledColumn: $config->string('firefly.security.users.enabled_column', ''),
            lockedColumn: $config->string('firefly.security.users.locked_column', ''),
            authorities: $config->string('firefly.security.users.authorities', 'authorities'),
        );
    }
}
