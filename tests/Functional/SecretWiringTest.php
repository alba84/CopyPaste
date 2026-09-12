<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Crypto\Domain\Cryptography;
use App\Crypto\Infrastructure\Sodium\SodiumCryptography;
use App\Secret\Application\CreateSecret\CreateSecretHandler;
use App\Secret\Application\PurgeExpiredSecrets\PurgeExpiredSecretsHandler;
use App\Secret\Application\RevealSecret\LoadRevealSecretHandler;
use App\Secret\Application\RevealSecret\RevealSecretHandler;
use App\Secret\Domain\Repository\SecretRepository;
use App\Secret\Domain\Service\TokenGenerator;
use App\Secret\Infrastructure\Persistence\Doctrine\DoctrineSecretRepository;
use App\Secret\Infrastructure\Token\RandomTokenGenerator;
use App\Secret\Presentation\Http\Form\CreateSecretType;
use App\Secret\Presentation\Http\Form\RevealSecretType;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\RouterInterface;

final class SecretWiringTest extends KernelTestCase
{
    public function testContainerExposesCreateSecretPortsAndHandler(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(SodiumCryptography::class, $container->get(Cryptography::class));
        self::assertInstanceOf(RandomTokenGenerator::class, $container->get(TokenGenerator::class));
        self::assertInstanceOf(DoctrineSecretRepository::class, $container->get(SecretRepository::class));
        self::assertInstanceOf(ClockInterface::class, $container->get(ClockInterface::class));
        self::assertInstanceOf(CreateSecretHandler::class, $container->get(CreateSecretHandler::class));
    }

    public function testContainerExposesRevealSecretHandlers(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(RevealSecretHandler::class, $container->get(RevealSecretHandler::class));
        self::assertInstanceOf(LoadRevealSecretHandler::class, $container->get(LoadRevealSecretHandler::class));
    }

    public function testContainerExposesPurgeExpiredSecretsHandler(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(PurgeExpiredSecretsHandler::class, $container->get(PurgeExpiredSecretsHandler::class));
    }

    public function testCreateSecretTypeKeepsLegacyWireContract(): void
    {
        self::bootKernel();
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $type = new CreateSecretType();
        self::assertSame('create_paste', $type->getBlockPrefix());

        $form = $formFactory->create(CreateSecretType::class);
        self::assertSame('create_paste', $form->getName());
        self::assertSame('create_paste', $form->getConfig()->getOption('csrf_token_id'));
    }

    public function testRevealSecretTypeKeepsLegacyWireContract(): void
    {
        self::bootKernel();
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $type = new RevealSecretType();
        self::assertSame('unlock_paste', $type->getBlockPrefix());

        $form = $formFactory->create(RevealSecretType::class);
        self::assertSame('unlock_paste', $form->getName());
        self::assertSame('unlock_paste', $form->getConfig()->getOption('csrf_token_id'));
    }

    public function testRevealRouteKeepsLegacyNameAndUrl(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame('/p/'.str_repeat('a', 32), $router->generate('app_paste_view', ['token' => str_repeat('a', 32)]));
    }
}
