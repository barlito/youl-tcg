<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\Booster;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * @extends AbstractCrudController<Booster>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class BoosterCrudController extends AbstractCrudController
{
    public function __construct(private readonly UploaderHelper $uploaderHelper, private readonly AssetMapperInterface $assetMapper)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Booster::class;
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
                if (!$entity instanceof Booster) {
                    throw new UnexpectedTypeException($entity, Booster::class);
                }

                return $this->uploaderHelper->asset($entity, 'imageFile');
            })
        ;
        yield Field::new('id')->onlyOnDetail();
        yield AssociationField::new('extension');
        yield IntegerField::new('cardCount')
            ->setLabel('Cards')
            ->hideOnForm()
        ;
        yield IntegerField::new('holoRate')
            ->setHelp('Chance (0-100 %) for each drawn card to be holo')
        ;
        yield CodeEditorField::new('rarityRatesJson')
            ->setLabel('Rarity rates')
            ->setLanguage('js')
            ->onlyOnForms()
            ->setHelp('One weight map per card slot. Example: [{"common": 100}, {"common": 100}, {"common": 60, "rare": 30, "legendary": 10}]')
        ;
        yield Field::new('rarityRates')->onlyOnDetail();
        yield VichImageField::new('imageFile')->onlyOnForms();
    }
}
