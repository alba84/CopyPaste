<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SQLitePragmaSubscriber implements EventSubscriberInterface
{
    private bool $configured = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['configure', 100]];
    }

    public function configure(RequestEvent $event): void
    {
        if ($this->configured || !$event->isMainRequest()) {
            return;
        }$this->connection->executeStatement('PRAGMA journal_mode=WAL');
        $this->connection->executeStatement('PRAGMA busy_timeout=5000');
        $this->configured = true;
    }
}
