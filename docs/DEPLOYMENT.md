# PETOrders — Production Deployment Guide (RHEL 8)

Audience: IT staff deploying PETOrders for the first time. Assumes you
know RHEL, Apache, and MariaDB in general, but nothing about this app.
Follow the steps in order.

What you're deploying: a self-contained PHP 7.4 app with a MariaDB
database. No build step, no external services, no outbound network
calls. The app never sends email and loads no assets from CDNs.
Deployment = install prerequisites, put files on disk, create the
database, fill in one config file, point the web server at `public/`,
create the first admin.

---

## Contents

1. [Prerequisites](#1-prerequisites)
2. [Get the code](#2-get-the-code)
3. [Create the database](#3-create-the-database)
4. [Configure the app (src/config.php)](#4-configure-the-app-srcconfigphp)
5. [Configure Apache: document root must be public/](#5-configure-apache-document-root-must-be-public)
6. [HTTPS](#6-https)
7. [Create the first admin account](#7-create-the-first-admin-account)
8. [Verification checklist](#8-verification-checklist)
9. [Operational notes](#9-operational-notes)

---

## 1. Prerequisites

| Component  | Version                                                                 |
| ---------- | ----------------------------------------------------------------------- |
| OS         | RHEL 8                                                                  |
| Web server | Apache (httpd) with `mod_ssl`                                           |
| PHP        | 7.4, with `pdo_mysql`                                                   |
| Database   | MariaDB 10.11                                                           |

Check what's already installed:

```bash
cat /etc/redhat-release
php -v
php -m | grep -i pdo_mysql
httpd -v
mysql --version || mariadb --version
```

Install whatever's missing. If PHP/MariaDB are already present at the
right version, skip straight to Apache:

```bash
sudo dnf install -y httpd mod_ssl
sudo systemctl enable --now httpd
```

If PHP or MariaDB are missing:

```bash
# PHP 7.4
sudo dnf module enable -y php:7.4
sudo dnf install -y php php-mysqlnd php-json

# MariaDB (module stream name varies by RHEL release, use whatever
# stream your team supports; the app needs nothing newer than InnoDB
# + utf8mb4)
sudo dnf install -y mariadb-server
sudo systemctl enable --now mariadb
```

Re-run the check commands to confirm everything's in place.

---

## 2. Get the code

Put the app outside any existing web root. This guide uses
`/var/www/petorders`.

```bash
sudo git clone <your-git-remote>/petorders.git /var/www/petorders
```

(File archive instead of git? Extract so `/var/www/petorders` contains
`public/`, `src/`, `sql/`, `tools/`, `config/` directly.)

Ownership and permissions. `apache` only needs to read the app. Nothing
writes to disk except PHP's session storage and error log, both outside
the project:

```bash
sudo chown -R root:apache /var/www/petorders
sudo find /var/www/petorders -type d -exec chmod 750 {} \;
sudo find /var/www/petorders -type f -exec chmod 640 {} \;
```

Layout:

```
/var/www/petorders/
  public/    # the ONLY folder Apache serves (step 5)
  src/       # app code + config.php (DB credentials)
  config/    # static app settings (display name)
  sql/       # schema.sql (required), seed.sql (dev only, NOT for production)
  tools/     # command-line setup scripts (one is dev-only -- step 7)
```

---

## 3. Create the database

Dedicated, least-privilege user. Don't run the app as `root`.

```bash
sudo mysql
```

```sql
CREATE DATABASE petorders CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'petorders_app'@'localhost' IDENTIFIED BY 'CHOOSE_A_STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON petorders.* TO 'petorders_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Load the schema:

```bash
sudo mysql petorders < /var/www/petorders/sql/schema.sql
```

**Don't load `sql/seed.sql` in production.** It's fictional dev/test
data (sample labs, accounts, orders). Production should have schema
only until step 7 creates the first real admin.

---

## 4. Configure the app (src/config.php)

`src/config.php` isn't in the repo (gitignored, keeps credentials out of
git). Create it from the template:

```bash
cd /var/www/petorders
sudo cp src/config.sample.php src/config.php
sudo chown root:apache src/config.php
sudo chmod 640 src/config.php
sudo vi src/config.php
```

Set every constant:

| Constant                 | Production value         | Notes                                                                                                                                                 |
| ------------------------ | ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DB_HOST`                | `127.0.0.1`              | Assumes DB runs on the same host. Use the actual hostname if it doesn't.                                                                              |
| `DB_PORT`                | `3306`                   | MariaDB default.                                                                                                                                      |
| `DB_NAME`                | `petorders`              | The database from step 3.                                                                                                                             |
| `DB_USER`                | `petorders_app`          | The dedicated user from step 3, never `root`.                                                                                                         |
| `DB_PASS`                | _(password from step 3)_ |                                                                                                                                                       |
| `REQUIRE_SECURE_COOKIES` | `true`                   | **Must be `true` in production.** Marks session cookies HTTPS-only. Requires working HTTPS (step 6). Login won't work over plain HTTP with this set. |

`config/app_settings.php` holds the app display name (currently
`PETOrders`). Plain PHP file, no admin UI. Leave it alone unless you
have a reason not to.

---

## 5. Configure Apache: document root must be public/

The most important step.

**`DocumentRoot` must be `/var/www/petorders/public`, not**
**`/var/www/petorders`.**

Why: `public/` is the only folder meant to be reachable by URL. Code
(`src/`, including `config.php` with your DB credentials), SQL files,
the admin bootstrap script, and settings all live outside the document
root. Apache can't serve them under any URL, period. That's stronger
than any deny rule. Point the document root at the project root instead
and those folders become downloadable, credentials included.

Same reason `public/.htaccess` (dotfile deny, no directory listing,
server signature off, 404 handler) must stay inside `public/`. A
`.htaccess` at the project root does nothing, since Apache never serves
that directory.

Create the vhost:

```bash
sudo vi /etc/httpd/conf.d/petorders.conf
```

```apache
<VirtualHost *:443>
    ServerName petorders.example.nih.gov
    DocumentRoot /var/www/petorders/public

    SSLEngine on
    SSLCertificateFile      /etc/pki/tls/certs/petorders.crt
    SSLCertificateKeyFile   /etc/pki/tls/private/petorders.key
    # If IT provides a chain file:
    # SSLCertificateChainFile /etc/pki/tls/certs/petorders-chain.crt

    <Directory /var/www/petorders/public>
        # AuthConfig is required: public/.htaccess uses "Require all
        # denied" to block dotfiles, and without AuthConfig Apache
        # fails those requests with "Require not allowed here".
        AllowOverride FileInfo Options AuthConfig
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:80>
    ServerName petorders.example.nih.gov
    Redirect permanent / https://petorders.example.nih.gov/
</VirtualHost>
```

Replace the server name and cert paths with real values (step 6). Then:

```bash
sudo apachectl configtest        # expect: Syntax OK
sudo systemctl restart httpd
```

Check SELinux (read-only, changes nothing):

```bash
sestatus
```

or, if unavailable:

```bash
cat /sys/fs/selinux/enforce
```

(`1` = enforcing, `0` = permissive, file missing = SELinux inactive.)

If enforcing, label the content for httpd:

```bash
sudo semanage fcontext -a -t httpd_sys_content_t "/var/www/petorders(/.*)?"
sudo restorecon -R /var/www/petorders
```

Open the firewall if needed:

```bash
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

---

## 6. HTTPS

HTTPS-only in production, with a real cert from IT. Self-signed certs
are for local dev only.

1. Request a cert for the server's DNS name through your normal process.
2. Install cert + key where the vhost expects them
   (`/etc/pki/tls/certs/`, `/etc/pki/tls/private/`), key readable only
   by root (`chmod 600`).
3. Keep the HTTP→HTTPS redirect vhost from step 5 in place.

This is what makes `REQUIRE_SECURE_COOKIES = true` (step 4) work:
session cookies get flagged HTTPS-only, never sent in clear text. Real
cert, then `true`. The two go together.

---

## 7. Create the first admin account

No default accounts, no public way to create an admin. First (and only)
admin comes from the command line:

```bash
cd /var/www/petorders
php tools/bootstrap_admin.php jane.smith@example.com Jane Smith 301-555-0199
```

Arguments: `<username> <first_name> <last_name> <phone>`. Username must
be a valid email address. It's the login.

What it does:

- Creates one account, staff + admin privileges.
- Prints a temp password to the terminal:

  ```
  Admin account created.
  Username: jane.smith@example.com
  Temp password: Kx3nQ8rTb2mWp9Ls
  The account must change this password on first login.
  ```

- Forces a password change on first login (12+ chars, one letter, one
  number). Temp password stops working once a real one is set.

Relay the temp password over NIH email. The app never sends email
itself, and this terminal output is the only place it appears.

**Safety guard:** refuses to run if `users` already has any rows. Can't
clobber a live database, only works against a fresh empty schema. Need
a second admin later? Create it from inside the app (Accounts → +
Account).

Not the same as `tools/set_temp_passwords.php`, that's a dev-only
helper that resets every account for the seeded dev database. Never run
it in production. The same goes for `tools/generate_stress_test.php`, a
dev-only script that bulk-inserts thousands of synthetic orders
(configurable count) into whatever database `src/config.php` points
at — never run it against a production database.

Once the admin's in, everything else happens through the UI: approve
registrations, create staff accounts, build the catalog and directory.

---

## 8. Verification checklist

Manual only, no test tooling needed.

**Server:**

- [ ] `php -l /var/www/petorders/public/login.php` → `No syntax errors detected`
- [ ] `sudo apachectl configtest` → `Syntax OK`

**Browser:**

- [ ] `https://<hostname>/` loads and redirects to the login page

  ![PETOrders login page after a successful deployment](images/deployment/login-first-load.png)
  _PETOrders heading, Username/Password fields, Log In button, served over HTTPS with no cert warning._

- [ ] `http://<hostname>/` redirects to HTTPS
- [ ] `https://<hostname>/src/config.php` returns **404**: confirms the
      document root is correct. PHP source or a blank 200 page here
      means the DocumentRoot is wrong (step 5). Stop and fix it.
- [ ] `https://<hostname>/assets/` returns 403/404, not a file listing
- [ ] Log in with the bootstrapped admin's username + temp password →
      forced to Change Password → set a real one (12+ chars, letter +
      number)
- [ ] Lands on the Admin Dashboard
- [ ] Log out, log back in with the new password

All boxes checked = done.

---

## 9. Operational notes

- **Sessions time out after 15 min idle.** Returns to login on next
  click. By design.
- **Lockout:** 5 failed attempts locks the account for 15 min. The
  login page tells the user the account is temporarily locked and how
  many minutes remain (a deliberate disclosure choice for this
  intranet-only app — see the security posture section of
  ARCHITECTURE.md). Admins see every lockout from the past 7 days on
  the Admin Dashboard (the list has no row cap — within that window
  it's a complete record).
- **No email, ever.** Temp passwords and reset passwords are shown once
  to the admin, who relays them via NIH email manually.
- Admins can trigger a password reset but never see or set the actual
  password.
- **Timezone** pinned in code to `America/New_York`, server timezone
  doesn't affect order timestamps.
- **Backups:** everything lives in the `petorders` database. Back it up
  on your normal schedule, plus `src/config.php` (the one file on disk
  not in git).
- **Logs:** PHP errors go to the system PHP/Apache error log
  (`display_errors` off, users see a generic error page). Nothing
  app-specific to rotate.
