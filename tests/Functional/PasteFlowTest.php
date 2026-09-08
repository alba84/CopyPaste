<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\EncryptedPayload;
use App\Entity\Paste;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasteFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->entityManager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testCreateAndUnlockPaste(): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Зашифровать и получить ссылку')->form([
            'create_paste[text]' => 'Секретный текст',
            'create_paste[hint]' => 'Подсказка',
            'create_paste[secret]' => 'ключ',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Ссылка готова');

        $url = (string) $crawler->filter('#paste-url')->attr('value');
        $path = (string) parse_url($url, PHP_URL_PATH);
        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('body', 'Подсказка');

        $form = $crawler->selectButton('Расшифровать')->form(['unlock_paste[secret]' => 'ключ']);
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextContains('#plaintext', 'Секретный текст');
    }

    public function testWrongKeyShowsNeutralError(): void
    {
        $path = $this->createPaste();
        $crawler = $this->client->request('GET', $path);
        $this->client->submit($crawler->selectButton('Расшифровать')->form(['unlock_paste[secret]' => 'wrong']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Неверный ключ');
    }

    public function testUnknownAndExpiredPastesReturn404(): void
    {
        $this->client->request('GET', '/p/'.str_repeat('a', 32));
        self::assertResponseStatusCodeSame(404);

        $payload = new EncryptedPayload(str_repeat('s', SODIUM_CRYPTO_PWHASH_SALTBYTES), str_repeat('n', SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), 'ciphertext');
        $paste = new Paste(str_repeat('b', 32), null, $payload, new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($paste);
        $this->entityManager->flush();

        $this->client->request('GET', '/p/'.str_repeat('b', 32));
        self::assertResponseStatusCodeSame(404);
    }

    public function testValidationRejectsEmptyAndOversizedInput(): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Зашифровать и получить ссылку')->form([
            'create_paste[text]' => '',
            'create_paste[hint]' => str_repeat('x', 256),
            'create_paste[secret]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'Введите текст');
        self::assertSelectorTextContains('body', 'Введите секретный ключ');
    }

    private function createPaste(): string
    {
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Зашифровать и получить ссылку')->form([
            'create_paste[text]' => 'payload',
            'create_paste[secret]' => 'right',
        ]));
        $crawler = $this->client->followRedirect();

        return (string) parse_url((string) $crawler->filter('#paste-url')->attr('value'), PHP_URL_PATH);
    }
}
