<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Persistence\Doctrine\Schema;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'paste'), ORM\Index(name: 'idx_paste_expires_at', columns: ['expires_at'])]
class PasteSchemaAnchor
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    public string $token = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $hint = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $salt = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $nonce = null;

    #[ORM\Column(type: Types::BLOB)]
    public mixed $ciphertext = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $expiresAt = null;
}
