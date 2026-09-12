<?php

declare(strict_types=1);

namespace App\Tests\Integration\Secret;

use App\Crypto\Domain\Cryptography;
use App\Crypto\Domain\DecryptedPlaintext;
use App\Entity\Paste;
use App\Repository\PasteRepository;
use App\Secret\Application\RevealSecret\Revealed;
use App\Secret\Application\RevealSecret\RevealSecret;
use App\Secret\Application\RevealSecret\RevealSecretHandler;
use App\Secret\Domain\Model\Plaintext;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\SecretKey;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use App\Secret\Domain\Repository\SecretRepository;
use App\Service\EncryptionService;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LegacyInteropTest extends KernelTestCase
{
    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testLegacyEncryptedPasteIsReadableAndDecryptableByNewPath(): void
    {
        $token = str_repeat('a', 32);
        $plaintext = 'Секрет, созданный легаси путём';
        $secretKey = 'legacy-key';
        $createdAt = new DateTimeImmutable('2026-05-01 12:00:00');
        $legacyPayload = $this->legacyEncryption()->encrypt($plaintext, $secretKey);

        $this->entityManager->persist(new Paste($token, 'легаси подсказка', $legacyPayload, $createdAt, $createdAt->add(new DateInterval('P7D'))));
        $this->entityManager->flush();

        $secret = $this->secretRepository()->findByToken(new Token($token));

        self::assertInstanceOf(Secret::class, $secret);
        self::assertSame('легаси подсказка', $secret->hint()?->value);
        self::assertSame($legacyPayload->salt, $secret->payload()->salt);
        self::assertSame($legacyPayload->nonce, $secret->payload()->nonce);
        self::assertSame($legacyPayload->ciphertext, $secret->payload()->ciphertext);
        self::assertSame($createdAt->format('Y-m-d H:i:s'), $secret->createdAt()->format('Y-m-d H:i:s'));

        $decrypted = $this->cryptography()->decrypt($secret->payload(), SecretKey::fromString($secretKey));

        self::assertInstanceOf(DecryptedPlaintext::class, $decrypted);
        self::assertSame($plaintext, $decrypted->plaintext);
    }

    public function testSecretSavedByNewPathIsReadableAndDecryptableByLegacyPath(): void
    {
        $token = str_repeat('b', 32);
        $plaintext = 'Секрет, созданный новым путём';
        $secretKey = 'new-key';
        $payload = $this->cryptography()->encrypt(Plaintext::fromString($plaintext), SecretKey::fromString($secretKey));
        $secret = Secret::create(
            new Token($token),
            null,
            $payload,
            new DateTimeImmutable('2026-05-02 10:00:00'),
            Ttl::fromDays(7),
        );

        $this->secretRepository()->save($secret);

        $paste = $this->pasteRepository()->findOneBy(['token' => $token]);

        self::assertInstanceOf(Paste::class, $paste);
        self::assertSame($plaintext, $this->legacyEncryption()->decrypt($paste->getEncryptedPayload(), $secretKey));
    }

    public function testLegacySavedSecretIsRevealedByApplicationUseCase(): void
    {
        $token = str_repeat('c', 32);
        $plaintext = 'Легаси запись, открытая Reveal use case';
        $secretKey = 'legacy-reveal-key';
        $createdAt = new DateTimeImmutable('-1 hour');
        $legacyPayload = $this->legacyEncryption()->encrypt($plaintext, $secretKey);

        $this->entityManager->persist(new Paste($token, 'подсказка', $legacyPayload, $createdAt, $createdAt->add(new DateInterval('P1D'))));
        $this->entityManager->flush();

        $result = $this->revealSecretHandler()(new RevealSecret($token, $secretKey));

        self::assertInstanceOf(Revealed::class, $result);
        self::assertSame($plaintext, $result->plaintext);
    }

    public function testSecretSavedByNewPathIsRevealedByApplicationUseCase(): void
    {
        $token = str_repeat('d', 32);
        $plaintext = 'Запись 002, открытая Reveal use case';
        $secretKey = 'new-reveal-key';
        $payload = $this->cryptography()->encrypt(Plaintext::fromString($plaintext), SecretKey::fromString($secretKey));
        $secret = Secret::create(
            new Token($token),
            null,
            $payload,
            new DateTimeImmutable('-1 hour'),
            Ttl::fromDays(7),
        );

        $this->secretRepository()->save($secret);

        $result = $this->revealSecretHandler()(new RevealSecret($token, $secretKey));

        self::assertInstanceOf(Revealed::class, $result);
        self::assertSame($plaintext, $result->plaintext);
    }

    private function revealSecretHandler(): RevealSecretHandler
    {
        $handler = self::getContainer()->get(RevealSecretHandler::class);
        self::assertInstanceOf(RevealSecretHandler::class, $handler);

        return $handler;
    }

    private function secretRepository(): SecretRepository
    {
        $repository = self::getContainer()->get(SecretRepository::class);
        self::assertInstanceOf(SecretRepository::class, $repository);

        return $repository;
    }

    private function pasteRepository(): PasteRepository
    {
        $repository = self::getContainer()->get(PasteRepository::class);
        self::assertInstanceOf(PasteRepository::class, $repository);

        return $repository;
    }

    private function cryptography(): Cryptography
    {
        $cryptography = self::getContainer()->get(Cryptography::class);
        self::assertInstanceOf(Cryptography::class, $cryptography);

        return $cryptography;
    }

    private function legacyEncryption(): EncryptionService
    {
        $encryption = self::getContainer()->get(EncryptionService::class);
        self::assertInstanceOf(EncryptionService::class, $encryption);

        return $encryption;
    }
}
