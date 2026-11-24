<?php

namespace App\Support;

/**
 * DEVICES IN PLAIN WORDS (2026-10-31, Anthony: "staff notifications can you add a column to show what version of the app
 * they are on, type of phone, android version as well").
 *
 * device_tokens keeps the platform, the Android versionCode / iOS build the app reported, and the WebView's User-Agent
 * (device_name, first 120 chars). This turns them into what a manager reads: "3.12.10", "Samsung Galaxy S25 Ultra",
 * "Android 16". One helper so the readiness list, audit and push sentences say the same thing.
 */
final class DeviceLabel
{
    /** Android versionCode -> versionName, from the kiddietrac-android build.gradle history. */
    public const ANDROID_VERSIONS = [
        8 => '1.7', 19 => '3.6', 20 => '3.6.1', 21 => '3.6.2', 22 => '3.6.3', 23 => '3.6.4', 24 => '3.6.5', 25 => '3.6.6',
        26 => '3.6.7', 27 => '3.6.8', 28 => '3.6.9', 29 => '3.7.0', 30 => '3.7.1', 52 => '3.10.3', 53 => '3.10.4', 54 => '3.10.5',
        55 => '3.11.0', 56 => '3.11.1', 57 => '3.12.0', 58 => '3.12.1', 59 => '3.12.2', 60 => '3.12.3', 61 => '3.12.4',
        62 => '3.12.5', 63 => '3.12.6', 64 => '3.12.8', 65 => '3.12.9', 66 => '3.12.10', 67 => '3.12.11',
    ];

    /** Model codes as Android reports them (prefix match) -> the name people know. Samsung puts a region letter after. */
    private const MODELS = [
        'SM-S938' => 'Samsung Galaxy S25 Ultra', 'SM-S936' => 'Samsung Galaxy S25+', 'SM-S931' => 'Samsung Galaxy S25',
        'SM-S937' => 'Samsung Galaxy S25 Edge', 'SM-S928' => 'Samsung Galaxy S24 Ultra', 'SM-S926' => 'Samsung Galaxy S24+',
        'SM-S921' => 'Samsung Galaxy S24', 'SM-S721' => 'Samsung Galaxy S24 FE', 'SM-S918' => 'Samsung Galaxy S23 Ultra',
        'SM-S916' => 'Samsung Galaxy S23+', 'SM-S911' => 'Samsung Galaxy S23', 'SM-S711' => 'Samsung Galaxy S23 FE',
        'SM-S908' => 'Samsung Galaxy S22 Ultra', 'SM-S906' => 'Samsung Galaxy S22+', 'SM-S901' => 'Samsung Galaxy S22',
        'SM-G998' => 'Samsung Galaxy S21 Ultra', 'SM-G996' => 'Samsung Galaxy S21+', 'SM-G991' => 'Samsung Galaxy S21',
        'SM-G990' => 'Samsung Galaxy S21 FE', 'SM-G988' => 'Samsung Galaxy S20 Ultra', 'SM-G981' => 'Samsung Galaxy S20',
        'SM-G973' => 'Samsung Galaxy S10', 'SM-G975' => 'Samsung Galaxy S10+',
        'SM-A566' => 'Samsung Galaxy A56', 'SM-A556' => 'Samsung Galaxy A55', 'SM-A546' => 'Samsung Galaxy A54',
        'SM-A536' => 'Samsung Galaxy A53', 'SM-A526' => 'Samsung Galaxy A52', 'SM-A366' => 'Samsung Galaxy A36',
        'SM-A356' => 'Samsung Galaxy A35', 'SM-A346' => 'Samsung Galaxy A34', 'SM-A266' => 'Samsung Galaxy A26',
        'SM-A256' => 'Samsung Galaxy A25', 'SM-A166' => 'Samsung Galaxy A16', 'SM-A156' => 'Samsung Galaxy A15',
        'SM-A155' => 'Samsung Galaxy A15', 'SM-A146' => 'Samsung Galaxy A14', 'SM-A135' => 'Samsung Galaxy A13',
        'SM-F966' => 'Samsung Galaxy Z Fold7', 'SM-F956' => 'Samsung Galaxy Z Fold6', 'SM-F946' => 'Samsung Galaxy Z Fold5',
        'SM-F766' => 'Samsung Galaxy Z Flip7', 'SM-F741' => 'Samsung Galaxy Z Flip6', 'SM-F731' => 'Samsung Galaxy Z Flip5',
        'SM-X' => 'Samsung Galaxy Tab', 'SM-T' => 'Samsung Galaxy Tab',
        'CPH2581' => 'OnePlus 12', 'CPH2573' => 'OnePlus 12R', 'CPH2449' => 'OnePlus 11', 'CPH2655' => 'OnePlus 13',
        'NE2215' => 'OnePlus 10 Pro', 'IN2025' => 'OnePlus 8 Pro',
        'XT2' => 'Motorola phone',
    ];

