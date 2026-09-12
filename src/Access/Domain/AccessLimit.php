<?php

declare(strict_types=1);

namespace App\Access\Domain;

enum AccessLimit
{
    case CreateSecret;
    case RevealSecret;
}
