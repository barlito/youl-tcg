<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Admin\FoilSizeSliderScript;
use App\Admin\VisualConfigFields;
use App\Entity\Card;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * @extends AbstractCrudController<Card>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class CardCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UploaderHelper $uploaderHelper,
        private readonly AssetMapperInterface $assetMapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Card::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Carte')
            ->setEntityLabelInPlural('Cartes')
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->renderContentMaximized()
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('extension')
            ->add('rarity')
            ->add('status')
            ->add(BooleanFilter::new('uniqueFlag', 'Carte unique (1/1)'))
            ->add(BooleanFilter::new('alwaysHolo', 'Toujours holo'))
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->addBatchAction(
                Action::new('publishCards', 'Publier')
                    ->linkToCrudAction('publishCards')
                    ->addCssClass('btn btn-primary')
                    ->setIcon('fa fa-eye'),
            )
            ->addBatchAction(
                Action::new('draftCards', 'Repasser en brouillon')
                    ->linkToCrudAction('draftCards')
                    ->addCssClass('btn btn-secondary')
                    ->setIcon('fa fa-eye-slash'),
            )
        ;
    }

    /**
     * @param AdminContext<Card>   $context
     * @param BatchActionDto<Card> $batchActionDto
     */
    #[AdminRoute]
    public function publishCards(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        return $this->applyStatusBatch($context, $batchActionDto, CardStatusEnum::PUBLISHED, 'publiée(s)');
    }

    /**
     * @param AdminContext<Card>   $context
     * @param BatchActionDto<Card> $batchActionDto
     */
    #[AdminRoute]
    public function draftCards(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        return $this->applyStatusBatch($context, $batchActionDto, CardStatusEnum::DRAFT, 'repassée(s) en brouillon');
    }

    /**
     * Mêmes gardes que le batchDelete natif d'EasyAdmin : rejet si le FQCN
     * posté (contrôlé par le client) ne vise pas ce CRUD, puis token CSRF lié
     * à l'action ET au FQCN — le FQCN étant validé d'abord, le token exigé est
     * de fait toujours celui minté pour Card par le listing.
     *
     * @param AdminContext<Card>   $context
     * @param BatchActionDto<Card> $batchActionDto
     */
    private function applyStatusBatch(AdminContext $context, BatchActionDto $batchActionDto, CardStatusEnum $status, string $successLabel): Response
    {
        if (Card::class !== $batchActionDto->getEntityFqcn()) {
            throw new BadRequestHttpException();
        }

        if (!$this->isCsrfTokenValid('ea-batch-action-' . $batchActionDto->getName() . '-' . $batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        $updated = 0;
        foreach ($batchActionDto->getEntityIds() as $entityId) {
            $card = $this->entityManager->find(Card::class, $entityId);
            if ($card instanceof Card && $status !== $card->getStatus()) {
                $card->setStatus($status);
                ++$updated;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('%d carte%s %s.', $updated, $updated > 1 ? 's' : '', $successLabel));

        // retour au listing d'origine (filtres/tri conservés) — c'est le remplacement
        // documenté de BatchActionDto::getReferrerUrl(), déprécié en EA 4.22 ; et
        // AdminUrlGenerator déprécie les URLs non-pretty, donc pas d'URL regénérée
        return $this->redirect($context->getRequest()->headers->get('referer') ?? '/admin');
    }

    /**
     * Floating live preview on the edit page: a fixed iframe renders the real
     * front component and follows the form in real time. The whole form is
     * forwarded to the preview route under its own field names, so the panel
     * has no per-field mapping to keep in sync — CardPreviewController picks
     * the keys it renders. Nothing is persisted. Plain inline JS on purpose —
     * the admin has its own asset pipeline, nothing else to hook.
     */
    #[\Override]
    public function configureAssets(Assets $assets): Assets
    {
        return parent::configureAssets($assets)->addHtmlContentToBody(<<<'HTML'
            <div id="card-live-preview" hidden
                 style="position: fixed; top: 90px; right: 24px; z-index: 1030; width: 300px;
                        background: #12121a; border: 1px solid #2c2c3a; border-radius: 14px;
                        padding: 12px; box-shadow: 0 12px 40px rgba(0,0,0,.45);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong style="color: #cfcfe0; font-size: 12px; letter-spacing: .08em;">PREVIEW LIVE</strong>
                    <button type="button" id="card-live-preview-close"
                            style="border: 0; background: none; color: #8a8aa0; cursor: pointer; font-size: 14px;">✕</button>
                </div>
                <iframe id="card-live-preview-frame" title="Preview"
                        style="width: 276px; height: 400px; border: 0; border-radius: 10px; background: #0b0712;"></iframe>
            </div>
            <script>
            (() => {
                // EA5 pretty URL: /admin/card/{entityId}/edit
                const match = location.pathname.match(/\/admin\/card\/([^\/]+)\/edit$/);
                const entityId = match ? match[1] : null;
                const form = document.querySelector('form[name="Card"]');
                if (!entityId || !form) { return; }

                const panel = document.getElementById('card-live-preview');
                const frame = document.getElementById('card-live-preview-frame');
                panel.hidden = false;
                document.getElementById('card-live-preview-close').addEventListener('click', () => { panel.hidden = true; });

                // uploads are never posted here and the description is never
                // drawn on the card: both would only bloat the URL
                const skippedNames = new Set(['Card[_token]', 'Card[description]']);
                const skippedTypes = new Set(['file', 'submit', 'button', 'reset', 'image']);
                const query = () => {
                    const params = new URLSearchParams();
                    for (const input of form.elements) {
                        if (!input.name || skippedNames.has(input.name) || skippedTypes.has(input.type)) { continue; }
                        if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) { continue; }
                        params.append(input.name, input.value);
                    }
                    return params;
                };

                let last = '';
                const refresh = () => {
                    const src = `/admin/card-preview/${entityId}?${query()}`;
                    if (src === last) { return; }
                    last = src;
                    // replace(): a live preview must not stack one history
                    // entry per keystroke behind the back button
                    if (frame.contentWindow) { frame.contentWindow.location.replace(src); } else { frame.src = src; }
                };

                refresh();
                form.addEventListener('change', () => setTimeout(refresh, 50));
                form.addEventListener('input', () => { clearTimeout(window.__cardPvT); window.__cardPvT = setTimeout(refresh, 600); });
            })();
            </script>
            HTML)
            ->addHtmlContentToBody(FoilSizeSliderScript::HTML)
            ->addHtmlContentToBody(VisualConfigFields::COLOR_PICKER_HTML)
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $imageCss = $this->assetMapper->getAsset('styles/admin/image.css')
            ?? throw new \LogicException('Asset "styles/admin/image.css" not found in the asset map.');

        yield ImageField::new('imageName')
            ->setLabel('Artwork')
            ->hideOnForm()
            ->addCssFiles($imageCss->publicPath)
            ->formatValue(function ($value, $entity): ?string {
                if (!$entity instanceof Card) {
                    throw new UnexpectedTypeException($entity, Card::class);
                }

                return $this->uploaderHelper->asset($entity, 'imageFile');
            })
        ;
        yield Field::new('id')->onlyOnDetail();
        yield FormField::addFieldset('Carte');
        yield Field::new('name')->setLabel('Nom');
        yield Field::new('description')->setLabel('Description')->hideOnIndex();
        yield ChoiceField::new('status')
            ->setLabel('Statut')
            ->setChoices(CardStatusEnum::cases())
        ;
        yield ChoiceField::new('rarity')
            ->setLabel('Rareté')
            ->setChoices(CardRarityEnum::cases())
        ;
        yield AssociationField::new('extension')->setLabel('Univers');
        yield BooleanField::new('unique')
            ->setLabel('Carte unique (1/1)')
            ->renderAsSwitch(false)
        ;
        // read-only on purpose: the holder is set atomically the first time the
        // unique is drawn, reassigning it by hand would rewrite a player's luck
        yield AssociationField::new('claimedBy')
            ->setLabel('Détenteur (1/1)')
            ->setHelp('Joueur qui a tiré cette carte unique. Vide tant qu\'elle n\'est pas sortie.')
            ->onlyOnDetail()
        ;
        yield BooleanField::new('alwaysHolo')
            ->setLabel('Toujours holo')
            ->setHelp('La carte sort toujours en holo, quelle que soit la chance holo du slot.')
            ->renderAsSwitch(false)
        ;

        yield FormField::addFieldset('Images');
        yield VichImageField::new('imageFile')
            ->setLabel('Artwork')
            ->onlyOnForms()
        ;
        yield VichImageField::new('imageFoilFile', allowDelete: true)
            ->setLabel('Foil (fichier)')
            ->setHelp('Prioritaire sur la texture foil de la bibliothèque ci-dessous.')
            ->onlyOnForms()
        ;
        yield VichImageField::new('imageMaskFile', allowDelete: true)
            ->setLabel('Masque holo')
            ->onlyOnForms()
        ;

        yield FormField::addFieldset('Visuel')
            ->setHelp('Cascade : un réglage vide est hérité de l\'univers de la carte, sinon il le surcharge.')
        ;
        yield from VisualConfigFields::fields(isOverride: true);
        yield Field::new('visualConfigOverrideJson')->setLabel('Surcharges visuelles (JSON)')->onlyOnDetail();
    }
}
