<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\UserCard;
use PHPUnit\Framework\TestCase;

final class DiscordUserTest extends TestCase
{
    public function testCardCopyCountDoesNotCountHoloCopiesTwice(): void
    {
        // quantity is the TOTAL of copies, holoQuantity a sub-count of it:
        // 3 copies of which 2 are holo is 3 cards on the table, not 5.
        $user = $this->userOwning([[3, 2], [1, 0]]);

        $this->assertSame(4, $user->getCardCopyCount());
    }

    public function testCardCopyCountOfAFullyHoloCollection(): void
    {
        // the worst case of the double-count bug: every copy is holo
        $user = $this->userOwning([[2, 2]]);

        $this->assertSame(2, $user->getCardCopyCount());
    }

    public function testCountsAreZeroWithoutAnyCard(): void
    {
        $user = new DiscordUser();

        $this->assertSame(0, $user->getCardCopyCount());
        $this->assertSame(0, $user->getDistinctCardCount());
    }

    /**
     * @param list<array{int, int}> $rows quantity / holoQuantity pairs
     */
    private function userOwning(array $rows): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId('unit-test')->setUsername('Unit');

        foreach ($rows as [$quantity, $holoQuantity]) {
            $user->addUserCard(
                new UserCard()
                    ->setDiscordUser($user)
                    ->setCard(new Card())
                    ->setQuantity($quantity)
                    ->setHoloQuantity($holoQuantity),
            );
        }

        return $user;
    }
}
