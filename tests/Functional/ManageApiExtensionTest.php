<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Admin\AdminApiScopeEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManageApiExtensionTest extends WebTestCase
{
    use ManageApiTestTrait;

    public function testShowReturnsTheFullState(): void
    {
        $client = self::createClient();
        $extension = $this->makeExtension();
        $this->makeCard($extension);
        $this->makeBooster($extension);

        $body = $this->jsonRequest($client, 'GET', '/api/admin/extensions/' . $extension->getSlug(), $this->newToken());

        self::assertResponseIsSuccessful();
        $this->assertSame('DRAFT', $body['status']);
        $this->assertSame(1, $body['cardCount']);
        $this->assertSame(1, $body['draftCardCount']);
        $this->assertCount(1, $body['boosters']);
        $this->assertSame([], $body['banners']);
        $this->assertStringContainsString('"visualConfig":{}', (string) $client->getResponse()->getContent());
    }

    public function testUnknownExtensionIsNotFoundOnEveryRoute(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        foreach ([['GET', ''], ['PATCH', ''], ['POST', '/files'], ['POST', '/publish'], ['GET', '/banners'], ['POST', '/banners']] as [$method, $suffix]) {
            $this->jsonRequest($client, $method, '/api/admin/extensions/nope' . $suffix, $token, ['name' => 'x']);
            $this->assertSame(404, $client->getResponse()->getStatusCode(), $method . $suffix);
        }
    }

    public function testPatchUpdatesTheFieldsAndReturnsTheStateAndLogsIt(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $extension = $this->makeExtension();

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $extension->getSlug(), $this->newToken(), [
            'description' => 'Nouvelle description',
            'completionRewardCoins' => 750,
            'visualConfig' => ['glow' => '#a435f0', 'holoEffect' => 'cosmos', 'foilSize' => 60],
        ]);

        self::assertResponseIsSuccessful();
        $this->assertSame('Nouvelle description', $body['description']);
        $this->assertSame(750, $body['completionRewardCoins']);
        $this->assertSame(['glow' => '#a435f0', 'holoEffect' => 'cosmos', 'foilSize' => 60], $body['visualConfig']);
        $this->assertContains('L\'extension n\'a aucune carte.', $body['warnings']);

        $reloaded = $this->refreshed($extension);
        $this->assertInstanceOf(Extension::class, $reloaded);
        $this->assertSame(750, $reloaded->getCompletionRewardCoins());

        $records = $this->auditHandler()->getRecords();
        $this->assertCount(1, $records);
        $context = $records[0]->context;
        $this->assertSame('188967649332428800', $context['admin']);
        $this->assertSame('api_admin_extension_update', $context['route']);
        $this->assertSame('extension:' . $extension->getSlug(), $context['target']);
        $this->assertEqualsCanonicalizing(['description', 'completionRewardCoins', 'visualConfig'], array_keys($context['changes']));
    }

    public function testVisualConfigIsMergedAndNullClearsAKey(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $extension = $this->makeExtension();
        $url = '/api/admin/extensions/' . $extension->getSlug();

        $this->jsonRequest($client, 'PATCH', $url, $token, ['visualConfig' => ['glow' => '#111111', 'frame' => 'youl']]);
        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['visualConfig' => ['glow' => null]]);

        $this->assertSame(['frame' => 'youl'], $body['visualConfig']);

        $this->jsonRequest($client, 'PATCH', $url, $token, ['visualConfig' => null]);
        $this->assertStringContainsString('"visualConfig":{}', (string) $client->getResponse()->getContent());
    }

    public function testInvalidPayloadsAreUnprocessableWithAViolationPerField(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $extension = $this->makeExtension();
        $url = '/api/admin/extensions/' . $extension->getSlug();

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, [
            'name' => '',
            'status' => 'archived',
            'upcoming' => 'maybe',
            'completionRewardCoins' => -5,
            'visualConfig' => ['glow' => 'red', 'holoEffect' => 'nope', 'unknown' => 'x'],
            'surprise' => 1,
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['name', 'status', 'upcoming', 'visualConfig', 'surprise'], array_keys($body['violations']));

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['completionRewardCoins' => -5]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('completionRewardCoins', $body['violations']);

        $this->jsonRequest($client, 'PATCH', $url, $token, []);
        self::assertResponseStatusCodeSame(422);

        $this->jsonRequest($client, 'PATCH', $url, $token, ['completionRewardCoins' => 99999999999]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMalformedJsonIsUnprocessable(): void
    {
        $client = self::createClient();
        $extension = $this->makeExtension();

        $client->request('PATCH', '/api/admin/extensions/' . $extension->getSlug(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->newToken()], content: '{oops');

        self::assertResponseStatusCodeSame(422);
    }

    public function testRenamingToAnExistingNameIsAConflict(): void
    {
        $client = self::createClient();
        $other = $this->makeExtension();
        $extension = $this->makeExtension();

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $extension->getSlug(), $this->newToken(), ['name' => strtoupper($other->getName())]);

        self::assertResponseStatusCodeSame(409);
        $this->assertSame($other->getSlug(), $body['extension']['slug']);
    }

    public function testAnOwnedExtensionCannotGoBackToDraft(): void
    {
        $client = self::createClient();
        $extension = $this->makeExtension(ExtensionStatusEnum::PUBLISHED);
        $this->giveTo($this->makeCard($extension, CardStatusEnum::PUBLISHED));

        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $extension->getSlug(), $this->newToken(), ['status' => 'draft']);

        self::assertResponseStatusCodeSame(409);
        $this->assertStringContainsString('1 joueur(s) possèdent des cartes de cet univers', $body['message']);
        $this->assertSame(ExtensionStatusEnum::PUBLISHED, $this->refreshed($extension)->getStatus());
    }

    public function testAnUnownedExtensionCanBeUnpublishedAndRepublishedThroughPatch(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $extension = $this->makeExtension(ExtensionStatusEnum::PUBLISHED);
        $url = '/api/admin/extensions/' . $extension->getSlug();

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['status' => 'DRAFT']);
        self::assertResponseIsSuccessful();
        $this->assertSame('DRAFT', $body['status']);

        $body = $this->jsonRequest($client, 'PATCH', $url, $token, ['status' => 'published']);
        $this->assertSame('PUBLISHED', $body['status']);
    }

    public function testOnlyOneExtensionStaysUpcoming(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $first = $this->makeExtension();
        $second = $this->makeExtension();

        $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $first->getSlug(), $token, ['upcoming' => true]);
        $body = $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $second->getSlug(), $token, ['upcoming' => '1']);

        self::assertResponseIsSuccessful();
        $this->assertTrue($body['upcoming']);
        $this->em()->clear();
        $this->assertFalse($this->em()->find(Extension::class, $first->getId())?->isUpcoming());
        $this->assertTrue($this->em()->find(Extension::class, $second->getId())?->isUpcoming());
    }

    public function testPublishPublishesTheExtensionAndEveryDraftCard(): void
    {
        $client = self::createClient();
        $extension = $this->makeExtension();
        $draft = $this->makeCard($extension);
        $alreadyPublished = $this->makeCard($extension, CardStatusEnum::PUBLISHED);
        $this->makeBooster($extension);

        $body = $this->jsonRequest($client, 'POST', '/api/admin/extensions/' . $extension->getSlug() . '/publish', $this->newToken());

        self::assertResponseIsSuccessful();
        $this->assertFalse($body['dryRun']);
        $this->assertCount(1, $body['cards']);
        $this->assertSame($draft->getId(), $body['cards'][0]['id']);
        $this->assertSame('PUBLISHED', $body['state']['status']);
        $this->assertSame(2, $body['state']['publishedCardCount']);

        $this->em()->clear();
        $this->assertSame(ExtensionStatusEnum::PUBLISHED, $this->em()->find(Extension::class, $extension->getId())?->getStatus());
        $this->assertSame(CardStatusEnum::PUBLISHED, $this->em()->find(Card::class, $draft->getId())?->getStatus());
        $this->assertSame(CardStatusEnum::PUBLISHED, $this->em()->find(Card::class, $alreadyPublished->getId())?->getStatus());
    }

    public function testDryRunReportsWithoutWritingAnything(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $extension = $this->makeExtension();
        $draft = $this->makeCard($extension);

        $body = $this->jsonRequest($client, 'POST', '/api/admin/extensions/' . $extension->getSlug() . '/publish?dryRun=1', $this->newToken());

        self::assertResponseIsSuccessful();
        $this->assertTrue($body['dryRun']);
        $this->assertTrue($body['extension']['wasDraft']);
        $this->assertSame([$draft->getId()], array_column($body['cards'], 'id'));
        $this->assertSame('DRAFT', $body['state']['status']);

        $this->em()->clear();
        $this->assertSame(ExtensionStatusEnum::DRAFT, $this->em()->find(Extension::class, $extension->getId())?->getStatus());
        $this->assertSame(CardStatusEnum::DRAFT, $this->em()->find(Card::class, $draft->getId())?->getStatus());
        $this->assertSame([], $this->auditHandler()->getRecords());
    }

    public function testPublishWarnsWithoutBlockingWhenThereIsNoBooster(): void
    {
        $client = self::createClient();
        $extension = $this->makeExtension();
        $this->makeCard($extension);
        $token = $this->newToken();

        $body = $this->jsonRequest($client, 'POST', '/api/admin/extensions/' . $extension->getSlug() . '/publish?dryRun=1', $token);
        $this->assertContains('L\'extension n\'a aucun booster : personne ne pourra en tirer les cartes.', $body['warnings']);

        $this->jsonRequest($client, 'POST', '/api/admin/extensions/' . $extension->getSlug() . '/publish', $token);
        self::assertResponseIsSuccessful();
    }

    public function testPublishIsAllOrNothing(): void
    {
        $client = self::createClient();
        $extension = $this->makeExtension();
        $good = $this->makeCard($extension);
        $broken = $this->makeCard($extension);
        // a legacy row the validator refuses: the whole publication must be aborted
        $this->em()->getConnection()->executeStatement("UPDATE card SET name = '' WHERE id = :id", ['id' => $broken->getId()]);

        $body = $this->jsonRequest($client, 'POST', '/api/admin/extensions/' . $extension->getSlug() . '/publish', $this->newToken());

        self::assertResponseStatusCodeSame(422);
        $this->assertNotEmpty($body['violations']);
        $this->em()->clear();
        $this->assertSame(ExtensionStatusEnum::DRAFT, $this->em()->find(Extension::class, $extension->getId())?->getStatus());
        $this->assertSame(CardStatusEnum::DRAFT, $this->em()->find(Card::class, $good->getId())?->getStatus());
    }

    public function testFilesReplaceTheImageAndTheLogo(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $extension = $this->makeExtension();

        $body = $this->multipartRequest($client, '/api/admin/extensions/' . $extension->getSlug() . '/files', $this->newToken(), [], ['image' => $this->pngFile(), 'logo' => $this->jpegFile()]);

        self::assertResponseIsSuccessful();
        $this->assertNotNull($body['imageName']);
        $this->assertNotNull($body['logoName']);
        $this->assertFileExists(self::getContainer()->getParameter('kernel.cache_dir') . '/uploads/extensions/' . $body['imageName']);
        $this->assertSame(['image', 'logo'], array_keys($this->auditHandler()->getRecords()[0]->context['changes']));
    }

    public function testFilesRefuseNothingUnknownFieldsAndBadTypes(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $url = '/api/admin/extensions/' . $this->makeExtension()->getSlug() . '/files';

        $body = $this->multipartRequest($client, $url, $token);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('files', $body['violations']);

        $body = $this->multipartRequest($client, $url, $token, [], ['mask' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('mask', $body['violations']);

        $body = $this->multipartRequest($client, $url, $token, [], ['image' => $this->tmpFile('<svg xmlns="http://www.w3.org/2000/svg"/>', 'a.svg', 'image/svg+xml')]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('image', $body['violations']);
    }

    public function testBannersAreAppendedAndListed(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $extension = $this->makeExtension();
        $url = '/api/admin/extensions/' . $extension->getSlug() . '/banners';

        $first = $this->multipartRequest($client, $url, $token, [], ['image' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(201);
        $this->assertSame(0, $first['position']);

        $second = $this->multipartRequest($client, $url, $token, ['position' => '5'], ['image' => $this->jpegFile()]);
        self::assertResponseStatusCodeSame(201);
        $this->assertSame(5, $second['position']);

        $list = $this->jsonRequest($client, 'GET', $url, $token);
        $this->assertSame([0, 5], array_column($list, 'position'));
    }

    public function testBannerNeedsAnImageAndAValidPosition(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $url = '/api/admin/extensions/' . $this->makeExtension()->getSlug() . '/banners';

        $body = $this->multipartRequest($client, $url, $token);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('image', $body['violations']);

        $body = $this->multipartRequest($client, $url, $token, ['position' => 'first'], ['image' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('position', $body['violations']);
    }

    public function testAFailedRefusalWritesNoLogLine(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $extension = $this->makeExtension();

        $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $extension->getSlug(), $this->newToken(), ['status' => 'nope']);
        $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $extension->getSlug(), $this->newToken(), ['name' => $extension->getName()]);

        $this->assertSame([], $this->auditHandler()->getRecords());
    }

    public function testTheStatsCacheIsDroppedByAWrite(): void
    {
        $client = self::createClient();
        $token = $this->newToken([AdminApiScopeEnum::MANAGE, AdminApiScopeEnum::STATS]);
        $extension = $this->makeExtension();

        $before = $this->jsonRequest($client, 'GET', '/api/admin/stats?sections=catalogue', $token);
        $this->jsonRequest($client, 'PATCH', '/api/admin/extensions/' . $extension->getSlug(), $token, ['status' => 'published']);
        $after = $this->jsonRequest($client, 'GET', '/api/admin/stats?sections=catalogue', $token);

        $this->assertNotSame($before['catalogue'], $after['catalogue']);
    }
}
