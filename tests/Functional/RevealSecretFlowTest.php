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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RevealSecretFlowTest extends WebTestCase
{
    private const string REMOTE_ADDR = '10.10.10.20';
    private const string NOT_FOUND_MESSAGE = 'Запись не найдена или срок её хранения истёк';

    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private KernelBrowser $client;
    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private SecretRepository $secretRepository;
    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private Cryptography $cryptography;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('REMOTE_ADDR', self::REMOTE_ADDR);
        $container = self::getContainer();

        // Technical isolation of the fixed-window rate limiter (Q6-A).
        $cache = $container->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $secretRepository = $container->get(SecretRepository::class);
        self::assertInstanceOf(SecretRepository::class, $secretRepository);
        $this->secretRepository = $secretRepository;

        $cryptography = $container->get(Cryptography::class);
        self::assertInstanceOf(Cryptography::class, $cryptography);
        $this->cryptography = $cryptography;

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testActiveSecretRendersUnlockFormWithHintAndWireContract(): void
    {
        $token = str_repeat('a', 32);
        $this->persistSecret($token, 'текст', 'ключ', 'Публичная подсказка');

        $crawler = $this->client->request('GET', '/p/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'У вас защищённое сообщение');
        self::assertSelectorTextContains('body', 'Публичная подсказка');
        self::assertCount(1, $crawler->filter('form.paste-form'));
        self::assertCount(1, $crawler->filter('input[type="password"][name="unlock_paste[secret]"]'));
        self::assertCount(1, $crawler->filter('input[name="unlock_paste[_token]"]'));
        self::assertCount(1, $crawler->filterXPath('//button[contains(., "Расшифровать")]'));
        self::assertSame('255', $crawler->filter('input[name="unlock_paste[secret]"]')->attr('maxlength'));
        self::assertSame('current-password', $crawler->filter('input[name="unlock_paste[secret]"]')->attr('autocomplete'));
        self::assertStringContainsString('Секретный ключ', $crawler->filter('body')->text());
        $crawler->selectButton('Расшифровать');
    }

    public function testActiveSecretWithoutHintRendersNoHintBlock(): void
    {
        $token = str_repeat('b', 32);
        $this->persistSecret($token, 'текст', 'ключ');

        $crawler = $this->client->request('GET', '/p/'.$token);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.hint'));
    }

    public function testTokenWithInvalidFormatReturns404BeforeController(): void
    {
        $this->client->request('GET', '/p/not-a-hex-token');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSuccessfulRevealShowsExactPlaintextAndNoStoreHeader(): void
    {
        $token = str_repeat('c', 32);
        $this->persistSecret($token, 'Секретный текст', 'ключ');

        $this->submitReveal($token, 'ключ');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextSame('#plaintext', 'Секретный текст');
    }

    public function testSecretCreatedByNewPathIsRevealedByNewPath(): void
    {
        $token = $this->createSecretByNewPath('Создано через 002', 'ключ-002');

        $this->submitReveal($token, 'ключ-002');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Создано через 002');
    }

    public function testRevealCanBeRepeatedAndLeavesRecordUntouched(): void
    {
        $token = $this->createSecretByNewPath('Повторное открытие', 'ключ');
        $before = $this->secretRepository->findByToken(new Token($token));
        self::assertInstanceOf(Secret::class, $before);

        $this->submitReveal($token, 'ключ');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Повторное открытие');

        $this->submitReveal($token, 'ключ');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#plaintext', 'Повторное открытие');

        $after = $this->secretRepository->findByToken(new Token($token));
        self::assertInstanceOf(Secret::class, $after);
        self::assertSame(1, $this->countStoredSecrets());
        self::assertSame($before->expiresAt()->format('Y-m-d H:i:s'), $after->expiresAt()->format('Y-m-d H:i:s'));
    }

    public function testWrongKeyShowsNeutralFieldErrorWithoutPlaintext(): void
    {
        $token = str_repeat('e', 32);
        $this->persistSecret($token, 'Секрет', 'верный ключ');

        $this->submitReveal($token, 'неверный ключ');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Неверный ключ');
        self::assertCount(0, $this->client->getCrawler()->filter('#plaintext'));
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeyShowsExactRussianMessage(string $secret, string $message): void
    {
        $token = str_repeat('f', 32);
        $this->persistSecret($token, 'Секрет', 'ключ');

        $this->submitReveal($token, $secret);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', $message);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => ['', 'Введите секретный ключ'];
        yield 'oversized' => [str_repeat('x', 256), 'Ключ не должен превышать 255 символов'];
    }

    public function testUnknownTokenOnGetReturnsNeutral404(): void
    {
        $this->client->catchExceptions(false);

        try {
            $this->client->request('GET', '/p/'.str_repeat('1', 32));
            self::fail('Expected NotFoundHttpException for unknown token.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame(self::NOT_FOUND_MESSAGE, $exception->getMessage());
        }
    }

    public function testUnknownTokenOnPostReturnsNeutral404(): void
    {
        $this->client->catchExceptions(false);

        try {
            $this->client->request('POST', '/p/'.str_repeat('2', 32), ['unlock_paste' => ['secret' => 'ключ']]);
            self::fail('Expected NotFoundHttpException for unknown token.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame(self::NOT_FOUND_MESSAGE, $exception->getMessage());
        }
    }

    public function testExpiredSecretOnGetReturnsTheSameNeutral404(): void
    {
        $token = str_repeat('3', 32);
        $this->persistSecret($token, 'Секрет', 'ключ', null, new DateTimeImmutable('-1 day'));
        $this->client->catchExceptions(false);

        try {
            $this->client->request('GET', '/p/'.$token);
            self::fail('Expected NotFoundHttpException for expired secret.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame(self::NOT_FOUND_MESSAGE, $exception->getMessage());
        }
    }

    public function testExpiredSecretOnPostReturnsTheSameNeutral404(): void
    {
        $token = str_repeat('6', 32);
        $this->persistSecret($token, 'Секрет', 'ключ', null, new DateTimeImmutable('-1 day'));
        $this->client->catchExceptions(false);

        try {
            $this->client->request('POST', '/p/'.$token, ['unlock_paste' => ['secret' => 'ключ']]);
            self::fail('Expected NotFoundHttpException for expired secret.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame(self::NOT_FOUND_MESSAGE, $exception->getMessage());
        }
    }

    public function testEleventhWrongAttemptIsRateLimitedBeforeDecryption(): void
    {
        $token = str_repeat('4', 32);
        $this->persistSecret($token, 'Секрет', 'верный ключ');

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitReveal($token, 'неверный ключ');
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Неверный ключ');
        }

        // Eleventh submission carries the correct key, yet must be rejected by the limiter.
        $this->submitReveal($token, 'верный ключ');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Слишком много попыток. Попробуйте через минуту.');
        self::assertCount(0, $this->client->getCrawler()->filter('#plaintext'));
    }

    public function testInvalidSubmissionsConsumeTheRateLimit(): void
    {
        $token = str_repeat('5', 32);
        $this->persistSecret($token, 'Секрет', 'верный ключ');

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitReveal($token, '');
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Введите секретный ключ');
        }

        $this->submitReveal($token, 'верный ключ');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Слишком много попыток. Попробуйте через минуту.');
        self::assertCount(0, $this->client->getCrawler()->filter('#plaintext'));
    }

    private function submitReveal(string $token, string $secret): void
    {
        $crawler = $this->client->request('GET', '/p/'.$token);
        $form = $crawler->selectButton('Расшифровать')->form(['unlock_paste[secret]' => $secret]);
        $this->client->submit($form);
    }

    private function createSecretByNewPath(string $plaintext, string $secretKey): string
    {
        $handler = self::getContainer()->get(CreateSecretHandler::class);
        self::assertInstanceOf(CreateSecretHandler::class, $handler);

        return $handler(new CreateSecret($plaintext, null, $secretKey))->token->value;
    }

    private function persistSecret(
        string $token,
        string $plaintext,
        string $secretKey,
        ?string $hint = null,
        ?DateTimeImmutable $expiresAt = null,
    ): void {
        $createdAt = new DateTimeImmutable('-1 hour');
        $secret = Secret::reconstitute(
            new Token($token),
            Hint::fromUserInput($hint),
            $this->cryptography->encrypt(Plaintext::fromString($plaintext), SecretKey::fromString($secretKey)),
            $createdAt,
            $expiresAt ?? $createdAt->add(new DateInterval('P7D')),
        );

        $this->secretRepository->save($secret);
    }

    private function countStoredSecrets(): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $count = $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM secret');
        self::assertIsInt($count);

        return $count;
    }
}
