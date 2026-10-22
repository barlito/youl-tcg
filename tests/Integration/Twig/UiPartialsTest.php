<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The shared Violet Arcade partials (parts/ui/) rendered on their own.
 */
final class UiPartialsTest extends KernelTestCase
{
    public function testCardCellShowsNameRarityIconAndQuantities(): void
    {
        $card = $this->publishedCard();

        $cell = $this->render('parts/ui/_card_cell.html.twig', ['card' => $card, 'quantity' => 3, 'holoQuantity' => 1]);

        $this->assertSame($card->getName(), trim($cell->filter('.cn')->text()));
        $this->assertSame('×3', trim($cell->filter('.qty')->text()));
        $rarity = $cell->filter('.lbl')->text();
        $this->assertStringContainsString($card->getRarity()->icon() . ' ' . $card->getRarity()->label(), $rarity);
        $this->assertStringContainsString('✦ 1', $rarity);
        $this->assertCount(1, $cell->filter('.card'));
    }

    public function testAMaskedCardCellLeaksNothingButTheRarity(): void
    {
        $cell = $this->render('parts/ui/_card_cell.html.twig', ['card' => null, 'rarity' => 'rare']);

        $this->assertCount(1, $cell->filter('[data-testid="masked-card"][data-rarity="rare"]'));
        $this->assertCount(0, $cell->filter('.card, .cn, img'));
        $this->assertSame('Carte à trouver', trim($cell->filter('.cm')->text()));
        $this->assertStringContainsString('★ Rare', $cell->filter('.lbl')->text());
    }

    public function testUniverseTileLinksToTheUniverse(): void
    {
        $extension = $this->publishedCard()->getExtension();
        \assert($extension instanceof Extension);

        $tile = $this->render('parts/ui/_universe_tile.html.twig', ['extension' => $extension, 'class' => 'h-[280px]']);

        $link = $tile->filter('a.tile');
        $this->assertCount(1, $link);
        $this->assertSame('/univers/' . $extension->getSlug(), $link->attr('href'));
        $this->assertStringContainsString($extension->getName(), $link->text());

        $static = $this->render('parts/ui/_universe_tile.html.twig', ['extension' => $extension, 'href' => false, 'soon' => true]);
        $this->assertCount(0, $static->filter('a'));
        $this->assertCount(1, $static->filter('div.tile.tile--soon'));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Crawler
    {
        $twig = static::getContainer()->get(Environment::class);

        return new Crawler($twig->render($template, $context));
    }

    private function publishedCard(): Card
    {
        $card = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Card::class)->findOneBy(['status' => CardStatusEnum::PUBLISHED]);
        \assert($card instanceof Card);

        return $card;
    }
}
