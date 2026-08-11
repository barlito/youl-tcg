<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Booster\BoosterCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Same flow as the card status batch actions: the CSRF token and the action
 * URL are read on the listing button, then posted like EasyAdmin's JS does.
 */
final class AdminBoosterCodeRevokeTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string INDEX_URL = '/admin/booster-code';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->authenticateClient($this->client);
    }

    public function testBatchRevokeOnlyDisablesSelectedCodes(): void
    {
        [$first, $second, $untouched] = $this->createCodes(3);

        $this->submitBatchAction('revokeCodes', [$first, $second]);

        self::assertResponseRedirects();
        $this->assertDisabled(true, [$first, $second]);
        $this->assertDisabled(false, [$untouched]);
    }

    public function testBatchRestoreReenablesRevokedCodes(): void
    {
        [$code] = $this->createCodes(1, disabled: true);

        $this->submitBatchAction('restoreCodes', [$code]);

        self::assertResponseRedirects();
        $this->assertDisabled(false, [$code]);
    }

    public function testInvalidCsrfTokenChangesNothing(): void
    {
        [$code] = $this->createCodes(1);

        $this->submitBatchAction('revokeCodes', [$code], csrfToken: 'forged-token');

        self::assertResponseRedirects();
        $this->assertDisabled(false, [$code]);
    }

    public function testMismatchedEntityFqcnIsRejected(): void
    {
        [$code] = $this->createCodes(1);

        $this->submitBatchAction('revokeCodes', [$code], entityFqcn: Card::class);

        self::assertResponseStatusCodeSame(400);
        $this->assertDisabled(false, [$code]);
    }

    /**
     * @param list<BoosterCode> $codes
     */
    private function submitBatchAction(string $actionName, array $codes, ?string $csrfToken = null, ?string $entityFqcn = null): void
    {
        $crawler = $this->client->request('GET', self::INDEX_URL);
        self::assertResponseIsSuccessful();

        // EA5 pretty URLs are kebab-case: revokeCodes -> /admin/booster-code/revoke-codes
        $actionPath = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $actionName));
        $button = $crawler->filter(\sprintf('[data-action-batch="true"][data-action-url*="%s"]', $actionPath));
        $this->assertCount(1, $button, \sprintf('Le bouton batch "%s" doit être présent sur le listing.', $actionName));

        $entityFqcn ??= BoosterCode::class;
        $csrfToken ??= (string) $button->attr('data-action-csrf-token');

        $this->client->request('POST', (string) $button->attr('data-action-url'), [
            'batchActionName' => $actionName,
            'entityFqcn' => $entityFqcn,
            'batchActionUrl' => (string) $button->attr('data-action-url'),
            'batchActionCsrfToken' => $csrfToken,
            'batchActionEntityIds' => array_map(static fn (BoosterCode $code): string => (string) $code->getId(), $codes),
        ]);
    }

    /**
     * @return list<BoosterCode>
     */
    private function createCodes(int $count, bool $disabled = false): array
    {
        $extension = new Extension()
            ->setName('Revoke test extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        $generator = self::getContainer()->get(BoosterCodeGenerator::class);

        $codes = [];
        foreach (range(1, $count) as $ignored) {
            $code = new BoosterCode()
                ->setCode($generator->generate())
                ->setBooster($booster)
                ->setBatchLabel('Revoke test')
                ->setDisabled($disabled)
            ;
            $this->entityManager->persist($code);
            $codes[] = $code;
        }
        $this->entityManager->flush();

        return $codes;
    }

    /**
     * @param list<BoosterCode> $codes
     */
    private function assertDisabled(bool $expected, array $codes): void
    {
        $this->entityManager->clear();

        foreach ($codes as $code) {
            $this->assertSame(
                $expected,
                $this->entityManager->getRepository(BoosterCode::class)->find($code->getId())?->isDisabled(),
            );
        }
    }
}
