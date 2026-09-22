<?php

declare(strict_types=1);

namespace Firefly\Security\Jwt;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/** A signing secret — the JWT secret, the remember-me key — is a placeholder or too short: boot refuses (mirrors pyfly's WEAK_SIGNING_SECRET). */
final class WeakSigningSecretException extends ConfigurationException {}
