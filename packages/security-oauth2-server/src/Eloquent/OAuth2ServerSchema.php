<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use DateTimeImmutable;
use DateTimeZone;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three tables of the `eloquent` drivers, as blueprints the shipped migration and every test share (the
 * OutboxSchema idiom). Lists are space-joined strings, settings and metadata JSON text, so the tables work on
 * every driver Laravel supports; token VALUES never enter — only `<type>_hash` (hex SHA-256, indexed) with the
 * issue/expiry instants and the metadata beside it, plus the refresh-token family and an indexed `expires_at`
 * (the latest token expiry) the purge deletes by.
 */
final class OAuth2ServerSchema
{
    public const string CLIENTS = 'oauth2_registered_clients';

    public const string AUTHORIZATIONS = 'oauth2_authorizations';

    public const string CONSENTS = 'oauth2_authorization_consents';

    /** @var list<string> */
    public const array TOKEN_PREFIXES = [
        OAuth2TokenType::AuthorizationCode->value,
        OAuth2TokenType::AccessToken->value,
        OAuth2TokenType::RefreshToken->value,
        OAuth2TokenType::IdToken->value,
    ];

    public static function clients(Blueprint $table): void
    {
        $table->string('id', 100)->primary();
        $table->string('client_id', 100)->unique();
        $table->timestamp('client_id_issued_at')->nullable();
        $table->text('client_secret')->nullable();
        $table->timestamp('client_secret_expires_at')->nullable();
        $table->string('client_name', 200);
        $table->text('client_authentication_methods');
        $table->text('authorization_grant_types');
        $table->text('redirect_uris');
        $table->text('post_logout_redirect_uris');
        $table->text('scopes');
        $table->text('client_settings');
        $table->text('token_settings');
    }

    public static function authorizations(Blueprint $table): void
    {
        $table->string('id', 64)->primary();
        $table->string('registered_client_id', 100)->index();
        $table->string('principal_name', 200)->index();
        $table->string('authorization_grant_type', 50);
        $table->text('authorized_scopes');
        $table->text('attributes');
        foreach (self::TOKEN_PREFIXES as $prefix) {
            $table->string($prefix.'_hash', 64)->nullable()->index();
            $table->timestamp($prefix.'_issued_at')->nullable();
            $table->timestamp($prefix.'_expires_at')->nullable();
            $table->text($prefix.'_metadata')->nullable();
        }
        $table->text('refresh_token_family')->nullable();
        $table->timestamp('expires_at')->nullable()->index();
    }

    public static function consents(Blueprint $table): void
    {
        $table->string('id', 400)->primary();
        $table->string('registered_client_id', 100)->index();
        $table->string('principal_name', 200);
        $table->text('scopes');
    }

    public static function create(): void
    {
        foreach ([self::CLIENTS => self::clients(...), self::AUTHORIZATIONS => self::authorizations(...), self::CONSENTS => self::consents(...)] as $name => $blueprint) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $blueprint);
            }
        }
    }

    public static function drop(): void
    {
        Schema::dropIfExists(self::CONSENTS);
        Schema::dropIfExists(self::AUTHORIZATIONS);
        Schema::dropIfExists(self::CLIENTS);
    }

    /**
     * Every instant is written as UTC `Y-m-d H:i:s` — what a `timestamp` column accepts on every driver Laravel
     * supports, with no offset to be silently dropped — and read back as UTC, so the instant survives whatever
     * the application's timezone is.
     */
    public static function instant(?DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function parseInstant(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }
}
