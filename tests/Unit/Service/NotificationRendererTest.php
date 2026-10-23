<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\FeatureEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Repository\FeatureFlagRepository;
use App\Service\Feature\FeatureFlags;
use App\Service\Notification\NotificationRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotificationRendererTest extends TestCase
{
    public function testABoosterCodeLinksToTheHubWithTheCodePreFilled(): void
    {
        $content = $this->renderer()->describe(NotificationTypeEnum::BOOSTER_CODE, [
            'code' => 'ABCDEFGHJKLM',
            'quantity' => 2,
            'boosterName' => 'Pack Cosmos',
            'message' => 'Merci pour le stream !',
        ]);

        $this->assertSame('/boosters?code=ABCDEFGHJKLM', $content->link);
        $this->assertSame('🎁 Un code booster t\'attend : 2 packs « Pack Cosmos »', $content->text);
        $this->assertSame('Merci pour le stream !', $content->body);
    }

    public function testATamperedCodeOnlyKeepsCodeCharacters(): void
    {
        $content = $this->renderer()->describe(NotificationTypeEnum::BOOSTER_CODE, ['code' => 'ab"><script>', 'quantity' => 1, 'boosterName' => 'Pack']);

        $this->assertSame('/boosters?code=ABSCRIPT', $content->link);
        $this->assertSame('🎁 Un code booster t\'attend : 1 pack « Pack »', $content->text);
        $this->assertNull($content->body);
    }

    public function testAnAnnouncementKeepsOnlyAnInternalLink(): void
    {
        $internal = $this->renderer()->describe(NotificationTypeEnum::ANNOUNCEMENT, ['title' => 'Maintenance', 'message' => 'Ce soir', 'link' => '/univers']);
        $this->assertSame('Maintenance', $internal->text);
        $this->assertSame('Ce soir', $internal->body);
        $this->assertSame('/univers', $internal->link);

        foreach (['https://evil.com', '//evil.com', 'javascript:alert(1)', '/\\evil.com'] as $link) {
            $content = $this->renderer()->describe(NotificationTypeEnum::ANNOUNCEMENT, ['title' => 'x', 'message' => 'y', 'link' => $link]);
            $this->assertNull($content->link, $link);
        }

        $this->assertNull($this->renderer()->describe(NotificationTypeEnum::ANNOUNCEMENT, ['title' => 'x', 'message' => 'y', 'link' => null])->link);
    }

    public function testUniverseCompletedShowsTheRewardAndLinksToTheUniverse(): void
    {
        $content = $this->renderer()->describe(NotificationTypeEnum::UNIVERSE_COMPLETED, ['universe' => 'Cosmos', 'slug' => 'cosmos', 'amount' => 1500]);

        $this->assertSame("Univers Cosmos complété : +1\u{202F}500 YLC", $content->text);
        $this->assertSame('/universes_show?slug=cosmos', $content->link);
        $this->assertSame('/universes', $this->renderer()->describe(NotificationTypeEnum::UNIVERSE_COMPLETED, ['slug' => '../x'])->link);
    }

    public function testTradeNotificationsNameTheOtherPlayerAndLeadToTheTradesPage(): void
    {
        $payload = ['playerName' => 'Benj', 'playerId' => '232457563910832129'];

        $received = $this->renderer()->describe(NotificationTypeEnum::TRADE_RECEIVED, $payload);
        $this->assertSame(['⇄', 'Benj te propose un échange', '/trades'], [$received->icon, $received->text, $received->link]);

        $accepted = $this->renderer()->describe(NotificationTypeEnum::TRADE_ACCEPTED, $payload);
        $this->assertSame(['✓', 'Benj a accepté ton échange', '/trades'], [$accepted->icon, $accepted->text, $accepted->link]);

        $refused = $this->renderer()->describe(NotificationTypeEnum::TRADE_REFUSED, $payload);
        $this->assertSame(['✕', 'Benj a refusé ton échange', '/trades'], [$refused->icon, $refused->text, $refused->link]);

        $this->assertSame('Un joueur te propose un échange', $this->renderer()->describe(NotificationTypeEnum::TRADE_RECEIVED, [])->text);
    }

    public function testAResolvedTradeLinksToItsHistoryEntryWhenTheOfferIsKnown(): void
    {
        $offerId = '019a0000-0000-7000-8000-000000000001';
        $payload = ['playerName' => 'Benj', 'playerId' => '232457563910832129', 'offerId' => $offerId];

        $this->assertSame('/trades?offre=' . $offerId, $this->renderer()->describe(NotificationTypeEnum::TRADE_ACCEPTED, $payload)->link);
        $this->assertSame('/trades?offre=' . $offerId, $this->renderer()->describe(NotificationTypeEnum::TRADE_REFUSED, $payload)->link);

        // legacy entries (no id) and forged ids keep the plain inbox link
        $this->assertSame('/trades', $this->renderer()->describe(NotificationTypeEnum::TRADE_ACCEPTED, ['playerName' => 'Benj'])->link);
        $this->assertSame('/trades', $this->renderer()->describe(NotificationTypeEnum::TRADE_ACCEPTED, ['offerId' => '../admin?x=1'])->link);
    }

    public function testWishlistAlertNamesAWishedCardButNeverAMissingOne(): void
    {
        $named = $this->renderer()->describe(NotificationTypeEnum::WISHLIST_LISTED, ['cardName' => 'Rogue', 'universe' => 'Cyberpunk', 'slug' => 'cyberpunk-2077', 'price' => 40]);
        $this->assertSame(['♡', '« Rogue » de ta wishlist est en vente : 40 YLC', '/market?univers=cyberpunk-2077'], [$named->icon, $named->text, $named->link]);

        $anonymous = $this->renderer()->describe(NotificationTypeEnum::WISHLIST_LISTED, ['cardName' => null, 'universe' => 'Cyberpunk', 'slug' => 'cyberpunk-2077', 'price' => 40]);
        $this->assertSame('Une carte qui te manque dans Cyberpunk est en vente : 40 YLC', $anonymous->text);

        $forged = $this->renderer()->describe(NotificationTypeEnum::WISHLIST_LISTED, ['slug' => '../evil?x=1', 'price' => 1]);
        $this->assertSame('/market', $forged->link);
    }

    public function testAUniquePullWearsTheUniqueCrown(): void
    {
        $content = $this->renderer()->describe(NotificationTypeEnum::UNIQUE_PULLED, ['playerName' => 'Benj', 'playerId' => '232457563910832129', 'universe' => 'Cosmos']);

        $this->assertSame(['♛', 'Benj a tiré une carte unique dans Cosmos'], [$content->icon, $content->text]);
    }

    public function testTradeNotificationsHaveNoLinkWhileTradesAreClosed(): void
    {
        $renderer = $this->renderer([FeatureEnum::TRADES->value => false]);
        $payload = ['playerName' => 'Benj', 'playerId' => '232457563910832129', 'offerId' => '019a0000-0000-7000-8000-000000000001'];

        foreach ([NotificationTypeEnum::TRADE_RECEIVED, NotificationTypeEnum::TRADE_ACCEPTED, NotificationTypeEnum::TRADE_REFUSED] as $type) {
            $content = $renderer->describe($type, $payload);
            $this->assertNull($content->link, $type->value);
            $this->assertStringContainsString('Benj', $content->text);
        }
    }

    public function testAnAnnouncementToAClosedFeaturePageHasNoLink(): void
    {
        $renderer = $this->renderer([
            FeatureEnum::TRADES->value => false,
            FeatureEnum::RECYCLING->value => false,
            FeatureEnum::FUSION->value => false,
            FeatureEnum::WISHLIST->value => false,
        ]);

        foreach (['/echanges', '/echanges?offre=x', '/echanges/nouveau', '/recyclage', '/fusion', '/wishlist#top'] as $link) {
            $this->assertNull($renderer->describe(NotificationTypeEnum::ANNOUNCEMENT, ['title' => 'x', 'link' => $link])->link, $link);
        }

        // only the flagged page itself, not a path sharing its prefix
        $this->assertSame('/fusionnes', $renderer->describe(NotificationTypeEnum::ANNOUNCEMENT, ['title' => 'x', 'link' => '/fusionnes'])->link);
        $this->assertSame('/marche', $renderer->describe(NotificationTypeEnum::ANNOUNCEMENT, ['title' => 'x', 'link' => '/marche'])->link);
        $this->assertSame('/echanges', $this->renderer()->describe(NotificationTypeEnum::ANNOUNCEMENT, ['title' => 'x', 'link' => '/echanges'])->link);
    }

    public function testFeatureFreeLinksSurviveEveryFlagOff(): void
    {
        $renderer = $this->renderer(array_fill_keys(array_map(static fn (FeatureEnum $feature): string => $feature->value, FeatureEnum::cases()), false));

        $this->assertSame('/market?univers=cosmos', $renderer->describe(NotificationTypeEnum::WISHLIST_LISTED, ['slug' => 'cosmos', 'price' => 1])->link);
        $this->assertSame('/universes_show?slug=cosmos', $renderer->describe(NotificationTypeEnum::UNIVERSE_COMPLETED, ['slug' => 'cosmos'])->link);
        $this->assertSame('/market_my_shop', $renderer->describe(NotificationTypeEnum::MARKET_SOLD, [])->link);
    }

    /**
     * @param array<string, bool> $flags every feature ON unless listed
     */
    private function renderer(array $flags = []): NotificationRenderer
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/' . $route . ([] !== $parameters ? '?' . http_build_query($parameters) : ''),
        );

        $repository = $this->createStub(FeatureFlagRepository::class);
        $repository->method('findStates')->willReturn([
            ...array_fill_keys(array_map(static fn (FeatureEnum $feature): string => $feature->value, FeatureEnum::cases()), true),
            ...$flags,
        ]);

        return new NotificationRenderer($urlGenerator, new FeatureFlags($repository));
    }
}
