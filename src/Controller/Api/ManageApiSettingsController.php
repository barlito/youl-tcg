<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\FeatureEnum;
use App\Exception\Admin\ManageApiException;
use App\Service\Admin\Manage\ApiInput;
use App\Service\Admin\Manage\ManageApiPresenter;
use App\Service\Admin\Manage\SettingsManageService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final class ManageApiSettingsController extends AbstractManageApiController
{
    public function __construct(
        private readonly SettingsManageService $manager,
        private readonly ManageApiPresenter $presenter,
    ) {
    }

    #[Route('/settings', name: 'api_admin_settings_show', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->respond($this->presenter->settings($this->manager->settings()));
    }

    #[Route('/settings', name: 'api_admin_settings_update', methods: ['PATCH'])]
    public function updateSettings(Request $request): JsonResponse
    {
        return $this->respond($this->presenter->settings($this->manager->updateSettings(ApiInput::fromRequest($request))));
    }

    #[Route('/features', name: 'api_admin_feature_list', methods: ['GET'])]
    public function features(): JsonResponse
    {
        $result = [];
        foreach ($this->manager->features() as $key => $flag) {
            $result[] = $this->presenter->feature(FeatureEnum::from($key), $flag);
        }

        return $this->respond($result);
    }

    #[Route('/features/{key}', name: 'api_admin_feature_update', methods: ['PATCH'])]
    public function updateFeature(string $key, Request $request): JsonResponse
    {
        $feature = FeatureEnum::tryFrom($key) ?? throw ManageApiException::notFound('Unknown feature');
        $flag = $this->manager->setFeature($feature, ApiInput::fromRequest($request));

        return $this->respond($this->presenter->feature($feature, $flag));
    }
}
