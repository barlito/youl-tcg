<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CoinSettings;
use App\Entity\FeatureFlag;
use App\Enum\FeatureEnum;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManageApiSettingsTest extends WebTestCase
{
    use ManageApiTestTrait;

    public function testSettingsAreReadAndPartiallyUpdated(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $token = $this->newToken();

        $before = $this->jsonRequest($client, 'GET', '/api/admin/settings', $token);
        self::assertResponseIsSuccessful();
        $this->assertArrayHasKey('marketFeePercent', $before);

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/settings', $token, ['marketFeePercent' => 7]);

        self::assertResponseIsSuccessful();
        $this->assertSame(7, $body['marketFeePercent']);
        $this->assertSame($before['defaultUniverseRewardCoins'], $body['defaultUniverseRewardCoins']);
        $this->assertSame(7, $this->em()->getRepository(CoinSettings::class)->find(CoinSettings::ID)?->getMarketFeePercent());
        $this->assertSame(['marketFeePercent'], array_keys($this->auditHandler()->getRecords()[0]->context['changes']));
    }

    public function testInvalidSettingsAreUnprocessable(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/settings', $token, ['marketFeePercent' => 101]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('marketFeePercent', $body['violations']);

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/settings', $token, ['defaultUniverseRewardCoins' => -1]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('defaultUniverseRewardCoins', $body['violations']);

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/settings', $token, ['marketFeePercent' => 'a lot', 'fee' => 1]);
        self::assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['marketFeePercent', 'fee'], array_keys($body['violations']));

        $this->jsonRequest($client, 'PATCH', '/api/admin/settings', $token, []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testFeaturesListEveryFeature(): void
    {
        $client = self::createClient();

        $body = $this->jsonRequest($client, 'GET', '/api/admin/features', $this->newToken());

        self::assertResponseIsSuccessful();
        $this->assertEqualsCanonicalizing(array_map(static fn (FeatureEnum $feature): string => $feature->value, FeatureEnum::cases()), array_column($body, 'key'));
        $this->assertArrayHasKey('enabled', $body[0]);
    }

    public function testAFeatureIsSwitchedOnAndOff(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $token = $this->newToken();

        $this->jsonRequest($client, 'PATCH', '/api/admin/features/recycling', $token, ['enabled' => false]);
        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/features/recycling', $token, ['enabled' => true]);
        self::assertResponseIsSuccessful();
        $this->assertTrue($body['enabled']);
        $this->assertSame('recycling', $body['key']);
        $this->em()->clear();
        $this->assertTrue($this->em()->find(FeatureFlag::class, 'recycling')?->isEnabled());
        // the spy handler is reset at the end of every request
        $records = $this->auditHandler()->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('feature:recycling', $records[0]->context['target']);
        $this->assertSame(['from' => false, 'to' => true], $records[0]->context['changes']['enabled']);

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/features/recycling', $token, ['enabled' => 'false']);
        $this->assertFalse($body['enabled']);
        $this->assertSame(['from' => true, 'to' => false], $this->auditHandler()->getRecords()[0]->context['changes']['enabled']);
    }

    public function testAFeatureWithoutARowIsCreatedOnFirstSwitch(): void
    {
        $client = self::createClient();
        $this->em()->createQuery('DELETE FROM ' . FeatureFlag::class . ' f WHERE f.name = :name')->setParameter('name', 'trades')->execute();

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/features/trades', $this->newToken(), ['enabled' => true]);

        self::assertResponseIsSuccessful();
        $this->assertTrue($body['enabled']);
    }

    public function testUnknownFeatureAndBadPayloads(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        $this->jsonRequest($client, 'PATCH', '/api/admin/features/teleport', $token, ['enabled' => true]);
        self::assertResponseStatusCodeSame(404);

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/features/trades', $token, []);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('enabled', $body['violations']);

        $this->jsonRequest($client, 'PATCH', '/api/admin/features/trades', $token, ['enabled' => 'maybe']);
        self::assertResponseStatusCodeSame(422);
    }
}
