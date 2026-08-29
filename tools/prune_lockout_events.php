#!/usr/bin/env php
<?php
/**
 * Retention prune for the three append-only security tables: lockout_events,
 * request_throttle and auth_events.
 *
 * The admin dashboard only ever reads a 7-day window of this table, but
 * nothing else removes rows and growth is attacker-controlled (one row
 * per lockout, so a credential-stuffing run against login.php grows it
 * at whatever rate the attacker chooses). Safe to rerun any time; run
 * monthly via cron on RHEL or manually (see docs/DEPLOYMENT.md).
 *
 * Usage: php tools/prune_lockout_events.php
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

$pdo = get_db();

$lockouts = $pdo->prepare('DELETE FROM lockout_events WHERE locked_at < NOW() - INTERVAL 90 DAY');
$lockouts->execute();
echo "Lockout events deleted (older than 90 days): {$lockouts->rowCount()}\n";

// request_throttle is attacker-controlled in exactly the same way (one row per
// source IP per action), and rows are dead once their window and block have
// both lapsed. Nothing reads them after that.
$throttle = $pdo->prepare(
    'DELETE FROM request_throttle
      WHERE window_started_at < NOW() - INTERVAL 1 DAY
        AND (blocked_until IS NULL OR blocked_until < NOW())'
);
$throttle->execute();
echo "Stale throttle rows deleted: {$throttle->rowCount()}\n";

// auth_events is the authentication audit trail, so its retention is a policy
// decision, not a cleanup detail: 400 days keeps a full year of history plus
// margin. Confirm this against NIH records-retention requirements before
// changing it -- shortening it destroys audit evidence.
$authEvents = $pdo->prepare('DELETE FROM auth_events WHERE occurred_at < NOW() - INTERVAL 400 DAY');
$authEvents->execute();
echo "Auth events deleted (older than 400 days): {$authEvents->rowCount()}\n";
