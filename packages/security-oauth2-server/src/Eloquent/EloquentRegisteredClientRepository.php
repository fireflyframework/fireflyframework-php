<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use Firefly\Security\OAuth2\Server\Client\ClientSettings;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Client\TokenSettings;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

/**
 * The `eloquent` client driver: RegisteredClient ↔ oauth2_registered_clients, through the framework's own
 * EloquentRepository. A row is validated on the way OUT through the same rules a config block meets — the
 * per-field parsers (methods, grants, the two settings maps) and then RegisteredClientFactory::assertConsistent()
 * for the rules that span fields — so a hand-edited or migrated row with an unknown grant, a plain-text secret or
 * a relative redirect URI is refused where it is read, naming the client, exactly as the config map would be
 * refused at boot. The refusal reaches every read path: findById(), findByClientId() and all().
 */
final class EloquentRegisteredClientRepository implements RegisteredClientRepository
{
    public function __construct(
        private readonly RegisteredClientModelRepository $models,
        private readonly AuthorizationServerSettings $settings,
    ) {}

    public function findById(string $id): ?RegisteredClient
    {
        $row = $this->models->findById($id);

        return $row instanceof RegisteredClientModel ? self::map($row, $this->settings) : null;
    }

    public function findByClientId(string $clientId): ?RegisteredClient
    {
        $row = $this->models->findFirstByClientId($clientId);

        return $row === null ? null : self::map($row, $this->settings);
    }

    public function save(RegisteredClient $client): void
    {
        $existing = $this->models->findById($client->id);
        $model = $existing instanceof RegisteredClientModel ? $existing : new RegisteredClientModel;
        $model->fill(self::row($client));
        $this->models->save($model);
    }

    public function all(): array
    {
        return array_map(fn (RegisteredClientModel $row): RegisteredClient => self::map($row, $this->settings), $this->models->findAll());
    }

    public static function map(RegisteredClientModel $row, AuthorizationServerSettings $settings): RegisteredClient
    {
        $id = self::text($row, 'id');
        $secret = self::text($row, 'client_secret');

        return RegisteredClientFactory::assertConsistent(new RegisteredClient(
            id: $id,
            clientId: self::text($row, 'client_id'),
            clientIdIssuedAt: OAuth2ServerSchema::parseInstant($row->getAttribute('client_id_issued_at')),
            clientSecret: $secret === '' ? null : $secret,
            clientSecretExpiresAt: OAuth2ServerSchema::parseInstant($row->getAttribute('client_secret_expires_at')),
            clientName: self::text($row, 'client_name'),
            clientAuthenticationMethods: RegisteredClientFactory::methods(self::list(self::text($row, 'client_authentication_methods')), $id),
            authorizationGrantTypes: RegisteredClientFactory::grants(self::list(self::text($row, 'authorization_grant_types')), $id),
            redirectUris: self::list(self::text($row, 'redirect_uris')),
            postLogoutRedirectUris: self::list(self::text($row, 'post_logout_redirect_uris')),
            scopes: self::list(self::text($row, 'scopes')),
            clientSettings: ClientSettings::fromArray(self::json(self::text($row, 'client_settings')), $settings->consentRequired, $id),
            tokenSettings: TokenSettings::fromArray(self::json(self::text($row, 'token_settings')), $settings, $id),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public static function row(RegisteredClient $client): array
    {
        return [
            'id' => $client->id,
            'client_id' => $client->clientId,
            'client_id_issued_at' => OAuth2ServerSchema::instant($client->clientIdIssuedAt),
            'client_secret' => $client->clientSecret,
            'client_secret_expires_at' => OAuth2ServerSchema::instant($client->clientSecretExpiresAt),
            'client_name' => $client->clientName,
            'client_authentication_methods' => implode(' ', array_map(static fn ($m): string => $m->value, $client->clientAuthenticationMethods)),
            'authorization_grant_types' => implode(' ', array_map(static fn ($g): string => $g->value, $client->authorizationGrantTypes)),
            'redirect_uris' => implode(' ', $client->redirectUris),
            'post_logout_redirect_uris' => implode(' ', $client->postLogoutRedirectUris),
            'scopes' => implode(' ', $client->scopes),
            'client_settings' => json_encode($client->clientSettings->toArray(), JSON_THROW_ON_ERROR),
            'token_settings' => json_encode($client->tokenSettings->toArray(), JSON_THROW_ON_ERROR),
        ];
    }

    /** A text column as the string it holds: every column of this table is written as a string, and a NULL reads as ''. */
    private static function text(RegisteredClientModel $row, string $column): string
    {
        $value = $row->getAttribute($column);

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function list(string $joined): array
    {
        return $joined === '' ? [] : array_values(array_filter(explode(' ', $joined), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return array<string,mixed>
     */
    private static function json(string $text): array
    {
        $decoded = $text === '' ? [] : json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string,mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
