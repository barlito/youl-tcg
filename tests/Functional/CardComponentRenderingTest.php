<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CardComponentRenderingTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);
    }

    public function testMaskedCardExposesMaskAndFoilVariables(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::LEGENDARY);
        $card->setImageMaskName('demo_mask.png');
        $card->setImageFoilName('demo_foil.png');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $node = $crawler->filter(\sprintf('[id="%s"]', $card->getId()));
        $this->assertCount(1, $node);
        $this->assertStringContainsString('masked', (string) $node->attr('class'));
        $this->assertSame('legendary', $node->attr('data-rarity'));

        $style = (string) $node->attr('style');
        $this->assertStringContainsString("--mask: url('/uploads/masks/demo_mask.png')", $style);
        $this->assertStringContainsString("--foil: url('/uploads/foils/demo_foil.png')", $style);
    }

    public function testPlainCardHasNoMaskWiring(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $node = $crawler->filter(\sprintf('[id="%s"]', $card->getId()));
        $this->assertCount(1, $node);
        $this->assertStringNotContainsString('masked', (string) $node->attr('class'));
        $this->assertSame('common', $node->attr('data-rarity'));
        $this->assertNull($node->attr('style'));
    }

    public function testHoloCardWithoutPresetFallsBackToBasic(): void
    {
        // The per-rarity holo recipes are gone: a card rendered holo whose
        // cascade resolves no preset must light up through holo--basic.
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card, 'holo' => true])
        ;

        $this->assertStringContainsString('holo--basic', $html);
    }

    public function testHoloCardKeepsItsConfiguredPreset(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $card->setVisualConfigOverride(new \App\Dto\VisualConfig(holoEffect: \App\Enum\Card\CardEffectEnum::COSMOS));
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card, 'holo' => true])
        ;

        $this->assertStringContainsString('holo--cosmos', $html);
        $this->assertStringNotContainsString('holo--basic', $html);
    }

    public function testArtworkComesFromUploadsAndErrorFallbackFromStatic(): void
    {
        // Contrat du split volume/statique : le contenu uploadé (Vich) sort de
        // /uploads/ (monté en volume en prod), le repli d'erreur de /images/
        // (statique, embarqué dans l'image Docker).
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        $this->assertStringContainsString('src="/uploads/cards/default_card.png"', $html);
        $this->assertStringContainsString("this.src='/images/default_card.png'", $html);
    }

    public function testFoilSizeEmitsTheImgsizeVariable(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $card->setVisualConfigOverride(new \App\Dto\VisualConfig(foilSize: 45));
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        $this->assertStringContainsString('--imgsize: 45%', $html);
    }

    public function testWithoutFoilSizeNoImgsizeVariableIsEmitted(): void
    {
        // pas d'override --imgsize : le réglage du preset (cover, ou le tiling
        // 20% du trainer non maské) doit rester maître
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        $this->assertStringNotContainsString('--imgsize', $html);
    }

    public function testNonHoloCardWithoutPresetGetsNoHoloClass(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        $this->assertStringNotContainsString('holo--', $html);
    }

    public function testFrameRendersByDefaultWithNameAndExtensionFallback(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        $this->assertStringContainsString('card__frame--youl', $html);
        $this->assertStringContainsString('card__watermark--youl', $html);
        $this->assertStringContainsString($card->getName(), $html);
        // no uploaded logo: the extension name renders as styled text
        $this->assertStringContainsString('card-frame__ext', $html);
        $this->assertStringContainsString((string) $card->getExtension()?->getName(), $html);
        $this->assertStringNotContainsString('card-frame__ext-logo', $html);
    }

    public function testFrameNoneRendersNoFrameLayers(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $card->setVisualConfigOverride(new \App\Dto\VisualConfig(frame: \App\Enum\Card\CardFrameEnum::NONE));
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        $this->assertStringNotContainsString('card__frame', $html);
        $this->assertStringNotContainsString('card__watermark', $html);
    }

    public function testUploadedExtensionLogoReplacesTheTextFallback(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $card->getExtension()?->setLogoName('cyberpunk-logo.png');
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        $this->assertStringContainsString('card-frame__ext-logo', $html);
        $this->assertStringContainsString('/uploads/extension_logos/cyberpunk-logo.png', $html);
    }

    public function testNameFontAndFrameLineEmitTheirCssVariables(): void
    {
        $card = $this->createOwnedCard(CardRarityEnum::COMMON);
        $card->setVisualConfigOverride(new \App\Dto\VisualConfig(
            nameFont: \App\Enum\Card\CardNameFontEnum::PIRATA_ONE,
            frameLineStart: '#46e6e6',
            frameLineEnd: '#ff3ea5',
        ));
        $this->entityManager->flush();

        $html = self::getContainer()->get('twig')
            ->render('components/CardComponent.html.twig', ['card' => $card])
        ;

        // the single quotes of the font stack are HTML-escaped inside the style attribute
        $this->assertStringContainsString('--frame-name-font:', $html);
        $this->assertStringContainsString('Pirata One', $html);
        $this->assertStringContainsString('--frame-line-1: #46e6e6', $html);
        $this->assertStringContainsString('--frame-line-2: #ff3ea5', $html);
    }

    private function createOwnedCard(CardRarityEnum $rarity): Card
    {
        $extension = new Extension()
            ->setName('Rendering test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        $card = new Card()
            ->setName('Rendering test card ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity($rarity)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        $userCard = new UserCard()
            ->setDiscordUser($this->user)
            ->setCard($card)
            ->setQuantity(1)
        ;
        $this->entityManager->persist($userCard);

        return $card;
    }
}
