<?php

namespace App\Command;

use App\Repository\PasteRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:paste:purge', description: 'Delete expired pastes')]
final class PurgeExpiredPastesCommand extends Command
{
    public function __construct(private readonly PasteRepository $repository, private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connection->executeStatement('PRAGMA journal_mode=WAL');
        $this->connection->executeStatement('PRAGMA busy_timeout=5000');
        $count = $this->repository->purgeExpired(new \DateTimeImmutable());
        if ($count > 0) {
            $this->connection->executeStatement('PRAGMA incremental_vacuum');
        }$output->writeln(sprintf('Deleted %d expired paste(s).', $count));

        return Command::SUCCESS;
    }
}
