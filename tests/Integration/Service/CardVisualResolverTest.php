<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Card\CardFrameEnum;
use App\Enum\Card\CardNameFontEnum;
use App\Enum\Card\FoilTextureEnum;
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

    public function testCardOwnFoilAndMaskAreResolved(): void
    {
        $card = $this->card($this->extension());
        $card->setImageFoilName('card-foil.png');
        $card->setImageMaskName('card-mask.png');

        $resolved = $this->resolver->resolve($card);

        $this->assertSame('/uploads/foils/card-foil.png', $resolved->foilUrl);
        $this->assertSame('/uploads/masks/card-mask.png', $resolved->maskUrl);
        $this->assertTrue($resolved->hasMask());
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

    public function testHoloEffectCascadesCardOverExtension(): void
    {
        $extension = $this->extension()->setVisualConfig(new VisualConfig(holoEffect: CardEffectEnum::BASIC));
        $card = $this->card($extension)->setVisualConfigOverride(new VisualConfig(holoEffect: CardEffectEnum::COSMOS));

        $this->assertSame(CardEffectEnum::COSMOS, $this->resolver->resolve($card)->holoEffect);
    }

    public function testHoloEffectFallsBackToExtensionThenNull(): void
    {
        $extension = $this->extension()->setVisualConfig(new VisualConfig(holoEffect: CardEffectEnum::TRAINER));

        $this->assertSame(CardEffectEnum::TRAINER, $this->resolver->resolve($this->card($extension))->holoEffect);
        $this->assertNull($this->resolver->resolve($this->card($this->extension()))->holoEffect);
    }

    public function testFoilTextureLibraryFallsBackThroughTheCascade(): void
    {
        // extension picks a library texture, the card overrides with another
        $extension = $this->extension()->setVisualConfig(new VisualConfig(foilTexture: FoilTextureEnum::GEOMETRIC));
        $card = $this->card($extension)->setVisualConfigOverride(new VisualConfig(foilTexture: FoilTextureEnum::ANCIENT));

        $this->assertSame('/images/holo/poke/ancient.png', $this->resolver->resolve($card)->foilUrl);
        $this->assertSame('/images/holo/poke/geometric.png', $this->resolver->resolve($this->card($extension))->foilUrl);
        $this->assertNull($this->resolver->resolve($this->card($this->extension()))->foilUrl);
    }

    public function testUploadedFoilBeatsTheLibraryTexture(): void
    {
        $extension = $this->extension()->setVisualConfig(new VisualConfig(foilTexture: FoilTextureEnum::VMAX));
        $card = $this->card($extension);
        $card->setImageFoilName('own_foil.png');

        $this->assertSame('/uploads/foils/own_foil.png', $this->resolver->resolve($card)->foilUrl);
    }

    public function testFoilSizeFallsBackThroughTheCascade(): void
    {
        $extension = $this->extension()->setVisualConfig(new VisualConfig(foilSize: 30));
        $card = $this->card($extension)->setVisualConfigOverride(new VisualConfig(foilSize: 60));

        $this->assertSame(60, $this->resolver->resolve($card)->foilSize);
        $this->assertSame(30, $this->resolver->resolve($this->card($extension))->foilSize);
        $this->assertNull($this->resolver->resolve($this->card($this->extension()))->foilSize);
    }

    public function testFoilSizeCssMapsCoverAndTiling(): void
    {
        $extension = $this->extension();

        $this->assertSame('45%', $this->resolver->resolve($this->card($extension)->setVisualConfigOverride(new VisualConfig(foilSize: 45)))->foilSizeCss());
        $this->assertSame('cover', $this->resolver->resolve($this->card($extension)->setVisualConfigOverride(new VisualConfig(foilSize: 100)))->foilSizeCss());
        $this->assertNull($this->resolver->resolve($this->card($extension))->foilSizeCss());
    }

    public function testFrameDefaultsToYoulAndCascades(): void
    {
        // system default: frames are on
        $bare = $this->resolver->resolve($this->card($this->extension()));
        $this->assertSame(CardFrameEnum::YOUL, $bare->frame);
        $this->assertSame('youl', $bare->frameVariant());

        // extension opts out, card opts back in
        $extension = $this->extension()->setVisualConfig(new VisualConfig(frame: CardFrameEnum::NONE));
        $card = $this->card($extension)->setVisualConfigOverride(new VisualConfig(frame: CardFrameEnum::YOUL));

        $this->assertNull($this->resolver->resolve($this->card($extension))->frameVariant());
        $this->assertSame(CardFrameEnum::YOUL, $this->resolver->resolve($card)->frame);
    }

    public function testNameFontAndFrameLineCascade(): void
    {
        $extension = $this->extension()->setVisualConfig(new VisualConfig(
            nameFont: CardNameFontEnum::PIRATA_ONE,
            frameLineStart: '#111111',
            frameLineEnd: '#222222',
        ));
        $card = $this->card($extension)->setVisualConfigOverride(new VisualConfig(frameLineEnd: '#333333'));

        $resolved = $this->resolver->resolve($card);

        $this->assertSame(CardNameFontEnum::PIRATA_ONE, $resolved->nameFont);
        $this->assertSame('#111111', $resolved->frameLineStart);
        $this->assertSame('#333333', $resolved->frameLineEnd);

        $bare = $this->resolver->resolve($this->card($this->extension()));
        $this->assertNull($bare->nameFont);
        $this->assertNull($bare->frameLineStart);
        $this->assertNull($bare->frameLineEnd);
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
