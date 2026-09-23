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
 * more specific to say — the registered client's scopes, for the authorization-code scheme an
 * authorization-server package would contribute. SecurityModel::resolve() is what applies it, and it applies
 * it in ONE case: a SecurityRequirement that names this scheme and carries no scopes of its own is published
 * with these. A requirement that states its own scopes keeps them; the operation's own statement is the more
 * specific one. Leave the list empty — as every scheme this framework ships does — and no requirement is
 * touched.
 *
 * It is deliberately not part of the definition: the Scheme Object declares which scopes EXIST (an oauth2
 * flow's `scopes` map), a Security Requirement Object declares which ones an operation NEEDS, and conflating
 * the two is how a document ends up demanding every scope on every path. Setting this field is therefore a
 * claim about what a caller NEEDS by default on the operations that name this scheme, not a catalogue of
 * what the scheme can issue — a contributor with a long client scope list almost certainly wants the former
 * to stay empty and to say the specific thing from a SecurityRequirementContributor instead.
 */
final readonly class SecurityScheme
{
    /**
     * @param  array<string, mixed>  $definition  the Security Scheme Object
     * @param  list<string>  $scopes  the default scopes a requirement naming this scheme inherits when it states none
     */
    public function __construct(
        public string $name,
        public array $definition,
        public array $scopes = [],
    ) {}
}
