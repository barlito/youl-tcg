<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Admin\VisualConfigFields;
use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\FoilTextureEnum;
use PHPUnit\Framework\TestCase;

/**
 * The back-office widgets write the visual config JSON through virtual
 * properties (HasVisualConfigTrait): they must round-trip, never clobber each
 * other, and treat an empty widget as "not set" so the cascade can resolve it.
 */
final class VisualConfigFieldsTest extends TestCase
{
    public function testExtensionWidgetsRoundTripThroughTheJsonColumn(): void
    {
        $extension = $this->configuredExtension();

        $config = $extension->getVisualConfig();
        $this->assertSame('#a435f0', $config->glow);
        $this->assertSame('#ff3db0', $config->borderColor);
        $this->assertSame('promo-2026', $config->cssClass);
        $this->assertSame(CardEffectEnum::COSMOS, $config->holoEffect);
        $this->assertSame(FoilTextureEnum::ANCIENT, $config->foilTexture);
        $this->assertSame(45, $config->foilSize);
    }

    public function testCardWidgetsRoundTripThroughTheOverrideColumn(): void
    {
        $card = $this->configuredCard();

        $override = $card->getVisualConfigOverride();
        $this->assertSame('#a435f0', $override->glow);
        $this->assertSame('#ff3db0', $override->borderColor);
        $this->assertSame('promo-2026', $override->cssClass);
        $this->assertSame(CardEffectEnum::COSMOS, $override->holoEffect);
        $this->assertSame(FoilTextureEnum::ANCIENT, $override->foilTexture);
        $this->assertSame(45, $override->foilSize);
    }

    public function testGettersReadBackWhatTheWidgetsWrote(): void
    {
        $card = $this->configuredCard();

        $this->assertSame('#a435f0', $card->getGlowColor());
        $this->assertSame('#ff3db0', $card->getBorderColor());
        $this->assertSame('promo-2026', $card->getCssClass());
        $this->assertSame(CardEffectEnum::COSMOS, $card->getHoloEffect());
        $this->assertSame(FoilTextureEnum::ANCIENT, $card->getFoilTexture());
        $this->assertSame(45, $card->getFoilSize());
    }

    public function testOneWidgetNeverClobbersTheOthers(): void
    {
        $extension = $this->configuredExtension();

        $extension->setGlowColor('#ffffff');

        $config = $extension->getVisualConfig();
        $this->assertSame('#ffffff', $config->glow);
        $this->assertSame('#ff3db0', $config->borderColor);
        $this->assertSame('promo-2026', $config->cssClass);
        $this->assertSame(CardEffectEnum::COSMOS, $config->holoEffect);
        $this->assertSame(FoilTextureEnum::ANCIENT, $config->foilTexture);
        $this->assertSame(45, $config->foilSize);
    }

    public function testEmptyWidgetsMeanInheritAndDropTheKeyEntirely(): void
    {
        $card = $this->configuredCard();

        // an empty select / text input submits '' or null, never a sentinel value
        $card->setHoloEffect(null);
        $card->setFoilTexture(null);
        $card->setGlowColor('');
        $card->setBorderColor(null);
        $card->setCssClass('   ');
        // a range input has no empty state: its leftmost position is the sentinel
        $card->setFoilSize(VisualConfig::FOIL_SIZE_AUTO);

        $this->assertSame([], $card->getVisualConfigOverride()->toArray());
        $this->assertTrue($card->getVisualConfigOverride()->isEmpty());
        $this->assertNull($card->getGlowColor());
        $this->assertSame(VisualConfig::FOIL_SIZE_AUTO, $card->getFoilSize());
    }

    public function testAnEmptyWidgetOnlyClearsItsOwnKey(): void
    {
        $extension = $this->configuredExtension();

        $extension->setCssClass('');

        $this->assertNull($extension->getCssClass());
        $this->assertSame('#a435f0', $extension->getGlowColor());
        $this->assertSame(CardEffectEnum::COSMOS, $extension->getHoloEffect());
    }

    public function testMalformedJsonThrowsAndLeavesTheConfigurationIntact(): void
    {
        // regression: a stray comma used to resolve to [] and wipe every key
        // while the form still reported a success
        $extension = $this->configuredExtension();

        try {
            $extension->setVisualConfigJson('{"glow": "#a435f0",}');
            $this->fail('Malformed JSON must be rejected.');
        } catch (\JsonException) {
        }

        $this->assertSame('#a435f0', $extension->getGlowColor());
        $this->assertSame('promo-2026', $extension->getCssClass());
    }

    public function testMalformedOverrideJsonThrowsAndLeavesTheOverrideIntact(): void
    {
        $card = $this->configuredCard();

        try {
            $card->setVisualConfigOverrideJson('nope');
            $this->fail('Malformed JSON must be rejected.');
        } catch (\JsonException) {
        }

        $this->assertSame('#a435f0', $card->getGlowColor());
    }

    /**
     * The Extension help promises that any set-wide setting is overridable per
     * card: both CRUDs must therefore expose the very same widgets.
     */
    public function testCardAndExtensionExposeTheSameWidgets(): void
    {
        foreach ([true, false] as $isOverride) {
            foreach (VisualConfigFields::fields($isOverride) as $field) {
                $suffix = ucfirst($field->getAsDto()->getProperty());

                foreach ([Card::class, Extension::class] as $entityFqcn) {
                    $this->assertTrue(method_exists($entityFqcn, 'get' . $suffix), $entityFqcn . '::get' . $suffix . '()');
                    $this->assertTrue(method_exists($entityFqcn, 'set' . $suffix), $entityFqcn . '::set' . $suffix . '()');
                }
            }
        }
    }

    /**
     * Guard against a new VisualConfig key that would only be reachable through
     * raw JSON — the very thing the dedicated widgets replaced.
     */
    public function testEveryVisualConfigKeyHasAWidget(): void
    {
        $widgets = [];
        foreach (VisualConfigFields::fields(isOverride: true) as $field) {
            $property = $field->getAsDto()->getProperty();
            // "glow" is exposed as "glowColor" to keep the admin label explicit
            $widgets[] = 'glowColor' === $property ? 'glow' : $property;
        }

        $keys = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            new \ReflectionMethod(VisualConfig::class, '__construct')->getParameters(),
        );

        sort($widgets);
        sort($keys);
        $this->assertSame($keys, $widgets);
    }

    private function configuredExtension(): Extension
    {
        return new Extension()
            ->setGlowColor('#a435f0')
            ->setBorderColor('#ff3db0')
            ->setCssClass('promo-2026')
            ->setHoloEffect(CardEffectEnum::COSMOS)
            ->setFoilTexture(FoilTextureEnum::ANCIENT)
            ->setFoilSize(45)
        ;
    }

    private function configuredCard(): Card
    {
        return new Card()
            ->setGlowColor('#a435f0')
            ->setBorderColor('#ff3db0')
            ->setCssClass('promo-2026')
            ->setHoloEffect(CardEffectEnum::COSMOS)
            ->setFoilTexture(FoilTextureEnum::ANCIENT)
            ->setFoilSize(45)
        ;
    }
}
