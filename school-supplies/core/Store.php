<?php
/**
 * Save files for the Inventory. The database is the only live copy; these files are copies of it:
 * the one-file export (Manager > Save) and an hourly backup in Documents. Nothing here is ever read back by itself.
 *
 * Every file is: "SCINV1" | 12-byte IV | 16-byte GCM tag | ciphertext | SHA-256 of everything before it.
 * The trailing checksum catches damage; the GCM tag catches edits (it can't be recomputed without the key).
 * The key comes from SECRET below, so this stops accidental and casual tampering, not someone who reads this file.
 */
class Store {
    private const SECRET = 'sc.INVENT|4d1f7a9e-53c2-4b8a-9e60-c7a2f0b81d35';
    private const MAGIC = 'SCINV1';
    private const EXT = '.scinvent';
    private const QR_DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ$%*+-./:';   // the 44 QR alphanumeric characters that are not a space
    private const BACKUP_EVERY = 3600;     // seconds between Documents backups

    private string $backup = ''; // '' = no Documents folder found

    public function __construct() {
        // OneDrive moves Documents out of the user profile, so look there first.
        $homes = array_filter([getenv('OneDrive'), getenv('USERPROFILE')], fn($h) => $h && is_dir("$h/Documents"));
        $this->backup = $homes ? current($homes) . '/Documents/sc.INVENT backup' . self::EXT : '';
    }

    /** Refreshes the Documents backup if it is older than BACKUP_EVERY. $tables is only called when it is due. */
    public function backup(callable $tables): void {
        clearstatcache();
        $this->backup && time() - self::mtime($this->backup) >= self::BACKUP_EVERY && $this->replace($this->bytes($tables()));
    }

    /** The tables as the bytes of a save file. Every file here has this format, so an exported file can be loaded back. */
    public function bytes(array $tables): string {
        return $this->encode(json_encode($tables, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Makes these bytes (already checked with decode()) the backup. Best effort: the database already holds the data,
     * so a Documents folder that can't be written must not turn a saved change into an error page.
     */
    public function replace(string $bytes): void {
        try {
            $this->backup && $this->put($this->backup, $bytes);
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
        }
    }

    /** Deletes the Documents backup. */
    public function wipe(): void {
        $this->backup && is_file($this->backup) && unlink($this->backup);
    }

    private static function mtime(string $file): int {
        return is_file($file) ? filemtime($file) : 0;
    }

    /** Write to a temp file, then swap it in, so a crash mid-write never leaves a half-written save. */
    private function put(string $file, string $bytes): void {
        file_put_contents("$file.tmp", $bytes, LOCK_EX) === strlen($bytes) && rename("$file.tmp", $file)
            || throw new RuntimeException("Could not write $file");
    }

    private function encode(string $json): string {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($json, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC);
        $body = self::MAGIC . $iv . $tag . $cipher;
        return $body . hash('sha256', $body, true);
    }

    public function decode(string $bytes): array {
        $body = substr($bytes, 0, -32);
        if (strlen($bytes) < 66 || !str_starts_with($body, self::MAGIC) || !hash_equals(substr($bytes, -32), hash('sha256', $body, true))) {
            throw new RuntimeException('Checksum mismatch.');
        }
        $json = openssl_decrypt(substr($body, 34), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($body, 6, 12), substr($body, 18, 16), self::MAGIC);
        return $json === false ? throw new RuntimeException('Authentication failed.') : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The text for a QR code: $plain deflated (raw, so no header bytes), encrypted and written in radix 44.
     * Layout: 12-byte IV | 16-byte GCM tag | ciphertext. "SCQR1" is authenticated but not stored, so it costs nothing.
     * Radix 44 = the QR alphanumeric set without the space (a space would not survive trimming), 3 characters per
     * 2 bytes; the QR library packs those at 11 bits per 2 characters, which beats base64 in byte mode by about 30%.
     */
    public function seal(string $plain): string {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt(gzdeflate($plain, 9), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'SCQR1');
        $bin = $iv . $tag . $cipher;
        $text = '';
        foreach (str_split($bin, 2) as $pair) {
            $n = strlen($pair) === 2 ? ord($pair[0]) * 256 + ord($pair[1]) : ord($pair);
            $text .= self::QR_DIGITS[$n % 44] . self::QR_DIGITS[intdiv($n, 44) % 44] . (strlen($pair) === 2 ? self::QR_DIGITS[intdiv($n, 1936)] : '');
        }
        return $text;
    }

    /** Reverses seal(). Throws RuntimeException if $text was not made by seal() with this key, or was edited. */
    public function open(string $text): string {
        $bin = '';
        foreach (str_split($text, 3) as $group) {
            $n = 0;
            foreach (array_reverse(str_split($group)) as $char) {
                ($digit = strpos(self::QR_DIGITS, $char)) === false && throw new RuntimeException('Not a QR code of this app.');
                $n = $n * 44 + $digit;
            }
            $bin .= strlen($group) === 3 ? chr($n >> 8 & 255) . chr($n & 255) : chr($n & 255);
        }
        strlen($bin) > 28 || throw new RuntimeException('QR code too short.');
        $deflated = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16), 'SCQR1');
        return $deflated === false ? throw new RuntimeException('Authentication failed.') : gzinflate($deflated);
    }

    private static function key(): string {
        return hash('sha256', self::SECRET, true);
    }
}
