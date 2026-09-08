<?php

namespace App\Repository;

use App\Entity\Paste;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Paste> */
final class PasteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paste::class);
    }

    public function findActiveByToken(string $token, \DateTimeImmutable $now): ?Paste
    {
        $r = $this->createQueryBuilder('p')->andWhere('p.token = :token')->andWhere('p.expiresAt > :now')->setParameter('token', $token)->setParameter('now', $now)->getQuery()->getOneOrNullResult();

        return $r instanceof Paste ? $r : null;
    }

    public function tokenExists(string $token): bool
    {
        return null !== $this->findOneBy(['token' => $token]);
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('p')->delete()->andWhere('p.expiresAt <= :now')->setParameter('now', $now)->getQuery()->execute();
    }
}
