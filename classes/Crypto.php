<?php
/**
 * Crypto — double (envelope) encryption for sensitive data at rest.
 *
 * Layer 1: every item (a file or a database value) is encrypted with its own
 *          random 256-bit data key, using AES-256-GCM (authenticated: any
 *          change to the stored bytes is detected and refused).
 * Layer 2: that data key is itself encrypted with the server's master key
 *          (AES-256-GCM) and stored next to the data.
 * Reading needs the master key to unwrap the data key, then the data key to
 * read the data. A copied database or uploads folder is useless without
 * the master key, and each item has a different key.
 *
 * The master key is created automatically on first use in
 * config/encryption.key (the config folder is blocked from the browser and
 * the file is not in git). BACK IT UP: without it, encrypted data cannot be
 * read. Each server (localhost, Hostinger) has its own key.
 *
 * Old values and files saved before encryption are still read normally
 * (they have no encryption marker) and are encrypted when next saved, or by
 * Crypto::encryptExistingData().
 */
class Crypto {
    const FILE_MAGIC = "OSWDENC2";       // start of an encrypted file
    const FIELD_PREFIX = 'enc2:';        // start of an encrypted database value
    const CIPHER = 'aes-256-gcm';
    const KEY_FILE = __DIR__ . '/../config/encryption.key';
    const DONE_FILE = __DIR__ . '/../config/encryption.done';

    private static $masterKey = null;

    /** The server's master key (32 bytes), created on first use. Null if unavailable. */
    private static function masterKey() {
        if (self::$masterKey !== null) {
            return self::$masterKey ?: null;
        }
        self::$masterKey = false;
        if (!extension_loaded('openssl')) {
            error_log('Crypto: the openssl extension is not available; data is stored without encryption.');
            return null;
        }
        if (is_file(self::KEY_FILE)) {
            $key = base64_decode(trim((string)file_get_contents(self::KEY_FILE)), true);
            if ($key !== false && strlen($key) === 32) {
                return self::$masterKey = $key;
            }
            error_log('Crypto: config/encryption.key is damaged.');
            return null;
        }
        $key = random_bytes(32);
        if (@file_put_contents(self::KEY_FILE, base64_encode($key) . "\n", LOCK_EX) === false) {
            error_log('Crypto: could not create config/encryption.key (folder not writable); data is stored without encryption.');
            return null;
        }
        @chmod(self::KEY_FILE, 0600);
        return self::$masterKey = $key;
    }

    public static function available() {
        return self::masterKey() !== null;
    }

    /**
     * Encrypt bytes: [12 nonce | 16 tag | 32 wrapped data key] + [12 nonce | 16 tag | ciphertext].
     * Returns null if encryption is not available.
     */
    public static function encrypt($plain) {
        $kek = self::masterKey();
        if ($kek === null) {
            return null;
        }
        $dataKey = random_bytes(32);
        // Layer 1: the data, with its own key.
        $iv1 = random_bytes(12);
        $cipher = openssl_encrypt((string)$plain, self::CIPHER, $dataKey, OPENSSL_RAW_DATA, $iv1, $tag1, 'oswd-data');
        // Layer 2: the data key, with the master key.
        $iv2 = random_bytes(12);
        $wrapped = openssl_encrypt($dataKey, self::CIPHER, $kek, OPENSSL_RAW_DATA, $iv2, $tag2, 'oswd-key');
        if ($cipher === false || $wrapped === false) {
            return null;
        }
        return $iv2 . $tag2 . $wrapped . $iv1 . $tag1 . $cipher;
    }

    /** Decrypt what encrypt() produced. Returns null if the key is wrong or the data was altered. */
    public static function decrypt($blob) {
        $kek = self::masterKey();
        if ($kek === null || strlen((string)$blob) < 88) {
            return null;
        }
        $iv2 = substr($blob, 0, 12);
        $tag2 = substr($blob, 12, 16);
        $wrapped = substr($blob, 28, 32);
        $iv1 = substr($blob, 60, 12);
        $tag1 = substr($blob, 72, 16);
        $cipher = substr($blob, 88);
        $dataKey = openssl_decrypt($wrapped, self::CIPHER, $kek, OPENSSL_RAW_DATA, $iv2, $tag2, 'oswd-key');
        if ($dataKey === false) {
            return null;
        }
        $plain = openssl_decrypt($cipher, self::CIPHER, $dataKey, OPENSSL_RAW_DATA, $iv1, $tag1, 'oswd-data');
        return $plain === false ? null : $plain;
    }

