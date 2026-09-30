<?php
/**
 * Local save files for the Inventory: one main save, 5 rotating autosaves and an occasional backup in Documents.
 *
 * Every file is: "SCINV1" | 12-byte IV | 16-byte GCM tag | ciphertext | SHA-256 of everything before it.
 * The trailing checksum catches damage; the GCM tag catches edits (it can't be recomputed without the key).
 * The key comes from SECRET below, so this stops accidental and casual tampering, not someone who reads this file.
 */
class Store {
    private const SECRET = 'sc.INVENT|4d1f7a9e-53c2-4b8a-9e60-c7a2f0b81d35';
    private const MAGIC = 'SCINV1';
    private const EXT = '.scinvent';
    private const AUTOSAVE_EVERY = 60;     // seconds between autosave rotations
    private const BACKUP_EVERY = 3600;     // seconds between Documents backups (keep well above AUTOSAVE_EVERY)

    private string $main;
    private array $slots = [];   // the 5 autosave files
    private string $backup = ''; // '' = no Documents folder found

    public function __construct() {
        $dir = (getenv('LOCALAPPDATA') ?: throw new RuntimeException('LOCALAPPDATA is not set, so there is nowhere to keep the save.')) . '/sc.INVENT';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
            PHP_OS_FAMILY === 'Windows' && exec('attrib +H ' . escapeshellarg($dir));
        }
        $this->main = "$dir/inventory" . self::EXT;
        for ($i = 1; $i <= 5; $i++) {
            $this->slots[] = "$dir/auto$i" . self::EXT;
        }
        // OneDrive moves Documents out of the user profile, so look there first.
        $homes = array_filter([getenv('OneDrive'), getenv('USERPROFILE')], fn($h) => $h && is_dir("$h/Documents"));
        $this->backup = $homes ? current($homes) . '/Documents/sc.INVENT backup' . self::EXT : '';
    }

    /**
     * Tries the main save, then the autosaves (newest first), then the Documents backup, and returns what $accept
     * returns for the first one that verifies and that $accept takes without throwing. Null = no save exists yet.
     */
    public function read(callable $accept): mixed {
        $files = array_filter([$this->main, ...$this->autosaves(), $this->backup], 'is_file');
        foreach ($files as $file) {
            try {
                return $accept($this->decode(file_get_contents($file)));
            } catch (Throwable) {
                // damaged or edited: fall through to the next copy
            }
        }
        return $files ? throw new RuntimeException('The saved inventory is damaged and no valid backup could be read. Nothing was changed; the files are in ' . dirname($this->main)) : null;
    }

    /** Writes the main save every time, plus an autosave and a Documents backup when they are due. */
    public function write(array $tables): void {
        clearstatcache();
        $bytes = $this->bytes($tables);
        $this->put($this->main, $bytes);

        $slots = $this->autosaves();
        if (time() - self::mtime($slots[0]) >= self::AUTOSAVE_EVERY) {
            $this->put(end($slots), $bytes);   // the oldest slot (or an empty one)
        }
        if ($this->backup && time() - self::mtime($this->backup) >= self::BACKUP_EVERY) {
            $this->put($this->backup, $bytes);
        }
    }

    /** The tables as the bytes of a save file. Every file here has this format, so an exported file can be loaded back. */
    public function bytes(array $tables): string {
        return $this->encode(json_encode($tables, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Makes these bytes (already checked with decode()) the only save: main and Documents backup overwritten, autosaves deleted. */
    public function replace(string $bytes): void {
        $this->put($this->main, $bytes);
        $this->backup && $this->put($this->backup, $bytes);   // an older backup could otherwise bring the previous inventory back
        array_map('unlink', array_filter($this->slots, 'is_file'));
    }

    /** Deletes every save, the Documents backup included (the next start would restore from it). */
    public function wipe(): void {
        array_map('unlink', array_filter([$this->main, ...$this->slots, $this->backup], 'is_file'));
    }

    /** The autosave files, newest first; slots that don't exist yet count as oldest. */
    private function autosaves(): array {
        $slots = $this->slots;
        usort($slots, fn($a, $b) => self::mtime($b) <=> self::mtime($a));
        return $slots;
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

    /** A short keyed hash of $text. Only a copy of this app can make it, so a matching hash shows the text was not edited. */
    public function sign(string $text): string {
        return substr(hash_hmac('sha256', $text, self::key()), 0, 16);
    }

    private static function key(): string {
        return hash('sha256', self::SECRET, true);
    }
}
