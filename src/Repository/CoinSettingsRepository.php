<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoinSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoinSettings>
 */
class CoinSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoinSettings::class);
    }

    public function get(): CoinSettings
    {
        // the singleton row comes from the migration; without it the defaults apply
        return $this->find(CoinSettings::ID) ?? new CoinSettings();
    }
}