    /** "3.12.10" for an Android build we know; "build 53" otherwise; null without a build. */
    public static function appVersion(?string $platform, $build): ?string
    {
        $b = (int) $build;
        if ($b <= 0) return null;
        if ($platform === 'android') return self::ANDROID_VERSIONS[$b] ?? ('build ' . $b);
        if ($platform === 'ios') return 'iOS build ' . $b;
        return null;
    }

    /** "Samsung Galaxy S25 Ultra" / "Pixel 8" / "iPhone" / "Windows computer"; null when the UA says nothing. */
    public static function model(?string $ua): ?string
    {
        $ua = (string) $ua;
        if ($ua === '') return null;
        if (stripos($ua, 'iPhone') !== false) return 'iPhone';
        if (stripos($ua, 'iPad') !== false) return 'iPad';
        if (preg_match('/Android [\d.]+; ([^;)]+?)(?: Build|;|\))/', $ua, $m)) {
            $code = trim($m[1]);
            if ($code === 'K' || $code === '') return 'Android phone';   // Chrome's reduced User-Agent hides the model
            foreach (self::MODELS as $prefix => $name) {
                if (stripos($code, $prefix) === 0) return $name;
            }
            return $code;   // "Pixel 8", "moto g power (2024)" already read well
        }
        if (stripos($ua, 'Windows') !== false) return 'Windows computer';
        if (stripos($ua, 'Macintosh') !== false) return 'Mac';
        if (stripos($ua, 'CrOS') !== false) return 'Chromebook';
        if (stripos($ua, 'Linux') !== false) return 'Linux computer';
        return null;
    }

    /** "Android 16" / "iOS 18.1" / "Windows (browser)". */
    public static function os(?string $ua, ?string $platform = null): ?string
    {
        $ua = (string) $ua;
        if (preg_match('/Android ([\d.]+)/', $ua, $m)) return 'Android ' . $m[1];
        if (preg_match('/(?:iPhone|CPU) OS (\d+)[_.](\d+)(?:[_.](\d+))?/', $ua, $m)) return 'iOS ' . $m[1] . '.' . $m[2] . (! empty($m[3]) ? '.' . $m[3] : '');
        $browser = $platform === 'web' ? ' (browser)' : '';
        if (stripos($ua, 'Windows') !== false) return 'Windows' . $browser;
        if (stripos($ua, 'Mac OS X') !== false) return 'macOS' . $browser;
        if (stripos($ua, 'CrOS') !== false) return 'ChromeOS' . $browser;
        if ($ua !== '' && stripos($ua, 'Linux') !== false) return 'Linux' . $browser;
        return null;
    }

    /** One line: "Samsung Galaxy S25 Ultra · Android 16 · app 3.12.10". */
    public static function line(?string $platform, $build, ?string $ua): string
    {
        return implode(' · ', array_filter([self::model($ua), self::os($ua, $platform),
            ($v = self::appVersion($platform, $build)) ? 'app ' . $v : ($platform === 'web' ? 'browser' : null)]));
    }
}
