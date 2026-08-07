<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

/**
 * Deletion guard for the entities the player history points at.
 *
 * Inventory and audit rows (owned cards, openings, claims) deliberately have no
 * ON DELETE: erasing a card a player owns would rewrite their collection. The
 * database therefore refuses the delete, which EasyAdmin surfaces as a bare 409
 * page — accurate, but it tells the admin neither why nor what to do instead.
 *
 * Rather than let the delete fail, the blocking references are counted first:
 * a blocked entity is skipped with an explanation, so a batch keeps deleting
 * everything it legitimately can instead of stopping at the first refusal.
 * Attempting and catching would not do — a failed flush closes the
 * EntityManager, and every later delete of the same batch would fail with it.
 *
 * @template TEntity of object
 *
 * @extends AbstractCrudController<TEntity>
 */
abstract class AbstractGuardedCrudController extends AbstractCrudController
{
    /**
     * What still points at this entity, as human-readable reasons.
     *
     * @return list<string> empty when the entity can be deleted
     */
    abstract protected function deletionBlockers(object $entity): array;

    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $blockers = $this->deletionBlockers($entityInstance);

        if ([] === $blockers) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }

        $label = $entityInstance instanceof \Stringable ? (string) $entityInstance : '';

        $this->addFlash('danger', \sprintf(
            '%sn\'a pas été supprimé : %s. Cet historique est volontairement immuable — pour retirer cet élément du jeu, repasse-le en brouillon plutôt que de le supprimer.',
            '' === $label ? 'L\'élément ' : \sprintf('« %s » ', $label),
            implode(', ', $blockers),
        ));
    }

    /**
     * @param array<string, int> $counts reason template => count, zero entries dropped
     *
     * @return list<string>
     */
    protected function describeBlockers(array $counts): array
    {
        $blockers = [];

        foreach ($counts as $template => $count) {
            if ($count > 0) {
                $blockers[] = \sprintf($template, $count);
            }
        }

        return $blockers;
    }
}
