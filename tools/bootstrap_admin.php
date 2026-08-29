#!/usr/bin/env php
<?php
/**
 * One-time production/launch bootstrap: creates exactly one real admin
 * account in an otherwise-empty database. Refuses to run if `users`
 * already has any rows -- this is NOT for local/dev use and does not
 * touch or depend on sql/seed.sql; use that + tools/set_temp_passwords.php
 * for dev/test data instead.
 *
 * Usage: php tools/bootstrap_admin.php <username> <first_name> <last_name> <phone>
 *   <username> must be a valid email address (matches the app-wide
 *   username-is-email convention).
 *   <phone> must contain only digits, spaces, dashes, parentheses, and an
 *   optional leading +, 20 characters or fewer (matches users.phone).
 *
 * Run once after loading sql/schema.sql alone, before pointing a real
 * deployment at the database.
 */

// SECURITY (finding H4): CLI-only guard. These maintenance scripts live outside
// the document root by design (docs/DEPLOYMENT.md step 5 sets DocumentRoot to
// public/), but that is a deployment convention, not an enforced boundary -- a
// single AllowOverride or vhost mistake would expose them to unauthenticated
// HTTP requests. This makes the boundary a property of the code instead.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../src/db.php';
// PASSWORD_BCRYPT_COST lives in auth.php alongside the rest of the password policy.
require_once __DIR__ . '/../src/auth.php';

/**
 * Deliberately duplicated per-file, not shared into src/helpers.php --
 * same shape as accounts.php / registrations.php / customer_detail.php /
 * account_detail.php's copies.
 */
function generate_temp_password(): string
{
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(16))), 0, 16);
}

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php tools/bootstrap_admin.php <username> <first_name> <last_name> <phone>\n");
    exit(1);
}

[, $username, $firstName, $lastName, $phone] = $argv;

if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: username must be a valid email address.\n");
    exit(1);
}

if ($firstName === '' || $lastName === '') {
    fwrite(STDERR, "Error: first_name and last_name are required.\n");
    exit(1);
}

if ($phone === '' || !preg_match('/^[0-9()+.\-\s]+$/', $phone) || !preg_match('/[0-9]/', $phone)) {
    fwrite(STDERR, "Error: phone must contain only digits, spaces, dashes, parentheses, and an optional leading +.\n");
    exit(1);
}
if (mb_strlen($phone) > 20) {
    fwrite(STDERR, "Error: phone must be 20 characters or fewer.\n");
    exit(1);
}

$pdo = get_db();

$pdo->beginTransaction();
try {
    // Broadest possible guard: abort if the database has ANY accounts at
    // all, dev-seeded or real -- this script is only for a truly empty
    // database. FOR UPDATE closes the race with a concurrent run.
    $existing = (int) $pdo->query('SELECT COUNT(*) FROM users FOR UPDATE')->fetchColumn();
    if ($existing > 0) {
        $pdo->rollBack();
        fwrite(STDERR, "Error: users table already has {$existing} row(s). Refusing to run against a non-empty database.\n");
        exit(1);
    }

    $tempPassword = generate_temp_password();
    $tempHash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_BCRYPT_COST]);

    $pdo->prepare(
        'INSERT INTO users (username, password_hash, first_name, last_name, phone, must_change_password, active) VALUES (?, ?, ?, ?, ?, 1, 1)'
    )->execute([$username, $tempHash, $firstName, $lastName, $phone]);
    $newUserId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO staff (user_id) VALUES (?)')->execute([$newUserId]);
    $pdo->prepare('INSERT INTO admins (user_id) VALUES (?)')->execute([$newUserId]);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: could not create the admin account: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Admin account created.\n";
echo "Username: {$username}\n";
echo "Temp password: {$tempPassword}\n";
echo "The account must change this password on first login.\n";
