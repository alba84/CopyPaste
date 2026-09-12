<?php

declare(strict_types=1);

namespace App\Access\Domain;

enum AccessDecision
{
    case Accepted;
    case Rejected;
}
