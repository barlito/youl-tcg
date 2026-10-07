<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Card;
use App\Exception\Admin\ManageApiException;
use App\Repository\CardRepository;
use App\Service\Admin\Manage\ApiInput;
use App\Service\Admin\Manage\CardManageService;
use App\Service\Admin\Manage\ManageApiPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/api/admin/cards/{id}', requirements: ['id' => Requirement::UUID])]
final class ManageApiCardController extends AbstractManageApiController
{
    public function __construct(
        private readonly CardRepository $cards,
        private readonly CardManageService $manager,
        private readonly ManageApiPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_admin_card_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->respond($this->presenter->card($this->card($id)));
    }

    #[Route('', name: 'api_admin_card_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $card = $this->card($id);
        $this->manager->update($card, ApiInput::fromRequest($request));

        return $this->respond($this->presenter->card($card));
    }

    #[Route('/files', name: 'api_admin_card_files', methods: ['POST'])]
    public function files(string $id, Request $request): JsonResponse
    {
        $card = $this->card($id);
        $this->manager->replaceFiles($card, $request);

        return $this->respond($this->presenter->card($card));
    }

    private function card(string $id): Card
    {
        return $this->cards->find($id) ?? throw ManageApiException::notFound('Unknown card');
    }
}
