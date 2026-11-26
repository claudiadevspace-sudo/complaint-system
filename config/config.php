<?php
/**
 * General Configuration
 * OSWD Complaint System
 */

// Session cookie hardening must happen before the session starts
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_lifetime', '0');          // gone when the browser closes
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
// Over HTTPS (e.g. a deployed server) the cookie is never sent over plain HTTP.
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    ini_set('session.cookie_secure', '1');
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once __DIR__ . '/database.php';

// Compare text with the same rule (collation) the tables use. Some hosts
// (e.g. Hostinger) default the connection to utf8mb4_general_ci while the
// tables are utf8mb4_unicode_ci, and newer MariaDB/MySQL versions then refuse
// queries that compare the two ("Illegal mix of collations").
try {
    Database::getInstance()->getConnection()->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $e) {
    // Never block the page over this.
}

// Autoload classes
spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/../classes/',
        __DIR__ . '/../controllers/',
        __DIR__ . '/../models/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Site settings. Values marked Settings::get() can be changed by the Super
// Admin (Settings page); the rest are fixed here.
define('SITE_NAME', Settings::get('site_name'));
define('REGISTRATION_OPEN', Settings::get('registration_open'));
define('MAINTENANCE_MODE', Settings::get('maintenance_mode'));
define('MAINTENANCE_MESSAGE', Settings::get('maintenance_message'));
/*
 * Site address, used for redirects and for links in emails. Worked out from
 * the request, so the same files run unchanged:
 *   on this computer   http://localhost/Complaint
 *   on the campus LAN  http://192.168.x.x/Complaint
 *   on the web host    https://your-domain   (files directly in public_html)
 * To fix it to one address (recommended on the live site, so a forged Host
 * header can never change links in emails), set SITE_URL_FIXED below, e.g.
 *   define('SITE_URL_FIXED', 'https://oswd.example.com');
 */
define('SITE_URL_FIXED', '');

if (SITE_URL_FIXED !== '') {
    define('SITE_URL', rtrim(SITE_URL_FIXED, '/'));
} elseif (PHP_SAPI === 'cli') {
    define('SITE_URL', 'http://localhost/Complaint');
} else {
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $requestHost)) {
        $requestHost = 'localhost';   // malformed Host header
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    // Every page lives in the project's top folder, so its folder is the base
    // path: "/Complaint" locally, "" when the files are in public_html.
    $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    define('SITE_URL', ($isHttps ? 'https' : 'http') . '://' . $requestHost . $basePath);
}
// System logo (shield mark): the navy/blue mark for light mode and the
// white/blue one for dark mode. The favicon is the app icon.
define('SITE_LOGO', 'assets/images/logo-mark.png');
define('SITE_LOGO_DARK', 'assets/images/logo-mark-dark.png');
define('SITE_FAVICON', 'assets/images/favicon.png');
define('ADMIN_EMAIL', 'oswd@nemsu.edu');

$smtpConfig = __DIR__ . '/smtp.php';
if (is_file($smtpConfig)) {
    require_once $smtpConfig;
} else {
    define('SMTP_ENABLED', false);
    define('SMTP_HOST', '');
    define('SMTP_PORT', 587);
    define('SMTP_ENCRYPTION', 'tls');
    define('SMTP_USERNAME', '');
    define('SMTP_PASSWORD', '');
    define('SMTP_FROM_EMAIL', ADMIN_EMAIL);
    define('SMTP_FROM_NAME', SITE_NAME);
}

// File upload settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);

// Python ML service settings
define('PYTHON_PATH', 'python');
define('ML_MODEL_PATH', __DIR__ . '/../ml_model/');

// Login Random Forest risk scoring
define('LOGIN_RF_ENABLED', Settings::get('login_rf_enabled'));
define('LOGIN_RF_SHADOW_MODE', false);
define('LOGIN_HASH_SALT', 'nemsu-oswd-login-v1');
define('LOGIN_OTP_TTL', Settings::get('login_otp_minutes') * 60);
define('LOGIN_COOLDOWN_AFTER_FAILS', Settings::get('login_cooldown_after_fails'));
define('LOGIN_COOLDOWN_SECONDS', Settings::get('login_cooldown_seconds'));
define('LOGIN_BLOCK_AFTER_FAILS', Settings::get('login_block_after_fails'));
define('LOGIN_BLOCK_SECONDS', Settings::get('login_block_hours') * 3600);
define('LOGIN_RESET_TTL', 3600);
define('LOGIN_OTP_MAX_ATTEMPTS', 5);

// Timezone
date_default_timezone_set('Asia/Manila');

// Errors: never shown to visitors in the browser (they can reveal file paths
// and SQL); they go to the PHP error log instead. Command-line scripts
// (migrations, backups) still print them.
error_reporting(E_ALL);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');

// Errors are written to logs/php_errors.log (private: logs/.htaccess blocks
// the browser), so an "Error 500" on any server can be diagnosed from the
// File Manager. The folder and its protection are created automatically.
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
if (is_dir($logDir) && !is_file($logDir . '/.htaccess')) {
    @file_put_contents($logDir . '/.htaccess', "# Private: never served to the browser.\nRequire all denied\n");
}
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('log_errors', '1');
    ini_set('error_log', $logDir . '/php_errors.log');
}
unset($logDir);
ini_set('log_errors', '1');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-XSS-Protection: 1; mode=block');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (isset($_SESSION['user_id'])) {
        // Pages with student information are never cached, so Back after
        // logging out (or on a shared computer) cannot show them again.
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net; frame-ancestors 'none'");
}


/*
 * Sign-in activity. A user counts as "Active" in User management while they
 * have opened a page within USER_ACTIVE_WINDOW seconds and not logged out.
 * Closing the browser without logging out shows "Inactive" once that time
 * has passed. Logging out clears it at once (see logout.php).
 */
