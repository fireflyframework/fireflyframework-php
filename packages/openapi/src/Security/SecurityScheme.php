<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Security;

/**
 * One entry of `components.securitySchemes`: the NAME an operation's requirement refers to, and the Security
 * Scheme Object itself as a plain array.
 *
 * The definition is an array rather than a typed object on purpose. OpenAPI's Security Scheme Object is a
 * union of five unrelated shapes (`apiKey`, `http`, `mutualTLS`, `oauth2`, `openIdConnect`), four of which
 * this framework has no way of producing today and one of which — `oauth2` — nests a Flows Object with four
 * more variants inside it. A class hierarchy over that union would be five classes and a factory to express
 * what is, at the point of use, a literal the generator hands to json_encode. The contributors build the
 * shape the specification states; this type carries it and its name together so the model can merge two
 * contributors without either having to know the other exists.
 *
 * `scopes` is the DEFAULT scope list a requirement naming this scheme carries when its author had nothing
 * more specific to say — the registered client's scopes, for the authorization-code scheme firefly/
 * security-oauth2-server contributes. It is deliberately not part of the definition: the Scheme Object
 * declares which scopes EXIST, a Security Requirement Object declares which ones an operation NEEDS, and
 * conflating the two is how a document ends up demanding every scope on every path.
 */
final readonly class SecurityScheme
{
    /**
     * @param  array<string, mixed>  $definition  the Security Scheme Object
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $name,
        public array $definition,
        public array $scopes = [],
    ) {}
}
