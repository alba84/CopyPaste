<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Crypto\Domain\Cryptography;
use App\Crypto\Domain\DecryptedPlaintext;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\SecretKey;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreateSecretFlowTest extends WebTestCase
{
    private const string REMOTE_ADDR = '10.10.10.10';

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

    public function testHomePageRendersLegacyCreateFormContract(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.paste-form'));
        self::assertCount(1, $crawler->filter('textarea[name="create_paste[text]"]'));
        self::assertCount(1, $crawler->filter('input[name="create_paste[hint]"]'));
        self::assertCount(1, $crawler->filter('input[type="password"][name="create_paste[secret]"]'));
        self::assertCount(1, $crawler->filter('input[name="create_paste[_token]"]'));
    }

    public function testValidSubmissionRedirectsToCreatedPageWithAbsoluteLink(): void
    {
        $plaintext = 'Секретный текст';
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Зашифровать и получить ссылку')->form([
            'create_paste[text]' => $plaintext,
            'create_paste[hint]' => 'Подсказка',
            'create_paste[secret]' => 'ключ',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/created/[a-f0-9]{32}$#', $location);
        self::assertSame(1, preg_match('#/created/([a-f0-9]{32})$#', $location, $matches));
        $token = $matches[1] ?? '';
        self::assertNotSame('', $token);

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ссылка готова');
        $link = (string) $crawler->filter('#paste-url')->attr('value');
        self::assertMatchesRegularExpression('#^https?://[^/]+/p/'.$token.'$#', $link);
    }

    /**
     * @return iterable<string, array{array{text?: string, hint?: string, secret?: string}, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty text' => [['text' => '', 'secret' => 'ключ'], 'Введите текст'];
        yield 'empty secret' => [['text' => 'текст', 'secret' => ''], 'Введите секретный ключ'];
        yield 'oversized text' => [['text' => str_repeat('x', 100001), 'secret' => 'ключ'], 'Текст не должен превышать 100 000 символов'];
        yield 'oversized hint' => [['text' => 'текст', 'hint' => str_repeat('x', 256), 'secret' => 'ключ'], 'Подсказка не должна превышать 255 символов'];
        yield 'oversized secret' => [['text' => 'текст', 'secret' => str_repeat('x', 256)], 'Ключ не должен превышать 255 символов'];
    }

    /**
     * @param array{text?: string, hint?: string, secret?: string} $fields
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputShowsExactRussianError(array $fields, string $message): void
    {
        $this->submitForm($fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', $message);
    }

    /** @return iterable<string, array{string}> */
    public static function blankHints(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
    }

    #[DataProvider('blankHints')]
    public function testBlankHintIsPersistedAsNull(string $hint): void
    {
        $token = $this->createAndReturnToken(['hint' => $hint]);

        $secret = $this->secretRepository->findByToken(new Token($token));

        self::assertInstanceOf(Secret::class, $secret);
        self::assertNull($secret->hint());
    }

    public function testNonBlankHintIsPersisted(): void
    {
        $token = $this->createAndReturnToken(['hint' => 'Подсказка получателю']);

        $secret = $this->secretRepository->findByToken(new Token($token));

        self::assertInstanceOf(Secret::class, $secret);
        self::assertSame('Подсказка получателю', $secret->hint()?->value);
    }

    public function testEleventhRequestIsRateLimitedAndCreatesNoExtraRecord(): void
    {
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitForm(['text' => 'текст '.$attempt]);
            self::assertResponseStatusCodeSame(302);
        }

        self::assertSame(10, $this->countStoredSecrets());

        $this->submitForm(['text' => 'одиннадцатый']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Слишком много запросов. Попробуйте через минуту.');
        self::assertSame(10, $this->countStoredSecrets());
    }

    public function testCreatedSecretIsReadableAndDecryptableThroughSecretRepository(): void
    {
        $plaintext = 'Текст, который должен прочитать SecretRepository';
        $secretKey = 'секретный ключ';
        $token = $this->createAndReturnToken([
            'text' => $plaintext,
            'hint' => 'Подсказка',
            'secret' => $secretKey,
        ]);

        $secret = $this->secretRepository->findByToken(new Token($token));

        self::assertInstanceOf(Secret::class, $secret);
        self::assertSame('Подсказка', $secret->hint()?->value);
        $decrypted = $this->cryptography->decrypt($secret->payload(), SecretKey::fromString($secretKey));
        self::assertInstanceOf(DecryptedPlaintext::class, $decrypted);
        self::assertSame($plaintext, $decrypted->plaintext);
    }

    /**
     * @param array{text?: string, hint?: string, secret?: string} $fields
     */
    private function submitForm(array $fields): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Зашифровать и получить ссылку')->form([
            'create_paste[text]' => $fields['text'] ?? 'Секретный текст',
            'create_paste[hint]' => $fields['hint'] ?? '',
            'create_paste[secret]' => $fields['secret'] ?? 'ключ',
        ]);
        $this->client->submit($form);
    }

    /**
     * @param array{text?: string, hint?: string, secret?: string} $fields
     */
    private function createAndReturnToken(array $fields): string
    {
        $this->submitForm($fields);

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('#/created/([a-f0-9]{32})$#', $location, $matches));
        $token = $matches[1] ?? '';
        self::assertNotSame('', $token);

        return $token;
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
