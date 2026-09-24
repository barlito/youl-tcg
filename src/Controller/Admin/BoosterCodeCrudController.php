<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\RecipientTarget;
use App\Entity\BoosterCode;
use App\Entity\DiscordUser;
use App\Exception\Notification\NotificationRefusedException;
use App\Form\Admin\RecipientTargetType;
use App\Service\Notification\BoosterCodeNotifier;
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
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Constraints as Assert;

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
        private readonly BoosterCodeNotifier $boosterCodeNotifier,
        private readonly ClockInterface $clock,
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
        $notify = Action::new('notifyCode', 'Notifier', 'fa fa-bell')
            ->linkToCrudAction('notifyCode')
            ->displayIf(fn (BoosterCode $code): bool => $code->isRedeemable($this->clock->now()))
        ;

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $notify)
            ->add(Crud::PAGE_DETAIL, $notify)
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
        yield TextField::new('formattedCode')->setLabel('Code')->setSortable(false)
            ->setTemplatePath('admin/field/booster_code.html.twig')
        ;
        yield AssociationField::new('booster')->setLabel('Booster');
        yield IntegerField::new('quantity')->setLabel('Packs');
        yield TextField::new('usageLabel')->setLabel('Utilisations')->setSortable(false);
        yield DateTimeField::new('expiresAt')->setLabel('Expire le');
        yield BooleanField::new('disabled')->setLabel('Révoqué')->renderAsSwitch(false);
        yield TextField::new('batchLabel')->setLabel('Lot');
        yield AssociationField::new('assignedTo')->setLabel('Envoyé à')
            ->setHelp('Joueur à qui ce code à usage unique a été notifié : il ne peut plus être envoyé à un autre.')
        ;
        yield DateTimeField::new('createdAt')->setLabel('Créé le')->onlyOnDetail();
        yield Field::new('id')->setLabel('Identifiant')->onlyOnDetail();
    }

    /**
     * Sends an existing code as a notification. Same rules as the batch
     * screen (BoosterCodeNotifier): a revoked, expired or exhausted code is
     * refused, a single-use code only goes to one player, never to all.
     *
     * @param AdminContext<BoosterCode> $context
     */
    #[AdminRoute(path: '/{entityId}/notify', name: 'notify')]
    public function notifyCode(AdminContext $context, Request $request): Response
    {
        $boosterCode = $context->getEntity()->getInstance();

        if (!$boosterCode instanceof BoosterCode) {
            throw new NotFoundHttpException();
        }

        $indexUrl = $this->generateUrl('admin_booster_code_index');
        $blocking = $this->boosterCodeNotifier->stateRefusal($boosterCode);

        if (null !== $blocking) {
            $this->addFlash('danger', $blocking);

            return $this->redirect($indexUrl);
        }

        $form = $this->createFormBuilder(['target' => $boosterCode->isSingleUse() ? RecipientTarget::selection([]) : RecipientTarget::all()])
            ->add('target', RecipientTargetType::class, ['label' => false])
            ->add('message', TextareaType::class, [
                'label' => 'Message joint (optionnel)',
                'required' => false,
                'help' => 'Texte brut ajouté sous « 🎁 Un code booster t\'attend… ». 500 caractères max.',
                'attr' => ['rows' => 3, 'maxlength' => 500],
                'constraints' => [new Assert\Length(max: 500)],
            ])
            ->getForm()
        ;
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{target: RecipientTarget, message: string|null} $data */
            $data = $form->getData();
            $author = $this->getUser();

            try {
                $announcement = $this->boosterCodeNotifier->notifyCode($boosterCode, $data['target'], $data['message'], $author instanceof DiscordUser ? $author : null);

                $this->addFlash('success', \sprintf('Code %s notifié — %s.', $boosterCode->getFormattedCode(), $announcement->getTargetLabel()));

                return $this->redirect($indexUrl);
            } catch (NotificationRefusedException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/booster_code_notify.html.twig', [
            'form' => $form, // a FormInterface makes an invalid submission answer 422
            'boosterCode' => $boosterCode,
            'indexUrl' => $indexUrl,
        ]);
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
