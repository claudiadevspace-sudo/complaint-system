<?php
/**
 * Settings — system settings the Super Admin can change without editing code.
 *
 * DEFINITIONS lists every setting with its type, default and allowed range.
 * config/config.php reads them through Settings::get() when it defines the
 * constants the rest of the system already uses (SITE_NAME,
 * LOGIN_BLOCK_AFTER_FAILS, ...), so existing code needs no changes.
 *
 * If the table is missing (migration not run) or a row is absent, the
 * default is used — the system always starts with sensible values.
 */
class Settings {
    const DEFINITIONS = [
        // ---- General -------------------------------------------------
        'site_name' => [
            'group' => 'general', 'type' => 'string', 'default' => 'OSWD Complaint System', 'max' => 100,
            'label' => 'System name', 'help' => 'Shown in page titles, the navbar and emails.',
        ],
        'registration_open' => [
            'group' => 'general', 'type' => 'bool', 'default' => true,
            'label' => 'Student registration open', 'help' => 'When off, new students cannot create accounts.',
        ],
        'maintenance_mode' => [
            'group' => 'general', 'type' => 'bool', 'default' => false,
            'label' => 'Maintenance mode', 'help' => 'When on, only the Super Admin can sign in; everyone else is signed out.',
        ],
        'school_year_start_month' => [
            'group' => 'general', 'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 12, 'unit' => '(1 = Jan … 12 = Dec)',
            'label' => 'School year starts in month', 'help' => 'Used by the OSWD Reports analytics. 8 = August, so SY 2026–2027 runs Aug 2026 to Jul 2027.',
        ],
        'maintenance_message' => [
            'group' => 'general', 'type' => 'string', 'max' => 255,
            'default' => 'The system is under maintenance. Please try again later.',
            'label' => 'Maintenance message', 'help' => 'Shown on the sign-in page during maintenance.',
        ],

        // ---- Login security (Random Forest + lockout) -----------------
        'login_rf_enabled' => [
            'group' => 'security', 'type' => 'bool', 'default' => true,
            'label' => 'Random Forest login check', 'help' => 'Score each sign-in; medium risk needs an emailed code, high risk is blocked.',
        ],
        'login_otp_minutes' => [
            'group' => 'security', 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 60, 'unit' => 'minutes',
            'label' => 'Verification code lifetime', 'help' => 'How long an emailed sign-in code stays valid.',
        ],
        'login_cooldown_after_fails' => [
            'group' => 'security', 'type' => 'int', 'default' => 2, 'min' => 1, 'max' => 10, 'unit' => 'failed attempts',
            'label' => 'Cooldown after', 'help' => 'Failed sign-ins in a row before a short wait is enforced.',
        ],
        'login_cooldown_seconds' => [
            'group' => 'security', 'type' => 'int', 'default' => 30, 'min' => 5, 'max' => 600, 'unit' => 'seconds',
            'label' => 'Cooldown length', 'help' => 'How long the user must wait during a cooldown.',
        ],
        'login_block_after_fails' => [
            'group' => 'security', 'type' => 'int', 'default' => 3, 'min' => 2, 'max' => 20, 'unit' => 'failed attempts',
            'label' => 'Lock account after', 'help' => 'Must be higher than "Cooldown after".',
        ],
        'login_block_hours' => [
            'group' => 'security', 'type' => 'int', 'default' => 24, 'min' => 1, 'max' => 168, 'unit' => 'hours',
            'label' => 'Lock duration', 'help' => 'How long a locked account stays locked (the Super Admin can unlock sooner).',
        ],
        'session_idle_minutes' => [
            'group' => 'security', 'type' => 'int', 'default' => 30, 'min' => 5, 'max' => 240, 'unit' => 'minutes',
            'label' => 'Sign out after inactivity', 'help' => 'A signed-in user who does nothing for this long is signed out automatically (protects student information on shared computers).',
        ],
        'user_active_minutes' => [
            'group' => 'security', 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120, 'unit' => 'minutes',
            'label' => 'Idle time before "Inactive"', 'help' => 'In User management, a signed-in user idle this long shows as Inactive.',
        ],

        // ---- Automatic backups (see DatabaseBackup::autoBackupIfDue) ----
        'auto_backup_enabled' => [
            'group' => 'backup', 'type' => 'bool', 'default' => true,
            'label' => 'Automatic backups', 'help' => 'Create a backup on a schedule. It appears on the Backup & restore page, ready to download.',
        ],
        'auto_backup_interval_hours' => [
            'group' => 'backup', 'type' => 'int', 'default' => 24, 'min' => 1, 'max' => 168, 'unit' => 'hours',
            'label' => 'Back up every', 'help' => '24 = once a day, 168 = once a week.',
        ],
        'auto_backup_keep' => [
            'group' => 'backup', 'type' => 'int', 'default' => 7, 'min' => 1, 'max' => 60, 'unit' => 'backups',
            'label' => 'Keep the newest', 'help' => 'Older automatic backups are deleted. Manual and safety backups are never deleted automatically.',
        ],

        // ---- Programs per department (used for routing to the right Program Coordinator) ----
        // Defaults are a starting point: the Super Admin edits them to the campus's real programs.
        'programs_DIT' => [
            'group' => 'programs', 'type' => 'lines', 'max' => 100, 'max_lines' => 20,
            'default' => ['BS Information Technology', 'BS Industrial Technology'],
            'label' => 'DIT programs', 'help' => 'One program per line.',
        ],
        'programs_DCS' => [
            'group' => 'programs', 'type' => 'lines', 'max' => 100, 'max_lines' => 20,
            'default' => ['Information Technology', 'Computer Engineering', 'Computer Science'],
            'label' => 'DCS programs', 'help' => 'One program per line.',
        ],
        'programs_CCJE' => [
            'group' => 'programs', 'type' => 'lines', 'max' => 100, 'max_lines' => 20,
            'default' => ['BS Criminology'],
            'label' => 'CCJE programs', 'help' => 'One program per line.',
        ],
        'programs_DBM' => [
            'group' => 'programs', 'type' => 'lines', 'max' => 100, 'max_lines' => 20,
            'default' => ['BS Business Administration', 'BS Hospitality Management'],
            'label' => 'DBM programs', 'help' => 'One program per line.',
        ],
        'programs_DGTT' => [
            'group' => 'programs', 'type' => 'lines', 'max' => 100, 'max_lines' => 20,
            'default' => ['Bachelor of Elementary Education', 'Bachelor of Secondary Education'],
            'label' => 'DGTT programs', 'help' => 'One program per line.',
        ],
    ];

