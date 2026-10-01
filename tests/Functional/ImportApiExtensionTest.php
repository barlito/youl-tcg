<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImportApiExtensionTest extends WebTestCase
{
    use ImportApiTestTrait;

    public function testCreatesADraftExtensionWithItsImages(): void
    {
        $client = self::createClient();
        $name = 'Api extension ' . uniqid();

        $body = $this->apiRequest($client, 'POST', '/api/admin/extensions', $this->newToken(), [
            'name' => $name,
            'description' => 'Imported',
            'status' => '2',
        ], ['image' => $this->pngFile(), 'logo' => $this->pngFile('logo.png')]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSame('DRAFT', $body['status']);
        $this->assertSame($name, $body['name']);
        $this->assertSame(0, $body['cardCount']);
        $this->assertNotEmpty($body['slug']);

        $extension = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Extension::class)->findOneBy(['name' => $name]);
        $this->assertInstanceOf(Extension::class, $extension);
        $this->assertSame(ExtensionStatusEnum::DRAFT, $extension->getStatus());
        $this->assertNotNull($extension->getImageName());
        $this->assertNotNull($extension->getLogoName());
    }

    public function testImagesAreOptional(): void
    {
        $client = self::createClient();

        $this->apiRequest($client, 'POST', '/api/admin/extensions', $this->newToken(), ['name' => 'No image ' . uniqid(), 'description' => 'x']);

        self::assertResponseStatusCodeSame(201);
    }

    public function testDuplicateNameAnswers409WithTheExistingExtension(): void
    {
        $client = self::createClient();
        $token = $this->newToken();
        $name = 'Twice ' . uniqid();
        $created = $this->apiRequest($client, 'POST', '/api/admin/extensions', $token, ['name' => $name, 'description' => 'x']);

        $body = $this->apiRequest($client, 'POST', '/api/admin/extensions', $token, ['name' => strtoupper($name), 'description' => 'y']);

        self::assertResponseStatusCodeSame(409);
        $this->assertSame($created['id'], $body['extension']['id']);
    }

    public function testMissingFieldsAnswer422WithTheDetailPerField(): void
    {
        $client = self::createClient();

        $body = $this->apiRequest($client, 'POST', '/api/admin/extensions', $this->newToken(), ['name' => '  ']);

        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('name', $body['violations']);
        $this->assertArrayHasKey('description', $body['violations']);
    }

    public function testNonImageUploadIsRefused(): void
    {
        $client = self::createClient();
        $php = $this->tmpFile('<?php echo 1;', 'evil.php', 'application/x-php');

        $body = $this->apiRequest($client, 'POST', '/api/admin/extensions', $this->newToken(), ['name' => 'Evil ' . uniqid(), 'description' => 'x'], ['logo' => $php]);

        self::assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('logo', $body['violations']);
    }

    public function testListExposesStatusAndCardCount(): void
    {
        $client = self::createClient();

        $body = $this->apiRequest($client, 'GET', '/api/admin/extensions', $this->newToken());

        self::assertResponseStatusCodeSame(200);
        $this->assertNotEmpty($body);
        foreach ($body as $row) {
            $this->assertSame(['id', 'name', 'slug', 'status', 'cardCount'], array_keys($row));
        }
    }
}
