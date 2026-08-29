# PETOrders Local Development Setup

Audience: a developer picking up PETOrders for maintenance or new work.
Gets you from a clean machine to a running app with a fully seeded test
database.

Reference dev environment is **MAMP on macOS** (what the app was built
against), but any Apache + PHP 8.2/8.3 + MariaDB stack works. No
Composer, no npm, no build step. Clone it, configure it, load the
database, done.

---

## Contents

1. [Prerequisites](#1-prerequisites)
2. [Clone and create the database](#2-clone-and-create-the-database)
3. [Configure the app](#3-configure-the-app)
4. [Set passwords for the seeded accounts](#4-set-passwords-for-the-seeded-accounts)
5. [Point Apache at public/ and log in](#5-point-apache-at-public-and-log-in)
6. [seed.sql vs. bootstrap_admin.php: don't mix them up](#6-seedsql-vs-bootstrap_adminphp-dont-mix-them-up)
7. [Development notes](#7-development-notes)

---

## 1. Prerequisites

- Apache + PHP 8.2 or 8.3 + MySQL (MAMP ships all three)
- Git

## 2. Clone and create the database

```bash
git clone <your-git-remote>/petorders.git
cd petorders
```

Create a database named `petorders`, then load the schema and seed data,
in that order:

```bash
# MAMP's MySQL listens on port 8889 (default user/pass: root/root)
/Applications/MAMP/Library/bin/mysql -u root -proot --port=8889 -e "CREATE DATABASE petorders CHARACTER SET utf8mb4"
/Applications/MAMP/Library/bin/mysql -u root -proot --port=8889 petorders < sql/schema.sql
/Applications/MAMP/Library/bin/mysql -u root -proot --port=8889 petorders < sql/seed.sql
```

**Existing dev database?** Schema changes only land in `sql/schema.sql`;
reloading it doesn't alter a database you already created. Either drop
and reload (losing local data), or apply the change by hand. The
pending index migrations (most recently from PR #108) and how to check
whether your local DB needs them are documented in full in
[DEPLOYMENT.md](DEPLOYMENT.md#3-create-the-database).

`sql/seed.sql` gives every screen something on it:

| Data                                                                                     | Count |
| ---------------------------------------------------------------------------------------- | ----- |
| Institutes                                                                               | 27    |
| Labs                                                                                     | 8     |
| PIs (with lab↔PI pairings)                                                               | 6     |
| Accounts (5 admin, 5 staff, 30 customers)                                                | 40    |
| Nuclides                                                                                 | 5     |
| Products (one seeded under two fulfillment methods, to exercise the dual-row convention) | 10    |
| Delivery locations (one per lab)                                                         | 8     |
| Product users                                                                            | 5     |
| Orders (all four statuses, mix of relative-to-today and fixed dates)                     | 50    |

## 3. Configure the app

```bash
cp src/config.sample.php src/config.php
```

Edit `src/config.php` with MAMP values:

| Constant                 | MAMP value                                                                                                                       |
| ------------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| `DB_HOST`                | `127.0.0.1`                                                                                                                      |
| `DB_PORT`                | `8889`                                                                                                                           |
| `DB_NAME`                | `petorders`                                                                                                                      |
| `DB_USER`                | `root`                                                                                                                           |
| `DB_PASS`                | `root`                                                                                                                           |
| `REQUIRE_SECURE_COOKIES` | `false`. Local dev runs plain HTTP; setting this `true` without HTTPS makes login silently fail (session cookie never gets sent) |

`src/config.php` is gitignored. Your local credentials never leave your
machine.

## 4. Set passwords for the seeded accounts

The seed file ships placeholder password hashes on purpose. No seeded
account can log in until you run:

```bash
php tools/set_temp_passwords.php
```

Sets every account's password to `TempPass123!` and forces a password
change on first login:

```
Temp password for all accounts: TempPass123!
Rows updated: 40
```

Safe to re-run any time you want to reset all dev accounts (e.g. after
testing password changes or lockouts).

## 5. Point Apache at public/ and log in

Set the document root to the `public/` folder, not the project root. In
MAMP: Preferences → Server → Document Root → `petorders/public`. Only
`public/` is designed to be web-reachable. `src/`, `sql/`, `tools/`, and
`config/` must stay outside the document root. Production has the same
rule, see
[DEPLOYMENT.md](DEPLOYMENT.md#5-configure-apache-document-root-must-be-public).

Open `http://localhost:8888/login.php`. Sign in as any seeded account,
password `TempPass123!` (you'll be prompted to set a real one, 12+
characters with a letter and a number). All 40 accounts share that
password; here's a representative sample (full counts above):

| Username                                     | Role                                    |
| --------------------------------------------- | ---------------------------------------- |
| `puddle.fizzlewick1@example-test.local`      | admin                                   |
| `rumble.sprocketfield6@example-test.local`   | staff                                   |
| `rumble.rattlebridge7@example-test.local`    | staff                                   |
| `zephyr.squigglesby11@example-test.local`    | customer (Neuroimaging Lab)             |
| `pretzel.muddlecombe12@example-test.local`   | customer (Cardiac Imaging Lab)          |
| `waffle.puddlejump13@example-test.local`     | customer (Musculoskeletal Imaging Lab)  |
| `quibble.dazzlebrook14@example-test.local`   | customer (Metabolic Imaging Lab)        |

## 6. seed.sql vs. bootstrap_admin.php: don't mix them up

Two very different setup paths share the `tools/` folder:

|                   | Dev sandbox (this guide)                                                   | Production launch                                                                                               |
| ----------------- | -------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Database contents | `schema.sql` + `seed.sql`                                                  | `schema.sql` only                                                                                               |
| Accounts          | 40 seeded accounts                                                         | exactly 1 real admin                                                                                            |
| Tool              | `tools/set_temp_passwords.php`, bulk-resets all accounts to `TempPass123!` | `tools/bootstrap_admin.php <email> <first> <last> <phone>`, creates the single admin, prints a random one-time password |
| Guard             | none (re-runnable)                                                         | refuses to run if `users` has any rows                                                                          |

`bootstrap_admin.php` is documented in full in
[DEPLOYMENT.md](DEPLOYMENT.md#8-create-the-first-admin-account). You'll
normally never run it locally, and never run `set_temp_passwords.php` in
production.

A third script, `tools/generate_stress_test.php`, bulk-inserts
thousands of synthetic orders (the count is a variable at the top of
the script) for exercising the dashboards and paginated lists against
real volume. Dev-only, like `set_temp_passwords.php`: it
writes into whatever database `src/config.php` points at, so never run
it against anything but your local dev database.

## 7. Development notes

| Topic                 | Detail                                                                                                                                                                                     |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Workflow              | Branch → PR → merge. `main` is branch-protected, never push to it directly                                                                                                                 |
| Verification policy   | `php -l`, static code review, grep. All live testing is manual in the browser against your MAMP instance. No test harness, no scratch databases, no HTTP-level test tooling, by design     |
| Gitignored files      | `src/config.php` (your local credentials) never leaves your machine. Committed template is `src/config.sample.php`                                                                         |
| Before making changes | Read [ARCHITECTURE.md](ARCHITECTURE.md). Covers the role model, the order state machine, and a couple of real gotchas (layout variable scoping in particular) that will bite you otherwise |
