<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminInvalidEntityIdTest extends WebTestCase
{
    use JwtAuthTrait;

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'card detail' => ['/admin/card/not-a-uuid'];
        yield 'card edit' => ['/admin/card/not-a-uuid/edit'];
        yield 'singular path of a custom page' => ['/admin/booster-code/batch'];
    }

    #[DataProvider('invalidIds')]
    public function testANonUuidEntityIdIsANotFound(string $path): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(404);
    }
}
