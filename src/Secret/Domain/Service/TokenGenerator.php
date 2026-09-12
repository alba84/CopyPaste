<?php

declare(strict_types=1);

namespace App\Secret\Domain\Service;

use App\Secret\Domain\Model\Token;

interface TokenGenerator
{
    public function generate(): Token;
}
