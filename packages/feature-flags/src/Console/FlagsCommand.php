<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Console;

use Firefly\Container\Container as FireflyContainer;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Management\FlagManagement;
use Firefly\FeatureFlags\Management\FlagManagementException;
use Firefly\FeatureFlags\Management\ManagementError;
use Illuminate\Console\Command;
use JsonException;

final class FlagsCommand extends Command
{
    /** @var list<string> */
    private const array ACTIONS = ['list', 'show', 'evaluate', 'enable', 'disable', 'default-variant', 'put', 'delete'];

    protected $signature = 'firefly:flags
        {action : list, show, evaluate, enable, disable, default-variant, put or delete}
        {key? : The flag key (every action but list)}
        {value? : The variant, for default-variant}
        {--file= : put: a JSON file holding one flagd flag definition}
        {--context= : evaluate: the evaluation context, a JSON object}
        {--targeting-key= : evaluate: the targeting key}
        {--expected-version= : writes: require this stored version (0 means absent)}
        {--json : Print the response body as JSON}';

    protected $description = 'List, inspect, evaluate and change feature flags.';

    public function handle(FireflyContainer $beans): int
    {
        try {
            $management = $beans->has(FlagManagement::class) ? $beans->get(FlagManagement::class) : null;
            if (! $management instanceof FlagManagement) {
                throw new FlagManagementException(ManagementError::BadRequest, 'Feature flags are switched off: set firefly.feature-flags.enabled to true.');
            }

            $action = $this->stringArgument('action') ?? '';
            if (! in_array($action, self::ACTIONS, true)) {
                throw new FlagManagementException(ManagementError::BadRequest, "Unknown action [{$action}]: use one of ".implode(', ', self::ACTIONS).'.');
            }

            if ($action === 'list') {
                return $this->render($management->overview());
            }

            $key = $this->stringArgument('key') ?? throw new FlagManagementException(ManagementError::BadRequest, "{$action} needs a flag key.");
            $result = $action === 'show'
                ? $management->describe($key)
                : $management->apply($key, $this->body($action), self::actor());

            return $this->render($result);
        } catch (FlagManagementException $refused) {
            if ($this->option('json') === true) {
                $this->line(Json::encode($refused->toArray()));
            } else {
                $this->components->error("{$refused->error->value}: {$refused->getMessage()}");
            }

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function body(string $action): array
    {
        $body = ['action' => $action];

        $expected = $this->option('expected-version');
        if (is_string($expected)) {
            $digits = ltrim($expected, '0');
            $limit = (string) PHP_INT_MAX;
            if (preg_match('/^\d+$/D', $expected) !== 1 || strlen($digits) > strlen($limit)
                || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
                throw new FlagManagementException(ManagementError::BadRequest, '--expected-version must be a non-negative integer within the PHP integer range.');
            }
            $body['expectedVersion'] = (int) $expected;
        }

        if ($action === 'default-variant') {
            $body['variant'] = $this->stringArgument('value') ?? throw new FlagManagementException(ManagementError::BadRequest, 'default-variant needs the variant: firefly:flags default-variant {key} {variant}.');
        }

        if ($action === 'put') {
            $file = $this->stringOption('file') ?? throw new FlagManagementException(ManagementError::BadRequest, 'put needs --file=<definition.json>.');
            $contents = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
            if ($contents === false) {
                throw new FlagManagementException(ManagementError::BadRequest, "Cannot read [{$file}].");
            }
            $definition = self::decode($contents, "[{$file}] is not valid JSON.");
            if (! Json::isObject($definition)) {
                throw new FlagManagementException(ManagementError::BadRequest, "[{$file}] must contain a JSON object.");
            }
            $body['definition'] = $definition;
        }

        if ($action === 'evaluate') {
            $context = $this->option('context');
            if (is_string($context)) {
                $decoded = self::decode($context, '--context must be a JSON object.');
                if (! Json::isObject($decoded)) {
                    throw new FlagManagementException(ManagementError::BadRequest, '--context must be a JSON object.');
                }
                $body['context'] = $decoded;
            }
            $targetingKey = $this->stringOption('targeting-key');
            if ($targetingKey !== null) {
                $body['targetingKey'] = $targetingKey;
            }
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function render(array $body): int
    {
        if ($this->option('json') === true) {
            $this->line(Json::encode($body));

            return self::SUCCESS;
        }

        if (isset($body['flags']) && is_array($body['flags'])) {
            $rows = [];
            foreach ($body['flags'] as $flag) {
                if (is_array($flag)) {
                    $rows[] = [
                        self::cell($flag['key'] ?? ''),
                        self::cell($flag['state'] ?? ''),
                        self::cell($flag['type'] ?? ''),
                        self::cell($flag['defaultVariant'] ?? ''),
                        self::cell($flag['origin'] ?? ''),
                        ($flag['expired'] ?? false) === true ? 'yes' : '',
                    ];
                }
            }
            $this->table(['Key', 'State', 'Type', 'Default', 'Origin', 'Expired'], $rows);

            return self::SUCCESS;
        }

        if (($body['refreshPending'] ?? false) === true) {
            $this->components->info('Write accepted for ['.self::cell($body['key'] ?? '').']; local visibility is pending. Check again after the next refresh.');

            return self::SUCCESS;
        }

        if (($body['deleted'] ?? false) === true) {
            $this->components->info('Deleted ['.self::cell($body['key'] ?? '').'].');

            return self::SUCCESS;
        }

        $this->line(json_encode($body, JSON_PRETTY_PRINT | Json::ENCODE_FLAGS | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private static function decode(string $json, string $refusal): mixed
    {
        try {
            return Json::decode($json);
        } catch (JsonException) {
            throw new FlagManagementException(ManagementError::BadRequest, $refusal);
        }
    }

    private static function actor(): string
    {
        $entry = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $user = is_array($entry) && $entry['name'] !== '' ? $entry['name'] : null;

        return 'cli:'.($user ?? (getenv('USER') ?: getenv('USERNAME') ?: 'unknown'));
    }

    private static function cell(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
