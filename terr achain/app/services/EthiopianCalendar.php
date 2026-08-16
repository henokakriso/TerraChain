<?php
declare(strict_types=1);

/**
 * Ethiopian calendar (section 33-34).
 * Conversion between Gregorian and Ethiopian (Ge'ez) calendar.
 * Database stores Gregorian dates consistently; interfaces display localized.
 */
final class EthiopianCalendar
{
    public const MONTH_NAMES_EN = [
        'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit',
        'Megabit', 'Miazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume',
    ];
    public const MONTH_NAMES_AM = [
        'መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት',
        'መጋቢት', 'ሚያዚያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ',
    ];

    private static function isEthiopianLeap(int $year): bool
    {
        return $year % 4 === 3;
    }

    /** Gregorian YYYY-MM-DD -> [year, month(1-13), day] Ethiopian */
    public static function toEthiopian(string $gregorianDate): array
    {
        $dt = new DateTime($gregorianDate);
        $gYear = (int)$dt->format('Y');
        $gMonth = (int)$dt->format('n');
        $gDay = (int)$dt->format('j');

        // Days since 1900-01-01 (Gregorian), where Ethiopian epoch 1900-01-01 = 1907-09-11 Gregorian
        $epochG = new DateTime('1907-09-11');
        $days = $epochG->diff($dt)->days;

        // 1900-01-01 Ethiopian is Gregorian 1907-09-11. Offset of months.
        $ethYear = 1900;
        $ethDays = 0;
        // Compute by subtracting whole years
        while (true) {
            $yearDays = self::isEthiopianLeap($ethYear) ? 366 : 365;
            if ($days < $yearDays) {
                break;
            }
            $days -= $yearDays;
            $ethYear++;
        }
        $month = 1;
        $day = 0;
        while ($month <= 13) {
            $monthDays = $month === 13 ? (self::isEthiopianLeap($ethYear) ? 6 : 5) : 30;
            if ($days < $monthDays) {
                $day = $days + 1;
                break;
            }
            $days -= $monthDays;
            $month++;
        }
        return [$ethYear, $month, $day];
    }

    /** Ethiopian [year, month(1-13), day] -> Gregorian YYYY-MM-DD */
    public static function toGregorian(int $ethYear, int $ethMonth, int $ethDay): string
    {
        $days = 0;
        for ($y = 1900; $y < $ethYear; $y++) {
            $days += self::isEthiopianLeap($y) ? 366 : 365;
        }
        for ($m = 1; $m < $ethMonth; $m++) {
            $days += $m === 13 ? (self::isEthiopianLeap($ethYear) ? 6 : 5) : 30;
        }
        $days += $ethDay - 1;
        $epoch = new DateTime('1907-09-11');
        return $epoch->modify("+$days days")->format('Y-m-d');
    }

    /** Format a Gregorian date in Ethiopian calendar with optional Amharic month names. */
    public static function format(string $gregorianDate, string $language = 'en'): string
    {
        try {
            [$year, $month, $day] = self::toEthiopian($gregorianDate);
            $names = $language === 'am' ? self::MONTH_NAMES_AM : self::MONTH_NAMES_EN;
            return sprintf('%d %s %d', $day, $names[$month - 1], $year);
        } catch (Throwable) {
            return $gregorianDate;
        }
    }

    /** Today's date in Ethiopian calendar. */
    public static function today(string $language = 'en'): string
    {
        return self::format(date('Y-m-d'), $language);
    }

    /** Validates Ethiopian calendar input and returns Gregorian date. */
    public static function fromEthiopian(int $year, int $month, int $day): string
    {
        if ($month < 1 || $month > 13 || $day < 1) {
            throw new ApiException('Invalid Ethiopian date.');
        }
        $maxDay = $month === 13 ? (self::isEthiopianLeap($year) ? 6 : 5) : 30;
        if ($day > $maxDay) {
            throw new ApiException("Day $day does not exist in month $month of Ethiopian year $year.");
        }
        return self::toGregorian($year, $month, $day);
    }
}
