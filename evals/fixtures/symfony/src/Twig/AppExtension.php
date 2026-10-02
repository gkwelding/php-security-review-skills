<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('paragraphs', $this->paragraphs(...), ['is_safe' => ['html']]),
        ];
    }

    public function paragraphs(string $text): string
    {
        $blocks = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode("\n", array_map(fn (string $block) => '<p>'.nl2br($block).'</p>', $blocks));
    }
}
