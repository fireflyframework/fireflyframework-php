<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\OpenApi\Generator\DocumentInfo;
use Firefly\OpenApi\Tests\Support\FixtureDocument;
use Illuminate\Config\Repository;

/**
 * The Info Object's optional members, read from config and emitted in the order the OpenAPI 3.1 specification
 * lists them.
 *
 * Every member asserted here is one the spec actually defines — title, summary, description, termsOfService,
 * contact, license, version, and nothing else. The License Object's `identifier`/`url` exclusivity is the
 * only rule in this corner that a document can silently violate, so it gets its own case.
 *
 * @param  array<string, mixed>  $openapi
 */
function documentInfoFrom(array $openapi): DocumentInfo
{
    return DocumentInfo::fromConfig(new Config(new Repository(['firefly' => ['openapi' => $openapi]])));
}

it('emits nothing for an application that configured none of it', function () {
    $info = documentInfoFrom([]);

    expect($info->applyTo(['title' => 'API', 'version' => '1.0.0']))
        ->toBe(['title' => 'API', 'version' => '1.0.0']);
});

it('reads every member OpenAPI 3.1 defines on the Info Object', function () {
    $info = documentInfoFrom([
        'summary' => 'Everything the warehouse exposes.',
        'terms-of-service' => 'https://example.test/terms',
        'contact' => ['name' => 'Platform Team', 'url' => 'https://example.test/support', 'email' => 'api@example.test'],
        'license' => ['name' => 'Apache 2.0', 'url' => 'https://www.apache.org/licenses/LICENSE-2.0'],
    ]);

    expect($info->applyTo(['title' => 'API', 'version' => '1.0.0', 'description' => 'Long form.']))->toBe([
        // Spec field order, which is the only thing a human reading a committed openapi.json sees.
        'title' => 'API',
        'summary' => 'Everything the warehouse exposes.',
        'description' => 'Long form.',
        'termsOfService' => 'https://example.test/terms',
        'contact' => ['name' => 'Platform Team', 'url' => 'https://example.test/support', 'email' => 'api@example.test'],
        'license' => ['name' => 'Apache 2.0', 'url' => 'https://www.apache.org/licenses/LICENSE-2.0'],
        'version' => '1.0.0',
    ]);
});

it('keeps the SPDX identifier and drops the url, because 3.1 says they are mutually exclusive', function () {
    $info = documentInfoFrom([
        'license' => ['name' => 'Apache 2.0', 'identifier' => 'Apache-2.0', 'url' => 'https://www.apache.org/licenses/LICENSE-2.0'],
    ]);

    /** @var array<string, mixed> $applied */
    $applied = $info->applyTo(['title' => 'API', 'version' => '1.0.0']);

    expect($applied['license'])->toBe(['name' => 'Apache 2.0', 'identifier' => 'Apache-2.0']);
});

it('drops a license with no name, because the License Object requires one', function () {
    $info = documentInfoFrom(['license' => ['url' => 'https://example.test/licence']]);

    expect($info->applyTo(['title' => 'API', 'version' => '1.0.0']))->not->toHaveKey('license');
});

it('emits a contact from any single member, since the Contact Object requires none', function () {
    $info = documentInfoFrom(['contact' => ['email' => 'api@example.test']]);

    /** @var array<string, mixed> $applied */
    $applied = $info->applyTo(['title' => 'API', 'version' => '1.0.0']);

    expect($applied['contact'])->toBe(['email' => 'api@example.test']);
});

it('treats a blank configured value as unconfigured rather than emitting an empty member', function () {
    $info = documentInfoFrom([
        'summary' => '   ',
        'terms-of-service' => '',
        'contact' => ['name' => '  '],
    ]);

    expect($info->applyTo(['title' => 'API', 'version' => '1.0.0']))
        ->toBe(['title' => 'API', 'version' => '1.0.0']);
});

it('reaches the generated document when the generator is given one', function () {
    $info = documentInfoFrom([
        'summary' => 'The fixture API, in one line.',
        'contact' => ['email' => 'api@example.test'],
        'license' => ['name' => 'Apache 2.0', 'identifier' => 'Apache-2.0'],
    ]);

    $document = FixtureDocument::generatorFor('DocFixture', info: $info)->generate();

    expect($document['info'])->toBe([
        'title' => 'Orders API',
        'summary' => 'The fixture API, in one line.',
        'description' => 'The fixture API.',
        'contact' => ['email' => 'api@example.test'],
        'license' => ['name' => 'Apache 2.0', 'identifier' => 'Apache-2.0'],
        'version' => '1.2.3',
    ]);
});

it('leaves the document exactly as it was when the generator is given none', function () {
    // The parameter is optional so that every existing three-argument construction keeps producing the
    // document it produced before — including OpenApiAutoConfiguration's #[Bean].
    expect(FixtureDocument::generatorFor('DocFixture')->generate()['info'])
        ->toBe(['title' => 'Orders API', 'version' => '1.2.3', 'description' => 'The fixture API.']);
});
