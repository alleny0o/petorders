-- ============================================================
-- PETOrders — schema.sql
-- ============================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS order_audit_log;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS lab_product_users;
DROP TABLE IF EXISTS lab_delivery_locations;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS nuclides;
DROP TABLE IF EXISTS customer_registration_requests;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS staff;
DROP TABLE IF EXISTS lockout_events;
DROP TABLE IF EXISTS password_history;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS lab_pis;
DROP TABLE IF EXISTS pis;
DROP TABLE IF EXISTS labs;
DROP TABLE IF EXISTS institutes;


-- ============================================================
-- Identity
-- ============================================================

-- The four tables below (institutes, labs, pis, lab_pis) are
-- provisional reference/lookup tables. Their internal shape may be
-- revised once the final order form design is settled by the other
-- team members working on that piece. The identity layer (users,
-- admins, staff, customers) is final and shouldn't need to change
-- as a result, as long as the FK contract (lab_id -> labs.lab_id,
-- pi_id -> pis.pi_id, etc.) stays intact.

CREATE TABLE institutes (
  institute_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(255) NOT NULL,
  shorthand_name VARCHAR(10),
  active         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_institutes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE labs (
  lab_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institute_id  INT UNSIGNED NOT NULL,
  lab_name      VARCHAR(100) NOT NULL,
  building      VARCHAR(50),
  room          VARCHAR(20),
  active        TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_labs_institute FOREIGN KEY (institute_id) REFERENCES institutes (institute_id),
  KEY idx_labs_institute_id (institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- email is required (unlike phone) and globally unique -- matches
-- institutes.name's plain unique key; not active-scoped (this is a
-- contact field, not a login credential like users.username).
CREATE TABLE pis (
  pi_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pi_name  VARCHAR(100) NOT NULL,
  email    VARCHAR(254) NOT NULL,
  phone    VARCHAR(20),
  active   TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pis_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Join table: a lab can have multiple PIs, a PI can oversee multiple labs.
CREATE TABLE lab_pis (
  lab_id  INT UNSIGNED NOT NULL,
  pi_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (lab_id, pi_id),
  CONSTRAINT fk_lab_pis_lab FOREIGN KEY (lab_id) REFERENCES labs (lab_id) ON DELETE CASCADE,
  CONSTRAINT fk_lab_pis_pi  FOREIGN KEY (pi_id)  REFERENCES pis (pi_id)   ON DELETE CASCADE,
  KEY idx_lab_pis_pi_id (pi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shared login table for all three roles. Role is determined by which
-- of admins/staff/customers a user_id appears in — no role column here.
-- first_name/last_name/phone live here, not duplicated per-role table --
-- every role needs a name, and a phone number is just as plausible for
-- staff/admins as for customers. username is already the NIH email
-- address (see seed.sql's own convention note) — no separate email
-- column is added here; it would just duplicate username.
CREATE TABLE users (
  user_id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username              VARCHAR(50) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  first_name            VARCHAR(100) NOT NULL,
  last_name             VARCHAR(100) NOT NULL,
  phone                 VARCHAR(20) NOT NULL,
  must_change_password  TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- username is unique only among ACTIVE accounts, not globally: a
  -- deactivated account's email becomes reusable by a new account (of
  -- any role) immediately. Neither MySQL 8.0 nor MariaDB 10.11 supports
  -- partial/filtered unique indexes, so this emulates one -- the
  -- generated column is NULL for inactive rows, and both engines allow
  -- unlimited NULLs in a unique index, so inactive rows never collide.
  -- idx_users_username (plain, non-unique) replaces the lookup index
  -- the old UNIQUE key used to provide for attempt_login().
  username_if_active    VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN active = 1 THEN username ELSE NULL END) VIRTUAL,
  KEY idx_users_username (username),
  UNIQUE KEY uq_users_username_active (username_if_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stores the outgoing password hash each time a user changes their
-- password, so change_password.php can block reuse of the last 5
-- passwords (current users.password_hash + the 4 rows kept here).
-- Pruned to the 4 most recent rows per user on every insert — no
-- reason to retain old hashes past what the policy needs.
CREATE TABLE password_history (
  history_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  changed_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_history_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY idx_password_history_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Records a lockout event each time a login attempt pushes
-- failed_login_count past FAILED_LOGIN_LOCKOUT_THRESHOLD and
-- locked_until gets set. Narrower than the Phase F audit log system —
-- just who/when/how many attempts. No admin UI to view these yet.
CREATE TABLE lockout_events (
  lockout_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  failed_attempts  TINYINT UNSIGNED NOT NULL,
  locked_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lockout_events_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY idx_lockout_events_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No category assignment: any staff member can process any order
-- regardless of type (the whole category concept was removed from the
-- system). first_name/last_name/phone live on users now, not duplicated
-- here -- every staff row is also a users row (fk_staff_user below), so
-- name/phone are always reachable through that FK.
CREATE TABLE staff (
  user_id     INT UNSIGNED PRIMARY KEY,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every admin is also staff (admin subset-of staff) — enforced here via FK
-- to staff, not straight to users, so an admin row can't exist without a
-- matching staff row. Name/phone reach here the same two-hop way as
-- everything else about a person: admins.user_id -> staff.user_id ->
-- users.user_id.
CREATE TABLE admins (
  user_id INT UNSIGNED PRIMARY KEY,
  CONSTRAINT fk_admins_user FOREIGN KEY (user_id) REFERENCES staff (user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Institute is NOT stored here — always derived via
-- lab_id -> labs.institute_id, since a lab belongs to exactly one
-- institute and storing it twice risks the two facts disagreeing.
-- Lab/supervising PI are locked at approval time.
-- registration_status predates customer_registration_requests below and
-- isn't used for gating by the current registration flow -- a customers
-- row only ever exists post-approval, so this is always 'approved' in
-- practice. Kept for now; not read by application logic that decides access.
-- first_name/last_name/phone live on users now, not duplicated here --
-- every customer row is also a users row (fk_customers_user below), so
-- name/phone are always reachable through that FK.
CREATE TABLE customers (
  user_id              INT UNSIGNED PRIMARY KEY,
  lab_id               INT UNSIGNED NULL,
  supervising_pi_id    INT UNSIGNED NULL,
  registration_status  ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  CONSTRAINT fk_customers_user         FOREIGN KEY (user_id)           REFERENCES users (user_id)         ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_customers_lab          FOREIGN KEY (lab_id)            REFERENCES labs (lab_id)             ON DELETE SET NULL,
  CONSTRAINT fk_customers_pi           FOREIGN KEY (supervising_pi_id) REFERENCES pis (pi_id)               ON DELETE SET NULL,
  KEY idx_customers_registration_status (registration_status),
  KEY idx_customers_lab_id (lab_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Holds a self-registration submission (Phase C.1) until an admin
-- approves or rejects it (Phase C.2). No users/customers row exists for
-- a request until it's approved — this table is fully separate from the
-- identity tables, not a staging area with FKs into them. lab_id/pi_id
-- reference the dropdowns the registrant picked from; email becomes the
-- eventual username. No DB-level uniqueness on email: MySQL/MariaDB
-- can't express "unique while status='pending'" as a plain index, and a
-- rejected request is allowed to be resubmitted — so duplicate
-- prevention is enforced at the app layer (see register.php).
CREATE TABLE customer_registration_requests (
  request_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lab_id                 INT UNSIGNED NOT NULL,
  pi_id                  INT UNSIGNED NOT NULL,
  first_name             VARCHAR(100) NOT NULL,
  last_name              VARCHAR(100) NOT NULL,
  email                   VARCHAR(254) NOT NULL,
  phone                   VARCHAR(20) NOT NULL,
  status                  ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  rejection_reason        VARCHAR(500) NULL,
  reviewed_by_admin_id    INT UNSIGNED NULL,
  reviewed_at             DATETIME NULL,
  submitted_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reg_requests_lab      FOREIGN KEY (lab_id)               REFERENCES labs (lab_id),
  CONSTRAINT fk_reg_requests_pi       FOREIGN KEY (pi_id)                REFERENCES pis (pi_id),
  CONSTRAINT fk_reg_requests_reviewer FOREIGN KEY (reviewed_by_admin_id) REFERENCES users (user_id) ON DELETE SET NULL,
  KEY idx_reg_requests_status (status),
  KEY idx_reg_requests_email (email),
  KEY idx_reg_requests_lab_id (lab_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- Catalog
-- ============================================================

CREATE TABLE nuclides (
  nuclide_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(50) NOT NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_nuclides_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flat catalog table — replaces the prior multi-table variant/SKU
-- catalog model entirely. Each row is one orderable product: a
-- nuclide + name + a single fixed delivery_method. Delivery
-- method is never chosen per-order — it's a property of the product row.
-- A product offered via more than one method (e.g. a product available
-- both picked up and radiopharmacy-delivered) is represented as two
-- separate rows sharing the same name+nuclide, not one row with a list
-- of methods; uq_products_name_nuclide_delivery blocks an exact
-- duplicate of one of those rows while still allowing that second
-- variant. No cost/pricing column and no sku/description columns — this
-- project does not track cost, and nothing else needs a free-text
-- description or a separate SKU string beyond the product name itself.
-- delivery_method should be treated as immutable once any order
-- references this product row: change delivery method by creating a new
-- product row (and deactivating the old one) rather than editing
-- delivery_method in place, so historical orders don't silently get
-- rewritten out from under their own audit trail.
CREATE TABLE products (
  product_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nuclide_id       INT UNSIGNED NOT NULL,
  name             VARCHAR(150) NOT NULL,
  delivery_method  ENUM('radiopharmacy', 'pick_up', 'direct_delivery') NOT NULL,
  active           TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_products_nuclide  FOREIGN KEY (nuclide_id)  REFERENCES nuclides (nuclide_id),
  UNIQUE KEY uq_products_name_nuclide_delivery (name, nuclide_id, delivery_method),
  KEY idx_products_nuclide_id (nuclide_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- Orders
-- ============================================================

-- Lab-scoped, not per-customer -- multiple customers in the same lab
-- share the same delivery locations. Soft-delete via active so a
-- historical order's location_id reference survives even after the
-- location is later deactivated.
CREATE TABLE lab_delivery_locations (
  location_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lab_id       INT UNSIGNED NOT NULL,
  name         VARCHAR(100) NOT NULL,
  room         VARCHAR(20),
  active       TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_lab_delivery_locations_lab FOREIGN KEY (lab_id) REFERENCES labs (lab_id),
  KEY idx_lab_delivery_locations_lab_id (lab_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Represents the actual person who will receive/use the dose, who may
-- not be the ordering customer (e.g. Jane Doe orders on behalf of John
-- Doe, a lab member who isn't a registered system user and has no row in
-- customers). Lab-scoped for the same reason as lab_delivery_locations
-- above. Soft-delete via active so a historical order's product_user_id
-- reference survives deactivation. first_name/last_name (not a single
-- combined name) matches the users/customers convention. email is
-- collected by the CRUD UI as an optional field (column stays nullable)
-- and carries a per-lab uniqueness constraint (not global: two different
-- labs may each have a product user sharing an email; the same lab may
-- not). MySQL/MariaDB composite unique indexes treat each NULL as
-- distinct, so multiple rows in the same lab with no email on file don't
-- collide with each other, same as the NULL-tolerant uniqueness already
-- relied on elsewhere in this schema.
CREATE TABLE lab_product_users (
  product_user_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lab_id           INT UNSIGNED NOT NULL,
  first_name       VARCHAR(100) NOT NULL,
  last_name        VARCHAR(100) NOT NULL,
  email            VARCHAR(254) NULL,
  active           TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_lab_product_users_lab FOREIGN KEY (lab_id) REFERENCES labs (lab_id),
  KEY idx_lab_product_users_lab_id (lab_id),
  UNIQUE KEY uq_lab_product_users_lab_email (lab_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One unified form for every order type -- no Type A/B split, no
-- separate per-type detail tables. Cyclotron-run specifics (beam
-- current, bombardment time, EOB activity, destination) are typed into
-- notes like any other order's notes, never given dedicated columns.
-- notes is the single shared, overwritable communication channel on an
-- order -- last-write-wins, no history or threading; staff/admin can
-- always edit it, a customer only on their own order. status has no
-- 'returned' value: a return is a transition back to 'pending', recorded
-- as a normal row in order_audit_log, not a status of its own. order_id
-- is a plain AUTO_INCREMENT: MySQL/MariaDB never reuses an
-- AUTO_INCREMENT value even after the owning row's status becomes
-- 'cancelled' (and this app never DELETEs orders), satisfying
-- "sequential, never reused" with no extra bookkeeping. product_user_id
-- is nullable -- NULL means the ordering customer is the recipient; a
-- row is only required when someone else is. delivery method is no
-- longer chosen per-order -- it's derived from the selected product's
-- delivery_method. location_id is nullable at the DB level -- most
-- delivery methods legitimately have no location -- but the app layer
-- treats it as required whenever the order's product has
-- delivery_method = 'direct_delivery'. That conditional requirement is
-- enforced in new_order.php's validation, not as a DB constraint -- a
-- plain NOT NULL can't be made conditional on another column's value
-- without a trigger, which is more complexity than this needs. There is
-- no cost column of any kind here -- this project does not track cost.
-- cancellation_reason is nullable at the DB level for the same reason as
-- location_id above: it's only conditionally required (whenever a
-- transition sets status to 'cancelled', from either the customer or
-- staff cancel path), which transition_order_status() (src/helpers.php)
-- enforces at the app layer rather than as a DB constraint. It's
-- structured data tied specifically to the cancel event -- distinct from
-- the general-purpose notes field above. chargeable is unrelated to the
-- order lifecycle: staff-only editable, freely toggleable regardless of
-- status, and never written to order_audit_log since toggling it is not
-- a status transition. It defaults to 1 (confirmed flip from the original
-- 0 default): a new order is chargeable unless staff say otherwise, so
-- "not chargeable" is the exceptional case the UI highlights.
CREATE TABLE orders (
  order_id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id          INT UNSIGNED NOT NULL,
  product_id           INT UNSIGNED NOT NULL,
  location_id          INT UNSIGNED NULL,
  product_user_id      INT UNSIGNED NULL,
  activity_mci         DECIMAL(8,3) NOT NULL,
  requested_datetime   DATETIME NOT NULL,
  notes                TEXT NULL,
  status               ENUM('pending', 'accepted', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  cancellation_reason  VARCHAR(500) NULL,
  chargeable           TINYINT(1) NOT NULL DEFAULT 1,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_customer        FOREIGN KEY (customer_id)        REFERENCES customers (user_id),
  CONSTRAINT fk_orders_product         FOREIGN KEY (product_id)         REFERENCES products (product_id),
  CONSTRAINT fk_orders_location        FOREIGN KEY (location_id)        REFERENCES lab_delivery_locations (location_id),
  CONSTRAINT fk_orders_product_user    FOREIGN KEY (product_user_id)    REFERENCES lab_product_users (product_user_id),
  KEY idx_orders_customer_id (customer_id),
  KEY idx_orders_product_id (product_id),
  KEY idx_orders_location_id (location_id),
  KEY idx_orders_product_user_id (product_user_id),
  KEY idx_orders_status (status),
  KEY idx_orders_requested_datetime (requested_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Status-only, not field-level diffing -- just status_from, status_to,
-- who, and when. Every order creation and every status transition writes
-- a row here, including the creation event itself (status_from NULL,
-- status_to 'pending'), atomically alongside the status change (an
-- app-layer responsibility). status_from is nullable specifically for
-- that creation-event row -- every other row has both endpoints
-- populated.
CREATE TABLE order_audit_log (
  audit_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id           INT UNSIGNED NOT NULL,
  status_from        ENUM('pending', 'accepted', 'completed', 'cancelled') NULL,
  status_to          ENUM('pending', 'accepted', 'completed', 'cancelled') NOT NULL,
  changed_by_user_id INT UNSIGNED NOT NULL,
  changed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_audit_log_order      FOREIGN KEY (order_id)           REFERENCES orders (order_id) ON DELETE CASCADE,
  CONSTRAINT fk_order_audit_log_changed_by FOREIGN KEY (changed_by_user_id) REFERENCES users (user_id),
  KEY idx_order_audit_log_order_id (order_id),
  KEY idx_order_audit_log_changed_by_user_id (changed_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
