<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\Card;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * @extends AbstractCrudController<Card>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class CardCrudController extends AbstractCrudController
{
    public function __construct(private readonly UploaderHelper $uploaderHelper, private readonly AssetMapperInterface $assetMapper)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Card::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->renderContentMaximized()
        ;
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('extension')
            ->add('rarity')
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
        $imageCss = $this->assetMapper->getAsset('styles/admin/image.css')
            ?? throw new \LogicException('Asset "styles/admin/image.css" not found in the asset map.');

        yield ImageField::new('imageName')
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
        yield Field::new('name');
        yield Field::new('description');
        yield ChoiceField::new('status')
            ->setChoices(CardStatusEnum::cases())
        ;
        yield ChoiceField::new('rarity')
            ->setChoices(CardRarityEnum::cases())
        ;
        yield BooleanField::new('unique')
            ->setLabel('Unique Flag')
            ->renderAsSwitch(false)
        ;
        yield BooleanField::new('alwaysHolo')
            ->setLabel('Always holo')
            ->setHelp('Always drawn holo, whatever the slot holo chance')
            ->renderAsSwitch(false)
        ;
        yield AssociationField::new('extension');
        yield VichImageField::new('imageFile')->onlyOnForms();
        yield VichImageField::new('imageFoilFile')
            ->setLabel('Foil texture')
            ->onlyOnForms()
        ;
        yield VichImageField::new('imageMaskFile')
            ->setLabel('Holo mask')
            ->onlyOnForms()
        ;
        yield CodeEditorField::new('visualConfigOverrideJson')
            ->setLabel('Visual overrides')
            ->setLanguage('js')
            ->onlyOnForms()
            ->setHelp('Optional per-card overrides. Example: {"glow": "#ff3db0", "cssClass": "my-card"}')
        ;
        yield Field::new('visualConfigOverrideJson')->setLabel('Visual overrides')->onlyOnDetail();
    }
}
