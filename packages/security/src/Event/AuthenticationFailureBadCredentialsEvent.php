<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

/**
 * A wrong password — or an unknown username, which is reported as the SAME event on purpose: a distinct
 * "user not found" event would let a listener tell an observer which accounts exist, undoing the timing- and
 * content-equalisation DaoAuthenticationProvider goes to lengths for.
 */
final readonly class AuthenticationFailureBadCredentialsEvent extends AbstractAuthenticationFailureEvent {}
