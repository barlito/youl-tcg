<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Repository\BoosterCodeRepository;

/**
 * Generates and normalises redeemable codes.
 *
 * Uses random_int (CSPRNG) rather than RandomService: the latter is a seeded
 * Mt19937 built for reproducible draws, and a predictable generator would let
 * anyone holding one code enumerate a whole batch.
 */
final readonly class BoosterCodeGenerator
{
    /** No I/O/0/1: codes get read aloud, screenshotted and retyped. */
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const int LENGTH = 12;

    private const int MAX_REROLLS = 5;

    public function __construct(
        private BoosterCodeRepository $boosterCodeRepository,
    ) {
    }

    public function generate(): string
    {
        $lastIndex = \strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $lastIndex)];
        }

        return $code;
    }

    /**
     * A batch of distinct, not-yet-stored codes. Collisions are astronomically
     * unlikely (32^12) but the unique index would fail the whole batch, so
     * duplicates are rerolled up front.
     *
     * @return list<string>
     */
    public function generateBatch(int $count): array
    {
        $codes = [];

        for ($reroll = 0; \count($codes) < $count; ++$reroll) {
            if ($reroll > self::MAX_REROLLS) {
                throw new \RuntimeException('Unable to generate enough unique booster codes.');
            }

            $candidates = [];
            for ($i = \count($codes); $i < $count; ++$i) {
                $candidates[$this->generate()] = true;
            }

            foreach (array_diff(array_keys($candidates), $this->boosterCodeRepository->findExistingCodes(array_keys($candidates))) as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Player input to canonical form: case and separators are noise.
     */
    public static function normalize(string $input): string
    {
        return mb_substr((string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($input))), 0, 32);
    }
}
