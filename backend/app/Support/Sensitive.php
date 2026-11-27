<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Encryption\Encrypter;

/**
 * FIELD-LEVEL ENCRYPTION FOR CHILDREN'S HEALTH INFORMATION (2026-10-21).
 *
 * Anthony asked whether the data in the database is encrypted and chose to encrypt
 * children's medical notes and allergies (and the related dietary/health lists), plus a
 * contact's account number. A copy of the database on its own — a backup file, an SQL dump,
 * a leaked export of the tables — now shows ciphertext for these, not a child's diagnosis.
 *
 * HOW IT IS WIRED, AND WHY NOT AT EACH CALL SITE
 * These columns are read and written in ~50 places (DB::table, Eloquent, CSV import,
 * iLearn sync, PDFs, exports). Encrypting at each one would leave a missed reader showing
 * ciphertext where an educator expects to see a child's allergy, which is a safety problem,
 * not a cosmetic one. So the database connection does it (App\Database\SensitiveMySqlConnection):
 *   - every SELECT result has any value carrying the PREFIX decrypted, whatever it is
 *     called or aliased as, so no reader can see ciphertext;
 *   - every INSERT / UPDATE on a listed table seals the listed columns in its bindings.
 * A write the parser does not understand is left as plaintext (never broken), and the
 * scheduled `kt:encrypt-sensitive` sweep seals it within minutes.
 *
 * STORAGE FORMAT
 *   text columns:  ktenc:v1:<Laravel ciphertext>
 *   json columns:  "ktenc:v1:<…>"  — a JSON *string*, so the json_valid() CHECK constraints
 *                  on children.allergies / dietary_restrictions / health_alerts still pass.
 * Empty values (NULL, '', [], {}, null) are left alone: they say nothing about a child, and
 * keeping them plain keeps SQL like `allergies != ''` meaning "has allergies".
 *
 * THE KEY: KT_DATA_KEY in .env (base64:…, 32 bytes, AES-256-CBC). Its own key, not APP_KEY,
 * so rotating the app key (sessions, signed links) can never make health records unreadable.
 * NEVER CHANGE OR LOSE IT: every sealed value needs it. It must travel with the database
 * (e.g. to the Cloudways server) and be kept somewhere safe off the server.
 *
 * Not encrypted, deliberately: dates of birth (a DATE column used by SQL for ages, ratio
 * age groups and birthday jobs in ~250 places; encrypting it would risk the compliance
 * logic). See the memory note kiddietrac-field-encryption.
 */
final class Sensitive
{
    public const PREFIX = 'ktenc:v1:';

    /** table => [column => 'text' | 'json'] */
    public const COLUMNS = [
        'children' => [
            'medical_notes'        => 'text',
            'dietary_notes'        => 'text',
            'allergies'            => 'json',
            'dietary_restrictions' => 'json',
            'health_alerts'        => 'json',
        ],
        'contacts' => [
            'account_number' => 'text',
        ],
        // A person's saved signature / initials (2026-11-06): it signs documents, so it is sealed.
        'user_signatures' => [
            'image' => 'text',
        ],
        // An email filed on a person's record (2026-11-07, App\Support\PersonMail): what it said is about a family.
        'email_person_links' => [
            'subject' => 'text',
            'preview' => 'text',
        ],
        // A staff member's workplace injury (2026-11-26, KiddieTrac HR, App\Support\HrInjuries): health details.
        'hr_injuries' => [
            'what_happened' => 'text',
            'body_part'     => 'text',
            'injury_nature' => 'text',
            'first_aid'     => 'text',
            'medical_care'  => 'text',
            'medical_notes' => 'text',
            'rtw_duties'    => 'text',
        ],
        'hr_injury_checkins' => [
            'note' => 'text',
        ],
        /* CHAT MESSAGES (2026-11-27, Anthony: encrypt message text at rest, keep search working - App\Support\ChatPrivacy).
           Family conversations, colleague / parent threads, their saved translations, the private "message parents"
           log, a reported message's quoted excerpt and parent issue threads. Attachments hold file names: sealed too.
           Bell rows that copy a message are sealed per row (Notify, ChatPrivacy::FLAG) - notifications.body as a
           whole is searched with LIKE by other features. Search inside messages: ChatPrivacy::matches(). */
        'messages' => [
            'body'            => 'text',
            'translated_body' => 'text',
            'attachments'     => 'json',
        ],
        'staff_messages' => [
            'body'        => 'text',
            'attachments' => 'text',
        ],
        'message_translations' => [
            'translated_text' => 'text',
        ],
        'staff_parent_messages' => [
            'body' => 'text',
        ],
        'content_reports' => [
            'excerpt' => 'text',
        ],
        'parent_issue_messages' => [
            'body' => 'text',
        ],
        // The outgoing-translation cache (looked up by hash only): what an email or notice said, in another language.
        'outgoing_translations' => [
            'source'     => 'text',
            'translated' => 'text',
        ],
    ];