define('USER_ACTIVE_WINDOW', Settings::get('user_active_minutes') * 60);

/*
 * Automatic sign-out. A session ends after SESSION_IDLE_SECONDS without the
 * user opening a page or submitting a form (Super Admin → Settings), and in
 * any case SESSION_MAX_SECONDS after signing in. The live-update check
 * (live_status.php) runs in the background, so it does NOT count as activity:
 * a tab left open on a shared computer still signs out.
 */
define('SESSION_IDLE_SECONDS', Settings::get('session_idle_minutes') * 60);
define('SESSION_MAX_SECONDS', 12 * 3600);

if (isset($_SESSION['user_id']) && PHP_SAPI !== 'cli') {
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
    $signedInAt = (int)($_SESSION['signed_in_at'] ?? $now);
    $_SESSION['signed_in_at'] = $signedInAt;
    if ($now - $lastActivity > SESSION_IDLE_SECONDS || $now - $signedInAt > SESSION_MAX_SECONDS) {
        $expiredUserId = (int)$_SESSION['user_id'];
        try {
            Database::getInstance()->getConnection()
                ->prepare('UPDATE users SET is_signed_in = 0 WHERE user_id = :id')
                ->execute([':id' => $expiredUserId]);
        } catch (PDOException $e) {
            // Never block signing out.
        }
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['message'] = 'You were signed out because of inactivity. Please sign in again.';
        $_SESSION['message_type'] = 'warning';
    } elseif (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'live_status.php') {
        $_SESSION['last_activity'] = $now;
    }
}

if (isset($_SESSION['user_id']) && PHP_SAPI !== 'cli') {
    // Write at most once a minute per session, not on every page load.
    if (time() - (int)($_SESSION['activity_saved_at'] ?? 0) >= 60) {
        try {
            Database::getInstance()->getConnection()
                ->prepare('UPDATE users SET last_activity_at = NOW(), is_signed_in = 1 WHERE user_id = :id')
                ->execute([':id' => (int)$_SESSION['user_id']]);
            $_SESSION['activity_saved_at'] = time();
        } catch (PDOException $e) {
            // Column missing (migration not run yet): never block the page.
        }
    }
}

/*
 * Automatic backups (Super Admin → Settings → Automatic backups). After the
 * page has been built, check whether a backup is due; usually this is just
 * a file-timestamp check. A failure here must never break the page.
 */
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(function () {
        try {
            DatabaseBackup::autoBackupIfDue();
        } catch (Throwable $e) {
            error_log('Automatic backup failed: ' . $e->getMessage());
        }
    });
}

/*
 * Maintenance mode (Super Admin → Settings): everyone except the Super Admin
 * is signed out on their next page load. LoginAuth also refuses new
 * sign-ins for them while it is on.
 */
if (MAINTENANCE_MODE && isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') !== 'super_admin' && PHP_SAPI !== 'cli') {
    $_SESSION = ['message' => MAINTENANCE_MESSAGE, 'message_type' => 'warning'];
    session_regenerate_id(true);
    redirect('login.php');
}

/**
 * Light / dark mode for the signed-in dashboards ('light' or 'dark').
 *
 * The choice is kept in the 'nemsu_theme' cookie (set by assets/js/theme.js
 * when the navbar toggle is pressed) and the page is rendered in that mode
 * on the server, so it never flashes the wrong colours while loading.
 * Default: dark. Sign-in, registration and other public pages are always
 * light: the mode only applies while someone is signed in.
 */
/**
 * Light or dark mode, saved in the 'nemsu_theme' cookie by the switch in the
 * top bar (dashboards) or the corner of the sign-in pages. Default: dark on
 * the dashboards, light on the sign-in pages.
 */
function appTheme() {
    $saved = $_COOKIE['nemsu_theme'] ?? '';
    if (in_array($saved, ['light', 'dark'], true)) {
        return $saved;
    }
    return isLoggedIn() ? 'dark' : 'light';
}

/**
 * Status shown in User management:
 *   'archived'    — archived by OSWD (hidden from their list, cannot sign in)
 *   'deactivated' — account switched off by the Super Admin (cannot sign in)
 *   'active'      — signed in and used the system in the last 10 minutes
 *   'inactive'    — logged out, or idle longer than that
 * $user['recently_active'] is computed in SQL (USER_RECENTLY_ACTIVE_SQL) so
 * the comparison uses the database clock, whatever PHP's time zone is.
 */
define('USER_RECENTLY_ACTIVE_SQL', '(is_signed_in = 1 AND last_activity_at IS NOT NULL AND last_activity_at >= NOW() - INTERVAL ' . USER_ACTIVE_WINDOW . ' SECOND)');

function userPresence(array $user) {
    if (!empty($user['archived_at'])) {
        return 'archived';
    }
    if (($user['status'] ?? '') !== 'active') {
        return 'deactivated';
    }
    return !empty($user['recently_active']) ? 'active' : 'inactive';
}

// Helper functions
function redirect($url) {
    header("Location: " . SITE_URL . "/" . $url);
    exit();
}

function nemsuLogoImg($class = 'nemsu-logo') {
    $path = appTheme() === 'dark' ? SITE_LOGO_DARK : SITE_LOGO;
    $full = __DIR__ . '/../' . $path;
    if (!is_file($full)) {
        return '';
    }
    $class = trim((string)$class);
    $alt = 'OSWD Student Complaint System';
    return '<img src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" decoding="async">';
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $userRole = $_SESSION['role'] ?? '';
    if (is_array($roles)) {
        return in_array($userRole, $roles, true);
    }
    return $userRole === $roles;
}

