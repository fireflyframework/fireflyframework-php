<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Source\FileFlagSource;
use Firefly\FeatureFlags\Source\FlagSource;
use Firefly\FeatureFlags\Source\FlagSourceUnavailable;
use Firefly\FeatureFlags\Tests\Support\TemporaryFlagFiles;
use Illuminate\Config\Repository;

function featureFlagsFileSource(string $path): FileFlagSource
{
    return new FileFlagSource(FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => [
        'sources' => ['file' => ['enabled' => true, 'path' => $path, 'refresh-interval' => '5s']],
    ]]]))));
}

/** The InvalidFlagDefinition loading $path raises, or null when it loads. */
function featureFlagsFileRefusal(string $path): ?InvalidFlagDefinition
{
    try {
        featureFlagsFileSource($path)->load(null);
    } catch (InvalidFlagDefinition $caught) {
        return $caught;
    }

    return null;
}

afterEach(function (): void {
    TemporaryFlagFiles::removeAll();
});

it('is the file layer, checked once per refresh interval, that refuses a boot it never loaded', function (): void {
    $source = featureFlagsFileSource('/srv/flags.json');

    expect($source->name())->toBe(FlagSource::FILE)
        ->and($source->precedence())->toBe(200)
        ->and($source->refreshInterval())->toBe(5.0)
        ->and($source->failsStartup())->toBeTrue()
        ->and($source->reportedRevision('1767225600-42'))->toBe('1767225600-42');
});

it('reads a JSON flagd document and keeps {} an object', function (): void {
    $json = '{"flags":{"banner":{"state":"ENABLED","variants":{"none":{},"promo":{"t":"x"}},"defaultVariant":"none"}}}';
    $path = TemporaryFlagFiles::write('json', $json);

    $snapshot = featureFlagsFileSource($path)->load(null);

    expect($snapshot?->document->flag('banner')?->variantValue('none'))->toBeInstanceOf(stdClass::class)
        ->and($snapshot?->revision)->toBe(TemporaryFlagFiles::MTIME.'-'.strlen($json));
});

it('reads the same document written as YAML', function (string $extension): void {
    $path = TemporaryFlagFiles::write($extension, "flags:\n  new-checkout:\n    state: ENABLED\n    variants: {on: true, off: false}\n    defaultVariant: off\n    metadata: {}\n");

    $flag = featureFlagsFileSource($path)->load(null)?->document->flag('new-checkout');

    expect($flag?->defaultVariant())->toBe('off')
        ->and($flag?->toArray()['metadata'] ?? null)->toBeInstanceOf(stdClass::class);
})->with(['yaml', 'yml', 'YAML']);

it('answers null while mtime and size are unchanged and reloads when either moves', function (): void {
    $enabled = '{"flags":{"a":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}}}';
    $disabled = '{"flags":{"a":{"state":"DISABLED","variants":{"on":true},"defaultVariant":"on"}},"metadata":{"v":2}}';
    $path = TemporaryFlagFiles::write('json', $enabled);
    $source = featureFlagsFileSource($path);
    $first = $source->load(null);

    expect($source->load($first?->revision))->toBeNull();

    TemporaryFlagFiles::rewrite($path, $disabled);
    $resized = $source->load($first?->revision);

    expect($resized?->document->flag('a')?->isDisabled())->toBeTrue()
        ->and($resized?->revision)->toBe(TemporaryFlagFiles::MTIME.'-'.strlen($disabled))
        ->and($source->load($resized?->revision))->toBeNull();

    touch($path, TemporaryFlagFiles::MTIME + 10);

    expect($source->load($resized?->revision)?->revision)->toBe((TemporaryFlagFiles::MTIME + 10).'-'.strlen($disabled));
});

