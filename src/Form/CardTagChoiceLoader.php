<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * Known tags as suggestions, but any submitted tag is accepted: the Card constraints judge its format.
 */
final class CardTagChoiceLoader implements ChoiceLoaderInterface
{
    /**
     * @var array<string, string>
     */
    private array $tags = [];

    /**
     * @param list<string> $knownTags
     */
    public function __construct(array $knownTags)
    {
        $this->remember($knownTags);
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        ksort($this->tags);

        return new ArrayChoiceList($this->tags, $value);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, string>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        return $this->remember($values);
    }

    /**
     * @param array<array-key, mixed> $choices
     *
     * @return array<array-key, string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        return $this->remember($choices);
    }

    /**
     * @param array<array-key, mixed> $tags
     *
     * @return array<array-key, string> the string entries, keys preserved
     */
    private function remember(array $tags): array
    {
        $accepted = [];
        foreach ($tags as $key => $tag) {
            if (\is_string($tag) && '' !== trim($tag)) {
                $accepted[$key] = $tag;
                $this->tags[$tag] = $tag;
            }
        }

        return $accepted;
    }
}
