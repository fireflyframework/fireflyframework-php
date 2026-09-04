<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\AttributeFixture;

use Firefly\OpenApi\Attributes\ApiIgnore;
use Firefly\OpenApi\Attributes\ApiTag;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * A whole controller left out of the document.
 *
 * It carries an #[ApiTag] with a description as well, so the test can prove that hiding the operations also
 * withholds the tag: a root `tags` entry for a group with no visible operations would advertise the existence
 * of exactly what #[ApiIgnore] was used to hide.
 */
#[RestController]
#[RequestMapping('/internal')]
#[ApiTag(name: 'Internal', description: 'Back-office tooling that is not part of the published contract.')]
#[ApiIgnore]
final class InternalToolingController
{
    /** @return array<string, mixed> */
    #[GetMapping('/reindex')]
    public function reindex(): array
    {
        return [];
    }
}
