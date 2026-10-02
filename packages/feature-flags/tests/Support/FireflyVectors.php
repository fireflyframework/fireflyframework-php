<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\Definition\Json;
use LogicException;

/** firefly-vectors.json, decoded with Json so `{}` stays an object (the `{}` defaults and empty variants need it). */
final class FireflyVectors
{
    public const array KINDS = ['normalize', 'validate', 'compose', 'evaluate', 'expiry', 'bucket'];

    /** @var array<array-key, mixed>|null */
    private static ?array $document = null;

    /** @return array<array-key, mixed> */
    public static function document(): array
    {
        return self::$document ??= Json::members(Json::decode((string) file_get_contents(ConformanceFiles::root().'/firefly-vectors.json')));
    }

    public static function today(): string
    {
        $today = self::document()['today'] ?? null;

        return is_string($today) ? $today : '';
    }

    /**
     * Pest dataset: case name => [case]. Two cases of one kind sharing a name would make one overwrite the other,
     * so a duplicate name fails loudly instead of dropping a case.
     *
     * @return array<string, array{0: array<array-key, mixed>}>
     */
    public static function cases(string $kind): array
    {
        $cases = [];
        foreach ((array) (self::document()['cases'] ?? []) as $case) {
            $members = Json::members($case);
            if (($members['kind'] ?? null) === $kind) {
                $name = $members['name'] ?? null;
                $name = is_string($name) ? $name : (string) count($cases);
                if (array_key_exists($name, $cases)) {
                    throw new LogicException(sprintf('firefly-vectors.json has two [%s] cases named [%s].', $kind, $name));
                }
                $cases[$name] = [$members];
            }
        }

        return $cases;
    }
}
