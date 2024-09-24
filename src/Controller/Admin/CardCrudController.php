<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\ImageField as VichImageField;
use App\Entity\Card;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->renderContentMaximized()
        ;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('extension')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function configureFields(string $pageName): iterable
    {
        yield ImageField::new('imageName')
            ->hideOnForm()
            ->addCssFiles($this->assetMapper->getAsset('styles/admin/image.css')->publicPath)
            ->formatValue(function ($value, $entity) {
                if (!$entity instanceof Card) {
                    throw new UnexpectedTypeException($entity, Card::class);
                }

                return $this->uploaderHelper->asset($entity, 'imageFile');
            })
        ;
        yield Field::new('id')->onlyOnDetail();
        yield Field::new('name');
        yield Field::new('description');
        yield BooleanField::new('isUnique')->renderAsSwitch(false);
        yield AssociationField::new('extension');
        yield VichImageField::new('imageFile')->onlyOnForms();
    }
}
