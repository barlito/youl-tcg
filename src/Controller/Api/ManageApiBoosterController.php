<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Booster;
use App\Exception\Admin\ManageApiException;
use App\Repository\BoosterRepository;
use App\Service\Admin\Manage\ApiInput;
use App\Service\Admin\Manage\BoosterManageService;
use App\Service\Admin\Manage\ManageApiPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/admin/boosters')]
final class ManageApiBoosterController extends AbstractManageApiController
{
    public function __construct(
        private readonly BoosterRepository $boosters,
        private readonly BoosterManageService $manager,
        private readonly ManageApiPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_admin_booster_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->respond(array_map(
            $this->presenter->booster(...),
            $this->boosters->findBy([], ['createdAt' => 'ASC']),
        ));
    }

    #[Route('/{id}', name: 'api_admin_booster_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $booster = $this->booster($id);

        return $this->respond($this->presenter->booster($booster) + ['warnings' => $this->manager->warnings($booster)]);
    }

    #[Route('', name: 'api_admin_booster_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $booster = $this->manager->create($request);

        return $this->respond($this->presenter->booster($booster) + ['warnings' => $this->manager->warnings($booster)], JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_booster_update', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $booster = $this->booster($id);
        $this->manager->update($booster, ApiInput::fromRequest($request));

        return $this->respond($this->presenter->booster($booster) + ['warnings' => $this->manager->warnings($booster)]);
    }

    #[Route('/{id}/files', name: 'api_admin_booster_files', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function files(string $id, Request $request): JsonResponse
    {
        $booster = $this->booster($id);
        $this->manager->replaceImage($booster, $request);

        return $this->respond($this->presenter->booster($booster));
    }

    private function booster(string $id): Booster
    {
        return $this->boosters->find($id) ?? throw ManageApiException::notFound('Unknown booster');
    }
}
