<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Crypto\Domain\Cryptography;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Plaintext;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\SecretKey;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SecretCreatedFlowTest extends WebTestCase
{
    public function testCreatedPageContainsAbsoluteRevealLinkAndFormattedExpiry(): void
    {
        $client = self::createClient();
        $this->recreateSchema();

        $token = str_repeat('a', 32);
        $expiresAt = new DateTimeImmutable('2027-01-15 14:05:00');
        $this->persistSecret($token, 'Секретный текст', 'ключ', 'Подсказка', $expiresAt);

        $client->request('GET', '/created/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ссылка готова');
        $link = (string) $client->getCrawler()->filter('#paste-url')->attr('value');
        self::assertMatchesRegularExpression('#^https?://[^/]+/p/'.$token.'$#', $link);
        self::assertStringContainsString($expiresAt->format('d.m.Y, H:i'), $client->getCrawler()->filter('body')->text());
    }

    public function testUnknownTokenReturnsNeutral404(): void
    {
        $client = self::createClient();
        $this->recreateSchema();

        $client->request('GET', '/created/'.str_repeat('b', 32));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'uppercase hex' => [str_repeat('A', 32)];
        yield 'non-hex characters' => [str_repeat('z', 32)];
        yield 'hyphenated' => ['not-a-hex-token'];
    }

    #[DataProvider('invalidTokens')]
    public function testMalformedTokenReturnsNeutral404(string $token): void
    {
        $client = self::createClient();
        $this->recreateSchema();

        $client->request('GET', '/created/'.$token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testExpiredSecretStillRendersCreatedPage(): void
    {
        $client = self::createClient();
        $this->recreateSchema();

        $token = str_repeat('c', 32);
        $this->persistSecret($token, 'Секретный текст', 'ключ', null, new DateTimeImmutable('-1 day'));

        $client->request('GET', '/created/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ссылка готова');
        $link = (string) $client->getCrawler()->filter('#paste-url')->attr('value');
        self::assertMatchesRegularExpression('#^https?://[^/]+/p/'.$token.'$#', $link);
    }

    public function testCreatedPageDoesNotLeakHintOrPlaintext(): void
    {
        $client = self::createClient();
        $this->recreateSchema();

        $token = str_repeat('d', 32);
        $hint = 'УНИКАЛЬНАЯ-ПОДСКАЗКА-9f3a';
        $plaintext = 'УНИКАЛЬНЫЙ-ТЕКСТ-7b2c';
        $this->persistSecret($token, $plaintext, 'ключ', $hint);

        $client->request('GET', '/created/'.$token);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($hint, $body);
        self::assertStringNotContainsString($plaintext, $body);
    }

    public function testCreatedPageDoesNotLeakEncryptedPayloadFields(): void
    {
        $client = self::createClient();
        $this->recreateSchema();

        $token = str_repeat('e', 32);
        $salt = str_repeat('S', 16);
        $nonce = str_repeat('N', 24);
        $ciphertext = 'CIPHERTEXT-MARKER-3f9a7c';
        $this->persistSecretWithPayload(
            $token,
            new EncryptedPayload($salt, $nonce, $ciphertext),
            'HINT-MARKER-1a2b',
        );

        $client->request('GET', '/created/'.$token);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($salt, $body);
        self::assertStringNotContainsString($nonce, $body);
        self::assertStringNotContainsString($ciphertext, $body);
    }

    private function persistSecret(
        string $token,
        string $plaintext,
        string $secretKey,
        ?string $hint = null,
        ?DateTimeImmutable $expiresAt = null,
    ): Secret {
        $container = self::getContainer();
        $repository = $container->get(SecretRepository::class);
        self::assertInstanceOf(SecretRepository::class, $repository);
        $cryptography = $container->get(Cryptography::class);
        self::assertInstanceOf(Cryptography::class, $cryptography);

        $createdAt = new DateTimeImmutable('-1 hour');
        $secret = Secret::reconstitute(
            new Token($token),
            Hint::fromUserInput($hint),
            $cryptography->encrypt(Plaintext::fromString($plaintext), SecretKey::fromString($secretKey)),
            $createdAt,
            $expiresAt ?? $createdAt->add(new DateInterval('P7D')),
        );

        $repository->save($secret);

        return $secret;
    }

    private function persistSecretWithPayload(
        string $token,
        EncryptedPayload $payload,
        ?string $hint = null,
    ): Secret {
        $container = self::getContainer();
        $repository = $container->get(SecretRepository::class);
        self::assertInstanceOf(SecretRepository::class, $repository);

        $createdAt = new DateTimeImmutable('-1 hour');
        $secret = Secret::reconstitute(
            new Token($token),
            Hint::fromUserInput($hint),
            $payload,
            $createdAt,
            $createdAt->add(new DateInterval('P7D')),
        );

        $repository->save($secret);

        return $secret;
    }

    private function recreateSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}
