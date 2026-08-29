<?php
/**
 * PDO connection, shared across a single request.
 */

require_once __DIR__ . '/config.php';
// Pulled in for date_default_timezone_set(), which must have run before the
// SET time_zone below computes an offset. Web entry points already load
// helpers.php first, but the CLI scripts in tools/ require only this file --
// without this line they would connect with PHP still on its ini default
// (UTC on most builds) and pin the DB session to the wrong offset.
// helpers.php does not require db.php, so this is not circular.
require_once __DIR__ . '/helpers.php';

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Align the database session's clock with PHP's pinned timezone
        // (America/New_York, set in src/helpers.php).
        //
        // WHY THIS MATTERS: this schema mixes two writers of the same kind of
        // value. TIMESTAMP columns defaulting to CURRENT_TIMESTAMP
        // (orders.created_at/updated_at, order_audit_log.changed_at,
        // lockout_events.locked_at, auth_events.occurred_at) are written by
        // MySQL in the DATABASE server's timezone, while users.locked_until is
        // written by PHP via date(). The app then reads both back through
        // strtotime()/date(), which interpret them in PHP's timezone.
        //
        // If the database server is not on America/New_York -- and a stock
        // cloud image is almost always UTC -- every MySQL-written timestamp
        // renders hours off, and any comparison mixing MySQL NOW() with a
        // PHP-written datetime is wrong by that offset. That silently
        // misdates the audit trail, which is the one thing an audit trail
        // may not do.
        //
        // A numeric offset is used rather than a named zone because named
        // zones require the optional MySQL timezone tables to be loaded.
        // Recomputed per connection, so DST transitions are picked up.
        $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        $pdo->exec("SET time_zone = '" . $offset . "'");
    }

    return $pdo;
}
