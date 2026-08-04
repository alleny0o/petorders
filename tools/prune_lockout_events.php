#!/usr/bin/env php
<?php
/**
 * Retention prune for lockout_events: deletes rows older than 90 days.
 *
 * The admin dashboard only ever reads a 7-day window of this table, but
 * nothing else removes rows and growth is attacker-controlled (one row
 * per lockout, so a credential-stuffing run against login.php grows it
 * at whatever rate the attacker chooses). Safe to rerun any time; run
 * monthly via cron on RHEL or manually (see docs/DEPLOYMENT.md).
 *
 * Usage: php tools/prune_lockout_events.php
 */

require __DIR__ . '/../src/db.php';

$stmt = get_db()->prepare('DELETE FROM lockout_events WHERE locked_at < NOW() - INTERVAL 90 DAY');
$stmt->execute();

echo "Lockout events deleted (older than 90 days): {$stmt->rowCount()}\n";
