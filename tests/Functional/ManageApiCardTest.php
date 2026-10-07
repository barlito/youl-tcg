<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManageApiCardTest extends WebTestCase
{
    use ManageApiTestTrait;

    private const string MISSING = '0194c3a0-0000-7000-8000-000000000000';

    public function testShowReturnsTheFullDetail(): void
    {
        $client = self::createClient();
        $card = $this->makeCard($this->makeExtension(), CardStatusEnum::PUBLISHED, CardRarityEnum::RARE);

        $body = $this->jsonRequest($client, 'GET', '/api/admin/cards/' . $card->getId(), $this->newToken());

        self::assertResponseIsSuccessful();
        $this->assertSame($card->getName(), $body['name']);
        $this->assertSame('rare', $body['rarity']);
        $this->assertSame('PUBLISHED', $body['status']);
        $this->assertSame(0, $body['holders']);
        $this->assertNull($body['claimedBy']);
        $this->assertSame($card->getExtension()?->getSlug(), $body['extension']['slug']);
    }

    public function testUnknownCardIsNotFound(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        foreach ([['GET', ''], ['PATCH', ''], ['POST', '/files']] as [$method, $suffix]) {
            $this->jsonRequest($client, $method, '/api/admin/cards/' . self::MISSING . $suffix, $token, ['name' => 'x']);
            $this->assertSame(404, $client->getResponse()->getStatusCode(), $method . $suffix);
        }

        $this->jsonRequest($client, 'GET', '/api/admin/cards/not-a-uuid', $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPatchUpdatesTheCardAndLogsTheChangedFields(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $card = $this->makeCard($this->makeExtension());

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/cards/' . $card->getId(), $this->newToken(), [
            'name' => 'Renamed',
            'description' => 'Lore',
            'rarity' => 'legendary',
            'status' => 'published',
            'alwaysHolo' => true,
            'unique' => true,
            'visualConfigOverride' => ['borderColor' => '#fff'],
        ]);

        self::assertResponseIsSuccessful();
        $this->assertSame('Renamed', $body['name']);
        $this->assertSame('legendary', $body['rarity']);
        $this->assertSame('PUBLISHED', $body['status']);
        $this->assertTrue($body['alwaysHolo']);
        $this->assertTrue($body['unique']);
        $this->assertSame(['borderColor' => '#fff'], $body['visualConfigOverride']);

        $records = $this->auditHandler()->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('card:' . $card->getId(), $records[0]->context['target']);
        $this->assertSame(['from' => 'DRAFT', 'to' => 'PUBLISHED'], $records[0]->context['changes']['status']);
    }

    public function testInvalidValuesAreUnprocessable(): void
    {
        $client = self::createClient();
        $card = $this->makeCard($this->makeExtension());

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/cards/' . $card->getId(), $this->newToken(), [
            'name' => 12,
            'rarity' => 'mythic',
            'status' => 'archived',
            'unique' => 'sometimes',
            'alwaysHolo' => 12,
            'extension' => 'no-such-extension',
            'description' => '',
            'id' => 'x',
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['name', 'rarity', 'status', 'unique', 'alwaysHolo', 'extension', 'description', 'id'], array_keys($body['violations']));
    }

    public function testTheEntityConstraintsApplyToo(): void
    {
        $client = self::createClient();
        $card = $this->makeCard($this->makeExtension());

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/cards/' . $card->getId(), $this->newToken(), ['name' => str_repeat('x', 300)]);

        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('name', $body['violations']);
    }

    public function testAnOwnedCardCannotBeUnpublished(): void
    {
        $client = self::createClient();
        $card = $this->makeCard($this->makeExtension(ExtensionStatusEnum::PUBLISHED), CardStatusEnum::PUBLISHED);
        $this->giveTo($card);

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/cards/' . $card->getId(), $this->newToken(), ['status' => 'draft']);

        self::assertResponseStatusCodeSame(409);
        $this->assertSame('1 joueur(s) possèdent cette carte : elle ne peut plus être dépubliée.', $body['message']);
        $this->assertSame(CardStatusEnum::PUBLISHED, $this->refreshed($card)->getStatus());
    }

    public function testADrawnUniqueCannotBeUnpublishedNorUnflaggedNorMoved(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $other = $this->makeExtension();
        $card = $this->makeCard($this->makeExtension(ExtensionStatusEnum::PUBLISHED), CardStatusEnum::PUBLISHED);
        $holder = new DiscordUser()->setDiscordId('manage-holder-' . uniqid())->setUsername('Holder');
        $this->em()->persist($holder);
        $card->setUnique(true)->setClaimedBy($holder);
        $this->em()->flush();
        $url = '/api/admin/cards/' . $card->getId();

        $this->jsonRequest($client, 'PATCH', $url, $token, ['status' => 'draft']);
        self::assertResponseStatusCodeSame(409);

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['unique' => false]);
        self::assertResponseStatusCodeSame(409);
        $this->assertStringContainsString('le flag unique ne peut plus être modifié', $body['message']);

        $this->jsonRequest($client, 'PATCH', $url, $token, ['extension' => $other->getSlug()]);
        self::assertResponseStatusCodeSame(409);

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['unique' => true, 'description' => 'still editable']);
        self::assertResponseIsSuccessful();
        $this->assertSame('still editable', $body['description']);
    }

    public function testAnUnownedCardMovesToAnotherExtensionButNotOntoADuplicateName(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $target = $this->makeExtension();
        $twin = $this->makeCard($target);
        $card = $this->makeCard($this->makeExtension());
        $url = '/api/admin/cards/' . $card->getId();

        $this->jsonRequest($client, 'PATCH', $url, $token, ['extension' => $target->getSlug(), 'name' => strtoupper($twin->getName())]);
        self::assertResponseStatusCodeSame(409);

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['extension' => $target->getSlug()]);
        self::assertResponseIsSuccessful();
        $this->assertSame($target->getSlug(), $body['extension']['slug']);
    }

    public function testAnOwnedCardCannotChangeExtension(): void
    {
        $client = self::createClient();
        $card = $this->makeCard($this->makeExtension(), CardStatusEnum::PUBLISHED);
        $this->giveTo($card);

        $this->jsonRequest($client, 'PATCH', '/api/admin/cards/' . $card->getId(), $this->newToken(), ['extension' => $this->makeExtension()->getSlug()]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testFilesReplaceTheImages(): void
    {
        $client = self::createClient();
        $card = $this->makeCard($this->makeExtension());

        $body = $this->multipartRequest($client, '/api/admin/cards/' . $card->getId() . '/files', $this->newToken(), [], [
            'image' => $this->pngFile(),
            'mask' => $this->pngFile('mask.png'),
            'foil' => $this->jpegFile(),
        ]);

        self::assertResponseIsSuccessful();
        $this->assertNotNull($body['imageName']);
        $this->assertNotNull($body['imageMaskName']);
        $this->assertNotNull($body['imageFoilName']);
        $this->assertStringStartsWith('/uploads/', (string) $body['imageUrl']);
        $this->assertFileExists(self::getContainer()->getParameter('kernel.cache_dir') . '/uploads/cards/' . $body['imageName']);
    }

    public function testFilesApplyTheImportUploadConstraints(): void
    {
        $client = self::createClient();
        $card = $this->makeCard($this->makeExtension());
        $url = '/api/admin/cards/' . $card->getId() . '/files';
        $token = $this->newToken();

        $body = $this->multipartRequest($client, $url, $token, [], ['mask' => $this->jpegFile()]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('mask', $body['violations']);

        $body = $this->multipartRequest($client, $url, $token, [], ['image' => $this->tmpFile('not an image', 'a.png', 'image/png')]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('image', $body['violations']);

        $this->multipartRequest($client, $url, $token);
        self::assertResponseStatusCodeSame(422);

        $this->assertNull($this->em()->find(Card::class, $card->getId())?->getImageName());
    }
}
