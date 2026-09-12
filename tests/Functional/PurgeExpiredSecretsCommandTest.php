<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Schedule;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

final class PurgeExpiredSecretsCommandTest extends KernelTestCase
{
    private const string LEGACY_COMMAND = 'App\\Command\\PurgeExpiredPastesCommand';
    private const string EXPIRED_TOKEN = '11111111111111111111111111111111';
    private const string ACTIVE_TOKEN = '22222222222222222222222222222222';

    public function testCommandKeepsLegacyNameAndDescription(): void
    {
        $application = $this->consoleApplication();

        self::assertTrue($application->has('app:paste:purge'));

        $command = $application->find('app:paste:purge');
        self::assertSame('app:paste:purge', $command->getName());
        self::assertSame('Delete expired pastes', $command->getDescription());
    }

    public function testDeletesExpiredSecretsAndPrintsExactCountAndSucceeds(): void
    {
        self::bootKernel();
        $this->recreateSchema();
        $this->saveSecretWithExpiry(self::EXPIRED_TOKEN, new DateTimeImmutable('-1 hour'));
        $this->saveSecretWithExpiry(self::ACTIVE_TOKEN, new DateTimeImmutable('+1 hour'));

        $tester = $this->runPurgeCommand();

        self::assertSame("Deleted 1 expired paste(s).\n", $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFalse($this->repository()->tokenExists(new Token(self::EXPIRED_TOKEN)));
        self::assertTrue($this->repository()->tokenExists(new Token(self::ACTIVE_TOKEN)));
    }

    public function testPrintsZeroAndSucceedsWhenNothingExpired(): void
    {
        self::bootKernel();
        $this->recreateSchema();

        $tester = $this->runPurgeCommand();

        self::assertSame("Deleted 0 expired paste(s).\n", $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testLegacyPurgeCommandAndRepositoryAreGone(): void
    {
        self::assertFalse(class_exists(self::LEGACY_COMMAND));
        self::assertFalse(class_exists('App\\Repository\\PasteRepository'));
    }

    public function testScheduleStillRunsPurgeCommandHourly(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(Schedule::class);
        self::assertInstanceOf(Schedule::class, $schedule);

        $messages = $schedule->getSchedule()->getRecurringMessages();

        self::assertCount(1, $messages);
        $recurring = $messages[0];
        $trigger = $recurring->getTrigger();
        self::assertInstanceOf(CronExpressionTrigger::class, $trigger);
        self::assertSame('0 * * * *', (string) $trigger);

        $context = new MessageContext('default', 'test', $trigger, new DateTimeImmutable());
        $dispatched = iterator_to_array($recurring->getProvider()->getMessages($context));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(RunCommandMessage::class, $dispatched[0]);
        self::assertSame('app:paste:purge', $dispatched[0]->input);
    }

    private function consoleApplication(): Application
    {
        return new Application(self::bootKernel());
    }

    private function runPurgeCommand(): CommandTester
    {
        $command = $this->consoleApplication()->find('app:paste:purge');
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
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

    private function repository(): SecretRepository
    {
        $repository = self::getContainer()->get(SecretRepository::class);
        self::assertInstanceOf(SecretRepository::class, $repository);

        return $repository;
    }

    private function saveSecretWithExpiry(string $token, DateTimeImmutable $expiresAt): void
    {
        $secret = Secret::reconstitute(
            new Token($token),
            null,
            new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext'),
            $expiresAt->sub(new DateInterval('P1D')),
            $expiresAt,
        );

        $this->repository()->save($secret);
    }
}
