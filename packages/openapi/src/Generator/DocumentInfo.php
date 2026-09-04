<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Generator;

use Firefly\Config\Config;

/**
 * The optional half of the Info Object — the members OpenApiProperties does not already carry.
 *
 * `title`, `version` and `description` were there from the first release because a document is invalid
 * without the first two and unreadable without the third. The four members here are the rest of what OpenAPI
 * 3.1 actually defines on the Info Object, and NOTHING BEYOND IT: the specification's list is
 * title, summary, description, termsOfService, contact, license, version — full stop. There is no `author`,
 * no `repository`, no `logo` (that is ReDoc's `x-logo` extension, not a spec member), and inventing one here
 * would emit a document that a strict 3.1 validator rejects on a field nobody asked for.
 *
 * `summary` is genuinely NEW in 3.1 — 3.0 had no such member — and it is not a shorter `description`: it is
 * the one-line blurb a catalogue or an API portal shows next to the title, where a CommonMark description
 * would be unusable.
 *
 * THE LICENSE OBJECT HAS A CONSTRAINT WORTH SPELLING OUT. `name` is REQUIRED, and `identifier` (an SPDX
 * expression, also new in 3.1) and `url` are MUTUALLY EXCLUSIVE — the spec says an implementation must use
 * one or the other, never both. Configuring both is a mistake nobody would ever see at runtime, so it is
 * resolved here rather than passed through: the SPDX identifier wins, because it is the machine-readable,
 * unambiguous statement of the same fact a URL only points at. A license with no name is dropped entirely,
 * since a License Object without one is invalid and half a license is worse than none.
 *
 * The Contact Object has no required member at all, so it is emitted whenever any of name/url/email is set
 * and omitted when none is.
 */
final readonly class DocumentInfo
{
    /**
     * @param  array<string, string>  $contact  any of `name`, `url`, `email`; absent keys are simply not set
     * @param  array<string, string>  $license  any of `name`, `identifier`, `url` — see the class docblock
     *                                          for why the last two cannot both survive
     */
    public function __construct(
        public string $summary = '',
        public string $termsOfService = '',
        public array $contact = [],
        public array $license = [],
    ) {}

    /**
     * Reads `firefly.openapi.summary`, `.terms-of-service`, `.contact.*` and `.license.*`, in the kebab-case
     * key style the rest of this package's configuration uses (`include-html`, `viewer.cdn`).
     *
     * Every key is optional and defaults to the empty string, so an application that has never heard of these
     * options produces exactly the document it produced before — an empty value is treated as "not
     * configured", never emitted as an empty member.
     */
    public static function fromConfig(Config $config): self
    {
        return new self(
            summary: trim($config->string('firefly.openapi.summary', '')),
            termsOfService: trim($config->string('firefly.openapi.terms-of-service', '')),
            contact: self::members($config, 'contact', ['name', 'url', 'email']),
            license: self::members($config, 'license', ['name', 'identifier', 'url']),
        );
    }

    /**
     * Layers these members onto an Info Object already carrying title/version/description, in the field order
     * the specification itself lists them in.
     *
     * The order is rebuilt rather than appended to because JSON object key order is the only thing a reader
     * of the generated file sees, and `title, version, description, summary` reads as an afterthought where
     * `title, summary, description, ..., version` reads as the spec's own table. Nothing consumes the order
     * semantically; a human diffing a committed openapi.json does.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    public function applyTo(array $info): array
    {
        $ordered = [];

        foreach (['title', 'summary', 'description', 'termsOfService', 'contact', 'license', 'version'] as $member) {
            $value = match ($member) {
                'summary' => $this->summary === '' ? null : $this->summary,
                'termsOfService' => $this->termsOfService === '' ? null : $this->termsOfService,
                'contact' => $this->contact === [] ? null : $this->contact,
                'license' => $this->licenseObject(),
                default => $info[$member] ?? null,
            };

            if ($value !== null) {
                $ordered[$member] = $value;
            }
        }

        // Anything an override bean put into `info` that is not a spec member survives, after the spec ones.
        // Specification extensions (`x-…`) are explicitly permitted on the Info Object, and silently eating
        // one would be a surprising thing for a reordering pass to do.
        foreach ($info as $member => $value) {
            $ordered[$member] ??= $value;
        }

        return $ordered;
    }

    /**
     * @return array{name: string, identifier?: string, url?: string}|null
     */
    private function licenseObject(): ?array
    {
        $name = $this->license['name'] ?? '';
        if ($name === '') {
            return null;
        }

        $identifier = $this->license['identifier'] ?? '';
        if ($identifier !== '') {
            return ['name' => $name, 'identifier' => $identifier];
        }

        $url = $this->license['url'] ?? '';

        return $url === '' ? ['name' => $name] : ['name' => $name, 'url' => $url];
    }

    /**
     * @param  list<string>  $members
     * @return array<string, string>
     */
    private static function members(Config $config, string $object, array $members): array
    {
        $values = [];

        foreach ($members as $member) {
            $value = trim($config->string('firefly.openapi.'.$object.'.'.$member, ''));

            if ($value !== '') {
                $values[$member] = $value;
            }
        }

        return $values;
    }
}
