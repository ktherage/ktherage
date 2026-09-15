<?php

declare(strict_types=1);

namespace App;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('truncate', function (?string $value, int $length = 80) {
                if (null === $value) {
                    return '';
                }
                if (mb_strlen($value) <= $length) {
                    return $value;
                }

                return mb_substr($value, 0, $length).'...';
            }),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('badge', function (string $label, string $color, array $options = []): string {
                $style = $options['style'] ?? 'for-the-badge';
                $logo = $options['logo'] ?? null;
                $logoColor = $options['logoColor'] ?? 'white';

                $url = sprintf('https://img.shields.io/badge/%s-%s?style=%s', urlencode($label), $color, $style);

                if ($logo) {
                    $url .= '&logo='.urlencode($logo).'&logoColor='.$logoColor;
                }

                return $url;
            }),
            new TwigFunction('badge_link', function (string $href, string $label, string $color, array $options = []): string {
                $url = self::badgeUrl($label, $color, $options);

                return sprintf('<a href="%s"><img src="%s" alt="%s"/></a>', htmlspecialchars($href), $url, htmlspecialchars($label));
            }),
            new TwigFunction('lang_badge', function (string $name, string $color, float $percentage): string {
                $label = sprintf('%s %s%%', $name, $percentage);
                $logo = strtolower($name);

                return sprintf(
                    'https://img.shields.io/badge/%s-%s?style=for-the-badge&logo=%s&logoColor=white',
                    urlencode($label),
                    $color,
                    urlencode($logo)
                );
            }),
        ];
    }

    private static function badgeUrl(string $label, string $color, array $options = []): string
    {
        $style = $options['style'] ?? 'for-the-badge';
        $logo = $options['logo'] ?? null;
        $logoColor = $options['logoColor'] ?? 'white';

        $url = sprintf('https://img.shields.io/badge/%s-%s?style=%s', urlencode($label), $color, $style);

        if ($logo) {
            $url .= '&logo='.urlencode($logo).'&logoColor='.$logoColor;
        }

        return $url;
    }
}
