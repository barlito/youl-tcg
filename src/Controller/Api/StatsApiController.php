<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\Admin\EconomyPeriodEnum;
use App\Enum\Admin\StatsSectionEnum;
use App\Service\Admin\Stats\ApiStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only game statistics (bearer token with the stats scope, ROLE_STATS_API).
 */
#[Route('/api/admin/stats')]
class StatsApiController extends AbstractController
{
    public function __construct(private readonly ApiStatsProvider $statsProvider)
    {
    }

    #[Route('', name: 'api_admin_stats', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $violations = [];
        $period = $this->period($request, $violations);
        $sections = $this->sections($request, $violations);

        if ([] !== $violations) {
            return $this->respond(['error' => 'Validation failed', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respond($this->statsProvider->get($period, $sections));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

        return $response->setData($data);
    }

    /**
     * @param array<string, string> $violations
     */
    private function period(Request $request, array &$violations): EconomyPeriodEnum
    {
        $raw = $request->query->get('period');

        if (null === $raw || '' === $raw) {
            return EconomyPeriodEnum::DEFAULT;
        }

        $period = \is_string($raw) && ctype_digit($raw) ? EconomyPeriodEnum::tryFrom((int) $raw) : null;

        if (!$period instanceof EconomyPeriodEnum) {
            $violations['period'] = 'Must be one of ' . implode(', ', array_map(static fn (EconomyPeriodEnum $case): int => $case->value, EconomyPeriodEnum::cases())) . '.';

            return EconomyPeriodEnum::DEFAULT;
        }

        return $period;
    }

    /**
     * @param array<string, string> $violations
     *
     * @return list<StatsSectionEnum>
     */
    private function sections(Request $request, array &$violations): array
    {
        $raw = $request->query->get('sections');
        $names = \is_string($raw) ? array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $name): bool => '' !== $name) : [];
        $sections = [];
        $unknown = [];

        foreach ($names as $name) {
            $section = StatsSectionEnum::tryFrom($name);

            if ($section instanceof StatsSectionEnum) {
                $sections[] = $section;
            } else {
                $unknown[] = $name;
            }
        }

        if ([] !== $unknown || (null !== $raw && !\is_string($raw))) {
            $violations['sections'] = 'Unknown section(s): ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', StatsSectionEnum::values()) . '.';
        }

        return $sections;
    }
}
