<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Extension;
use App\Exception\Admin\ManageApiException;
use App\Repository\ExtensionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Management API (bearer token, ROLE_MANAGE_API): updates only, never a deletion.
 */
abstract class AbstractManageApiController extends AbstractController
{
    protected ExtensionRepository $extensions;

    #[Required]
    public function setExtensionRepository(ExtensionRepository $extensions): void
    {
        $this->extensions = $extensions;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected function respond(array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        // never $this->json(): the serializer turns an empty object into []
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION);

        return $response->setData($data);
    }

    protected function extension(string $slug): Extension
    {
        return $this->extensions->findOneBy(['slug' => $slug]) ?? throw ManageApiException::notFound('Unknown extension');
    }
}
