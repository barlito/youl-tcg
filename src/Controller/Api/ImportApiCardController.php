<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Card;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\CardRepository;
use App\Repository\ExtensionRepository;
use App\Service\Admin\ImportApiInputValidator;
use App\Service\Admin\ImportApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import API (bearer token, ROLE_IMPORT_API): create + read only, always DRAFT.
 */
#[Route('/api/admin/extensions/{slug}/cards')]
class ImportApiCardController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensionRepository,
        private readonly CardRepository $cardRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportApiInputValidator $inputValidator,
        private readonly ValidatorInterface $validator,
        private readonly ImportApiPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_admin_card_list', methods: ['GET'])]
    public function list(string $slug): JsonResponse
    {
        $extension = $this->extensionRepository->findOneBy(['slug' => $slug]);
        if (null === $extension) {
            return $this->notFound();
        }

        return $this->json(array_map(
            $this->presenter->card(...),
            $this->cardRepository->findBy(['extension' => $extension], ['name' => 'ASC']),
        ));
    }

    #[Route('', name: 'api_admin_card_create', methods: ['POST'])]
    public function create(string $slug, Request $request): JsonResponse
    {
        $extension = $this->extensionRepository->findOneBy(['slug' => $slug]);
        if (null === $extension) {
            return $this->notFound();
        }

        $errors = $this->inputValidator->validateCard($request);
        if ([] !== $errors) {
            return $this->json(['error' => 'Validation failed', 'violations' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $name = trim((string) $request->request->get('name'));
        $existing = $this->cardRepository->findOneByExtensionAndNameIgnoringCase($extension, $name);
        if ($existing instanceof Card) {
            return $this->json(['error' => 'Card already exists in this extension', 'card' => $this->presenter->card($existing)], Response::HTTP_CONFLICT);
        }

        $rarity = CardRarityEnum::from((string) $request->request->get('rarity'));
        $card = new Card()
            ->setName($name)
            ->setDescription(trim((string) $request->request->get('description')))
            ->setRarity($rarity)
            ->setStatus(CardStatusEnum::DRAFT)
            ->setUnique($this->inputValidator->flagValue($request, 'unique') ?? false)
            // legendaries are always holo unless the caller says otherwise
            ->setAlwaysHolo($this->inputValidator->flagValue($request, 'alwaysHolo') ?? CardRarityEnum::LEGENDARY === $rarity)
            ->setExtension($extension)
        ;
        $card->setImageFile($this->uploadedFile($request, 'image'));
        $card->setImageMaskFile($this->uploadedFile($request, 'mask'));
        $card->setImageFoilFile($this->uploadedFile($request, 'foil'));

        // property-level only: validating the whole Card cascades to every card of the extension
        $violations = $this->validator->validateProperty($card, 'name');
        $violations->addAll($this->validator->validateProperty($card, 'description'));
        if (\count($violations) > 0) {
            return $this->json(['error' => 'Validation failed', 'violations' => $this->inputValidator->toFieldErrors($violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $this->json($this->presenter->card($card), Response::HTTP_CREATED);
    }

    private function uploadedFile(Request $request, string $field): ?UploadedFile
    {
        $file = $request->files->get($field);

        return $file instanceof UploadedFile ? $file : null;
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'Unknown extension'], Response::HTTP_NOT_FOUND);
    }
}
