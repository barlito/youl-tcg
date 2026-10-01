<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImportApiCardTest extends WebTestCase
{
    use ImportApiTestTrait;

    public function testCreatesADraftCardWhateverTheStatusSentAndStoresTheFiles(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $slug = $this->newExtension($client, $token);
        $name = 'Ichigo ' . uniqid();

        $body = $this->apiRequest($client, 'POST', "/api/admin/extensions/{$slug}/cards", $token, [
            'name' => $name,
            'description' => 'Shinigami',
            'rarity' => 'legendary',
            'status' => 'PUBLISHED',
            'unique' => '1',
        ], ['image' => $this->pngFile(), 'mask' => $this->pngFile('mask.png'), 'foil' => $this->jpegFile()]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSame('DRAFT', $body['status']);
        $this->assertSame('legendary', $body['rarity']);
        $this->assertNotNull($body['imageName']);
        $this->assertNotNull($body['imageMaskName']);
        $this->assertNotNull($body['imageFoilName']);

        $card = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Card::class)->findOneBy(['name' => $name]);
        $this->assertInstanceOf(Card::class, $card);
        $this->assertSame(CardStatusEnum::DRAFT, $card->getStatus());
        $this->assertSame(CardRarityEnum::LEGENDARY, $card->getRarity());
        $this->assertFalse($card->isUnique());
        $this->assertFileExists(self::getContainer()->getParameter('kernel.cache_dir') . '/uploads/cards/' . $body['imageName']);
        $this->assertFileExists(self::getContainer()->getParameter('kernel.cache_dir') . '/uploads/masks/' . $body['imageMaskName']);
    }

    public function testMaskAndFoilAreOptional(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $slug = $this->newExtension($client, $token);

        $body = $this->apiRequest($client, 'POST', "/api/admin/extensions/{$slug}/cards", $token, ['name' => 'Plain', 'description' => 'x', 'rarity' => 'common'], ['image' => $this->pngFile()]);

        self::assertResponseStatusCodeSame(201);
        $this->assertNull($body['imageMaskName']);
        $this->assertNull($body['imageFoilName']);
    }

    public function testSameNameInTheSameExtensionAnswers409ButAnotherExtensionIsFine(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $slug = $this->newExtension($client, $token);
        $other = $this->newExtension($client, $token);
        $fields = ['name' => 'Rukia', 'description' => 'x', 'rarity' => 'rare'];
        $created = $this->apiRequest($client, 'POST', "/api/admin/extensions/{$slug}/cards", $token, $fields, ['image' => $this->pngFile()]);

        $body = $this->apiRequest($client, 'POST', "/api/admin/extensions/{$slug}/cards", $token, ['name' => 'rukia'] + $fields, ['image' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(409);
        $this->assertSame($created['id'], $body['card']['id']);

        $this->apiRequest($client, 'POST', "/api/admin/extensions/{$other}/cards", $token, $fields, ['image' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testInvalidInputAnswers422WithTheDetailPerField(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $slug = $this->newExtension($client, $token);
        $url = "/api/admin/extensions/{$slug}/cards";
        $ok = ['name' => 'Valid ' . uniqid(), 'description' => 'x', 'rarity' => 'common'];

        $body = $this->apiRequest($client, 'POST', $url, $token, ['rarity' => 'epic'] + $ok, ['image' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('rarity', $body['violations']);

        $body = $this->apiRequest($client, 'POST', $url, $token, $ok);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('image', $body['violations']);

        $notAnImage = $this->tmpFile('<?php echo 1;', 'shell.png', 'image/png');
        $body = $this->apiRequest($client, 'POST', $url, $token, $ok, ['image' => $notAnImage]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('image', $body['violations']);

        $body = $this->apiRequest($client, 'POST', $url, $token, $ok, ['image' => $this->pngFile(), 'mask' => $this->jpegFile()]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('mask', $body['violations']);

        $body = $this->apiRequest($client, 'POST', $url, $token, ['name' => '', 'description' => ''] + $ok, ['image' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('name', $body['violations']);
        $this->assertArrayHasKey('description', $body['violations']);
    }

    public function testNothingIsCreatedWhenValidationFails(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $slug = $this->newExtension($client, $token);

        $this->apiRequest($client, 'POST', "/api/admin/extensions/{$slug}/cards", $token, ['name' => 'Ghost', 'description' => 'x', 'rarity' => 'nope'], ['image' => $this->pngFile()]);

        $this->assertSame([], $this->apiRequest($client, 'GET', "/api/admin/extensions/{$slug}/cards", $token));
    }

    public function testUnknownSlugAnswers404(): void
    {
        $client = self::createClient();
        $token = $this->newToken();

        $this->apiRequest($client, 'GET', '/api/admin/extensions/nope-' . uniqid() . '/cards', $token);
        self::assertResponseStatusCodeSame(404);

        $this->apiRequest($client, 'POST', '/api/admin/extensions/nope-' . uniqid() . '/cards', $token, ['name' => 'x', 'description' => 'x', 'rarity' => 'common'], ['image' => $this->pngFile()]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testListExposesTheCardsOfTheExtension(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $slug = $this->newExtension($client, $token);
        foreach (['B card', 'A card'] as $name) {
            $this->apiRequest($client, 'POST', "/api/admin/extensions/{$slug}/cards", $token, ['name' => $name, 'description' => 'x', 'rarity' => 'common'], ['image' => $this->pngFile()]);
        }

        $body = $this->apiRequest($client, 'GET', "/api/admin/extensions/{$slug}/cards", $token);

        self::assertResponseStatusCodeSame(200);
        $this->assertSame(['A card', 'B card'], array_column($body, 'name'));
        $this->assertSame(['id', 'name', 'status', 'rarity', 'imageName', 'imageMaskName', 'imageFoilName'], array_keys($body[0]));

        $extensions = $this->apiRequest($client, 'GET', '/api/admin/extensions', $token);
        $this->assertSame(2, array_column($extensions, 'cardCount', 'slug')[$slug]);
    }

    private function newExtension(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token): string
    {
        $body = $this->apiRequest($client, 'POST', '/api/admin/extensions', $token, ['name' => 'Ext ' . uniqid(), 'description' => 'x']);

        return (string) $body['slug'];
    }
}
