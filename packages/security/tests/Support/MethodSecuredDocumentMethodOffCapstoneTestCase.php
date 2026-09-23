<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

/**
 * The same boot with `firefly.security.method.enabled` OFF, which is a DIFFERENT boot and therefore a
 * different test case class: the flag is read while the wiring pass runs, and flipping it inside a test body
 * could never un-install a guard that is already installed.
 *
 * It stands down the PROXY LINK alone. SecurityWiringPass installs MethodSecurityControllerGuard once past
 * the master flag whatever this flag says, the guard consults only the compiled manifest, and the skeleton
 * config states the rule in as many words. So the dispatcher still answers 401 to an anonymous caller of a
 * #[PreAuthorize] action here — and a document that fell silent about it would be publishing the operation
 * as public, which is the one failure the OpenAPI security seam exists to rule out.
 */
abstract class MethodSecuredDocumentMethodOffCapstoneTestCase extends MethodSecuredDocumentCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return ['firefly.security.method.enabled' => false];
    }
}
