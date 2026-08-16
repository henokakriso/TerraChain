<?php
declare(strict_types=1);

/**
 * Localization (section 33): language resources are NOT hard-coded into
 * application logic. Strings live in lang/{lang}.json and are loaded here.
 */
final class Localization
{
    private static array $cache = [];

    public static function lang(): string
    {
        $user = Auth::user();
        return strtolower($user['language'] ?? 'en');
    }

    public static function supportedLanguages(): array
    {
        return ['en', 'am', 'or', 'ti', 'so', 'aa'];
    }

    public static function t(string $key, array $params = []): string
    {
        $lang = self::lang();
        if (!in_array($lang, self::supportedLanguages(), true)) {
            $lang = 'en';
        }
        if (!isset(self::$cache[$lang])) {
            $file = TERRACHAIN_LANG . '/' . $lang . '.json';
            self::$cache[$lang] = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        }
        $text = self::$cache[$lang][$key] ?? (self::$cache['en'][$key] ?? $key);
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    /** Formats a date in the user's calendar (Gregorian + Ethiopian, dual display). */
    public static function calendarDate(?string $gregorianDate): string
    {
        if ($gregorianDate === null || $gregorianDate === '') {
            return '-';
        }
        $eth = EthiopianCalendar::format($gregorianDate, self::lang() === 'am' ? 'am' : 'en');
        return trim($gregorianDate) . ' (' . $eth . ')';
    }
}