<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
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

/**
 * Import API (bearer token, ROLE_IMPORT_API): create + read only, always DRAFT.
 */
#[Route('/api/admin/extensions')]
class ImportApiExtensionController extends AbstractController
{
    public function __construct(
        private readonly ExtensionRepository $extensionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportApiInputValidator $inputValidator,
        private readonly ImportApiPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_admin_extension_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(
            fn (array $row): array => $this->presenter->extension($row['extension'], $row['cardCount']),
            $this->extensionRepository->findAllWithCardCount(),
        ));
    }

    #[Route('', name: 'api_admin_extension_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $errors = $this->inputValidator->validateExtension($request);
        if ([] !== $errors) {
            return $this->json(['error' => 'Validation failed', 'violations' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $name = trim((string) $request->request->get('name'));
        $existing = $this->extensionRepository->findOneByNameIgnoringCase($name);
        if ($existing instanceof Extension) {
            return $this->json([
                'error' => 'Extension already exists',
                'extension' => $this->presenter->extension($existing, $existing->getCards()->count()),
            ], Response::HTTP_CONFLICT);
        }

        $extension = new Extension()
            ->setName($name)
            ->setDescription(trim((string) $request->request->get('description')))
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $image = $request->files->get('image');
        if ($image instanceof UploadedFile) {
            $extension->setImageFile($image);
        }
        $logo = $request->files->get('logo');
        if ($logo instanceof UploadedFile) {
            $extension->setLogoFile($logo);
        }

        $this->entityManager->persist($extension);
        $this->entityManager->flush();

        return $this->json($this->presenter->extension($extension, 0), Response::HTTP_CREATED);
    }
}
