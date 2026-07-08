<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCardBatchTest extends WebTestCase
{
    use JwtAuthTrait;

    public function testBatchCreatesOneDraftCardPerUploadedArtwork(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $extension = new Extension()
            ->setName('Batch test extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $entityManager->persist($extension);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/cards/batch');
        self::assertResponseIsSuccessful();

        // DomCrawler can't set several files on one `multiple` input: POST by hand
        $form = $crawler->selectButton('Créer les cartes')->form();
        $token = $crawler->filter('input[name="form[_token]"]')->attr('value');
        $client->request('POST', $form->getUri(), [
            'form' => [
                'extension' => (string) $extension->getId(),
                'rarity' => CardRarityEnum::RARE->value,
                '_token' => $token,
            ],
        ], [
            'form' => [
                'images' => [
                    $this->uploadedArtwork('ahri_kda-alt.png'),
                    $this->uploadedArtwork('one-of-one.png'),
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        /** @var list<Card> $cards */
        $cards = $entityManager->getRepository(Card::class)->findBy(['extension' => $extension], ['name' => 'ASC']);
        $this->assertCount(2, $cards);
        $this->assertSame(['Ahri Kda Alt', 'One Of One'], array_map(static fn (Card $card): string => $card->getName(), $cards));
        foreach ($cards as $card) {
            $this->assertSame(CardStatusEnum::DRAFT, $card->getStatus());
            $this->assertSame(CardRarityEnum::RARE, $card->getRarity());
            $this->assertSame('À compléter.', $card->getDescription());
            $this->assertNotNull($card->getImageName(), 'Vich must have stored the artwork.');
        }
    }

    public function testAdminNewCardPageRenders(): void
    {
        // regression: EasyAdmin reads every field of the EMPTY entity on the
        // "new" form — a strict getExtension() used to turn this page into a 500
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $client->request('GET', '/admin?crudAction=new&crudControllerFqcn=App%5CController%5CAdmin%5CCardCrudController');

        self::assertResponseIsSuccessful();
    }

    /**
     * Raw $_FILES-style array: BrowserKit serialises its request history and
     * rejects UploadedFile objects there.
     *
     * @return array{tmp_name: string, name: string, type: string, error: int, size: int}
     */
    private function uploadedArtwork(string $clientName): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'batch-art-');
        \assert(false !== $tmp);
        copy(__DIR__ . '/../../public/images/cards/default_card.png', $tmp);

        return [
            'tmp_name' => $tmp,
            'name' => $clientName,
            'type' => 'image/png',
            'error' => \UPLOAD_ERR_OK,
            'size' => (int) filesize($tmp),
        ];
    }
}
