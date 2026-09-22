<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

/** The password was right and the account is disabled. Only ever published after a correct password. */
final readonly class AuthenticationFailureDisabledEvent extends AbstractAuthenticationFailureEvent {}
