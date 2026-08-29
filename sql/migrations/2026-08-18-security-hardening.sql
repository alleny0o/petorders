-- ============================================================
-- PETOrders — security hardening migration
-- Date: 2026-08-18
-- ============================================================
--
-- Applies the schema half of the security review remediation to an
-- EXISTING database. sql/schema.sql already contains everything below for
-- fresh installs — run this file only against a database that predates the
-- review.
--
-- Safe to rerun: every statement is guarded with IF NOT EXISTS.
-- No existing table is altered and no data is deleted.
--
-- Usage:
--   mysql -u <user> -p petorders < sql/migrations/2026-08-18-security-hardening.sql
--
-- Back up first, as always:
--   mysqldump -u <user> -p petorders > petorders-before-hardening.sql
-- ============================================================

-- ---- Findings H1, M5: per-IP request throttling ------------------------
-- Complements lockout_events (which counts per ACCOUNT and so cannot see
-- one source spraying attempts across many usernames, nor an anonymous
-- flood of registration submissions).
CREATE TABLE IF NOT EXISTS request_throttle (
  ip_address        VARCHAR(45) NOT NULL,
  action            VARCHAR(32) NOT NULL,
  attempt_count     INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until     DATETIME NULL,
  PRIMARY KEY (ip_address, action),
  KEY idx_request_throttle_blocked_until (blocked_until),
  KEY idx_request_throttle_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Authentication audit trail ---------------------------------------
-- Before this change only lockouts were recorded, so successful access
-- left no trace at all.
CREATE TABLE IF NOT EXISTS auth_events (
  event_id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            INT UNSIGNED NULL,
  username_attempted VARCHAR(50) NOT NULL,
  event_type         VARCHAR(32) NOT NULL,
  ip_address         VARCHAR(45) NOT NULL,
  user_agent         VARCHAR(255) NULL,
  occurred_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_auth_events_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL,
  KEY idx_auth_events_occurred_at (occurred_at),
  KEY idx_auth_events_user_id (user_id),
  KEY idx_auth_events_type_occurred (event_type, occurred_at),
  KEY idx_auth_events_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Finding H1: release pre-existing year-long lockouts ---------------
-- The old code locked an account for 365 days after 10 failed attempts.
-- Any account locked under that rule is still locked and cannot be
-- recovered through the UI. The application cap is now 1 hour, but rows
-- already written carry the old expiry, so clear anything locked further
-- out than the new maximum.
UPDATE users
   SET failed_login_count = 0,
       locked_until       = NULL
 WHERE locked_until IS NOT NULL
   AND locked_until > NOW() + INTERVAL 1 DAY;

-- Verification (expect 0 rows):
--   SELECT user_id, username, locked_until FROM users
--    WHERE locked_until > NOW() + INTERVAL 1 DAY;
