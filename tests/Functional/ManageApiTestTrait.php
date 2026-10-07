<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait ManageApiTestTrait
{
    use ImportApiTestTrait;

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function jsonRequest(KernelBrowser $client, string $method, string $uri, ?string $token, ?array $body = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->em()->clear();
        $client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string>                                                                          $fields
     * @param array<string, array{tmp_name: string, name: string, type: string, error: int, size: int}|null> $files
     *
     * @return array<string, mixed>
     */
    private function multipartRequest(KernelBrowser $client, string $uri, ?string $token, array $fields = [], array $files = []): array
    {
        $this->em()->clear();

        return $this->apiRequest($client, 'POST', $uri, $token, $fields, $files);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeExtension(ExtensionStatusEnum $status = ExtensionStatusEnum::DRAFT): Extension
    {
        $extension = new Extension()->setName('Manage ext ' . uniqid())->setDescription('Test')->setStatus($status);
        $this->em()->persist($extension);
        $this->em()->flush();

        return $extension;
    }

    private function makeCard(Extension $extension, CardStatusEnum $status = CardStatusEnum::DRAFT, CardRarityEnum $rarity = CardRarityEnum::COMMON): Card
    {
        $card = new Card()->setName('Manage card ' . uniqid())->setDescription('Test')->setStatus($status)->setRarity($rarity)->setExtension($extension);
        $extension->addCard($card);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    /**
     * @param list<array{rarities: array<string, int>, holoChance: int, uniqueChance?: int}> $rates
     */
    private function makeBooster(Extension $extension, array $rates = [['rarities' => ['common' => 100], 'holoChance' => 5]]): Booster
    {
        $booster = new Booster()->setExtension($extension)->setRarityRates($rates);
        $extension->addBooster($booster);
        $this->em()->persist($booster);
        $this->em()->flush();

        return $booster;
    }

    private function giveTo(Card $card): void
    {
        $player = new DiscordUser()->setDiscordId('manage-' . uniqid())->setUsername('Owner');
        $this->em()->persist($player);
        $this->em()->persist(new UserCard()->setDiscordUser($player)->setCard($card)->setQuantity(1)->setHoloQuantity(0));
        $this->em()->flush();
    }

    private function refreshed(object $entity): object
    {
        $this->em()->clear();

        $id = $entity instanceof Card || $entity instanceof Extension || $entity instanceof Booster ? $entity->getId() : throw new \LogicException();

        return $this->em()->find($entity::class, $id) ?? throw new \LogicException('Entity vanished.');
    }

    private function auditHandler(): TestHandler
    {
        $handler = self::getContainer()->get('monolog.handler.admin_api_spy');
        \assert($handler instanceof TestHandler);

        return $handler;
    }
}
