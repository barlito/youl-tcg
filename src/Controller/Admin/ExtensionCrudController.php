<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Admin\FoilSizeSliderScript;
use App\Admin\VisualConfigFields;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\ExtensionRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

/**
 * @extends AbstractGuardedCrudController<Extension>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ExtensionCrudController extends AbstractGuardedCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExtensionRepository $extensionRepository,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Extension::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Univers')
            ->setEntityLabelInPlural('Univers')
            ->setDefaultSort(['name' => 'ASC'])
            ->renderContentMaximized()
        ;
    }

    #[\Override]
    public function configureAssets(Assets $assets): Assets
    {
        return parent::configureAssets($assets)
            ->addHtmlContentToBody(FoilSizeSliderScript::HTML)
            ->addHtmlContentToBody(VisualConfigFields::COLOR_PICKER_HTML)
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield Field::new('id')->onlyOnDetail();
        yield Field::new('name')->setLabel('Nom');
        yield Field::new('description')->setLabel('Description');
        yield ChoiceField::new('status')
            ->setLabel('Statut')
            ->setChoices(ExtensionStatusEnum::cases())
        ;
        yield BooleanField::new('upcoming')
            ->setLabel('Prochain univers (teaser)')
            ->setHelp('Affiche l\'univers en tuile floutée « À suivre » sur l\'accueil et /univers, tant qu\'il est en brouillon (publié, il a déjà sa tuile). Un seul univers peut porter le flag : l\'activer ici le retire automatiquement des autres.')
        ;
        yield ImageField::new('imageName')
            ->setLabel('Image')
            ->setBasePath('/uploads/extensions')
            ->onlyOnIndex()
        ;
        yield VichImageField::new('imageFile', allowDelete: true)
            ->setLabel('Image (tuile univers)')
            ->setHelp('Fond des tuiles de l\'univers (accueil, /univers, collection). Sans image : artwork de la carte publiée la plus rare, sinon gradient seul.')
            ->onlyOnForms()
        ;
        yield VichImageField::new('logoFile', allowDelete: true)
            ->setLabel('Logo (cadre des cartes)')
            ->setHelp('Wordmark de l\'univers (PNG/SVG transparent) affiché en haut à droite du cadre CSS des cartes. Sans logo : le nom de l\'univers est écrit en texte stylé.')
            ->onlyOnForms()
        ;
        yield FormField::addFieldset('Visuel des cartes du set')
            ->setHelp('Réglages par défaut de toutes les cartes de l\'univers ; chaque carte peut les surcharger un par un.')
        ;
        yield from VisualConfigFields::fields(isOverride: false);
        yield Field::new('visualConfigJson')->setLabel('Config visuelle (JSON)')->onlyOnDetail();
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->enforceSingleUpcoming($entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->enforceSingleUpcoming($entityInstance);
    }

    /**
     * Runs AFTER the save (the id is generated on persist): the freshly
     * flagged extension is the only one allowed to keep the flag.
     */
    private function enforceSingleUpcoming(object $entityInstance): void
    {
        if ($entityInstance instanceof Extension && $entityInstance->isUpcoming()) {
            $this->extensionRepository->clearUpcomingExcept($entityInstance);
        }
    }

    #[\Override]
    protected function deletionBlockers(object $entity): array
    {
        return $this->describeBlockers([
            '%d carte(s) lui appartiennent' => $this->entityManager->getRepository(Card::class)->count(['extension' => $entity]),
            '%d booster(s) lui appartiennent' => $this->entityManager->getRepository(Booster::class)->count(['extension' => $entity]),
        ]);
    }
}
