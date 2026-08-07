<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

/**
 * Consultation-only CRUD. Disabling the write actions does more than hide the
 * buttons: EasyAdmin's SecurityVoter refuses EA_EXECUTE_ACTION for a disabled
 * action, so hitting the new/edit/delete URLs by hand answers 403.
 *
 * @template TEntity of object
 *
 * @extends AbstractCrudController<TEntity>
 */
abstract class AbstractReadOnlyCrudController extends AbstractCrudController
{
    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(
                Action::NEW,
                Action::EDIT,
                Action::DELETE,
                Action::BATCH_DELETE,
                Action::SAVE_AND_RETURN,
                Action::SAVE_AND_CONTINUE,
                Action::SAVE_AND_ADD_ANOTHER,
            )
        ;
    }
}
