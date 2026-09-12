<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Crypto\Domain\Cryptography;
use App\Crypto\Infrastructure\Sodium\SodiumCryptography;
use App\Secret\Application\CreateSecret\CreateSecretHandler;
use App\Secret\Domain\Repository\SecretRepository;
use App\Secret\Domain\Service\TokenGenerator;
use App\Secret\Infrastructure\Persistence\Doctrine\DoctrineSecretRepository;
use App\Secret\Infrastructure\Token\RandomTokenGenerator;
use App\Secret\Presentation\Http\Form\CreateSecretType;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

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
}
