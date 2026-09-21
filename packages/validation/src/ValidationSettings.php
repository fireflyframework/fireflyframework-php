<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The `firefly.validation.*` keys, read once into a value object — the DataSettings idiom, so the validator
 * adapter and anything else that cares see one answer.
 *
 * One key so far. `messages` decides whether a 422's field errors are worded by the CONSTRAINT (`must not be
 * blank` — the default, and Spring's shape) or by Laravel's validator (`The ship to.street field is
 * required.`); see MessageStyle. A value that is neither is refused rather than defaulted: a typo in a
 * config file that silently produced the other wording would be found by a client, not by the developer.
 */
final readonly class ValidationSettings
{
    public function __construct(
        public MessageStyle $messages = MessageStyle::Constraint,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $style = $config->string('firefly.validation.messages', MessageStyle::Constraint->value);
        $messages = MessageStyle::tryFrom($style);

        if ($messages === null) {
            throw new ConfigurationException(sprintf(
                'firefly.validation.messages must be one of "%s"; "%s" is not a message style.',
                implode('", "', array_map(static fn (MessageStyle $case): string => $case->value, MessageStyle::cases())),
                $style,
            ));
        }

        return new self($messages);
    }
}
