<?php

namespace App\Support\Pdf;

/**
 * Dompdf's PDF writer with AES-256 encryption (PDF standard security handler, revision 6 / AESV3).
 *
 * Dompdf's own setEncryption() writes the 1990s scheme (RC4, 40-bit key, /V 1 /R 2), which is broken in seconds. This
 * keeps every bit of Dompdf's writer and replaces only the three places encryption happens:
 *   - the key set-up ('keys'): a random 256-bit file key, wrapped for the user and owner passwords as ISO 32000-2
 *     Algorithms 8, 9 and 10 describe (U/UE, O/OE, Perms, the hardened SHA-256/384/512 hash of Algorithm 2.B);
 *   - the /Encrypt dictionary ('out'): /V 5 /R 6 with the AESV3 crypt filter for strings and streams;
 *   - ARC4($text), which Dompdf calls for every string and stream: AES-256-CBC with a fresh random IV in front, as the
 *     standard requires (there is no per-object key with AESV3, so encryptInit() and ARC4_init() do nothing);
 *   - the ToUnicode CMap, the one stream Dompdf forgets to encrypt.
 * Without setEncryption() nothing changes at all. Used through AesCpdfAdapter; see PdfSeal.
 */
class AesCpdf extends \Dompdf\Cpdf
{
    private ?string $aesKey = null;

    protected function o_encryption($id, $action, $options = '')
    {
        if ($action === 'keys') {
            $info = $this->objects[$id]['info'];
            $user = substr((string) $info['user'], 0, 127);
            $owner = substr((string) $info['owner'], 0, 127);
            // Bits 1-2 clear, 7-8 and 13-32 set (reserved), plus what was granted: print 3, copy 5, accessibility 10, print well 12.
            $p = (0xFFFFF000 | 0x0C0 | 0x200 | 0x800 | ((int) $info['p'] & 0x3C)) & 0xFFFFFFFF;
            $key = random_bytes(32);
            $zero = str_repeat("\0", 16);
            $raw = OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;

            $uvs = random_bytes(8);
            $uks = random_bytes(8);
            $U = self::hash2b($user, $uvs) . $uvs . $uks;
            $UE = openssl_encrypt($key, 'aes-256-cbc', self::hash2b($user, $uks), $raw, $zero);
            $ovs = random_bytes(8);
            $oks = random_bytes(8);
            $O = self::hash2b($owner, $ovs, $U) . $ovs . $oks;
            $OE = openssl_encrypt($key, 'aes-256-cbc', self::hash2b($owner, $oks, $U), $raw, $zero);
            $perms = openssl_encrypt(pack('V', $p) . "\xFF\xFF\xFF\xFF" . 'Tadb' . random_bytes(4), 'aes-256-ecb', $key, $raw);
            if (! is_string($UE) || ! is_string($OE) || ! is_string($perms)) {
                throw new \RuntimeException('PDF encryption failed.');
            }
            $this->objects[$id]['info'] += ['U' => $U, 'UE' => $UE, 'O' => $O, 'OE' => $OE, 'Perms' => $perms, 'P' => $p - 0x100000000];
            $this->aesKey = $key;
            $this->encryptionKey = $key;
            $this->encrypted = true;

            return null;
        }
        if ($action === 'out' && $this->aesKey !== null) {
            $i = $this->objects[$id]['info'];

            return "\n$id 0 obj\n<<\n/Filter /Standard\n/V 5\n/R 6\n/Length 256"
                . "\n/CF << /StdCF << /Type /CryptFilter /CFM /AESV3 /AuthEvent /DocOpen /Length 32 >> >>\n/StmF /StdCF\n/StrF /StdCF"
                . "\n/O <" . bin2hex($i['O']) . ">\n/U <" . bin2hex($i['U']) . ">\n/OE <" . bin2hex($i['OE']) . ">\n/UE <" . bin2hex($i['UE']) . '>'
                . "\n/Perms <" . bin2hex($i['Perms']) . ">\n/P " . $i['P'] . "\n/EncryptMetadata true\n>>\nendobj";
        }

        return parent::o_encryption($id, $action, $options);
    }

    /** Dompdf writes the ToUnicode CMap stream in the clear (with RC4 readers shrug; with AES they stop). Encrypt it whole. */
    protected function o_toUnicode($id, $action)
    {
        if ($action !== 'out' || $this->aesKey === null) {
            return parent::o_toUnicode($id, $action);
        }
        $this->encrypted = false;
        try {
            $res = (string) parent::o_toUnicode($id, $action);
        } finally {
            $this->encrypted = true;
        }
        if (! preg_match('/^(.*?)<<\/Length \d+ >>\nstream\n(.*)\nendstream\nendobj$/s', $res, $m)) {
            throw new \RuntimeException('PDF encryption failed.');
        }
        $enc = $this->ARC4($m[2]);

        return $m[1] . '<</Length ' . strlen($enc) . " >>\nstream\n" . $enc . "\nendstream\nendobj";
    }

    function encryptInit($id)
    {
        if ($this->aesKey === null) {
            parent::encryptInit($id);
        }
    }

    function ARC4_init($key = '')
    {
        if ($this->aesKey === null) {
            parent::ARC4_init($key);
        }
    }

    function ARC4($text)
    {
        if ($this->aesKey === null) {
            return parent::ARC4($text);
        }
        $iv = random_bytes(16);
        $out = openssl_encrypt((string) $text, 'aes-256-cbc', $this->aesKey, OPENSSL_RAW_DATA, $iv);
        if (! is_string($out)) {
            throw new \RuntimeException('PDF encryption failed.');
        }

        return $iv . $out;
    }

    /** ISO 32000-2 Algorithm 2.B: the revision 6 password hash. */
    private static function hash2b(string $pw, string $salt, string $udata = ''): string
    {
        $k = hash('sha256', $pw . $salt . $udata, true);
        $i = 0;
        while (true) {
            $e = openssl_encrypt(str_repeat($pw . $k . $udata, 64), 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $sum = 0;
            for ($j = 0; $j < 16; $j++) {
                $sum += ord($e[$j]);
            }
            $k = hash([0 => 'sha256', 1 => 'sha384', 2 => 'sha512'][$sum % 3], $e, true);
            $i++;
            if ($i >= 64 && ord($e[strlen($e) - 1]) <= $i - 32) {
                break;
            }
        }

        return substr($k, 0, 32);
    }
}
