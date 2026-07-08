<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Batch card creation: one card per uploaded artwork. Cards are created as
 * DRAFT with a placeholder description — the point is to mass-import artworks,
 * then refine each card (name, description, visuals) from the regular CRUD.
 */
class AdminCardBatchController extends AbstractController
{
    private const string DESCRIPTION_PLACEHOLDER = 'À compléter.';

    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/cards/batch', name: 'admin_cards_batch')]
    public function __invoke(Request $request): Response
    {
        // The template extends the EasyAdmin layout, which needs the admin
        // context: a direct hit bounces through the dashboard (same trick as
        // the admin guide).
        if (!$request->attributes->has(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
            return $this->redirect(
                $this->adminUrlGenerator->setRoute('admin_cards_batch')->generateUrl(),
            );
        }

        $form = $this->buildForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{extension: Extension, rarity: CardRarityEnum, images: list<UploadedFile>} $data */
            $data = $form->getData();

            foreach ($data['images'] as $image) {
                $card = new Card()
                    ->setName($this->nameFromFilename($image))
                    ->setDescription(self::DESCRIPTION_PLACEHOLDER)
                    ->setStatus(CardStatusEnum::DRAFT)
                    ->setRarity($data['rarity'])
                    ->setExtension($data['extension'])
                ;
                $card->setImageFile($image);
                $this->entityManager->persist($card);
            }

            $this->entityManager->flush();

            $this->addFlash('success', \sprintf(
                '%d carte(s) créée(s) en brouillon dans « %s » — noms dérivés des fichiers, descriptions à compléter.',
                \count($data['images']),
                $data['extension']->getName(),
            ));

            return $this->redirect(
                $this->adminUrlGenerator->setController(CardCrudController::class)->setAction('index')->generateUrl(),
            );
        }

        return $this->render('admin/card_batch.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @return FormInterface<array{extension: Extension, rarity: CardRarityEnum, images: list<UploadedFile>}|null>
     */
    private function buildForm(): FormInterface
    {
        return $this->createFormBuilder()
            ->add('extension', EntityType::class, [
                'class' => Extension::class,
                'choice_label' => 'name',
                'label' => 'Extension',
            ])
            ->add('rarity', ChoiceType::class, [
                'label' => 'Rareté (appliquée à tout le lot)',
                'choices' => CardRarityEnum::cases(),
                'choice_label' => static fn (CardRarityEnum $rarity): string => ucfirst($rarity->value),
                'choice_value' => static fn (?CardRarityEnum $rarity): string => $rarity->value ?? '',
                'data' => CardRarityEnum::COMMON,
            ])
            ->add('images', FileType::class, [
                'label' => 'Artworks (une carte par fichier)',
                'multiple' => true,
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp'],
                'constraints' => [
                    new Assert\Count(min: 1, minMessage: 'Sélectionne au moins un fichier.'),
                    new Assert\All([
                        new Assert\Image(
                            maxSize: '8M',
                            mimeTypes: ['image/png', 'image/jpeg', 'image/webp'],
                            mimeTypesMessage: 'Formats acceptés : PNG, JPEG, WebP.',
                        ),
                    ]),
                ],
            ])
            ->getForm()
        ;
    }

    /**
     * "farph_evo-alt_red-dead.png" → "Farph Evo Alt Red Dead".
     */
    private function nameFromFilename(UploadedFile $image): string
    {
        $base = pathinfo($image->getClientOriginalName(), \PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[_\-\s]+/', ' ', $base));

        return '' !== $name ? mb_convert_case($name, \MB_CASE_TITLE, 'UTF-8') : 'Carte sans nom';
    }
}
