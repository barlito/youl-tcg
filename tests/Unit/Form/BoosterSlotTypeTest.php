<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\BoosterSlotType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * The slot form is built without TypeTestCase on purpose: its mocked event
 * dispatcher raises a PHPUnit notice, and the suite fails on notices.
 */
final class BoosterSlotTypeTest extends TestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
        ;
    }

    public function testSubmittedWeightsBecomeTheStoredSlotStructure(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit([
            'rarities' => ['common' => '60', 'uncommon' => '', 'rare' => '40', 'legendary' => ''],
            'holoChance' => '25',
            'uniqueChance' => '25',
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid(), $this->errorsAsString($form));
        $this->assertSame(
            ['rarities' => ['common' => 60, 'rare' => 40], 'holoChance' => 25, 'uniqueChance' => 25],
            $form->getData(),
        );
    }

    public function testStoredSlotIsSpreadOverOneInputPerRarity(): void
    {
        $form = $this->factory->create(BoosterSlotType::class, [
            'rarities' => ['rare' => 30, 'legendary' => 10],
            'holoChance' => 40,
            'uniqueChance' => 25,
        ]);

        $weights = $form->get('rarities');
        $this->assertNull($weights->get('common')->getData());
        $this->assertNull($weights->get('uncommon')->getData());
        $this->assertSame(30, $weights->get('rare')->getData());
        $this->assertSame(10, $weights->get('legendary')->getData());
        $this->assertSame(40, $form->get('holoChance')->getData());
        $this->assertSame(25, $form->get('uniqueChance')->getData());
    }

    public function testSlotStoredBeforeTheUniqueOptionOpensOnABlankField(): void
    {
        // uniqueChance is an optional key: editing a booster saved before the
        // option must not blow up on the missing index
        $form = $this->factory->create(BoosterSlotType::class, [
            'rarities' => ['common' => 100],
            'holoChance' => 5,
        ]);

        $this->assertNull($form->get('uniqueChance')->getData());

        $form->submit(['rarities' => ['common' => '100'], 'holoChance' => '5']);

        $this->assertTrue($form->isValid(), $this->errorsAsString($form));
        $this->assertSame(0, $form->getData()['uniqueChance']);
    }

    public function testBlankUniqueChanceFallsBackToZero(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit(['rarities' => ['common' => '100'], 'holoChance' => '0', 'uniqueChance' => '']);

        $this->assertTrue($form->isValid(), $this->errorsAsString($form));
        $this->assertSame(0, $form->getData()['uniqueChance']);
    }

    public function testUniqueChanceOutOfBoundsIsRejected(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit(['rarities' => ['common' => '100'], 'holoChance' => '0', 'uniqueChance' => '10001']);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString('chance unique', $this->errorsAsString($form));
    }

    public function testWeightsKeepTheRarityScaleOrderWhateverTheInputOrder(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit([
            'rarities' => ['legendary' => '5', 'common' => '95'],
            'holoChance' => '0',
        ]);

        $this->assertSame(['common' => 95, 'legendary' => 5], $form->getData()['rarities']);
    }

    public function testBlankHoloChanceFallsBackToZero(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit(['rarities' => ['common' => '100'], 'holoChance' => '']);

        $this->assertTrue($form->isValid(), $this->errorsAsString($form));
        $this->assertSame(0, $form->getData()['holoChance']);
    }

    public function testSlotWithoutAnyWeightIsRejected(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit(['rarities' => [], 'holoChance' => '10']);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString('au moins une rareté', $this->errorsAsString($form));
        $this->assertSame([], $form->getData()['rarities']);
    }

    public function testNonPositiveWeightIsRejectedAndNotStored(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit(['rarities' => ['common' => '0', 'rare' => '10'], 'holoChance' => '10']);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString('entier positif', $this->errorsAsString($form));
        $this->assertSame(['rare' => 10], $form->getData()['rarities']);
    }

    public function testHoloChanceOutOfBoundsIsRejected(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit(['rarities' => ['common' => '100'], 'holoChance' => '101']);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString('chance holo', $this->errorsAsString($form));
    }

    public function testNonNumericWeightBreaksSynchronisationInsteadOfBeingSwallowed(): void
    {
        $form = $this->factory->create(BoosterSlotType::class);
        $form->submit(['rarities' => ['common' => 'beaucoup'], 'holoChance' => '10']);

        $this->assertFalse($form->get('rarities')->get('common')->isSynchronized());
        $this->assertFalse($form->isValid());
    }

    private function errorsAsString(FormInterface $form): string
    {
        return implode(
            ' | ',
            array_map(
                static fn (FormError $error): string => $error->getMessage(),
                iterator_to_array($form->getErrors(true), false),
            ),
        );
    }
}
