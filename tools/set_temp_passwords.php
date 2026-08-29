#!/usr/bin/env php
<?php
/**
 * DEV/TEST ONLY: gives every account its own random temp password and forces a
 * change on next login. Run after loading sql/schema.sql + sql/seed.sql.
 *
 * SECURITY (finding H4): this script previously set EVERY account to the
 * hardcoded string 'TempPass123!' -- a value committed to a public git
 * repository. Anyone who ran it (or who reached it over HTTP before the CLI
 * guard above existed) gained the password to every account in the database,
 * including every admin. It now mints a unique CSPRNG password per account and
 * refuses to run unless --i-understand-this-is-not-production is passed.
 *
 * Never run this against a production database. Use
 * tools/bootstrap_admin.php for a real deployment.
 *
 * Usage: php tools/set_temp_passwords.php --i-understand-this-is-not-production
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

if (!in_array('--i-understand-this-is-not-production', $argv, true)) {
    fwrite(STDERR, "Refusing to run without --i-understand-this-is-not-production\n");
    fwrite(STDERR, "This resets the password of EVERY account. Never run it in production.\n");
    exit(1);
}

require_once __DIR__ . '/../src/auth.php';

$pdo = get_db();
$users = $pdo->query('SELECT user_id, username FROM users ORDER BY user_id')->fetchAll();

$update = $pdo->prepare(
    'UPDATE users SET password_hash = ?, must_change_password = 1, failed_login_count = 0, locked_until = NULL
     WHERE user_id = ?'
);

foreach ($users as $user) {
    // Unique per account, CSPRNG-backed -- same generator shape as the
    // admin-facing temp password flows.
    $tempPassword = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(16))), 0, 16);
    $update->execute([
        password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_BCRYPT_COST]),
        $user['user_id'],
    ]);
    printf("%-50s %s\n", $user['username'], $tempPassword);
}

echo "Accounts updated: " . count($users) . "\n";
echo "Each account must change its password at next login.\n";