function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        $_SESSION['message'] = 'You do not have permission to access that page.';
        $_SESSION['message_type'] = 'danger';
        redirect('dashboard.php');
    }
}

function sanitizeInput($data) {
    return trim((string)$data);
}

function emptyToNull($value) {
    $value = is_string($value) ? trim($value) : $value;
    return ($value === '' || $value === null) ? null : $value;
}

function showAlert($message, $type = 'info') {
    $safeType = in_array($type, ['info', 'success', 'warning', 'danger'], true) ? $type : 'info';
    return "<div class='alert alert-{$safeType} alert-dismissible fade show' role='alert'>"
        . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8')
        . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}

function allowedComplaintStatuses() {
    return ['pending', 'under_review', 'investigating', 'resolved', 'rejected', 'escalated'];
}

function behavioralCategories() {
    return [
        'Academic Integrity Violation',
        'Unprofessional Behavior',
        'Institutional Rules Violation',
        'Teaching Standards Failure'
    ];
}

function serviceCategories() {
    return [
        'Campus Services',
        'Campus Facilities'
    ];
}

function allowedComplaintCategories() {
    return array_merge(behavioralCategories(), serviceCategories());
}

function complaintCategoryCards() {
    return [
        [
            'name' => 'Academic Integrity Violation',
            'type' => 'behavioral',
            'icon' => 'bi-mortarboard',
            'example' => 'Cheating, plagiarism, or exam misconduct',
            'route' => 'Starts with your Program Coordinator',
        ],
        [
            'name' => 'Unprofessional Behavior',
            'type' => 'behavioral',
            'icon' => 'bi-person-exclamation',
            'example' => 'Harassment, disrespect, or inappropriate conduct',
            'route' => 'Starts with your Program Coordinator',
        ],
        [
            'name' => 'Teaching Standards Failure',
            'type' => 'behavioral',
            'icon' => 'bi-easel',
            'example' => 'Unprepared classes, unfair grading, poor communication',
            'route' => 'Starts with your Program Coordinator',
        ],
        [
            'name' => 'Institutional Rules Violation',
            'type' => 'behavioral',
            'icon' => 'bi-journal-x',
            'example' => 'Breaking campus policies or code of conduct',
            'route' => 'Starts with your Program Coordinator',
        ],
        [
            'name' => 'Campus Services',
            'type' => 'services',
            'icon' => 'bi-building-gear',
            'example' => 'Enrollment, ID, records, or student services',
            'route' => 'Goes directly to OSWD',
        ],
        [
            'name' => 'Campus Facilities',
            'type' => 'services',
            'icon' => 'bi-tools',
            'example' => 'Classrooms, restrooms, equipment, or maintenance',
            'route' => 'Goes directly to OSWD',
        ],
    ];
}

function complaintTypeFromCategory($category) {
    return in_array($category, serviceCategories(), true) ? 'services' : 'behavioral';
}

function allowedRespondentTypes() {
    return ['faculty', 'staff', 'student', 'other'];
}

/**
 * Severity is decided by the nature of the complaint only (the student does
 * not choose it). Severity 1 is the most serious.
 */
function severityByNature() {
    return [
        '1' => 'Academic Integrity Violation',
        '2' => 'Unprofessional Behavior',
        '3' => 'Teaching Standards Failure',
        '4' => 'Institutional Rules Violation',
        '5' => 'Campus Services',
        '6' => 'Campus Facilities',
    ];
}

function allowedSeverities() {
    return array_map('strval', array_keys(severityByNature()));
}

/** The severity ('1'–'6') for a complaint category. */
function severityForCategory($category) {
    $level = array_search((string)$category, severityByNature(), true);
    return $level === false ? '6' : (string)$level;
}

/** Severity 1 and 2 need attention first (warning banners, red rows). */
function isUrgentSeverity($severity) {
    return in_array(normalizeSeverity($severity), ['1', '2'], true);
}

/** Old values (low/medium/high/critical) from before severity followed the nature. */
function normalizeSeverity($severity) {
    $severity = (string)$severity;
    return match ($severity) {
        'critical' => '1',
        'high' => '2',
        'medium' => '4',
        'low' => '6',
        default => in_array($severity, allowedSeverities(), true) ? $severity : '6',
    };
}

function staffRoles() {
    return ['oswd', 'program_coordinator', 'department_chair', 'guidance_office'];
}

/**
 * Every role in the system, in display order. The Super Admin is NOT a
 * staff role: it never receives or handles complaints, so staffRoles()
 * above deliberately leaves it out.
 */
function allRoles() {
    return ['student', 'program_coordinator', 'department_chair', 'guidance_office', 'oswd', 'super_admin'];
}

/**
 * Roles the Super Admin can create in User management. Students register
 * themselves, and the Super Admin account is not created from the form.
 */
function staffAccountRoles() {
    return ['program_coordinator', 'department_chair', 'guidance_office', 'oswd'];
}

/** Roles that have a program: a coordinator handles one, a student studies one. */
function programRoles() {
    return ['program_coordinator', 'student'];
}

/**
 * The campus departments. Accounts created by the Super Admin must use one
 * of these, so every department's personnel are spelled the same way.
 */
function departments() {
    return ['DIT', 'DCS', 'CCJE', 'DBM', 'DGTT', 'DTM', 'GS'];
}

/**
 * Course codes used in the campus Student Directory (e.g. "BSIT AUTO.",
 * "BSInfoTech", "BSBA HRDM", "BSED MATH") → the official program they
 * belong to. Majors are grouped under their program. Keys are matched on
 * letters only, from the start of the code (longest key first).
 */
