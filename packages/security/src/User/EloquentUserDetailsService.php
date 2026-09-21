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
 * A CONFIGURED NAME THE ROW DOES NOT CARRY IS REFUSED, NOT READ AS NULL. Model::getAttribute() answers null
 * for a column the row has no attribute for (Laravel's default; preventAccessingMissingAttributes() is
 * opt-in), and null is the wrong answer in every position here — for `locked_column` it is the dangerous
 * one: `is_locked` typed where the column is `locked` would make `! (bool) null` true for every row and
 * switch account lockout off with no error anywhere. `authorities` would lose every role, `enabled_column`
 * would disable every account (loud, but for the wrong reason), and the username or password would read as
 * the empty string. So every column read goes through attribute(), which asks Model::hasAttribute() — raw
 * attributes, casts and accessors alike — and names the setting and the column in a ConfigurationException
 * when the row has no such attribute; the first segment of `relation.attribute` is checked the same way,
 * as an attribute or a Model::isRelation(). Text of the wrong type gets the same treatment in text(): a
 * column of the wrong shape is a configuration mistake, not a user to refuse.
 *
 * Construction never touches the database, so SecurityWiringPass can resolve the bean at boot for its
 * settings' refusals without a connection being configured yet; the Model check on the class is
 * UserStoreSettings::fromConfig()'s (a boot refusal) and repeated here only for a service constructed by
 * hand around settings that never went through fromConfig().
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
            $this->text($row, $this->settings->usernameColumn, 'username_column'),
            $this->text($row, $this->settings->passwordColumn, 'password_column'),
            $this->authorities($row),
            $this->settings->enabledColumn === '' || (bool) $this->attribute($row, $this->settings->enabledColumn, 'enabled_column'),
            $this->settings->lockedColumn === '' || ! (bool) $this->attribute($row, $this->settings->lockedColumn, 'locked_column'),
        );
    }

    /**
     * A configured column of the row, refused when the row carries no such attribute. Model::hasAttribute()
     * is true for a selected column (present in the attributes array even when its value is NULL), a cast
     * and an accessor, so a NULL password still reads as NULL here and only a name the model has nothing
     * for — a typo, a column of another table, a migration not yet run — is the ConfigurationException.
     */
    private function attribute(Model $row, string $column, string $setting): mixed
    {
        if (! $row->hasAttribute($column)) {
            throw new ConfigurationException(
                "firefly.security.users.{$setting} names `{$column}`, but ".$row::class.' carries no such attribute.',
            );
        }

        return $row->getAttribute($column);
    }

    /**
     * A username or password column as the string the User carries. A string is itself; an integer or float
     * (an employee number as the username) is its decimal form; NULL is the empty string — for the password
     * that is an account with no password set, which no encoder matches, and the DelegatingPasswordEncoder
     * refuses an unprefixed hash outright. Anything else (an array cast, an object) names a column that
     * cannot be the username or the password, which is a configuration mistake, not a user to refuse.
     */
    private function text(Model $row, string $column, string $setting): string
    {
        /** @var mixed $value */
        $value = $this->attribute($row, $column, $setting);

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
     * is `relation.attribute` — that attribute plucked from every related model (a single related model, a
     * belongsTo role, contributes its one attribute). Anything that is not a non-empty string is dropped
     * rather than turned into an authority nobody configured. A relation the model does not define, or a
     * column the row does not carry, is refused for the reason the class docblock gives: `[]` for every user
     * would be the silent loss of every role.
     *
     * @return list<GrantedAuthority>
     */
    private function authorities(Model $row): array
    {
        $source = $this->settings->authorities;

        if (str_contains($source, '.')) {
            [$relation, $attribute] = explode('.', $source, 2);
            // Model::getAttribute() reads an attribute first and a relation second; refused only when the
            // name is neither (a cast column of `{name: …}` objects is a legitimate first segment too).
            if (! $row->hasAttribute($relation) && ! $row->relationLoaded($relation) && ! $row->isRelation($relation)) {
                throw new ConfigurationException(
                    "firefly.security.users.authorities names `{$relation}`, but ".$row::class.' defines no such relation or attribute.',
                );
            }
            /** @var mixed $related */
            $related = $row->getAttribute($relation);
            $related = $related instanceof Model ? [$related] : $related;
            $values = is_iterable($related) ? array_map(
                static fn (mixed $item): mixed => $item instanceof Model ? $item->getAttribute($attribute) : (is_array($item) ? ($item[$attribute] ?? null) : null),
                is_array($related) ? $related : iterator_to_array($related, false),
            ) : [];
        } else {
            /** @var mixed $values */
            $values = $this->attribute($row, $source, 'authorities');
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
