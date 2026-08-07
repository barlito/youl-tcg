<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\BoosterCodeRepository;
use App\Service\Booster\BoosterCodeGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoosterCodeGeneratorTest extends TestCase
{
    public function testGeneratedCodesUseTheAmbiguityFreeAlphabet(): void
    {
        $generator = new BoosterCodeGenerator($this->repositoryReturning([]));

        foreach (range(1, 50) as $ignored) {
            $code = $generator->generate();

            $this->assertSame(BoosterCodeGenerator::LENGTH, \strlen($code));
            $this->assertMatchesRegularExpression('/^[' . BoosterCodeGenerator::ALPHABET . ']+$/', $code);
            $this->assertDoesNotMatchRegularExpression('/[IO01]/', $code);
        }
    }

    public function testBatchReturnsTheRequestedNumberOfDistinctCodes(): void
    {
        $codes = new BoosterCodeGenerator($this->repositoryReturning([]))->generateBatch(200);

        $this->assertCount(200, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
    }

    public function testBatchRerollsCodesThatAlreadyExist(): void
    {
        $call = 0;
        $repository = $this->createStub(BoosterCodeRepository::class);
        // first pass: every candidate is already taken, second pass: none is
        $repository->method('findExistingCodes')->willReturnCallback(
            static function (array $codes) use (&$call): array {
                return 1 === ++$call ? $codes : [];
            },
        );

        $codes = new BoosterCodeGenerator($repository)->generateBatch(5);

        $this->assertCount(5, $codes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizationProvider(): iterable
    {
        yield 'lowercase' => ['abcdefghjklm', 'ABCDEFGHJKLM'];
        yield 'dashes' => ['ABCD-EFGH-JKLM', 'ABCDEFGHJKLM'];
        yield 'spaces and tabs' => [" ABCD EFGH\tJKLM ", 'ABCDEFGHJKLM'];
        yield 'mixed noise' => ['abcd_efgh.jklm', 'ABCDEFGHJKLM'];
        yield 'separators only' => ['- -', ''];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('normalizationProvider')]
    public function testNormalize(string $input, string $expected): void
    {
        $this->assertSame($expected, BoosterCodeGenerator::normalize($input));
    }

    public function testNormalizeCapsTheInputToTheColumnLength(): void
    {
        $this->assertSame(32, \strlen(BoosterCodeGenerator::normalize(str_repeat('A', 100))));
    }

    /**
     * @param list<string> $existing
     */
    private function repositoryReturning(array $existing): BoosterCodeRepository
    {
        $repository = $this->createStub(BoosterCodeRepository::class);
        $repository->method('findExistingCodes')->willReturn($existing);

        return $repository;
    }
}
