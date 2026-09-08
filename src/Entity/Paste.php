<?php

namespace App\Entity;

use App\Dto\EncryptedPayload;
use App\Repository\PasteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasteRepository::class), ORM\Table(name: 'paste'), ORM\Index(name: 'idx_paste_expires_at', columns: ['expires_at'])]
class Paste
{
    /** @phpstan-ignore property.unusedType (assigned by Doctrine) */
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 64, unique: true)] private string $token;
    #[ORM\Column(length: 255, nullable: true)] private ?string $hint;
    #[ORM\Column(type: Types::BLOB)] private mixed $salt;
    #[ORM\Column(type: Types::BLOB)] private mixed $nonce;
    #[ORM\Column(type: Types::BLOB)] private mixed $ciphertext;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $expiresAt;
    public function __construct(string $token, ?string $hint, EncryptedPayload $payload, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt)
    {
        $this->token = $token;
        $this->hint = null === $hint || '' === trim($hint) ? null : $hint;
        $this->salt = $payload->salt;
        $this->nonce = $payload->nonce;
        $this->ciphertext = $payload->ciphertext;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function getEncryptedPayload(): EncryptedPayload
    {
        return new EncryptedPayload(self::blob($this->salt), self::blob($this->nonce), self::blob($this->ciphertext));
    }

    private static function blob(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        } if (!is_string($value)) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }

return $value;
    }
}
