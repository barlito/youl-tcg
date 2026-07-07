<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\VisualConfig;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\FoilTextureEnum;
use App\Repository\CardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Standalone card render for the back office: embedded as a floating iframe on
 * the Card edit page. The edit form's UNSAVED visual values come in as query
 * parameters and are applied to the in-memory entity (never flushed), so the
 * preview follows the form in real time. Lives under /admin → covered by the
 * ROLE_ADMIN access_control; values are sanitised by VisualConfig::fromArray.
 */
class CardPreviewController extends AbstractController
{
    #[Route('/admin/card-preview/{id}', name: 'admin_card_preview')]
    public function __invoke(string $id, Request $request, CardRepository $cardRepository): Response
    {
        $card = Uuid::isValid($id) ? $cardRepository->find($id) : null;

        if (null === $card) {
            throw $this->createNotFoundException();
        }

        $override = $this->liveOverride($request);
        if ($override instanceof VisualConfig) {
            $card->setVisualConfigOverride($override);
        }

        return $this->render('admin/card_preview.html.twig', ['card' => $card]);
    }

    /**
     * Rebuilds the visual override from the form's live values: the JSON editor
     * content first, then the dedicated selects/pickers on top (they win, same
     * merge order as the form itself). Null when no live value was provided.
     */
    private function liveOverride(Request $request): ?VisualConfig
    {
        if (!$request->query->has('live')) {
            return null;
        }

        $data = [];

        $json = $request->query->getString('json');
        if ('' !== $json) {
            try {
                $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    $data = $decoded;
                }
            } catch (\JsonException) {
                // invalid JSON while typing: ignore, the selects still apply
            }
        }

        foreach (['holoEffect', 'foilTexture', 'glow'] as $key) {
            $value = $request->query->getString($key);
            if ('' !== $value) {
                $data[$key] = $value;
            }
        }

        // EasyAdmin's ChoiceField submits the enum INDEX (cases() order), not
        // its value — translate before the sanitising fromArray().
        if (isset($data['holoEffect']) && \is_string($data['holoEffect']) && ctype_digit($data['holoEffect'])) {
            $data['holoEffect'] = (CardEffectEnum::cases()[(int) $data['holoEffect']] ?? null)?->value;
        }
        if (isset($data['foilTexture']) && \is_string($data['foilTexture']) && ctype_digit($data['foilTexture'])) {
            $data['foilTexture'] = (FoilTextureEnum::cases()[(int) $data['foilTexture']] ?? null)?->value;
        }

        return VisualConfig::fromArray($data);
    }
}