    const GROUPS = [
        'general' => ['General', 'gear'],
        'security' => ['Login security', 'shield-lock'],
        'backup' => ['Automatic backups', 'clock-history'],
        'programs' => ['Programs per department', 'mortarboard'],
    ];

    private static $cache = null;

    /** Current value of a setting (stored value, else the default). */
    public static function get($key) {
        if (!isset(self::DEFINITIONS[$key])) {
            throw new InvalidArgumentException('Unknown setting: ' . $key);
        }
        if (self::$cache === null) {
            self::$cache = [];
            try {
                $rows = Database::getInstance()->getConnection()
                    ->query('SELECT setting_key, setting_value FROM system_settings')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                self::$cache = $rows ?: [];
            } catch (PDOException $e) {
                // Table not created yet: every setting uses its default.
            }
        }
        $def = self::DEFINITIONS[$key];
        if (!array_key_exists($key, self::$cache)) {
            return $def['default'];
        }
        return self::cast($def, self::$cache[$key]);
    }

    private static function cast(array $def, $value) {
        return match ($def['type']) {
            'bool' => (bool)(int)$value,
            'int' => (int)$value,
            // One item per line → array of non-empty, trimmed lines.
            'lines' => array_values(array_filter(array_map('trim', explode("
", (string)$value)), 'strlen')),
            default => (string)$value,
        };
    }

    /**
     * Validate and save the submitted form. Booleans missing from $input
     * mean "unchecked". Returns ['success' => bool, 'errors' => [...], 'changed' => n].
     */
    public static function save(array $input, $userId) {
        $errors = [];
        $new = [];
        foreach (self::DEFINITIONS as $key => $def) {
            if ($def['type'] === 'bool') {
                $new[$key] = !empty($input[$key]);
                continue;
            }
            if ($def['type'] === 'lines') {
                // One per line; blank lines and repeats (any letter case) are dropped.
                $lines = [];
                foreach (preg_split('/\R/', (string)($input[$key] ?? '')) as $line) {
                    $line = trim($line);
                    if ($line !== '' && !in_array(mb_strtolower($line), array_map('mb_strtolower', $lines), true)) {
                        $lines[] = $line;
                    }
                }
                if (!$lines) {
                    $errors[] = $def['label'] . ': enter at least one.';
                } elseif (count($lines) > $def['max_lines']) {
                    $errors[] = $def['label'] . ': at most ' . $def['max_lines'] . ' lines.';
                } elseif (max(array_map('mb_strlen', $lines)) > $def['max']) {
                    $errors[] = $def['label'] . ': each line can be up to ' . $def['max'] . ' characters.';
                } else {
                    $new[$key] = $lines;
                }
                continue;
            }
            $raw = trim((string)($input[$key] ?? ''));
            if ($def['type'] === 'int') {
                if (!preg_match('/^\d+$/', $raw) || (int)$raw < $def['min'] || (int)$raw > $def['max']) {
                    $errors[] = $def['label'] . ' must be a whole number from ' . $def['min'] . ' to ' . $def['max'] . '.';
                    continue;
                }
                $new[$key] = (int)$raw;
            } else {
                if ($raw === '' || mb_strlen($raw) > $def['max']) {
                    $errors[] = $def['label'] . ' is required (up to ' . $def['max'] . ' characters).';
                    continue;
                }
                $new[$key] = $raw;
            }
        }
        // Rules that involve two settings.
        if (isset($new['login_block_after_fails'], $new['login_cooldown_after_fails'])
            && $new['login_block_after_fails'] <= $new['login_cooldown_after_fails']) {
            $errors[] = '"Lock account after" must be higher than "Cooldown after".';
        }
        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        // Save only what changed, and log each change as old → new.
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        $changes = [];
        foreach ($new as $key => $value) {
            $old = self::get($key);
            if ($old === $value) {
                continue;
            }
            $stored = is_array($value) ? implode("
", $value) : (is_bool($value) ? (string)(int)$value : (string)$value);
            $stmt->execute([':k' => $key, ':v' => $stored, ':u' => (int)$userId]);
            $changes[$key] = ['from' => self::display($key, $old), 'to' => self::display($key, $value)];
        }
        self::$cache = null;   // re-read on next get()

        if ($changes) {
            (new AuditLog())->record('settings_update', 'settings', null, implode(', ', array_keys($changes)), null, $changes);
        }
        return ['success' => true, 'changed' => count($changes)];
    }

    /** Human-readable value for the audit log. */
    public static function display($key, $value) {
        $def = self::DEFINITIONS[$key];
        if ($def['type'] === 'bool') {
            return $value ? 'On' : 'Off';
        }
        if ($def['type'] === 'lines') {
            return implode(', ', (array)$value);
        }
        return (string)$value . (isset($def['unit']) ? ' ' . $def['unit'] : '');
    }
}
