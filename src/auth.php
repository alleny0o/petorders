<?php
/**
 * Login, session guard, logout. Assumes session_start() has already
 * been called by the page (see CLAUDE.md page template).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const SESSION_IDLE_LIMIT_SECONDS = 15 * 60;

// Lockout thresholds and durations.
//
// SECURITY (finding H1): the second tier was previously a 365-day lock, which
// let anyone who could guess a username permanently disable that account with
// 10 unauthenticated POSTs -- and there is no way back into the app if the
// account locked is the last admin. Both tiers are now bounded in hours, so a
// lockout always self-heals. Brute-force resistance comes from the tiers plus
// the per-IP throttle in login.php, not from the lock being effectively
// permanent. Do not raise EXTENDED_LOCKOUT_SECONDS into days without also
// providing an out-of-band unlock path.
const FAILED_LOGIN_LOCKOUT_THRESHOLD = 5;
const LOCKOUT_DURATION_SECONDS = 15 * 60;
const FAILED_FULL_LOGIN_LOCKOUT_THRESHOLD = 10;
const EXTENDED_LOCKOUT_SECONDS = 60 * 60; // 1 hour (was 1 year)

// bcrypt work factor. PHP's default is 10; 12 is the current common baseline
// and costs a few tens of milliseconds per verify, which is irrelevant at this
// app's login volume. Existing hashes keep working -- password_verify() reads
// the cost from the stored hash -- and are upgraded on next password change.
const PASSWORD_BCRYPT_COST = 12;

/**
 * Appends an authentication event to auth_events (sql/schema.sql).
 *
 * The app previously recorded only lockouts, so there was no way to answer
 * "who logged in, from where, and when" after an incident -- successful
 * access left no trace at all. $userId is nullable: a failed login against an
 * unknown username has no user to attribute.
 *
 * Never throws into the caller: an audit-write failure must not block a
 * legitimate login, so it is logged and swallowed.
 */
function record_auth_event(PDO $pdo, string $eventType, ?int $userId, string $username): void
{
    try {
        $pdo->prepare(
            'INSERT INTO auth_events (user_id, username_attempted, event_type, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            safe_truncate_bytes($username, 50),
            $eventType,
            client_ip(),
            safe_truncate_bytes((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255),
        ]);
    } catch (Throwable $e) {
        error_log('[AUDIT] failed to record auth event ' . $eventType . ': ' . $e->getMessage());
    }
}

function lockout_message(string $lockedUntil): string
{
    $minutesLeft = (int) ceil((strtotime($lockedUntil) - time()) / 60);
    $unit = $minutesLeft === 1 ? 'minute' : 'minutes';
    return "Account temporarily locked. Try again in {$minutesLeft} {$unit}.";
}

