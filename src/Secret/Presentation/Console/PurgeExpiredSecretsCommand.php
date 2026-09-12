<?php

declare(strict_types=1);

namespace App\Secret\Presentation\Console;

use App\Secret\Application\PurgeExpiredSecrets\PurgeExpiredSecrets;
use App\Secret\Application\PurgeExpiredSecrets\PurgeExpiredSecretsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:paste:purge', description: 'Delete expired pastes')]
final class PurgeExpiredSecretsCommand extends Command
{
    public function __construct(private readonly PurgeExpiredSecretsHandler $handler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = ($this->handler)(new PurgeExpiredSecrets());
        $output->writeln(sprintf('Deleted %d expired paste(s).', $result->deletedCount));

        return Command::SUCCESS;
    }
}
