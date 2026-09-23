<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Once a 1/1 has been drawn its unique flag is frozen: unflagged, it would go
 * back into the draw pool while still counted as its holder's unique.
 */
final class AdminCardUniqueLockTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->followRedirects();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);
    }

    public function testAClaimedUniqueShowsALockedFlagWithTheReason(): void
    {
        $id = (string) $this->createUnique(claimed: true)->getId();

        $crawler = $this->client->request('GET', '/admin/card/' . $id . '/edit');

        self::assertResponseIsSuccessful();
        $checkbox = $crawler->filter('input[name="Card[unique]"]');
        $this->assertCount(1, $checkbox);
        $this->assertNotNull($checkbox->attr('disabled'));
        $this->assertStringContainsString(
            \sprintf('Cette carte unique a déjà été tirée par %s : le flag unique ne peut plus être modifié.', $this->user),
            $crawler->filter('form[name="Card"]')->text(),
        );
    }

    public function testAClaimedUniqueKeepsItsFlagWhenTheFormOmitsIt(): void
    {
        $id = (string) $this->createUnique(claimed: true)->getId();

        $crawler = $this->client->request('GET', '/admin/card/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        // an absent checkbox reads as "unchecked": exactly what a tampered POST would send
        $values = $crawler->filter('form[name="Card"]')->form()->getPhpValues();
        unset($values['Card']['unique']);
        $this->client->request('POST', '/admin/card/' . $id . '/edit', $values);
        self::assertResponseIsSuccessful();

        $saved = $this->reload($id);
        $this->assertTrue($saved->isUnique());
        $this->assertSame($this->user->getDiscordId(), $saved->getClaimedBy()?->getDiscordId());
    }

    public function testTheEntityRefusesToUnflagAClaimedUnique(): void
    {
        $card = $this->createUnique(claimed: true)->setUnique(false);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($card);

        $this->assertCount(1, $violations);
        $this->assertSame('unique', $violations[0]->getPropertyPath());
        $this->assertSame(
            \sprintf('Cette carte unique a déjà été tirée par %s : le flag unique ne peut plus être modifié.', $this->user),
            $violations[0]->getMessage(),
        );
    }

    public function testAnUnclaimedUniqueCanStillBeUnflagged(): void
    {
        $id = (string) $this->createUnique(claimed: false)->getId();

        $crawler = $this->client->request('GET', '/admin/card/' . $id . '/edit');
        self::assertResponseIsSuccessful();
        $this->assertNull($crawler->filter('input[name="Card[unique]"]')->attr('disabled'));

        $form = $crawler->filter('form[name="Card"]')->form();
        $form['Card[unique]']->untick();
        $this->client->submit($form);
        self::assertResponseIsSuccessful();

        $this->assertFalse($this->reload($id)->isUnique());
    }

    public function testTheOtherFieldsOfAClaimedUniqueStayEditable(): void
    {
        $id = (string) $this->createUnique(claimed: true)->getId();

        $crawler = $this->client->request('GET', '/admin/card/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="Card"]')->form();
        $form['Card[name]'] = 'Renamed claimed unique';
        $this->client->submit($form);
        self::assertResponseIsSuccessful();

        $saved = $this->reload($id);
        $this->assertSame('Renamed claimed unique', $saved->getName());
        $this->assertTrue($saved->isUnique());
    }

    private function createUnique(bool $claimed): Card
    {
        $extension = new Extension()
            ->setName('Unique lock universe ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $card = new Card()
            ->setName('Unique lock card ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension)
            ->setUnique(true)
        ;
        if ($claimed) {
            $holder = $this->entityManager->find(DiscordUser::class, $this->user->getDiscordId());
            $this->assertInstanceOf(DiscordUser::class, $holder);
            $card->setClaimedBy($holder);
        }
        $this->entityManager->persist($extension);
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function reload(string $id): Card
    {
        $this->entityManager->clear();
        $card = $this->entityManager->find(Card::class, $id);
        $this->assertInstanceOf(Card::class, $card);

        return $card;
    }
}
