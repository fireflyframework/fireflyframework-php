<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\ValidatorUser;

use Firefly\Container\Attributes\Service;
use Firefly\Validation\Validator;

#[Service]
final class AppValidator implements Validator
{
    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    public function validate(array $data, array $rules): array
    {
        return $data; // a trivial app override — its mere presence must back the default off
    }
}
