<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminExtensionCrudTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CRUD_URL = '/admin/extension';

    public function testNewPageRendersWithTheImageUploadField(): void
    {
        // regression guard: EasyAdmin reads every field of the EMPTY entity on
        // the "new" form — a Vich field wired to a strict getter would 500
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CRUD_URL . '/new');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('input[type="file"][name*="imageFile"]'));
    }

    public function testEditPageRendersWithTheImageUploadField(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $extension = new Extension()
            ->setName('Crud test extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $entityManager->persist($extension);
        $entityManager->flush();

        $crawler = $client->request('GET', \sprintf('%s/%s/edit', self::CRUD_URL, $extension->getId()));

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('input[type="file"][name*="imageFile"]'));
    }
}
