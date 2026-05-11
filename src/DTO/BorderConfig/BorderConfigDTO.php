<?php

declare(strict_types=1);

namespace App\DTO\BorderConfig;

use Symfony\Component\Validator\Constraints as Assert;

class BorderConfigDTO
{
    #[Assert\Choice(choices: ['gradient', 'solid'])]
    public string $type = 'gradient';

    #[Assert\Count(min: 2, max: 6)]
    #[Assert\All([
        new Assert\Regex(pattern: '/^#[0-9A-Fa-f]{6}$/'),
    ])]
    public array $colors = ['#9333ea', '#ec4899'];

    #[Assert\Range(min: 1, max: 20)]
    public int $width = 4;

    #[Assert\Range(min: 0, max: 50)]
    public int $radius = 16;

    #[Assert\Range(min: 0, max: 360)]
    public int $angle = 135;

    #[Assert\Valid]
    public ?GlowConfigDTO $glow = null;

    #[Assert\Valid]
    public ?FadeConfigDTO $fade = null;

    public function __construct()
    {
        $this->glow = new GlowConfigDTO();
        $this->fade = new FadeConfigDTO();
    }
}