    /* ---------------- database values ---------------- */

    public static function isEncryptedField($value) {
        return is_string($value) && str_starts_with($value, self::FIELD_PREFIX);
    }

    /** Value to store: "enc2:<base64>". Empty values and already-encrypted values are kept as they are. */
    public static function encryptField($value) {
        if ($value === null || $value === '' || self::isEncryptedField($value)) {
            return $value;
        }
        $blob = self::encrypt((string)$value);
        return $blob === null ? $value : self::FIELD_PREFIX . base64_encode($blob);
    }

    /** Value to show. Plain (older) values pass through unchanged. */
    public static function decryptField($value) {
        if (!self::isEncryptedField($value)) {
            return $value;
        }
        $plain = self::decrypt((string)base64_decode(substr($value, strlen(self::FIELD_PREFIX)), true));
        if ($plain === null) {
            error_log('Crypto: a stored value could not be decrypted (wrong key or altered data).');
            return '';
        }
        return $plain;
    }

    /* ---------------- files ---------------- */

    /** Encrypt a file in place (no-op if already encrypted or encryption unavailable). */
    public static function encryptFile($path) {
        $data = @file_get_contents($path);
        if ($data === false || str_starts_with($data, self::FILE_MAGIC)) {
            return $data !== false;
        }
        $blob = self::encrypt($data);
        if ($blob === null) {
            return false;
        }
        $tmp = $path . '.enc-tmp';
        if (@file_put_contents($tmp, self::FILE_MAGIC . $blob, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** The file's original bytes (decrypted if it was encrypted), or null. */
    public static function readFile($path) {
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        if (!str_starts_with($data, self::FILE_MAGIC)) {
            return $data;   // saved before encryption was added
        }
        return self::decrypt(substr($data, strlen(self::FILE_MAGIC)));
    }

    /* ---------------- existing data ---------------- */

    /** Encrypt everything saved before encryption was added. Runs once per server. */
    public static function encryptExistingData() {
        if (is_file(self::DONE_FILE) || !self::available()) {
            return ['files' => 0, 'values' => 0];
        }
        $files = 0;
        $values = 0;
        foreach (array_merge(glob(UPLOAD_DIR . '*') ?: [], glob(UPLOAD_DIR . 'enrollment/*') ?: []) as $path) {
            if (is_file($path) && basename($path)[0] !== '.' && self::encryptFile($path)) {
                $files++;
            }
        }
        $db = Database::getInstance()->getConnection();
        // Encrypted values are ~140 characters: make sure the columns can hold them
        // first (a too-short column would cut them and they could not be decrypted).
        foreach (['users', 'enrolled_students'] as $table) {
            $len = (int)$db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'contact_number'")->fetchColumn();
            if ($len > 0 && $len < 255) {
                $db->exec("ALTER TABLE {$table} MODIFY contact_number VARCHAR(255) NULL");
            }
        }
        foreach (['users' => 'user_id', 'enrolled_students' => 'enrolled_id'] as $table => $idCol) {
            $rows = $db->query("SELECT {$idCol} AS id, contact_number FROM {$table}
                                WHERE contact_number IS NOT NULL AND contact_number <> '' AND contact_number NOT LIKE 'enc2:%'")->fetchAll();
            $update = $db->prepare("UPDATE {$table} SET contact_number = :v WHERE {$idCol} = :id");
            foreach ($rows as $row) {
                $update->execute([':v' => self::encryptField($row['contact_number']), ':id' => $row['id']]);
                $values++;
            }
        }
        @file_put_contents(self::DONE_FILE, date('c') . "\n");
        return ['files' => $files, 'values' => $values];
    }
}
