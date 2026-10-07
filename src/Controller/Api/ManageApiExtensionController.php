<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Admin\Manage\ApiInput;
use App\Service\Admin\Manage\ExtensionManageService;
use App\Service\Admin\Manage\ManageApiPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/extensions/{slug}')]
final class ManageApiExtensionController extends AbstractManageApiController
{
    public function __construct(
        private readonly ExtensionManageService $manager,
        private readonly ManageApiPresenter $presenter,
    ) {
    }

    #[Route('', name: 'api_admin_extension_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        return $this->respond($this->presenter->extension($this->extension($slug)));
    }

    #[Route('', name: 'api_admin_extension_update', methods: ['PATCH'])]
    public function update(string $slug, Request $request): JsonResponse
    {
        $extension = $this->extension($slug);
        $this->manager->update($extension, ApiInput::fromRequest($request));

        return $this->respond($this->presenter->extension($extension) + ['warnings' => $this->manager->warnings($extension)]);
    }

    #[Route('/files', name: 'api_admin_extension_files', methods: ['POST'])]
    public function files(string $slug, Request $request): JsonResponse
    {
        $extension = $this->extension($slug);
        $this->manager->replaceFiles($extension, $request);

        return $this->respond($this->presenter->extension($extension));
    }

    #[Route('/publish', name: 'api_admin_extension_publish', methods: ['POST'])]
    public function publish(string $slug, Request $request): JsonResponse
    {
        $extension = $this->extension($slug);
        $report = $this->manager->publish($extension, $request->query->getBoolean('dryRun'));

        return $this->respond($report + ['state' => $this->presenter->extension($extension)]);
    }

    #[Route('/banners', name: 'api_admin_extension_banner_list', methods: ['GET'])]
    public function banners(string $slug): JsonResponse
    {
        return $this->respond(array_map($this->presenter->banner(...), $this->extension($slug)->getBanners()->toArray()));
    }

    #[Route('/banners', name: 'api_admin_extension_banner_create', methods: ['POST'])]
    public function addBanner(string $slug, Request $request): JsonResponse
    {
        $banner = $this->manager->addBanner($this->extension($slug), $request);

        return $this->respond($this->presenter->banner($banner), JsonResponse::HTTP_CREATED);
    }
}
