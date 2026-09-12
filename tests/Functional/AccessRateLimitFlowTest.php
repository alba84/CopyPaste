<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Crypto\Domain\Cryptography;
use App\Secret\Application\CreateSecret\CreateSecret;
use App\Secret\Application\CreateSecret\CreateSecretHandler;
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
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level invariance of rate limiting after the Access extraction (task 007).
 *
 * Existing flow tests keep the original 10/11 expectations; this class closes
 * the remaining gaps: GET requests, invalid submissions, 404 ordering and
 * independent windows per token/IP/limit.
 */
final class AccessRateLimitFlowTest extends WebTestCase
{
    private const string CLIENT_IP = '10.30.40.50';
    private const string OTHER_CLIENT_IP = '10.30.40.51';
    private const string CREATE_LIMIT_MESSAGE = 'Слишком много запросов. Попробуйте через минуту.';
    private const string REVEAL_LIMIT_MESSAGE = 'Слишком много попыток. Попробуйте через минуту.';

    private ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('REMOTE_ADDR', self::CLIENT_IP);

        $container = self::getContainer();

        $cache = $container->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testRepeatedHomePageGetsDoNotConsumeCreateLimit(): void
    {
        for ($attempt = 1; $attempt <= 15; ++$attempt) {
            $this->client()->request('GET', '/');
            self::assertResponseIsSuccessful();
        }

        $this->submitCreate(['text' => 'Создано после GET']);

        self::assertResponseStatusCodeSame(302);
        self::assertSame(1, $this->countStoredSecrets());
    }

    public function testInvalidCreateSubmissionsConsumeLimitBeforeValidation(): void
    {
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitCreate(['text' => '']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Введите текст');
        }

        $this->submitCreate(['text' => 'Валидный одиннадцатый']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', self::CREATE_LIMIT_MESSAGE);
        self::assertSame(0, $this->countStoredSecrets());
    }

    public function testExhaustedCreateLimitDoesNotBlockReveal(): void
    {
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitCreate(['text' => '']);
            self::assertResponseStatusCodeSame(422);
        }

        $token = $this->createSecretThroughApplication('Секрет после лимита создания', 'ключ');

        $this->submitReveal($token, 'ключ');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Секрет после лимита создания');
    }

    public function testRepeatedRevealPageGetsDoNotConsumeRevealLimit(): void
    {
        $token = $this->createSecretThroughApplication('Секрет после GET', 'ключ');

        for ($attempt = 1; $attempt <= 15; ++$attempt) {
            $this->client()->request('GET', '/p/'.$token);
            self::assertResponseIsSuccessful();
        }

        $this->submitReveal($token, 'ключ');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Секрет после GET');
    }

    public function testRevealLimitIsScopedToToken(): void
    {
        $limitedToken = $this->createSecretThroughApplication('Первый', 'ключ');
        $otherToken = $this->createSecretThroughApplication('Второй', 'ключ');

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitReveal($limitedToken, 'неверный ключ');
            self::assertResponseStatusCodeSame(422);
        }

        $this->submitReveal($limitedToken, 'ключ');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', self::REVEAL_LIMIT_MESSAGE);

        $this->submitReveal($otherToken, 'ключ');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Второй');
    }

    public function testRevealLimitIsScopedToClientIp(): void
    {
        $token = $this->createSecretThroughApplication('Секрет', 'ключ');

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitReveal($token, 'неверный ключ');
            self::assertResponseStatusCodeSame(422);
        }

        $this->submitReveal($token, 'ключ');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', self::REVEAL_LIMIT_MESSAGE);

        $this->client()->setServerParameter('REMOTE_ADDR', self::OTHER_CLIENT_IP);
        $this->submitReveal($token, 'ключ');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Секрет');
    }

    public function testCreateLimitIsScopedToClientIp(): void
    {
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitCreate(['text' => '']);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client()->setServerParameter('REMOTE_ADDR', self::OTHER_CLIENT_IP);
        $this->submitCreate(['text' => 'С другого IP']);

        self::assertResponseStatusCodeSame(302);
        self::assertSame(1, $this->countStoredSecrets());
    }

    public function testUnknownTokenReturns404WithoutConsumingRevealLimit(): void
    {
        $token = str_repeat('7', 32);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->client()->request('POST', '/p/'.$token, ['unlock_paste' => ['secret' => 'ключ']]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->persistSecret($token, 'Появился позже', 'ключ');
        $this->submitReveal($token, 'ключ');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Появился позже');
    }

    public function testExpiredTokenReturns404WithoutConsumingRevealLimit(): void
    {
        $token = str_repeat('8', 32);
        $this->persistSecret($token, 'Просрочен', 'ключ', new DateTimeImmutable('-1 day'));

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->client()->request('POST', '/p/'.$token, ['unlock_paste' => ['secret' => 'ключ']]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->removeSecret($token);
        $this->persistSecret($token, 'Активен снова', 'ключ');
        $this->submitReveal($token, 'ключ');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Активен снова');
    }

    /**
     * @param array{text?: string, hint?: string, secret?: string} $fields
     */
    private function submitCreate(array $fields): void
    {
        $crawler = $this->client()->request('GET', '/');
        $form = $crawler->selectButton('Зашифровать и получить ссылку')->form([
            'create_paste[text]' => $fields['text'] ?? 'Секретный текст',
            'create_paste[hint]' => $fields['hint'] ?? '',
            'create_paste[secret]' => $fields['secret'] ?? 'ключ',
        ]);
        $this->client()->submit($form);
    }

    private function submitReveal(string $token, string $secret): void
    {
        $crawler = $this->client()->request('GET', '/p/'.$token);
        $form = $crawler->selectButton('Расшифровать')->form(['unlock_paste[secret]' => $secret]);
        $this->client()->submit($form);
    }

    private function createSecretThroughApplication(string $plaintext, string $secretKey): string
    {
        $handler = self::getContainer()->get(CreateSecretHandler::class);
        self::assertInstanceOf(CreateSecretHandler::class, $handler);

        return $handler(new CreateSecret($plaintext, null, $secretKey))->token->value;
    }

    private function persistSecret(string $token, string $plaintext, string $secretKey, ?DateTimeImmutable $expiresAt = null): void
    {
        $container = self::getContainer();
        $cryptography = $container->get(Cryptography::class);
        self::assertInstanceOf(Cryptography::class, $cryptography);
        $repository = $container->get(SecretRepository::class);
        self::assertInstanceOf(SecretRepository::class, $repository);

        $createdAt = new DateTimeImmutable('-1 hour');
        $secret = Secret::reconstitute(
            new Token($token),
            Hint::fromUserInput(null),
            $cryptography->encrypt(Plaintext::fromString($plaintext), SecretKey::fromString($secretKey)),
            $createdAt,
            $expiresAt ?? $createdAt->add(new DateInterval('P7D')),
        );

        $repository->save($secret);
    }

    private function removeSecret(string $token): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->delete('secret', ['token' => $token]);
    }

    private function countStoredSecrets(): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $count = $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM secret');
        self::assertIsInt($count);

        return $count;
    }

    private function client(): KernelBrowser
    {
        if (null === $this->client) {
            throw new LogicException('Kernel browser is not initialized.');
        }

        return $this->client;
    }
}