function directoryCourseAliases() {
    return [
        'DIT' => ['BSIT' => 'BS Industrial Technology'],
        'DCS' => ['BSINFOTECH' => 'BS Information Technology', 'BSCS' => 'BS Computer Science',
                  'BSCPE' => 'BS Computer Engineering'],
        'CCJE' => ['BSCRIM' => 'BS Criminology'],
        'DBM' => ['BSBA' => 'BS Business Administration', 'BSHRM' => 'BS Hospitality Management',
                  'BSHM' => 'BS Hospitality Management'],
        'DGTT' => ['BSED' => 'Bachelor of Secondary Education', 'BEED' => 'Bachelor of Elementary Education',
                   'BTLED' => 'Bachelor of Technology and Livelihood Education',
                   'BTVTED' => 'Bachelor of Technical-Vocational Teacher Education'],
        'DTM' => ['BSTM' => 'BS Tourism Management'],
        'GS' => ['MTTE' => 'Master of Technology Teacher Education'],
    ];
}

/**
 * Validate an uploaded image/document and save it in /uploads under a random
 * name. Same rules as complaint evidence: PDF, JPG, PNG, DOC or DOCX, up to
 * MAX_FILE_SIZE, and the real file type must match the extension.
 * Returns ['ok' => true, 'file' => stored name, 'original' => original name]
 * or ['ok' => false, 'message' => '...'].
 */
function saveUploadedDocument($file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Choose an image or document to upload.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'File upload failed. Please try again.'];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['ok' => false, 'message' => 'File is too large. Maximum size is ' . round(MAX_FILE_SIZE / 1048576) . 'MB.'];
    }
    $originalName = basename((string)$file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];
    if (!in_array($ext, ALLOWED_EXTENSIONS, true) || !isset($allowedMimes[$ext])) {
        return ['ok' => false, 'message' => 'Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes[$ext], true)) {
        return ['ok' => false, 'message' => 'The uploaded file type does not match its extension.'];
    }
    $uploadDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        return ['ok' => false, 'message' => 'Failed to save the uploaded file.'];
    }
    Crypto::encryptFile($uploadDir . $fileName);   // stored encrypted (see classes/Crypto.php)
    return ['ok' => true, 'file' => $fileName, 'original' => $originalName];
}

/** How the document viewer shows a stored file: 'image', 'pdf' or 'other' (Word). */
function documentKind($fileName) {
    $ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png'], true) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other');
}

/**
 * Official programs of a department (Super Admin → Settings → Programs).
 * Complaints are routed to the Program Coordinator of the student's program,
 * so programs are chosen from this list rather than typed freely.
 */
function programsFor($department) {
    $department = strtoupper(trim((string)$department));
    return in_array($department, departments(), true) ? Settings::get('programs_' . $department) : [];
}

/**
 * A program name reduced for comparison: lower case, no punctuation, and
 * without the degree prefix. "BS in Information Technology" → "information technology".
 */
function programKey($program) {
    $key = mb_strtolower(trim((string)$program));
    $key = preg_replace('/[^a-z0-9 ]+/', ' ', $key);
    $key = preg_replace('/^(bachelor of science in|bachelor of science|bachelor of|bs in|bs|bsc|ab)\s+/', '', trim(preg_replace('/\s+/', ' ', $key)));
    // Joined short forms: "bsit" → "it", "bscs" → "cs".
    $key = preg_replace('/^bs(?=[a-z]{2,5}$)/', '', trim($key));
    return trim($key);
}

/** "information technology" → "it" (ignoring small words like "of", "in", "and"). */
function programAcronym($key) {
    $letters = '';
    foreach (explode(' ', $key) as $word) {
        if ($word !== '' && !in_array($word, ['of', 'in', 'and', 'the', 'for'], true)) {
            $letters .= $word[0];
        }
    }
    return $letters;
}

/**
 * The official spelling of a program in a department, or null if it is not
 * on that department's list. Accepts the full name in any letter case, with
 * or without "BS"/"Bachelor of ...", or its initials:
 * "it", "BSIT", "BS in Information Technology" → "Information Technology".
 */
