<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\SiblingSubclass;

/**
 * The second implementation — a hand-written fake, a test double, an in-memory stand-in: no stereotype, no
 * attribute of its own, and nothing its author would ever want proxied. `getMethods(IS_PUBLIC)` still shows
 * it the base's annotated `charge()`, so it compiles a row; refusing it would fail `firefly:cache` naming a
 * class whose author greps it for a resilience attribute and finds none, with a remedy ("add #[Service]")
 * that is the opposite of what they want. Its rows are dropped in silence instead — the annotated ancestor
 * is where a refusal would belong, and the ancestor is already answered for by the bean beside it.
 */
class FakeGateway extends BaseGateway {}
