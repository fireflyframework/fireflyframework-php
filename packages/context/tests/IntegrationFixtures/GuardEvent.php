<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

/**
 * Published directly by IntegrationTest (after boot) at two listeners: FalsyFirstListener (which
 * deliberately returns false) and SecondGuardListener. Proves guardListener() wiring end-to-end
 * through the real RegisterEventListenersPass, not merely in isolation.
 */
final class GuardEvent {}