function canonicalProgram($department, $program, $tryAliases = true) {
    $wanted = programKey($program);
    if ($wanted === '') {
        return null;
    }
    $wantedLetters = str_replace(' ', '', $wanted);
    foreach (programsFor($department) as $official) {
        $officialKey = programKey($official);
        if ($officialKey === $wanted || ($wantedLetters !== '' && programAcronym($officialKey) === $wantedLetters)) {
            return $official;
        }
    }
    // Student Directory course codes ("BSIT AUTO." → BS Industrial Technology).
    if ($tryAliases) {
        $code = strtoupper(preg_replace('/[^A-Za-z]+/', '', (string)$program));
        $aliases = directoryCourseAliases()[strtoupper(trim((string)$department))] ?? [];
        uksort($aliases, fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($aliases as $prefix => $programName) {
            if ($code !== '' && str_starts_with($code, $prefix)) {
                return canonicalProgram($department, $programName, false);
            }
        }
    }
    return null;
}

/**
 * Roles that belong to ONE department, so a department is required for
 * them. Guidance, OSWD and the Super Admin serve the whole campus.
 */
function departmentRoles() {
    return ['program_coordinator', 'department_chair'];
}

/**
 * Campus-wide offices shared by all their accounts: every active Guidance
 * (or OSWD) account sees, handles and is notified about every complaint at
 * that level, from every department and program.
 */
function officeRoles() {
    return ['guidance_office', 'oswd'];
}

/**
 * Strong password rules for accounts made by the Super Admin and by
 * create_super_admin.php. Returns a list of problems (empty = password OK).
 * Modelled on Django's default validators: minimum length, character mix,
 * common-password list, and similarity to the username/email.
 */
function passwordPolicyErrors($password, $username = '', $email = '') {
    $password = (string)$password;
    $errors = [];

    if (strlen($password) < 12) {
        $errors[] = 'Use at least 12 characters.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Use both upper-case and lower-case letters.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Include at least one symbol (for example ! @ # $).';
    }

    // Common passwords (and these with numbers stuck on the end) are rejected.
    $common = ['password', 'admin', 'administrator', 'superadmin', 'qwerty', 'letmein', 'welcome',
               'iloveyou', 'nemsu', 'oswd', 'abc123', '123456', 'passw0rd', 'changeme'];
    $core = strtolower(preg_replace('/[^a-z]/i', '', $password));
    if (in_array($core, $common, true)) {
        $errors[] = 'This password is too common.';
    }

    // The password must not contain the username or the email's name part.
    $lower = strtolower($password);
    $emailName = strtolower(strstr((string)$email, '@', true) ?: '');
    foreach ([strtolower((string)$username), $emailName] as $personal) {
        if (strlen($personal) >= 3 && strpos($lower, $personal) !== false) {
            $errors[] = 'The password is too similar to the username or email.';
            break;
        }
    }

    return $errors;
}

function workflowLevels() {
    return ['program_coordinator', 'department_chair', 'guidance_office', 'oswd'];
}

function initialWorkflowLevel($complaintType) {
    return $complaintType === 'services' ? 'oswd' : 'program_coordinator';
}

function nextWorkflowLevel($currentLevel) {
    return match ($currentLevel) {
        'program_coordinator' => 'department_chair',
        'department_chair' => 'guidance_office',
        'guidance_office' => 'oswd',
        default => null,
    };
}

function roleLabel($role) {
    return match ($role) {
        'student' => 'Student',
        'program_coordinator' => 'Program Coordinator',
        'department_chair' => 'Department Chairperson',
        'guidance_office' => 'Guidance Counselor',
        'oswd' => 'OSWD Admin',
        'super_admin' => 'Super Admin',
        default => formatStatus($role),
    };
}

function workflowLevelLabel($level) {
    return roleLabel($level);
}

function dashboardMetaForRole($role) {
    return match ($role) {
        'student' => [
            'title' => 'Student Dashboard',
            'subtitle' => 'Submit complaints and track your own submissions only.',
            'theme' => 'primary',
        ],
        'program_coordinator' => [
            'title' => 'Program Coordinator Dashboard',
            'subtitle' => 'Behavioral complaints start here. Escalate to the Department Chair if unresolved.',
            'theme' => 'info',
        ],
        'department_chair' => [
            'title' => 'Department Chairperson Dashboard',
            'subtitle' => 'Review escalated behavioral complaints. Escalate to Guidance if needed.',
            'theme' => 'secondary',
        ],
        'guidance_office' => [
            'title' => 'Guidance Counselor Dashboard',
            'subtitle' => 'Handle escalated behavioral cases. Escalate to OSWD when necessary.',
            'theme' => 'warning',
        ],
        'oswd' => [
            'title' => 'OSWD Admin Dashboard',
            'subtitle' => 'Campus services and facilities complaints come here directly. Final level for behavioral cases.',
            'theme' => 'dark',
        ],
        'super_admin' => [
            'title' => 'Super Admin',
            'subtitle' => 'System-wide oversight: monitoring, accounts, audit and reports. View only for complaints.',
            'theme' => 'dark',
        ],
        default => [
            'title' => 'Dashboard',
            'subtitle' => '',
            'theme' => 'primary',
        ],
    };
}

function formatStatus($status) {
    return ucfirst(str_replace('_', ' ', (string)$status));
}

function statusBadgeClass($status) {
    return statusMeta($status)['bootstrap'];
}

function severityBadgeClass($severity) {
    return severityMeta($severity)['bootstrap'];
}

function statusMeta($status) {
    return match ((string)$status) {
        'resolved' => ['bootstrap' => 'success', 'icon' => 'bi-check-circle-fill', 'label' => 'Resolved'],
        'under_review' => ['bootstrap' => 'info', 'icon' => 'bi-eye-fill', 'label' => 'Under review'],
        'investigating' => ['bootstrap' => 'primary', 'icon' => 'bi-search', 'label' => 'Investigating'],
        'escalated' => ['bootstrap' => 'danger', 'icon' => 'bi-arrow-up-circle-fill', 'label' => 'Escalated'],
        'rejected' => ['bootstrap' => 'dark', 'icon' => 'bi-x-circle-fill', 'label' => 'Rejected'],
        default => ['bootstrap' => 'warning', 'icon' => 'bi-hourglass-split', 'label' => 'Pending'],
    };
}

function severityMeta($severity) {
    $level = normalizeSeverity($severity);
    $nature = severityByNature()[$level];
    [$bootstrap, $icon] = match ($level) {
        '1' => ['danger', 'bi-exclamation-octagon-fill'],
        '2' => ['danger', 'bi-exclamation-triangle-fill'],
        '3', '4' => ['warning', 'bi-dash-circle-fill'],
        default => ['secondary', 'bi-circle'],
    };
    return [
        'bootstrap' => $bootstrap,
        'icon' => $icon,
        'label' => 'Severity ' . $level,
        'long' => 'Severity ' . $level . ' · ' . $nature,
        'nature' => $nature,
    ];
}

function statusBadgeHtml($status) {
    $m = statusMeta($status);
    return '<span class="badge nemsu-badge bg-' . htmlspecialchars($m['bootstrap'], ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="bi ' . htmlspecialchars($m['icon'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i> '
        . '<span>' . htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') . '</span></span>';
}

function severityBadgeHtml($severity) {
    $m = severityMeta($severity);
    return '<span class="badge nemsu-badge bg-' . htmlspecialchars($m['bootstrap'], ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($m['long'], ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="bi ' . htmlspecialchars($m['icon'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i> '
        . '<span>' . htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') . '</span></span>';
}

function statusPlainLanguage($status) {
    return match ((string)$status) {
        'pending' => 'Your complaint was received and is waiting for staff review.',
        'under_review' => 'A staff member is reviewing your complaint.',
        'investigating' => 'Your case is being investigated in more detail.',
        'escalated' => 'Your complaint was escalated to the next office in the chain.',
        'resolved' => 'This complaint has been marked resolved.',
        'rejected' => 'This complaint was closed without further action.',
        default => 'Status updates will appear here as your case moves forward.',
    };
}

function expectedResponseHint($status) {
    return match ((string)$status) {
        'pending' => 'Typical first response: 3–5 school days',
        'under_review', 'investigating' => 'Updates usually within 5–7 school days',
        'escalated' => 'The next office will review within 7–10 school days',
        'resolved', 'rejected' => 'No further action expected unless you have new information',
        default => 'Response times vary by case complexity',
    };
}

function renderEmptyState($icon, $title, $message, $actionLabel = '', $actionHref = '') {
    $icon = preg_replace('/[^a-z0-9-]/i', '', (string)$icon);
    ob_start();
    echo '<div class="nemsu-empty-state text-center py-5 px-3">';
    echo '<div class="nemsu-empty-state__icon" aria-hidden="true"><i class="bi bi-' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></div>';
    echo '<h3 class="h5">' . htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') . '</h3>';
    echo '<p class="text-muted mb-3">' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($actionLabel !== '' && $actionHref !== '') {
        echo '<a class="btn btn-primary" href="' . htmlspecialchars((string)$actionHref, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string)$actionLabel, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '</div>';
    return (string)ob_get_clean();
}

function consumeFlashForToast() {
    if (empty($_SESSION['message'])) {
        return null;
    }
    $payload = [
        'message' => (string)$_SESSION['message'],
        'type' => in_array($_SESSION['message_type'] ?? 'info', ['info', 'success', 'warning', 'danger'], true)
            ? (string)$_SESSION['message_type']
            : 'info',
    ];
    unset($_SESSION['message'], $_SESSION['message_type']);
    return $payload;
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function navLinkClass($filename) {
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $current === $filename ? 'nav-link active' : 'nav-link';
}

function statusProgressionOrder() {
    return ['pending', 'under_review', 'investigating', 'resolved'];
}

function selectableStatusesForUpdate($currentStatus) {
    $currentStatus = (string)$currentStatus;
    if (in_array($currentStatus, ['resolved', 'rejected'], true)) {
        return [$currentStatus];
    }

    $order = statusProgressionOrder();
    $index = array_search($currentStatus, $order, true);
    if ($index === false) {
        $index = array_search('under_review', $order, true);
        if ($index === false) {
            $index = 0;
        }
    }

    $options = array_slice($order, (int)$index);
    if ($currentStatus === 'escalated' && !in_array('under_review', $options, true)) {
        array_unshift($options, 'under_review');
        $options = array_values(array_unique($options));
    }

    if (!in_array('rejected', $options, true)) {
        $options[] = 'rejected';
    }

    return $options;
}

/**
 * @return list<array{id: string, label: string, state: string}>
 */
function complaintProgressSteps(array $complaint) {
    $type = $complaint['complaint_type'] ?? complaintTypeFromCategory($complaint['complaint_category'] ?? '');
    $status = $complaint['status'] ?? 'pending';
    $level = $complaint['current_level'] ?? initialWorkflowLevel($type);

    if ($type === 'services') {
        $steps = [
            ['id' => 'submitted', 'label' => 'Submitted'],
            ['id' => 'oswd', 'label' => 'OSWD Admin'],
            ['id' => 'outcome', 'label' => 'Final Decision'],
        ];
        $levelIndex = 1;
    } else {
        $steps = [
            ['id' => 'submitted', 'label' => 'Submitted'],
            ['id' => 'program_coordinator', 'label' => 'Program Coordinator'],
            ['id' => 'department_chair', 'label' => 'Department Chair'],
            ['id' => 'guidance_office', 'label' => 'Guidance Counselor'],
            ['id' => 'oswd', 'label' => 'OSWD Admin'],
            ['id' => 'outcome', 'label' => 'Final Decision'],
        ];
        $levelMap = [
            'program_coordinator' => 1,
            'department_chair' => 2,
            'guidance_office' => 3,
            'oswd' => 4,
        ];
        $levelIndex = $levelMap[$level] ?? 1;
    }

    if ($status === 'resolved') {
        $activeIndex = count($steps) - 1;
        $steps[$activeIndex]['label'] = 'Resolved';
    } elseif ($status === 'rejected') {
        $activeIndex = count($steps) - 1;
        $steps[$activeIndex]['label'] = 'Rejected';
    } else {
        $activeIndex = $levelIndex;
    }

    foreach ($steps as $i => &$step) {
        if ($i < $activeIndex) {
            $step['state'] = 'done';
        } elseif ($i === $activeIndex) {
            $step['state'] = 'current';
        } else {
            $step['state'] = 'upcoming';
        }
    }
    unset($step);

    return $steps;
}

function renderComplaintProgressSteps(array $complaint, $compact = false) {
    $steps = complaintProgressSteps($complaint);
    $compactClass = $compact ? ' nemsu-progress--compact' : '';

    ob_start();
    echo '<div class="nemsu-progress' . $compactClass . '" role="list" aria-label="Complaint progress">';
    $total = count($steps);
    foreach ($steps as $i => $step) {
        $state = htmlspecialchars($step['state'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8');
        $isLast = $i === $total - 1;
        echo '<div class="nemsu-progress-step nemsu-progress-step--' . $state . '" role="listitem">';
        echo '<div class="nemsu-progress-marker" aria-hidden="true">';
        if ($step['state'] === 'done') {
            echo '<i class="bi bi-check-lg"></i>';
        } else {
            echo (string)($i + 1);
        }
        echo '</div>';
        echo '<span class="nemsu-progress-label">' . $label . '</span>';
        if (!$isLast) {
            echo '<div class="nemsu-progress-line" aria-hidden="true"></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    return (string)ob_get_clean();
}

/**
 * Hide superseded status timeline entries; keep the latest status update only.
 *
 * @param list<array<string, mixed>> $timeline
 * @return list<array<string, mixed>>
 */
function filterTimelineForDisplay(array $timeline) {
    $latestStatusIndex = null;
    foreach ($timeline as $i => $event) {
        if (($event['action_type'] ?? '') === 'status_changed') {
            $latestStatusIndex = $i;
        }
    }

    $filtered = [];
    foreach ($timeline as $i => $event) {
        if (($event['action_type'] ?? '') === 'status_changed' && $latestStatusIndex !== null && $i !== $latestStatusIndex) {
            continue;
        }
        $filtered[] = $event;
    }

    return $filtered;
}

/**
 * " If the next attempt also fails, your account will be locked." (or "... 2
 * more failed attempts ..."), from the attempts left before the lock. Empty
 * when unknown.
 */
function lockWarning($attemptsLeft) {
    if ($attemptsLeft === null || (int)$attemptsLeft <= 0) {
        return '';
    }
    return (int)$attemptsLeft === 1
        ? ' If the next attempt also fails, your account will be locked.'
        : ' Your account will be locked after ' . (int)$attemptsLeft . ' more failed attempts.';
}

/**
 * What the signed-in user can currently see, for live updates without a
 * manual refresh (live_status.php + assets/js/app.js initLiveUpdates).
 * 'sig' changes whenever a complaint in their scope is added, escalated to
 * or away from them, updated or gets a new timeline entry, or they get a
 * notification. Cheap: a few aggregate queries.
 */
function liveStateFor($userId, $role, $focusComplaintId = 0) {
    $db = Database::getInstance()->getConnection();
    $userId = (int)$userId;
    // Same scope as Complaint::getComplaintsForDashboard().
    if ($role === 'student') {
        $scope = 'c.complainant_id = ' . $userId;
    } elseif (in_array($role, ['oswd', 'super_admin'], true)) {
        $scope = '1 = 1';
    } elseif ($role === 'guidance_office') {
        // The whole Guidance office: every complaint at the Guidance level.
        $scope = '(c.current_level = "guidance_office" OR c.assigned_to = ' . $userId . ' OR EXISTS (SELECT 1 FROM complaint_escalations e WHERE e.complaint_id = c.complaint_id AND e.escalated_by = ' . $userId . '))';
    } else {
        // Held now, or escalated onward by them (they keep a view-only copy).
        $scope = '(c.assigned_to = ' . $userId . ' OR EXISTS (SELECT 1 FROM complaint_escalations e WHERE e.complaint_id = c.complaint_id AND e.escalated_by = ' . $userId . '))';
    }
    $complaints = $db->query(
        "SELECT COUNT(*) AS n, MAX(c.updated_at) AS changed, MAX(c.complaint_id) AS newest,
                (SELECT MAX(t.timeline_id) FROM complaint_timeline t
                   INNER JOIN complaints c ON c.complaint_id = t.complaint_id WHERE {$scope}) AS activity,
                (SELECT MAX(m.comment_id) FROM complaint_comments m
                   INNER JOIN complaints c ON c.complaint_id = m.complaint_id WHERE {$scope}) AS comments
         FROM complaints c WHERE {$scope}"
    )->fetch(PDO::FETCH_ASSOC);

    // The complaint open on screen (view_complaint.php), even when it is not
    // in the user's list, e.g. a coordinator looking at one they escalated.
    $focus = null;
    $focusComplaintId = (int)$focusComplaintId;
    if ($focusComplaintId > 0) {
        $complaintObj = new Complaint();
        $complaint = $complaintObj->getComplaintById($focusComplaintId);
        if ($complaint && $complaintObj->canAccessComplaint($complaint, $userId, $role)) {
            $stmt = $db->prepare(
                'SELECT c.status, c.assigned_to, c.updated_at,
                        (SELECT MAX(comment_id) FROM complaint_comments WHERE complaint_id = c.complaint_id) AS comments,
                        (SELECT MAX(timeline_id) FROM complaint_timeline WHERE complaint_id = c.complaint_id) AS activity,
                        (SELECT MAX(CONCAT(proof_id, ":", status)) FROM complaint_resolution_proofs WHERE complaint_id = c.complaint_id) AS proof
                 FROM complaints c WHERE c.complaint_id = :id'
            );
            $stmt->execute([':id' => $focusComplaintId]);
            $focus = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total, SUM(is_read = 0) AS unread, MAX(notification_id) AS newest FROM notifications WHERE user_id = :id'
    );
    $stmt->execute([':id' => $userId]);
    $notes = $stmt->fetch(PDO::FETCH_ASSOC);

    $latest = null;
    if ((int)$notes['unread'] > 0) {
        $stmt = $db->prepare(
            'SELECT notification_id, title FROM notifications WHERE user_id = :id AND is_read = 0 ORDER BY notification_id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'sig' => substr(sha1(json_encode([$complaints, $notes, $focus])), 0, 16),
        'unread' => (int)$notes['unread'],
        'latest_id' => $latest ? (int)$latest['notification_id'] : 0,
        'latest_title' => $latest['title'] ?? '',
    ];
}

/**
 * Default order of every complaint list: Severity 1 (most serious) first,
 * then 2, 3 ...; newest first within the same severity.
 */
function sortComplaintsBySeverity(array &$complaints) {
    usort($complaints, function ($a, $b) {
        return [(int)normalizeSeverity($a['severity'] ?? ''), $b['created_at'] ?? '']
           <=> [(int)normalizeSeverity($b['severity'] ?? ''), $a['created_at'] ?? ''];
    });
}

/** "View only" badge for a complaint the user escalated onward (no longer theirs to act on). */
function viewOnlyBadgeHtml(array $complaint) {
    if (empty($complaint['view_only'])) {
        return '';
    }
    return ' <span class="badge nemsu-badge bg-secondary" title="You escalated this complaint. You can view it but not change it.">'
        . '<i class="bi bi-eye" aria-hidden="true"></i> <span>View only</span></span>';
}

/**
 * Does the name a student typed ("Person involved") refer to this staff
 * member? Case, punctuation and titles (Sir, Ma'am, Prof., Dr. ...) are
 * ignored. A full name matches when all its words are in the staff
 * member's name (or the reverse); a single word matches their first or
 * last name or username. Leaning towards a match is deliberate: a false
 * match only sends the complaint one level higher.
 */
function personNameMatches($typed, $staffFullName, $staffUsername = '') {
    $words = function ($name) {
        $name = strtolower((string)$name);
        $name = preg_replace('/[^a-z0-9\s]+/', ' ', $name);
        $titles = ['sir', 'maam', 'ma', 'am', 'mam', 'madam', 'mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'professor',
                   'engr', 'atty', 'mx', 'jr', 'sr', 'ii', 'iii', 'iv', 'teacher', 'instructor', 'coordinator'];
        return array_values(array_filter(preg_split('/\s+/', trim($name)), fn($w) => $w !== '' && !in_array($w, $titles, true)));
    };
    $typedWords = $words(str_replace(["ma'am", 'ma’am'], ' ', strtolower((string)$typed)));
    $staffWords = $words($staffFullName);
    if (!$typedWords || !$staffWords) {
        return false;
    }
    if (count($typedWords) === 1) {
        $one = $typedWords[0];
        return strlen($one) >= 3 && (in_array($one, [$staffWords[0], end($staffWords)], true)
            || $one === strtolower((string)$staffUsername));
    }
    return !array_diff($typedWords, $staffWords) || !array_diff($staffWords, $typedWords);
}

/**
 * Link to a complaint's uploaded file (evidence or resolution proof). Files
 * are never linked from uploads/ directly: file.php checks that the signed-in
 * user may see that complaint before sending the file.
 */
function complaintFileUrl($complaintId, $fileName) {
    return 'file.php?c=' . (int)$complaintId . '&f=' . rawurlencode(basename((string)$fileName));
}

/**
 * Server-side rate limit. Records one attempt of $action for $key (an email,
 * an IP...) and returns true when more than $max attempts were made within
 * $windowSeconds, in which case the caller must refuse. Keys are stored only
 * as salted hashes. Unlike session counters, clearing cookies does not reset
 * it. If the table is missing (migrate_rate_limits.php not run) it never blocks.
 */
function rateLimitExceeded($action, $key, $max, $windowSeconds) {
    try {
        $db = Database::getInstance()->getConnection();
        $hash = hash('sha256', strtolower(trim((string)$key)) . '|' . LOGIN_HASH_SALT);
        // Purge old rows now and then.
        if (random_int(1, 50) === 1) {
            $db->exec('DELETE FROM security_rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM security_rate_limits
             WHERE action = :a AND key_hash = :k AND created_at >= (NOW() - INTERVAL :w SECOND)'
        );
        $stmt->execute([':a' => $action, ':k' => $hash, ':w' => (int)$windowSeconds]);
        if ((int)$stmt->fetchColumn() >= $max) {
            return true;
        }
        $db->prepare('INSERT INTO security_rate_limits (action, key_hash) VALUES (:a, :k)')
            ->execute([':a' => $action, ':k' => $hash]);
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

function clientIp() {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/**
 * Run a shell command, or return null when the host does not allow it.
 * Shared hosting (e.g. Hostinger) disables shell_exec; on PHP 8 calling a
 * disabled function is a fatal error, so it is never called directly.
 * Callers (the Python models) then use their built-in PHP fallback.
 */
function runShell($command) {
    static $allowed = null;
    if ($allowed === null) {
        $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        $allowed = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
    }
    if (!$allowed) {
        return null;
    }
    $output = @shell_exec($command);
    return is_string($output) ? $output : null;
}

/*
 * Masking for screens where a person's details must be recognisable but not
 * readable in full (e.g. registration, where someone may type another
 * student's ID). Letters are replaced with asterisks.
 */

/** "ABA-A, CARL ANDRIE P." → "A****, C*** A***** P." (first letter of each word kept). */
function maskName($name) {
    return preg_replace_callback('/\p{L}[\p{L}\'-]*/u', function ($m) {
        $word = $m[0];
        $len = mb_strlen($word);
        return $len <= 1 ? $word : mb_substr($word, 0, 1) . str_repeat('*', $len - 1);
    }, trim((string)$name));
}

/** "09171234567" → "09*******67" (first 2 and last 2 digits kept). */
function maskPhone($number) {
    $number = preg_replace('/\s+/', '', (string)$number);
    $len = strlen($number);
    if ($len === 0) {
        return '';
    }
    return $len <= 4 ? str_repeat('*', $len) : substr($number, 0, 2) . str_repeat('*', $len - 4) . substr($number, -2);
}

/** "juan.delacruz@gmail.com" → "j***********@gmail.com". */
function maskEmailAddress($email) {
    $parts = explode('@', (string)$email, 2);
    if (count($parts) !== 2 || $parts[0] === '') {
        return '';
    }
    return mb_substr($parts[0], 0, 1) . str_repeat('*', max(3, mb_strlen($parts[0]) - 1)) . '@' . $parts[1];
}
