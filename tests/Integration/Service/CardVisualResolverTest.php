<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Service\Card\CardVisualResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Uses the real CardVisualResolver from the container: UploaderHelper is
 * final and cannot be doubled, but asset() resolves to the mapping's
 * uri_prefix + filename without touching the filesystem, which is enough to
 * assert the cascade.
 */
final class CardVisualResolverTest extends KernelTestCase
{
    private CardVisualResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = self::getContainer()->get(CardVisualResolver::class);
    }

    public function testCardFoilAndMaskWinOverExtensionDefaults(): void
    {
        $extension = $this->extension();
        $extension->setImageFoilName('extension-foil.png');
        $extension->setImageMaskName('extension-mask.png');
        $card = $this->card($extension);
        $card->setImageFoilName('card-foil.png');
        $card->setImageMaskName('card-mask.png');

        $resolved = $this->resolver->resolve($card);

        $this->assertSame('/images/foils/card-foil.png', $resolved->foilUrl);
        $this->assertSame('/images/masks/card-mask.png', $resolved->maskUrl);
        $this->assertTrue($resolved->hasMask());
    }

    public function testFallsBackToExtensionFoilAndMask(): void
    {
        $extension = $this->extension();
        $extension->setImageFoilName('extension-foil.png');
        $extension->setImageMaskName('extension-mask.png');

        $resolved = $this->resolver->resolve($this->card($extension));

        $this->assertSame('/images/foils/extension-foil.png', $resolved->foilUrl);
        $this->assertSame('/images/masks/extension-mask.png', $resolved->maskUrl);
    }

    public function testNoFoilNorMaskResolvesToNull(): void
    {
        $resolved = $this->resolver->resolve($this->card($this->extension()));

        $this->assertNull($resolved->foilUrl);
        $this->assertNull($resolved->maskUrl);
        $this->assertFalse($resolved->hasMask());
    }

    public function testCardOverrideWinsOverExtensionConfig(): void
    {
        $extension = $this->extension()->setVisualConfig(new VisualConfig(glow: '#000000', cssClass: 'ext-class'));
        $card = $this->card($extension)->setVisualConfigOverride(new VisualConfig(glow: '#ffffff'));

        $resolved = $this->resolver->resolve($card);

        // glow overridden, cssClass falls through to the extension's value.
        $this->assertSame('#ffffff', $resolved->glow);
        $this->assertSame('ext-class', $resolved->cssClass);
    }

    public function testFallsBackToExtensionConfigThenNull(): void
    {
        $extension = $this->extension()->setVisualConfig(new VisualConfig(glow: '#a435f0', borderColor: '#ff3db0'));

        $resolved = $this->resolver->resolve($this->card($extension));

        $this->assertSame('#a435f0', $resolved->glow);
        $this->assertSame('#ff3db0', $resolved->borderColor);
        $this->assertNull($resolved->cssClass);
    }

    public function testHoloTuningCascadesCardOverExtension(): void
    {
        $extension = $this->extension()->setVisualConfig(
            new VisualConfig(holoIntensity: 0.4, holoSaturation: 1.1, holoGlitter: 0.3),
        );
        // the card only overrides intensity; saturation/glitter fall through
        $card = $this->card($extension)->setVisualConfigOverride(new VisualConfig(holoIntensity: 0.9));

        $resolved = $this->resolver->resolve($card);

        $this->assertSame(0.9, $resolved->holoIntensity);
        $this->assertSame(1.1, $resolved->holoSaturation);
        $this->assertSame(0.3, $resolved->holoGlitter);
    }

    private function extension(): Extension
    {
        return new Extension()->setName('Test extension')->setDescription('Test');
    }

    private function card(Extension $extension): Card
    {
        return new Card()
            ->setName('Test card')
            ->setExtension($extension)
        ;
    }
}
