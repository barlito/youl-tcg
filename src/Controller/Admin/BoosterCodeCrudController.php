<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BoosterCode;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Codes are generated from the batch page, never hand-written: this CRUD is
 * consultation plus revocation. Revoking keeps the row and its redemption
 * history — a deleted code would take the audit trail with it.
 *
 * @extends AbstractReadOnlyCrudController<BoosterCode>
 */
class BoosterCodeCrudController extends AbstractReadOnlyCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return BoosterCode::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Code')
            ->setEntityLabelInPlural('Codes')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['code', 'batchLabel', 'booster.name'])
            ->setTimezone('Europe/Paris')
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, 'Les codes se créent depuis « Générer des codes ». Pour en révoquer (ou réactiver), coche les lignes concernées : les boutons apparaissent en bas de la liste. Un code révoqué garde son historique — on ne les supprime pas.')
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('booster')
            ->add(TextFilter::new('batchLabel', 'Lot'))
            ->add(BooleanFilter::new('disabled', 'Révoqué'))
            ->add(DateTimeFilter::new('expiresAt', 'Expiration'))
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->addBatchAction(
                Action::new('revokeCodes', 'Révoquer')
                    ->linkToCrudAction('revokeCodes')
                    ->addCssClass('btn btn-danger')
                    ->setIcon('fa fa-ban'),
            )
            ->addBatchAction(
                Action::new('restoreCodes', 'Réactiver')
                    ->linkToCrudAction('restoreCodes')
                    ->addCssClass('btn btn-secondary')
                    ->setIcon('fa fa-rotate-left'),
            )
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        // computed getters: no column to ORDER BY behind them
        yield TextField::new('formattedCode')->setLabel('Code')->setSortable(false);
        yield AssociationField::new('booster')->setLabel('Booster');
        yield IntegerField::new('quantity')->setLabel('Packs');
        yield TextField::new('usageLabel')->setLabel('Utilisations')->setSortable(false);
        yield DateTimeField::new('expiresAt')->setLabel('Expire le');
        yield BooleanField::new('disabled')->setLabel('Révoqué')->renderAsSwitch(false);
        yield TextField::new('batchLabel')->setLabel('Lot');
        yield DateTimeField::new('createdAt')->setLabel('Créé le')->onlyOnDetail();
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
    }

    /**
     * @param AdminContext<BoosterCode>   $context
     * @param BatchActionDto<BoosterCode> $batchActionDto
     */
    #[AdminRoute]
    public function revokeCodes(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        return $this->applyRevocationBatch($context, $batchActionDto, true, 'révoqué(s)');
    }

    /**
     * @param AdminContext<BoosterCode>   $context
     * @param BatchActionDto<BoosterCode> $batchActionDto
     */
    #[AdminRoute]
    public function restoreCodes(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        return $this->applyRevocationBatch($context, $batchActionDto, false, 'réactivé(s)');
    }

    /**
     * Same guards as EasyAdmin's native batchDelete: reject a posted FQCN that
     * does not target this CRUD, then check the CSRF token bound to the action
     * AND the FQCN.
     *
     * @param AdminContext<BoosterCode>   $context
     * @param BatchActionDto<BoosterCode> $batchActionDto
     */
    private function applyRevocationBatch(AdminContext $context, BatchActionDto $batchActionDto, bool $disabled, string $successLabel): Response
    {
        if (BoosterCode::class !== $batchActionDto->getEntityFqcn()) {
            throw new BadRequestHttpException();
        }

        if (!$this->isCsrfTokenValid('ea-batch-action-' . $batchActionDto->getName() . '-' . $batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        $updated = 0;
        foreach ($batchActionDto->getEntityIds() as $entityId) {
            $boosterCode = $this->entityManager->find(BoosterCode::class, $entityId);
            if ($boosterCode instanceof BoosterCode && $disabled !== $boosterCode->isDisabled()) {
                $boosterCode->setDisabled($disabled);
                ++$updated;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('%d code%s %s.', $updated, $updated > 1 ? 's' : '', $successLabel));

        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }
}
