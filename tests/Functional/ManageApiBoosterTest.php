<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManageApiBoosterTest extends WebTestCase
{
    use ManageApiTestTrait;

    private const string MISSING = '0194c3a0-0000-7000-8000-000000000000';

    private const array TWO_SLOTS = [
        ['rarities' => ['common' => 70, 'rare' => 30], 'holoChance' => 10],
        ['rarities' => ['rare' => 90, 'legendary' => 10], 'holoChance' => 40, 'uniqueChance' => 25],
    ];

    public function testListAndShowExposeTheFullConfiguration(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $booster = $this->makeBooster($this->makeExtension(), self::TWO_SLOTS);

        $list = $this->jsonRequest($client, 'GET', '/api/admin/boosters', $token);
        self::assertResponseIsSuccessful();
        $row = array_values(array_filter($list, static fn (array $item): bool => $item['id'] === $booster->getId()))[0];
        $this->assertSame(2, $row['cardCount']);
        $this->assertSame(['common' => 70, 'rare' => 30], $row['rarityRates'][0]['rarities']);
        $this->assertSame(0, $row['rarityRates'][0]['uniqueChance']);
        $this->assertSame(25, $row['rarityRates'][1]['uniqueChance']);

        $one = $this->jsonRequest($client, 'GET', '/api/admin/boosters/' . $booster->getId(), $token);
        $this->assertTrue($one['claimable']);
        $this->assertFalse($one['purchasable']);
        $this->assertNull($one['purchasePrice']);
        $this->assertSame($booster->getExtension()->getSlug(), $one['extension']['slug']);
    }

    public function testUnknownBoosterIsNotFound(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        foreach ([['GET', ''], ['PATCH', ''], ['POST', '/files']] as [$method, $suffix]) {
            $this->jsonRequest($client, $method, '/api/admin/boosters/' . self::MISSING . $suffix, $token, ['name' => 'x']);
            $this->assertSame(404, $client->getResponse()->getStatusCode(), $method . $suffix);
        }
    }

    public function testCreateFromJson(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $extension = $this->makeExtension();

        $body = $this->jsonRequest($client, 'POST', '/api/admin/boosters', $this->newToken(), [
            'name' => 'Pack Full Rare',
            'extension' => $extension->getSlug(),
            'claimable' => false,
            'purchasable' => true,
            'purchasePrice' => 150,
            'rarityRates' => self::TWO_SLOTS,
        ]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSame('Pack Full Rare', $body['name']);
        $this->assertFalse($body['claimable']);
        $this->assertSame(150, $body['purchasePrice']);

        $this->em()->clear();
        $stored = $this->em()->find(Booster::class, $body['id']);
        $this->assertInstanceOf(Booster::class, $stored);
        $this->assertSame(self::TWO_SLOTS, $stored->getRarityRates());
        $this->assertSame($extension->getSlug(), $stored->getExtension()->getSlug());
        $this->assertSame('booster:' . $body['id'], $this->auditHandler()->getRecords()[0]->context['target']);
    }

    public function testCreateFromMultipartWithAnImage(): void
    {
        $client = self::createClient();
        $extension = $this->makeExtension();

        $body = $this->multipartRequest($client, '/api/admin/boosters', $this->newToken(), [
            'extension' => $extension->getSlug(),
            'claimable' => '1',
            'purchasable' => '0',
            'rarityRates' => json_encode(self::TWO_SLOTS, \JSON_THROW_ON_ERROR),
        ], ['image' => $this->pngFile('pack.png')]);

        self::assertResponseStatusCodeSame(201);
        $this->assertNull($body['name']);
        $this->assertSame($extension->getName(), $body['displayName']);
        $this->assertNotNull($body['imageName']);
        $this->assertFileExists(self::getContainer()->getParameter('kernel.cache_dir') . '/uploads/boosters/' . $body['imageName']);
    }

    public function testCreateValidatesEveryField(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        $body = $this->jsonRequest($client, 'POST', '/api/admin/boosters', $token, []);
        self::assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['extension', 'rarityRates'], array_keys($body['violations']));

        $body = $this->jsonRequest($client, 'POST', '/api/admin/boosters', $token, ['extension' => 'nope', 'rarityRates' => self::TWO_SLOTS, 'claimable' => 'x', 'purchasePrice' => 'free']);
        self::assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['extension', 'claimable', 'purchasePrice'], array_keys($body['violations']));

        $body = $this->multipartRequest($client, '/api/admin/boosters', $token, ['extension' => $this->makeExtension()->getSlug(), 'rarityRates' => json_encode(self::TWO_SLOTS)], ['image' => $this->tmpFile('x', 'a.png', 'image/png')]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('image', $body['violations']);
    }

    public function testPatchChangesRatesPriceAndFlags(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $booster = $this->makeBooster($this->makeExtension());

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/boosters/' . $booster->getId(), $this->newToken(), [
            'rarityRates' => self::TWO_SLOTS,
            'purchasable' => true,
            'purchasePrice' => 99,
            'claimable' => false,
            'name' => 'Pack rare',
        ]);

        self::assertResponseIsSuccessful();
        $this->assertSame(99, $body['purchasePrice']);
        $this->assertCount(2, $body['rarityRates']);

        $stored = $this->refreshed($booster);
        $this->assertInstanceOf(Booster::class, $stored);
        $this->assertSame(self::TWO_SLOTS, $stored->getRarityRates());
        $this->assertFalse($stored->isClaimable());

        $changes = $this->auditHandler()->getRecords()[0]->context['changes'];
        $this->assertEqualsCanonicalizing(['rarityRates', 'purchasable', 'purchasePrice', 'claimable', 'name'], array_keys($changes));
    }

    public function testInvalidRarityRatesAreRefusedWithTheBackOfficeMessages(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $booster = $this->makeBooster($this->makeExtension());
        $url = '/api/admin/boosters/' . $booster->getId();

        $invalid = [
            [],
            [['rarities' => ['mythic' => 10], 'holoChance' => 0]],
            [['rarities' => ['common' => 0], 'holoChance' => 0]],
            [['rarities' => ['common' => 10], 'holoChance' => 101]],
            [['rarities' => ['common' => 10], 'holoChance' => 0, 'uniqueChance' => 10001]],
            [['rarities' => ['common' => 10]]],
            ['not a slot'],
        ];

        foreach ($invalid as $rates) {
            $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['rarityRates' => $rates]);
            $this->assertSame(422, $client->getResponse()->getStatusCode(), json_encode($rates));
            $this->assertArrayHasKey('rarityRates', $body['violations']);
        }

        $this->jsonRequest($client, 'PATCH', $url, $token, ['rarityRates' => ['common' => 100]]);
        self::assertResponseStatusCodeSame(422);

        $this->assertSame([['rarities' => ['common' => 100], 'holoChance' => 5]], $this->refreshed($booster)->getRarityRates());
    }

    public function testAPurchasableBoosterNeedsAPositivePrice(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $booster = $this->makeBooster($this->makeExtension());
        $url = '/api/admin/boosters/' . $booster->getId();

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['purchasable' => true]);
        self::assertResponseStatusCodeSame(422);
        $this->assertSame(['Un booster achetable doit avoir un prix.'], $body['violations']['purchasePrice']);

        $this->jsonRequest($client, 'PATCH', $url, $token, ['purchasePrice' => 0]);
        self::assertResponseStatusCodeSame(422);

        $this->jsonRequest($client, 'PATCH', $url, $token, ['purchasePrice' => 5000000000]);
        self::assertResponseStatusCodeSame(422);

        $this->assertFalse($this->refreshed($booster)->isPurchasable());
    }

    public function testPatchWarnsAboutRaritiesWithoutDrawableCards(): void
    {
        $client = self::createClient();
        $booster = $this->makeBooster($this->makeExtension());

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/boosters/' . $booster->getId(), $this->newToken(), ['rarityRates' => self::TWO_SLOTS]);

        self::assertResponseIsSuccessful();
        $this->assertNotEmpty($body['warnings']);
    }

    public function testFilesReplaceTheImage(): void
    {
        $client = self::createClient();
        $booster = $this->makeBooster($this->makeExtension());

        $body = $this->multipartRequest($client, '/api/admin/boosters/' . $booster->getId() . '/files', $this->newToken(), [], ['image' => $this->jpegFile('pack.jpg')]);

        self::assertResponseIsSuccessful();
        $this->assertNotNull($body['imageName']);

        $this->multipartRequest($client, '/api/admin/boosters/' . $booster->getId() . '/files', $this->newToken());
        self::assertResponseStatusCodeSame(422);
    }
}