function attempt_login(string $username, string $password): array
{
    $pdo = get_db();

    // active = 1: username is only unique among active rows
    // (uq_users_username_active). Without the filter, a deactivated
    // account sharing a since-freed username could be the row fetched
    // here -- verifying against the wrong hash and pointing the
    // failed-count/lockout writes below at the wrong user_id.
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        record_auth_event($pdo, 'login_failed_unknown_user', null, $username);
        return ['success' => false, 'reason' => 'Invalid username or password.'];
    }

    if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
        error_log('Login attempt on locked account: username=' . $username);
        record_auth_event($pdo, 'login_blocked_locked', (int) $user['user_id'], $username);
        return ['success' => false, 'reason' => lockout_message($user['locked_until'])];
    }

    // An expired lock resets the counter. Without this, an account that served
    // its lock sat permanently at the threshold, so the very next wrong
    // password re-locked it immediately -- the same practical outcome as the
    // permanent lock removed above.
    if ($user['locked_until'] !== null && strtotime($user['locked_until']) <= time()) {
        $pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE user_id = ?')
            ->execute([$user['user_id']]);
        $user['failed_login_count'] = 0;
    }

    // Case: password is incorrect.
    if (!password_verify($password, $user['password_hash'])) {
        $failedCount = $user['failed_login_count'] + 1;
        $lockedUntil = null;
        // Check if the number of failed login attempts have exceeded the threshold. If so, locked their accounts.
        if($failedCount >= FAILED_FULL_LOGIN_LOCKOUT_THRESHOLD) {
            $lockedUntil = date('Y-m-d H:i:s', time() + EXTENDED_LOCKOUT_SECONDS);
        } else if ($failedCount >= FAILED_LOGIN_LOCKOUT_THRESHOLD) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION_SECONDS);
        } 

        // Update the failed login count
        $pdo->prepare('UPDATE users SET failed_login_count = ?, locked_until = ? WHERE user_id = ?')
            ->execute([$failedCount, $lockedUntil, $user['user_id']]);

        // Log the lockout in the lockout_events table
        if ($lockedUntil !== null) {
            $pdo->prepare('INSERT INTO lockout_events (user_id, failed_attempts) VALUES (?, ?)')
                ->execute([$user['user_id'], $failedCount]);
            record_auth_event($pdo, 'account_locked', (int) $user['user_id'], $username);
            return ['success' => false, 'reason' => lockout_message($lockedUntil)];
        }

        record_auth_event($pdo, 'login_failed_bad_password', (int) $user['user_id'], $username);
        return ['success' => false, 'reason' => 'Invalid username or password.'];
    }

    $pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE user_id = ?')
        ->execute([$user['user_id']]);

    $role = determine_role($pdo, (int) $user['user_id']);
    if ($role === null) {
        record_auth_event($pdo, 'login_failed_no_role', (int) $user['user_id'], $username);
        return ['success' => false, 'reason' => 'Account has no assigned role.'];
    }

    session_regenerate_id(true);
    // Drop the pre-login CSRF token along with the old session id so the
    // authenticated session starts with a fresh one (csrf_token() mints it
    // on the next page render).
    unset($_SESSION['csrf_token']);

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $role;
    $_SESSION['must_change_password'] = (bool) $user['must_change_password'];
    $_SESSION['last_activity'] = time();
    // Anchors the absolute session lifetime enforced in require_role().
    $_SESSION['session_started'] = time();

    record_auth_event($pdo, 'login_success', (int) $user['user_id'], $username);

    return ['success' => true, 'reason' => null];
}

/**
 * Whether a session role satisfies a page's role requirement.
 *
 * admin ⊆ staff is a hard DB-level invariant (admins.user_id -> staff.user_id
 * FK) — every admin also has a staff row, so an admin session must satisfy
 * staff-only pages. This is one-directional: staff does NOT satisfy
 * admin-only pages.
 */
function role_satisfies(string $sessionRole, string $requiredRole): bool
{
    if ($sessionRole === $requiredRole) {
        return true;
    }

    return $sessionRole === 'admin' && $requiredRole === 'staff';
}

/**
 * @param string|string[] $allowedRoles One role, or several (e.g. a page
 *                                      reachable by every role such as
 *                                      change_password.php).
 */
