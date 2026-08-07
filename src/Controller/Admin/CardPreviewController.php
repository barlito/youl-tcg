<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Form\HexColorType;
use App\Repository\CardRepository;
use App\Repository\ExtensionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Standalone card render for the back office: embedded as a floating iframe on
 * the Card edit page, it renders the very same CardComponent as the player
 * pages so the preview shows the card exactly as it will drop.
 *
 * The edit form forwards its UNSAVED values as query parameters under their
 * own field names (Card[...]); they are applied to the in-memory entity, which
 * this request never flushes. Lives under /admin → covered by the ROLE_ADMIN
 * access_control, and every value goes through the sanitising of the save path
 * (VisualConfig::fromArray, the HexColorType pattern).
 */
class CardPreviewController extends AbstractController
{
    /**
     * VisualConfig key => name of the form field carrying it; the two only
     * differ on the glow, which the back office labels "glowColor".
     */
    private const array VISUAL_FIELDS = [
        'glow' => 'glowColor',
        'borderColor' => 'borderColor',
        'cssClass' => 'cssClass',
        'holoEffect' => 'holoEffect',
        'foilTexture' => 'foilTexture',
        'foilSize' => 'foilSize',
        'frame' => 'frame',
        'nameFont' => 'nameFont',
        'frameLineStart' => 'frameLineStart',
        'frameLineEnd' => 'frameLineEnd',
    ];

    /**
     * Keys checked against the HexColorType pattern before reaching the CSS.
     */
    private const array COLOUR_KEYS = ['glow', 'borderColor', 'frameLineStart', 'frameLineEnd'];

    /**
     * The "profiler" service has no autowiring alias, hence the explicit id; it
     * only exists in dev, and a nullable argument resolves to null elsewhere.
     */
    public function __construct(
        #[Autowire(service: 'profiler')]
        private readonly ?Profiler $profiler = null,
    ) {
    }

    #[Route('/admin/card-preview/{id}', name: 'admin_card_preview')]
    public function __invoke(string $id, Request $request, CardRepository $cardRepository, ExtensionRepository $extensionRepository): Response
    {
        // the iframe is 276x400: the debug toolbar would eat a fifth of it.
        // Disabling the profiler stops ProfilerListener from setting the
        // X-Debug-Token header, which is what WebDebugToolbarListener injects on.
        $this->profiler?->disable();

        $card = Uuid::isValid($id) ? $cardRepository->find($id) : null;

        if (!$card instanceof Card) {
            throw $this->createNotFoundException();
        }

        $this->applyLiveValues($card, $request->query->all('Card'), $extensionRepository);

        return $this->render('admin/card_preview.html.twig', ['card' => $card]);
    }

    /**
     * Applies the form's live values on the in-memory entity. An absent key
     * means "field not submitted" and leaves the persisted value alone; an
     * empty one means "cleared", which the cascade reads as inherit.
     *
     * @param array<mixed> $live
     */
    private function applyLiveValues(Card $card, array $live, ExtensionRepository $extensionRepository): void
    {
        if ([] === $live) {
            return;
        }

        // an empty name would be rejected on save: keep the persisted one
        $name = $this->text($live['name'] ?? null);
        if (null !== $name) {
            $card->setName($name);
        }

        $rarity = CardRarityEnum::tryFrom($this->text($live['rarity'] ?? null) ?? '');
        if ($rarity instanceof CardRarityEnum) {
            $card->setRarity($rarity);
        }

        // an unchecked box submits nothing at all: absence is the false value
        $card->setUnique(null !== ($live['unique'] ?? null));

        // the universe drives the wordmark AND the inherited half of the
        // cascade, so a switched select has to move the preview too
        $extensionId = $this->text($live['extension'] ?? null);
        $extension = null !== $extensionId && Uuid::isValid($extensionId) ? $extensionRepository->find($extensionId) : null;
        if ($extension instanceof Extension) {
            $card->setExtension($extension);
        }

        $card->setVisualConfigOverride(VisualConfig::fromArray($this->visualData($live)));
    }

    /**
     * @param array<mixed> $live
     *
     * @return array<string, mixed>
     */
    private function visualData(array $live): array
    {
        $data = [];

        foreach (self::VISUAL_FIELDS as $key => $field) {
            $value = $live[$field] ?? null;

            // same rule as the save path: an invalid literal is dropped instead
            // of leaking into the style attribute
            if (\in_array($key, self::COLOUR_KEYS, true) && !$this->isHexColour($value)) {
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    private function isHexColour(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^' . HexColorType::PATTERN . '$/', trim($value));
    }

    private function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