    private const UNREADABLE = '⚠ This information could not be decrypted (KT_DATA_KEY). Ask the office to check the record.';

    private static ?Encrypter $encrypter = null;
    private static bool $paused = false;

    public static function key(): string
    {
        $k = (string) env('KT_DATA_KEY', '');
        if (str_starts_with($k, 'base64:')) {
            $k = (string) base64_decode(substr($k, 7), true);
        }
        return $k;
    }

    public static function enabled(): bool
    {
        return strlen(self::key()) === 32;
    }

    private static function encrypter(): Encrypter
    {
        return self::$encrypter ??= new Encrypter(self::key(), 'AES-256-CBC');
    }

    /** Is this stored value already sealed (either form)? */
    public static function isSealed($v): bool
    {
        return is_string($v) && (str_starts_with($v, self::PREFIX) || str_starts_with($v, '"' . self::PREFIX));
    }

    /** Worth sealing? Empty values carry nothing about a child. */
    public static function isBlank($v, string $kind): bool
    {
        if ($v === null) {
            return true;
        }
        $t = trim((string) $v);
        return $t === '' || ($kind === 'json' && in_array(strtolower($t), ['[]', '{}', 'null', '""'], true));
    }

    /** Plaintext (as stored today) -> sealed form for this column kind. */
    public static function seal($plain, string $kind)
    {
        if (! is_string($plain) || self::isBlank($plain, $kind) || self::isSealed($plain) || ! self::enabled()) {
            return $plain;
        }
        $c = self::PREFIX . self::encrypter()->encryptString($plain);
        return $kind === 'json' ? json_encode($c, JSON_UNESCAPED_SLASHES) : $c;
    }

    /** Sealed value -> exactly the plaintext that was sealed. Anything else is returned untouched. */
    public static function open($v)
    {
        if (! self::isSealed($v)) {
            return $v;
        }
        $json = $v[0] === '"';
        $c = $json ? json_decode($v) : $v;
        if (! is_string($c) || ! str_starts_with($c, self::PREFIX)) {
            return $v;
        }
        try {
            return self::encrypter()->decryptString(substr($c, strlen(self::PREFIX)));
        } catch (\Throwable $e) {
            /* LOUD, never silent: an allergy list that quietly reads as empty is worse than
               one that says it cannot be read. */
            report($e);
            return $json ? json_encode([self::UNREADABLE]) : self::UNREADABLE;
        }
    }

    /** Decrypt every sealed value in one result row (object or array). */
    public static function openRow($row)
    {
        if (self::$paused) {
            return $row;
        }
        if (is_object($row)) {
            foreach (get_object_vars($row) as $k => $v) {
                if (is_string($v) && isset($v[8]) && ($v[0] === 'k' || $v[0] === '"') && self::isSealed($v)) {
                    $row->{$k} = self::open($v);
                }
            }
        } elseif (is_array($row)) {
            foreach ($row as $k => $v) {
                if (is_string($v) && isset($v[8]) && ($v[0] === 'k' || $v[0] === '"') && self::isSealed($v)) {
                    $row[$k] = self::open($v);
                }
            }
        }
        return $row;
    }

    public static function openRows(array $rows): array
    {
        if (self::$paused) {
            return $rows;
        }
        foreach ($rows as $i => $r) {
            $rows[$i] = self::openRow($r);
        }
        return $rows;
    }

    /** Run $fn seeing the raw stored values (for verification only). */
    public static function raw(callable $fn)
    {
        $was = self::$paused;
        self::$paused = true;
        try {
            return $fn();
        } finally {
            self::$paused = $was;
        }
    }
}
