<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Paste;
use App\Repository\PasteRepository;
use App\Service\EncryptionService;
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
    private PasteRepository $pasteRepository;
    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private EncryptionService $encryption;

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

        $pasteRepository = $container->get(PasteRepository::class);
        self::assertInstanceOf(PasteRepository::class, $pasteRepository);
        $this->pasteRepository = $pasteRepository;

        $encryption = $container->get(EncryptionService::class);
        self::assertInstanceOf(EncryptionService::class, $encryption);
        $this->encryption = $encryption;

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

        $paste = $this->pasteRepository->findOneBy(['token' => $token]);

        self::assertInstanceOf(Paste::class, $paste);
        self::assertNull($paste->getHint());
    }

    public function testNonBlankHintIsPersisted(): void
    {
        $token = $this->createAndReturnToken(['hint' => 'Подсказка получателю']);

        $paste = $this->pasteRepository->findOneBy(['token' => $token]);

        self::assertInstanceOf(Paste::class, $paste);
        self::assertSame('Подсказка получателю', $paste->getHint());
    }

    public function testEleventhRequestIsRateLimitedAndCreatesNoExtraRecord(): void
    {
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitForm(['text' => 'текст '.$attempt]);
            self::assertResponseStatusCodeSame(302);
        }

        self::assertCount(10, $this->pasteRepository->findAll());

        $this->submitForm(['text' => 'одиннадцатый']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Слишком много запросов. Попробуйте через минуту.');
        self::assertCount(10, $this->pasteRepository->findAll());
    }

    public function testCreatedSecretIsReadableAndDecryptableByLegacyPath(): void
    {
        $plaintext = 'Текст, который должен прочитать легаси Reveal';
        $secretKey = 'секретный ключ';
        $token = $this->createAndReturnToken([
            'text' => $plaintext,
            'hint' => 'Подсказка',
            'secret' => $secretKey,
        ]);

        $paste = $this->pasteRepository->findOneBy(['token' => $token]);

        self::assertInstanceOf(Paste::class, $paste);
        self::assertSame('Подсказка', $paste->getHint());
        self::assertSame($plaintext, $this->encryption->decrypt($paste->getEncryptedPayload(), $secretKey));
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
}
