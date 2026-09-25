<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\Notification\NotificationTypeEnum;
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

    private function renderer(): NotificationRenderer
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/' . $route . ([] !== $parameters ? '?' . http_build_query($parameters) : ''),
        );

        return new NotificationRenderer($urlGenerator);
    }
}