it('retries a broken edit on every check: a failed load carries no revision to remember', function (): void {
    $good = '{"flags":{"a":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}}}';
    $fixed = '{"flags":{"a":{"state":"DISABLED","variants":{"on":true},"defaultVariant":"on"}}}';
    $path = TemporaryFlagFiles::write('json', $good);
    $source = featureFlagsFileSource($path);
    $loaded = $source->load(null);

    TemporaryFlagFiles::rewrite($path, '{"flags": {', TemporaryFlagFiles::MTIME + 10);

    expect(fn () => $source->load($loaded?->revision))->toThrow(InvalidFlagDefinition::class, 'not valid JSON')
        ->and(fn () => $source->load($loaded?->revision))->toThrow(InvalidFlagDefinition::class, 'not valid JSON');

    TemporaryFlagFiles::rewrite($path, $fixed, TemporaryFlagFiles::MTIME + 20);

    expect($source->load($loaded?->revision)?->document->flag('a')?->isDisabled())->toBeTrue();
});

it('refuses shorthand in a file: shorthand belongs to config', function (): void {
    $path = TemporaryFlagFiles::write('json', '{"flags":{"a":true}}');

    expect(fn () => featureFlagsFileSource($path)->load(null))->toThrow(InvalidFlagDefinition::class, 'flag definition must be an object');
});

it('reports a missing or misnamed file as unavailable, naming the extension before looking at the disk', function (): void {
    $text = TemporaryFlagFiles::write('txt', '{}');

    expect(fn () => featureFlagsFileSource('/nowhere/flags.json')->load(null))->toThrow(FlagSourceUnavailable::class, 'does not exist')
        ->and(fn () => featureFlagsFileSource($text)->load(null))->toThrow(FlagSourceUnavailable::class, '.json, .yaml or .yml')
        ->and(fn () => featureFlagsFileSource('/nowhere/flags.txt')->load(null))->toThrow(FlagSourceUnavailable::class, '.json, .yaml or .yml');
});

it('refuses a file it cannot parse as the whole document, naming the file', function (string $extension, string $contents, string $reason): void {
    $path = TemporaryFlagFiles::write($extension, $contents);

    $refusal = featureFlagsFileRefusal($path);

    expect($refusal?->flagKey())->toBe('<document>')
        ->and($refusal?->reason())->toStartWith("the flag file [{$path}] is not valid {$reason}");
})->with([
    'JSON syntax' => ['json', '{"flags": {', 'JSON'],
    'an empty JSON file' => ['json', '', 'JSON'],
    'JSON with malformed UTF-8' => ['json', "{\"flags\":{},\"metadata\":{\"owner\":\"\xff\"}}", 'JSON'],
    'YAML syntax' => ['yaml', "flags: [unclosed\n", 'YAML'],
    'YAML with malformed UTF-8' => ['yml', "metadata:\n  owner: \xff\n", 'YAML'],
    'a PHP constant in YAML' => ['yaml', "metadata:\n  limit: !php/const PHP_INT_MAX\n", 'YAML'],
    'a date as a flow-mapping key in YAML' => ['yaml', "metadata: {2024-01-01: launch}\n", 'YAML'],
    'a YAML block key starting with NUL, which Symfony Yaml fails on with an Error' => ['yaml', "metadata:\n  \"\\0a\": x\n", 'YAML'],
]);

it('reads an absent document as no flags: JSON null, an empty or comment-only YAML file', function (string $extension, string $contents): void {
    $path = TemporaryFlagFiles::write($extension, $contents);

    $snapshot = featureFlagsFileSource($path)->load(null);

    expect($snapshot?->document->keys())->toBe([])
        ->and($snapshot?->revision)->toBe(TemporaryFlagFiles::MTIME.'-'.strlen($contents));
})->with([
    'JSON null' => ['json', 'null'],
    'an empty YAML file' => ['yaml', ''],
    'a comment-only YAML file' => ['yml', "# no flags yet\n"],
    'a YAML null' => ['yaml', "~\n"],
]);

