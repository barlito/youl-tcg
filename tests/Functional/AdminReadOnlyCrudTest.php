<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\Admin\BoosterClaimCrudController;
use App\Controller\Admin\BoosterCodeCrudController;
use App\Controller\Admin\BoosterCodeRedemptionCrudController;
use App\Controller\Admin\BoosterOpeningCrudController;
use App\Controller\Admin\DiscordUserCrudController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The economy screens are consultation only: EasyAdmin must answer 403 on the
 * write actions, not merely hide their buttons.
 */
final class AdminReadOnlyCrudTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string ADMIN_DISCORD_ID = '188967649332428800';

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function readOnlyControllerProvider(): iterable
    {
        yield 'joueurs' => [DiscordUserCrudController::class];
        yield 'ouvertures' => [BoosterOpeningCrudController::class];
        yield 'recuperations' => [BoosterClaimCrudController::class];
        yield 'codes' => [BoosterCodeCrudController::class];
        yield 'utilisations-de-codes' => [BoosterCodeRedemptionCrudController::class];
    }

    /**
     * @param class-string $controllerFqcn
     */
    #[DataProvider('readOnlyControllerProvider')]
    public function testIndexRenders(string $controllerFqcn): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $client->request('GET', $this->crudUrl($controllerFqcn, 'index'));

        self::assertResponseIsSuccessful();
    }

    /**
     * @param class-string $controllerFqcn
     */
    #[DataProvider('readOnlyControllerProvider')]
    public function testNewIsForbidden(string $controllerFqcn): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $client->request('GET', $this->crudUrl($controllerFqcn, 'new'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testDiscordUserDetailRendersButEditAndDeleteAreForbidden(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $detailUrl = '/admin/discord-user/' . self::ADMIN_DISCORD_ID;

        $client->request('GET', $detailUrl);
        self::assertResponseIsSuccessful();

        $client->request('GET', $detailUrl . '/edit');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', $detailUrl . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexOffersNoWriteAction(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', $this->crudUrl(DiscordUserCrudController::class, 'index'));

        self::assertResponseIsSuccessful();
        // the detail action proves the "action-*" convention holds on this page,
        // so the absence of the write ones below is a real assertion
        $this->assertGreaterThan(0, $crawler->filter('.action-detail')->count());
        $this->assertCount(0, $crawler->filter('.action-new, .action-edit, .action-delete'));
    }

    /**
     * @param class-string $controllerFqcn
     */
    private function crudUrl(string $controllerFqcn, string $action): string
    {
        // EA5 pretty URLs: one path per CRUD, the action is a suffix
        $base = match ($controllerFqcn) {
            DiscordUserCrudController::class => '/admin/discord-user',
            BoosterOpeningCrudController::class => '/admin/booster-opening',
            BoosterClaimCrudController::class => '/admin/booster-claim',
            BoosterCodeCrudController::class => '/admin/booster-code',
            BoosterCodeRedemptionCrudController::class => '/admin/booster-code-redemption',
            default => throw new \LogicException('Unknown CRUD ' . $controllerFqcn),
        };

        return 'index' === $action ? $base : $base . '/' . $action;
    }
}
