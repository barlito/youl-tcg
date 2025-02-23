<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Card;
use App\Enum\Entity\CardStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 *
 * @method Card|null find($id, $lockMode = null, $lockVersion = null)
 * @method Card|null findOneBy(array $criteria, array $orderBy = null)
 * @method Card[]    findAll()
 * @method Card[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    public function findRandomCardId(int $maxResult): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT id FROM card WHERE status = :status ORDER BY RANDOM() LIMIT :maxResult';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'status' => CardStatusEnum::DRAFT->value,
            'maxResult' => $maxResult,
        ]);

        return $result->fetchFirstColumn();
    }
}