it('refuses any other document that is not an object', function (string $extension, string $contents): void {
    $refusal = featureFlagsFileRefusal(TemporaryFlagFiles::write($extension, $contents));

    expect([$refusal?->flagKey(), $refusal?->reason()])->toBe(['<document>', 'document must be an object']);
})->with([
    'a JSON array' => ['json', '[]'],
    'a JSON zero' => ['json', '0'],
    'a JSON false' => ['json', 'false'],
    'a JSON string' => ['json', '"flags"'],
    'a YAML sequence' => ['yaml', "- new-checkout\n"],
    'an empty YAML sequence' => ['yaml', "[]\n"],
    'a YAML false' => ['yml', "false\n"],
    'a YAML string' => ['yaml', "flags\n"],
]);

it('reads an unquoted YAML date as the YYYY-MM-DD text flagd expects, and a timestamp as ISO-8601 text', function (): void {
    $path = TemporaryFlagFiles::write('yaml', <<<'YAML'
        flags:
          old-banner:
            state: ENABLED
            variants: {on: true, off: false}
            defaultVariant: off
            metadata:
              expires: 2025-01-01
              releasedAt: 2024-06-01T10:30:00Z
            notes: [2024-06-01, {at: 2024-06-01 08:00:00.5 +02:00}]
        metadata: {snapshot: 2025-01-01}

        YAML);

    $document = featureFlagsFileSource($path)->load(null)?->document;
    $flag = $document?->flag('old-banner');

    expect($flag?->expires())->toBe('2025-01-01')
        ->and($flag?->isExpired('2026-10-02'))->toBeTrue()
        ->and($flag?->metadata()['releasedAt'] ?? null)->toBe('2024-06-01T10:30:00+00:00')
        ->and($flag?->toArray()['notes'] ?? null)->toEqual(['2024-06-01', ['at' => '2024-06-01T08:00:00.500000+02:00']])
        ->and($document?->metadata)->toBe(['snapshot' => '2025-01-01']);
});

it('loads an impossible unquoted YAML date as the date PHP rolls it over to: a known YAML divergence, documented (quote dates)', function (): void {
    $unquoted = TemporaryFlagFiles::write('yaml', "flags:\n  typo:\n    state: ENABLED\n    variants: {on: true, off: false}\n    defaultVariant: off\n    metadata: {expires: 2025-02-30, note: 2025-02-30}\n");
    $quoted = TemporaryFlagFiles::write('yaml', "flags:\n  typo:\n    state: ENABLED\n    variants: {on: true, off: false}\n    defaultVariant: off\n    metadata: {expires: '2025-02-30'}\n");

    $flag = featureFlagsFileSource($unquoted)->load(null)?->document->flag('typo');

    expect($flag?->expires())->toBe('2025-03-02')
        ->and($flag?->metadata()['note'] ?? null)->toBe('2025-03-02')
        ->and(featureFlagsFileRefusal($quoted)?->reason())->toBe('expires must be a YYYY-MM-DD date');
});

it('sees a rewrite by another process that PHP\'s stat cache would hide', function (): void {
    $before = '{"flags":{"a":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}}}';
    $after = '{"flags":{"a":{"state":"DISABLED","variants":{"on":true},"defaultVariant":"on"}},"metadata":{"v":2}}';
    $path = TemporaryFlagFiles::write('json', $before);
    $source = featureFlagsFileSource($path);
    $loaded = $source->load(null);

    expect($source->load($loaded?->revision))->toBeNull() // an unchanged check leaves the stat of $path cached
        ->and(filesize($path))->toBe(strlen($before));

    TemporaryFlagFiles::rewriteFromAnotherProcess($path, $after);
    $reloaded = $source->load($loaded?->revision);

    expect($reloaded?->document->flag('a')?->isDisabled())->toBeTrue()
        ->and($reloaded?->revision)->toEndWith('-'.strlen($after));
});

it('keeps a YAML flow-mapping key starting with NUL as Symfony Yaml reads it', function (): void {
    $path = TemporaryFlagFiles::write('yaml', "metadata: {\"\\0a\": x}\n");

    expect(featureFlagsFileSource($path)->load(null)?->document->metadata)->toBe(["\0a" => 'x']);
});
