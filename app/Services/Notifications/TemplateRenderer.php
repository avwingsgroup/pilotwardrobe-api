<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/** Fills `:placeholder` tokens in notification templates. */
final class TemplateRenderer
{
    /** @param array<string, scalar|null> $data */
    public static function text(string $template, array $data): string
    {
        return strtr($template, self::pairs($data, false));
    }

    /** Internal SPA path with URL-encoded values. */
    public static function path(string $template, array $data): string
    {
        return strtr($template, self::pairs($data, true));
    }

    /** @return array{subject: string, heading: string, lines: list<string>, cta: ?array{label: string, url: string}} */
    public static function email(array $definition, array $data): array
    {
        $cta = null;
        if (! empty($definition['cta'])) {
            [$label, $path] = $definition['cta'];
            $path = self::path($path, $data);
            $cta = [
                'label' => self::text($label, $data),
                'url' => str_starts_with($path, 'http') ? $path : config('platform.frontend_url').$path,
            ];
        }

        return [
            'subject' => self::text($definition['subject'], $data),
            'heading' => self::text($definition['heading'] ?? $definition['subject'], $data),
            'lines' => array_values(array_filter(array_map(fn (string $l) => self::text($l, $data), $definition['lines'] ?? []), fn ($l) => trim($l) !== '')),
            'cta' => $cta,
        ];
    }

    /** @return array<string, string> */
    private static function pairs(array $data, bool $encode): array
    {
        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $string = (string) $value;
                $pairs[':'.$key] = $encode ? rawurlencode($string) : $string;
            }
        }

        return $pairs;
    }
}