function require_role($allowedRoles): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        redirect('/login.php');
    }

    $stmt = get_db()->prepare('SELECT active, username FROM users WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
    if (!$currentUser || !$currentUser['active']) {
        session_unset();
        session_destroy();
        redirect('/login.php');
    }
    $_SESSION['username'] = $currentUser['username'];

    // Re-derive role from table membership every request (mirrors the
    // active/username re-check above) so a promote/demote takes effect
    // on the target's next request instead of at next login.
    $currentRole = determine_role(get_db(), (int) $_SESSION['user_id']);
    if ($currentRole === null) {
        session_unset();
        session_destroy();
        redirect('/login.php');
    }
    $_SESSION['role'] = $currentRole;

    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_LIMIT_SECONDS) {
        session_unset();
        session_destroy();
        redirect('/login.php');
    }

    // Absolute cap, independent of activity: a session cookie that keeps being
    // used still dies at SESSION_ABSOLUTE_LIMIT_SECONDS. Sessions predating
    // this change have no session_started key and are treated as expired.
    if ((time() - (int) ($_SESSION['session_started'] ?? 0)) > SESSION_ABSOLUTE_LIMIT_SECONDS) {
        session_unset();
        session_destroy();
        redirect('/login.php');
    }

    $satisfiesRequirement = false;
    foreach ((array) $allowedRoles as $required) {
        if (role_satisfies($_SESSION['role'], $required)) {
            $satisfiesRequirement = true;
            break;
        }
    }

    if (!$satisfiesRequirement) {
        redirect(dashboard_path_for_role($_SESSION['role']));
    }

    $currentPage = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($_SESSION['must_change_password']) && $currentPage !== 'change_password.php') {
        redirect('/change_password.php');
    }

    // Security headers are sent for EVERY request (authenticated or not) by
    // send_security_headers(), called from bootstrap_session() in
    // src/helpers.php. They used to be set here, which silently excluded
    // login.php, register.php, registration_status.php, 404.php and
    // index.php -- see finding M1. Do not re-add them here.

    $_SESSION['last_activity'] = time();
}

function logout(): void
{
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    redirect('/login.php');
}

const PASSWORD_MIN_LENGTH = 12;
const PASSWORD_MAX_LENGTH = 72; // bcrypt hashes only the first 72 bytes; longer input is silently truncated
const PASSWORD_HISTORY_LIMIT = 4; // plus the current users.password_hash = last 5 checked/kept

/**
 * @return string[] Validation error messages; empty array means the
 *                   password satisfies the strength policy.
 */
function validate_password_strength(string $password, string $username): array
{
    $errors = [];

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }

    // strlen (bytes), matching the min check above: bcrypt's limit is
    // 72 bytes, so a byte count is the honest measure here.
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        $errors[] = 'Password must be ' . PASSWORD_MAX_LENGTH . ' characters or fewer.';
    }

    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must include at least one letter and one number.';
    }

    if ($username !== '' && stripos($password, $username) !== false) {
        $errors[] = 'Password must not contain your username or email.';
    }

    return $errors;
}

/**
 * Checks the new password against the account's current password plus
 * its last PASSWORD_HISTORY_LIMIT prior passwords.
 */
function is_password_reused(PDO $pdo, int $userId, string $currentHash, string $newPassword): bool
{
    if (password_verify($newPassword, $currentHash)) {
        return true;
    }

    $stmt = $pdo->prepare(
        'SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY history_id DESC LIMIT ' . PASSWORD_HISTORY_LIMIT
    );
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldHash) {
        if (password_verify($newPassword, $oldHash)) {
            return true;
        }
    }

    return false;
}

/**
 * Archives the hash being replaced, then prunes password_history down
 * to the PASSWORD_HISTORY_LIMIT most recent rows for that user.
 */
function record_password_history(PDO $pdo, int $userId, string $outgoingHash): void
{
    $pdo->prepare('INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)')
        ->execute([$userId, $outgoingHash]);

    $pdo->prepare(
        'DELETE FROM password_history WHERE user_id = ? AND history_id NOT IN (
            SELECT history_id FROM (
                SELECT history_id FROM password_history WHERE user_id = ? ORDER BY history_id DESC LIMIT ' . PASSWORD_HISTORY_LIMIT . '
            ) AS keep_rows
        )'
    )->execute([$userId, $userId]);
}

function determine_role(PDO $pdo, int $userId): ?string
{
    $roleTables = ['admin' => 'admins', 'staff' => 'staff', 'customer' => 'customers'];

    foreach ($roleTables as $role => $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return $role;
        }
    }

    return null;
}

function dashboard_path_for_role(string $role): string
{
    switch ($role) {
        case 'admin':
            return '/admin/dashboard.php';
        case 'staff':
            return '/staff/dashboard.php';
        case 'customer':
            return '/customer/dashboard.php';
        default:
            return '/login.php';
    }
}
