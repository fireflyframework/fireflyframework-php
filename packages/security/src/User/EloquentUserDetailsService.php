<?php

declare(strict_types=1);

namespace Firefly\Security\User;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\GrantedAuthority;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Database\Eloquent\Model;

/**
 * A UserDetailsService over any Eloquent model (Spring's JdbcDaoImpl, on the ORM the application already
 * has). One query per lookup — `where(username_column, ?)->first()` — and the row is mapped to the immutable
 * User value object rather than handed out as the model: the principal ends up in the session and in event
 * objects, and a model there drags its connection, its relations and its attributes along. An absent row is
 * the UsernameNotFoundException DaoAuthenticationProvider equalises against a wrong password.
 *
 * The expected schema (every name configurable under firefly.security.users):
 *   email       the username (unique)
 *   password    the ENCODED password, `{id}`-prefixed for the delegating encoder (`{bcrypt}$2y$…`)
 *   enabled     boolean, optional (enabled_column; empty means every account is enabled)
 *   locked      boolean, optional (locked_column; empty means no account is locked)
 *   authorities a JSON list of strings — `["ROLE_USER", "orders:read"]` — or a relation (`roles.name`)
 *
 * Construction never touches the database, so SecurityWiringPass can resolve the bean at boot for its
 * settings' refusals without a connection being configured yet.
 */
final class EloquentUserDetailsService implements UserDetailsService
{
    public function __construct(private readonly UserStoreSettings $settings) {}

    public function loadUserByUsername(string $username): UserDetails
    {
        $model = $this->settings->model ?? throw new ConfigurationException('The eloquent users driver has no model class.');
        if (! is_a($model, Model::class, true)) {
            throw new ConfigurationException("firefly.security.users.model names {$model}, which is not an Eloquent model.");
        }

        $row = $model::query()->where($this->settings->usernameColumn, $username)->first();
        if (! $row instanceof Model) {
            throw new UsernameNotFoundException("No user found for username [{$username}].");
        }

        return new User(
            $this->text($row, $this->settings->usernameColumn),
            $this->text($row, $this->settings->passwordColumn),
            $this->authorities($row),
            $this->settings->enabledColumn === '' || (bool) $row->getAttribute($this->settings->enabledColumn),
            $this->settings->lockedColumn === '' || ! (bool) $row->getAttribute($this->settings->lockedColumn),
        );
    }

    /**
     * A username or password column as the string the User carries. A string is itself; an integer or float
     * (an employee number as the username) is its decimal form; NULL is the empty string — for the password
     * that is an account with no password set, which no encoder matches, and the DelegatingPasswordEncoder
     * refuses an unprefixed hash outright. Anything else (an array cast, an object) names a column that
     * cannot be the username or the password, which is a configuration mistake, not a user to refuse.
     */
    private function text(Model $row, string $column): string
    {
        /** @var mixed $value */
        $value = $row->getAttribute($column);

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value === null => '',
            default => throw new ConfigurationException(
                "firefly.security.users: column `{$column}` of ".$row::class.' holds '.get_debug_type($value).', not a string.',
            ),
        };
    }

    /**
     * The authorities of a row: a JSON column decoded (or an `array` cast read as-is), or — when the setting
     * is `relation.attribute` — that attribute plucked from every related model. Anything that is not a
     * non-empty string is dropped rather than turned into an authority nobody configured.
     *
     * @return list<GrantedAuthority>
     */
    private function authorities(Model $row): array
    {
        $source = $this->settings->authorities;

        if (str_contains($source, '.')) {
            [$relation, $attribute] = explode('.', $source, 2);
            /** @var mixed $related */
            $related = $row->getAttribute($relation);
            $values = is_iterable($related) ? array_map(
                static fn (mixed $item): mixed => $item instanceof Model ? $item->getAttribute($attribute) : (is_array($item) ? ($item[$attribute] ?? null) : null),
                is_array($related) ? $related : iterator_to_array($related, false),
            ) : [];
        } else {
            /** @var mixed $values */
            $values = $row->getAttribute($source);
            if (is_string($values)) {
                /** @var mixed $values */
                $values = json_decode($values, true);
            }
        }

        $authorities = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && $value !== '') {
                $authorities[] = new SimpleGrantedAuthority($value);
            }
        }

        return $authorities;
    }
}
