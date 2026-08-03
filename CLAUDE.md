# CLAUDE.md

Guidance for Claude Code when working on PETOrders. This app is
**functionally complete and style/UI complete**. This file exists to
preserve conventions, hard-won gotchas, and non-negotiable business rules
for maintenance, not to narrate how the app was built.

## Stack

- PHP 7.4 (RHEL 8 compatible)
- MySQL 8.0 / MariaDB 10.11 (wire-compatible via PDO)
- PDO with prepared statements; no ORM, no framework
- Vanilla CSS (system fonts only, no external dependencies)
- Vanilla JavaScript (no framework, minimal, single `script.js`, no bundler)
- No Composer, no npm, no external packages, no CDN; all assets local

## Further Documentation

`docs/` has deeper reference material not duplicated here. Check it
before doing a large exploration this file doesn't cover:
`ARCHITECTURE.md` (role/catalog/order-lifecycle diagrams, security
posture, full helper inventory), `DEPLOYMENT.md` (RHEL production
steps), `LOCAL_DEV_SETUP.md`, and per-role `USER_GUIDE_*.md`.

## Local Dev Setup

1. Create `petorders` database, load `sql/schema.sql`, then `sql/seed.sql`
   for broad dev/test data.
2. Copy `src/config.sample.php` → `src/config.php`, fill in DB credentials.
3. Run `tools/set_temp_passwords.php` once to set temp passwords for
   seeded accounts.
4. Point Apache document root at `public/`.
5. Log in at `/login.php`.

**MAMP on Mac:** DB port `8889`. `REQUIRE_SECURE_COOKIES = false` locally,
`true` on RHEL (HTTPS only).

**Production bootstrap (real launch, not dev):** run
`tools/bootstrap_admin.php <username> <first_name> <last_name> <phone>`
against an
otherwise-empty DB (schema.sql loaded, no seed.sql). Creates exactly one
real admin account. Refuses to run if `users` already has any rows.
Distinct from `set_temp_passwords.php`, which is dev-only bulk reset for
seeded accounts.

## Directory Layout

```
petorders/
  README.md            # project overview + index into docs/
  public/              # Only web-reachable folder (Apache doc root)
    .htaccess (deny dotfiles, no directory listings, ServerSignature
      Off, ErrorDocument 404 → /404.php; extensionless-URL rewrite
      present but commented out; needs AllowOverride incl. AuthConfig)
    index.php, login.php, register.php, logout.php, change_password.php,
    404.php (branded not-found page, wired via .htaccess)
    customer/
      dashboard.php, new_order.php (POST-only JSON endpoint),
      order_detail.php, orders.php, lab_delivery_locations.php,
      lab_product_users.php
    staff/
      dashboard.php, orders.php (Order Queue: triage list, no actions),
      order_detail.php (all lifecycle actions + chargeable toggle live here)
    admin/
      dashboard.php, registrations.php, customers.php, customer_detail.php,
      accounts.php, account_detail.php, nuclides.php, products.php,
      institutes.php, labs.php, pis.php, reports.php, export_csv.php
    account_profile.php (staff/admin self-service; excludes customer)
    registration_status.php (public, unauthenticated status lookup)
    favicons/ (favicon set + site.webmanifest; local, like all assets)
    assets/
      css/
        style.css (tokens, reset, typography, accessibility)
        layout/shell.css, layout/sidebar.css
        components/ (auth, page-structure, forms, buttons, tables, alerts,
                     badges, utilities, toasts, modals, feedback,
                     dashboard, radio-cards, order-page, print)
      js/script.js

  src/                 # Above web root; never servable by URL
    config.php (gitignored), config.sample.php, db.php, auth.php
    helpers.php (~35 shared functions + 3 constants: session/CSRF/
                 escaping/redirects, the JSON-AJAX endpoint contract
                 (json_response(), request_wants_json()), toast_flash,
                 order form data + validate_order_input(), the order
                 state machine (transition_order_status() + audit-trail
                 readers), the order-search resolver
                 (order_search_conditions(), backing both orders lists'
                 search boxes), and the pagination/query-state layer
                 (paginate(), build_query(), canonicalize_get(),
                 form_action()); modal dirty-tracking lives in
                 script.js, not here)
    partials/
      head.php, layout_customer.php, layout_staff.php, layout_admin.php,
      _sidebar_footer.php (all three layouts; role-branched trigger +
      staff/admin-only profile-edit modal), new_order_form.php,
      new_order_modal.php, table_pagination.php (reads a
      $tablePagination array the including page assigns first; see
      reserved variables below), order_cancel_modal.php,
      order_cancellation_card.php, order_notes_card.php (last three
      shared by the two order_detail.php pages; order_notes_card assigns
      $orderNotesValue into the including page's scope -- reserved)

  sql/
    schema.sql, seed.sql (broad dev/test data),
    EER Diagram.webp (data-model diagram)

  tools/
    set_temp_passwords.php (dev-only, seeded accounts)
    bootstrap_admin.php (production launch, single real admin)
    generate_stress_test.php (dev-only: bulk-inserts a configurable
    number of synthetic orders, thousands, into the configured DB
    for volume testing; never run it in production)
    prune_lockout_events.php (retention prune: deletes lockout_events
    rows older than 90 days; safe to rerun, cron'd monthly on RHEL --
    see DEPLOYMENT.md)

  config/
    app_settings.php (static app-wide settings, e.g. app_name; no table,
    no admin UI; this app's stand-in for a .env, read via app_setting())

  docs/
    ARCHITECTURE.md, DEPLOYMENT.md, LOCAL_DEV_SETUP.md,
    USER_GUIDE_{CUSTOMER,STAFF,ADMIN}.md, images/
```

## Layout partials: reserved variables (real bug, don't reintroduce)

`layout_customer.php` / `layout_staff.php` / `layout_admin.php` are plain
`include`s executed mid-page, so variables they assign land in the
**including page's own scope**, not a sandbox. To prevent silent
collisions, everything each layout produces is namespaced under a single
`$petordersLayout` array rather than loose same-named variables.

**Reserved names before including a layout:**
- `layout_customer.php`: `$petordersLayout` (`account`, `name`, `initials`,
  `current_page`; plus `nuclides`, `products`, `locations`, `product_users`
  only on pages that opt in via `$petordersNeedsOrderForm` — see below),
  plus two loose page-owned inputs (deliberate exceptions, not
  namespaced): `$labId`, and `$petordersNeedsOrderForm` (opt-in flag for
  the New Order modal + its 4 catalog queries; set before the include by
  `orders.php` (always — it owns the app's only `data-new-order-trigger`
  buttons) and `order_detail.php` (only when `$editing`; its edit form
  reuses the catalog lists). The flag gates the data load and the modal
  markup TOGETHER — `new_order_modal.php` reads all four catalog keys
  unguarded, so never gate them separately. A new
  `data-new-order-trigger` on another page requires setting the flag
  there too.)
- `layout_staff.php`: `$petordersLayout` (`account`, `name`, `initials`,
  `current_page`). Also reads `$_GET['profile_updated']`/`['profile_error']`
  and echoes toasts.
- `layout_admin.php`: staff's keys plus `accounts_child_pages`,
  `accounts_section_active`, `catalog_child_pages`,
  `catalog_section_active`, `directory_child_pages`,
  `directory_section_active`.
- `head.php`: expects `$pageTitle` from caller, used unguarded.

**Root cause of a real past bug:** before namespacing, bare `$nuclides`/
`$products`/`$locations`/`$productUsers` names got silently overwritten
when a page declared its own same-named variable (hit on
`lab_delivery_locations.php`). Fix was namespacing under `$petordersLayout` +
renaming page-level lists to collision-safe names
(`$deliveryLocations`, `$productUsersList`; still used deliberately,
even though namespacing has since closed the general collision case).

**Standing rule:** grep `layout_customer.php` / `layout_staff.php` for
their variable names before naming variables on any new page.

`new_order_modal.php` is collision-safe by design (closure params, not
scope injection). `customer/dashboard.php` and
`customer/order_detail.php` intentionally consume `$petordersLayout` fields
post-include; treat as an undocumented API surface if touching either
layout.

**Non-layout include-scope contracts (same mechanism):**

- `table_pagination.php` *reads* `$tablePagination`, an array the
  including page assigns immediately before the include. Required keys:
  `idPrefix`, `itemLabel`, `hiddenFields`, `page`, `totalPages`,
  `pageSize`, `rangeStart`/`rangeEnd` (take from `paginate()`, don't
  recompute), `totalCount`. Precondition: `canonicalize_get()` has
  already run (the partial's links call `build_query()`, which reads
  `$_GET`). Used by all 11 paginated list pages; the partial
  deliberately never aliases the name to a short local (it's a live
  loop variable on `products.php`/`pis.php`). Treat `$tablePagination`
  as reserved on any paginated page.
- `order_notes_card.php` *writes* `$orderNotesValue` into the including
  page's scope; reserved on both order_detail pages. Its textarea id
  is `order-notes`, not `notes` (the new-order modal owns `#notes` on
  pages that opt in via `$petordersNeedsOrderForm`).

The three order_detail partials (`order_cancel_modal.php`,
`order_cancellation_card.php`, `order_notes_card.php`) are shared by
`customer/order_detail.php` and `staff/order_detail.php` and are
zero-parameter by design: each reads the caller's scope and POSTs to
`$_SERVER['PHP_SELF']?id=<order_id>`, so whichever page included it
handles the submit. `order_cancel_modal.php` branches its hidden
`action` on session role (`cancel_order` for customer vs `cancel` for
staff; a pre-existing naming split kept as-is so neither POST handler
changed). `order_cancellation_card.php` renders nothing unless the
order is cancelled with a stored reason, so callers include it
unconditionally.

## Database

See `sql/schema.sql` for exact columns/constraints. Key facts:

**Username uniqueness is scoped to active accounts, not global.**
`users.username` (email) is enforced unique only among `active = 1`
rows, via a generated column (`username_if_active`) uniquely indexed.
Neither MySQL 8.0 nor MariaDB 10.11 supports partial/filtered unique
indexes, so this emulates one (inactive rows generate `NULL`, and both
engines allow unlimited `NULL`s in a unique index). A deactivated
account's email becomes reusable by a new account of any role
immediately; this is what makes the customer→staff transition (see
Roles) actually work. Every write path that can change `username`
(create, edit, reactivate) pre-checks `AND active = 1` before writing,
backed by a try/catch as the race-condition backstop. Reactivate
specifically needed this guard added (previously unguarded) since a
deactivated account's freed email may have since been claimed by a
different active account.

**Terminology:** isotope → **nuclide**, compound → **product**. UI-only
rename: `delivery_method` → displayed as "Fulfillment" (column/enum/
function names unchanged).

**Catalog:** `nuclides` + flat `products` table. Product columns:
`nuclide_id`, `name`, `delivery_method` (enum: `radiopharmacy` /
`pick_up` / `direct_delivery`, fixed per-product, not chosen per-order;
multi-method products = multiple product rows), `active`. No cost/pricing
anywhere. No category concept. Availability is computed:
`products.active = 1 AND nuclides.active = 1` (both gates live in
`get_new_order_form_data()` and `validate_order_input()`). No institute/
lab-scoped catalog access: every available product is visible to every
lab. Admin-only CRUD. Product edit: nuclide + delivery_method lock once
any order references the product (create new row + deactivate old
instead). Nuclide rename always allowed.

**Naming collision (intentional):** "product" = catalog item (`products`
table) vs. "product user" = dose recipient (`lab_product_users`). Don't
rename either.

**Orders:** `orders` + `order_audit_log` (status-only, not field-level).
`orders.notes` is the single shared, overwritable communication field
(last-write-wins, no history). Staff/admin always editable; customer only
on own pending order. No staff-only private notes channel. One order form
for all order types; cyclotron-run specifics go in Notes, not a separate
table.

**Lifecycle:** `orders.status` ∈ `pending`, `accepted`, `completed`,
`cancelled`. All transitions go through `transition_order_status()`
(`src/helpers.php`), the single validated path, audit-logged atomically.
Order creation is deliberately not a transition: `customer/new_order.php`
inserts the order and its `NULL → 'pending'` audit row directly, in the
same transaction; the one intentional `order_audit_log` write outside
`transition_order_status()`.

| Transition | Who | Path |
|---|---|---|
| accept | staff | pending → accepted |
| return | staff | accepted → pending |
| complete | staff | accepted → completed (terminal) |
| cancel | customer (own order) or staff (any) | pending/accepted → cancelled |
| reopen | staff | cancelled → pending |

`completed` is the only true terminal status. `orders.cancellation_reason`
(varchar 500) is required on every cancel, cleared on reopen.
`orders.chargeable` (boolean, default true) is staff-only, freely
toggleable regardless of status, **not** a lifecycle transition and not
audit-logged. UI treats "Not chargeable" as the flagged exception
(warning-tinted badge); "Chargeable" is the quiet default state.

Staff drives all lifecycle actions from `staff/order_detail.php`, not the
Order Queue table (pure triage list, no actions). Cancel (either path)
uses a shared reason-required modal.

**Identity/Directory:** Institute → lab availability mirrors nuclide →
product (computed, `labs.active = 1 AND institutes.active = 1`, no
cascade writes). `labs.active`/`pis.active` gate only new-registration
selection and changed-to assignments, never existing customers/orders.
Admin customer edit: keeping current lab+PI always saves; changing either
requires both active + paired in `lab_pis`. Lab↔PI pairing managed only
from the Lab modal's PI roster checkboxes. DB unique keys:
`institutes.name`, `institutes.shorthand_name` (NOT NULL, required;
it's the display text for every institute dropdown, full name shown via
`title` tooltip), and `pis.email`. No uniqueness for lab names.
No category/staff_categories concept anywhere. NRC contact fields fully
removed; don't re-add.

## Business Rules (Non-Negotiable)

- No phone-in orders. No `is_phone_in` field.
- Self-registration → `customer_registration_requests` only. No
  `users`/`customers` row until admin approval.
- One order form for all order types. No second order-detail table.
- All lifecycle transitions go through `transition_order_status()`: no
  call site bypasses it or invents new transitions. (Order creation is
  not a transition; `customer/new_order.php` writes the
  `NULL → pending` audit row itself; see Lifecycle above.)
- Cancelling always requires a reason (enforced inside
  `transition_order_status()`).
- `chargeable` is independent of lifecycle: freely toggleable, never
  audit-logged.
- Cost is not tracked anywhere. Do not add a cost field.
- Nuclide first, then product (never the reverse).
- Delivery method is a fixed property of the product, never per-order.
- Audit log is status-only; no field-level diffing.
- `orders.notes` is the only communication mechanism: single shared
  field, last-write-wins, no threading, no staff-only channel.
- No per-order/per-period quantity limits.
- No email from the app, ever. No SMTP, no mail-sending code.
- Session timeout: 15 min idle. Lockout: 5 failed attempts → 15 min.
  The login page shows "Account temporarily locked" with a minutes-left
  countdown (`lockout_message()`, `src/auth.php`), on the locking
  attempt and on every attempt while locked. **History:** the countdown
  was removed in a security review (#57) as anti-enumeration hardening,
  then deliberately restored (#96) as a considered UX tradeoff: the
  app is intranet-only behind badge access, so disclosing lockout state
  was judged acceptable there. Don't "fix" it back to a generic message
  without revisiting that decision.
- Order IDs sequential, never reused.
- Deactivating a customer never hides/auto-cancels historical orders.
- Admin triggers password resets but never views/sets the actual
  password; one-time temp password, forced change + strength check.
- Order search covers: ID, product, nuclide, date, customer/lab/PI/institute.
  Digit-only terms are an exact order-ID lookup (sargable PK seek), never
  a substring or text match; a term with any non-digit (e.g. "F-18") runs
  the text search and never matches order IDs. Text search never
  LIKE-scans `orders` itself: order_search_conditions() (helpers.php)
  resolves the term against the small dimension tables first and filters
  orders by the matched ID sets — every searched column lives on a
  dimension table, so this is exact, and it's what keeps search flat as
  orders grows. Don't reintroduce joined LIKEs into the orders queries.

## Roles

| Role | Access |
|------|--------|
| `customer` | Place orders, view own lab's orders, edit Notes on own pending orders, cancel own pending orders (reason required), manage own lab's delivery locations/product users |
| `staff` | Process any order (not category-split); accept/return/complete/cancel/reopen; edit Notes on any order; toggle `chargeable` |
| `admin` | Everything staff can, plus catalog/customer/staff management, reports, registration approval |

Role = which table a `user_id` appears in (`customers`/`staff`/`admins`);
`users` itself has no role column.

**Promote/demote (staff ↔ admin):** admins can promote a staff account
to admin or demote an admin to staff from `account_detail.php`'s
Account Actions card (`toggle_role` action: insert/delete the `admins`
row; the DB enforces admin ⊆ staff via FK). Guards: demoting the last
active admin is blocked (same FOR UPDATE count as deactivation's
last-admin guard), and admins can never change their own role. Role
changes take effect on the target's **next request**: `require_role()`
re-derives role from table membership every request (same mechanism
that signs out deactivated users). **Customers are never promotable to
staff/admin** (hard business rule). A real-world customer-becomes-staff
transition is handled outside the app: create a fresh staff account
manually and deactivate the old customer account; order history stays
on the deactivated customer account, no linkage.

## Dashboards

- All three dashboards: four stat tiles + preview lists. Tiles deep-link
  only when an exactly matching filtered list exists ("honest partial
  match"; e.g. staff's New Today and Total tiles link unfiltered
  because `staff/orders.php` can't filter on `created_at`).
- Preview lists are `LIMIT 5`: customer Recent Orders (newest requested
  time first), staff Due Today & Overdue (pending/accepted requested by
  end of today, soonest first, overdue included), admin Pending
  Registrations preview and Recently Added Customers.
- **Two deliberate exceptions** (admin dashboard): Lockouts and Rejected
  Registrations use a 7-day window with **no row cap**: each is a
  complete record for its window, so a burst of events can never
  silently scroll off the list. Don't re-add a cap.
- No-dot convention: urgency/state on dashboards is plain text (tile
  meta lines, the staff "Timing" column reading "Overdue"/"Due today"),
  never colored dots. The one surviving dot is `.dot--info`, the
  "updated since your last visit" flag on customer views, which is not
  an urgency signal. Statuses stay badges.

## CSS / UI Conventions

- No role-specific CSS files: shared component library across all three
  roles.
- Light mode only: no dark-mode tokens, no `prefers-color-scheme`
  anywhere in the CSS. Don't add them.
- Transient success → toast via `toast_flash()`. PRG convention after
  successful POST. Temp-password reveals use a session flash (read-once,
  60s TTL) instead of riding the redirect URL; never a toast.
- Errors/warnings → inline `.alert--error/--warning` + per-field
  `field_class()`/`field_error()`.
- Destructive actions → `data-confirm*` attributes, intercepted by
  script.js into a custom modal, never `window.confirm`.
- Status language: dotted pill badges for states, square no-dot chips for
  facts (e.g. `.badge--role-admin`, `.badge--not-chargeable`).
- Military time (24-hour HH:MM) for order date/time fields: pattern-
  validated text input, never a native time picker (real department
  requirement).
- Query strings stay clean: `build_query()` (links) and script.js's
  `initFilterFormCleanup()` (native GET form submits) both omit
  empty/default params and never emit a bare `?`. `FORM_FIELD_DEFAULTS`
  (script.js) mirrors `BUILD_QUERY_DEFAULTS` (helpers.php); keep them
  in sync. `build_query()` bakes in its own leading `?`; never prepend
  another, and same-page anchors must prepend `$_SERVER['PHP_SELF']`
  (an empty `href=""` resolves to the current URL, not the bare path).
- Any `json_encode()` that echoes request-derived data into an inline
  `<script>` uses `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT |
  JSON_HEX_AMP` (pattern source: `toast_flash()`; applied across the 7
  error-modal-reopen blocks). Deliberately exempt: `json_response()`
  (real JSON HTTP body) and `labs.php`'s `data-*` attribute encodes
  (`e()`-wrapped HTML attributes); don't "fix" those.
- Intentional list-sort divergence: `customer/orders.php` keeps one
  fixed newest-requested-first sort on every tab (it's order history);
  `staff/orders.php` sorts per status tab (it's triage:
  pending/accepted soonest-requested first, completed/cancelled most
  recently updated first). Commented in both files; don't align them.

## Admin CRUD Page Conventions

Default pattern for any new list/create/edit page (see `staff/orders.php`,
`admin/accounts.php`/`customers.php` as reference):

- **List pages:** `.status-tabs` strip (not `<select>`) when there's a
  status dimension, counts computed against other active filters. Full
  `.table-pagination` footer. Search/filter forms are explicit-submit
  (`method="get"`), never live-as-you-type.
- **Count/aggregate queries:** join only what the active filters
  reference, never unconditionally. On the orders pages that means
  `products` under the fulfillment filter and bare `orders` otherwise
  (customer/orders.php always keeps its lab-scope `customers` join —
  it's the access control); text search adds no joins at all, since
  order_search_conditions() reduces it to `o.`-column ID-set
  predicates. Pattern source: `staff/orders.php` and
  `customer/orders.php`. Performance only; results are identical in
  every branch (every droppable join targets a PK via a NOT-NULL FK,
  so removal is count-neutral).
- **Order-list queries are deferred joins** (both orders pages): an
  inner subquery resolves the page of order_ids against the filter
  joins alone — its WHERE + ORDER BY + LIMIT can ride the `orders`
  indexes — and the outer half attaches the display joins to just that
  page of rows, re-applying ORDER BY (a derived table has no guaranteed
  order). Related invariant: the `o.order_id` sort tiebreak always runs
  in the same direction as the primary sort column; a mixed-direction
  ORDER BY (e.g. `requested_datetime ASC, order_id DESC`) can't be
  served by any index and filesorts the whole tab.
- **Create/Edit:** `.modal-overlay` > `.modal` convention, dirty-tracking +
  discard-confirm via `window.petordersWireModalDirtyTracking()` (shared
  in script.js; the byte-identical wrapper was promoted there after 8
  inline copies; each page still keeps its own local `snapshotForm()`,
  passed as the third argument, since what counts as a field value
  varies per page: labs.php keys its `pi_ids[]` checkbox roster by
  name+value, products.php skips disabled locked-mirror fields). No
  shared modal-shell partial, deliberately (assessed and rejected as more
  config surface than it saves). Copy `nuclides.php`'s Add modal as the
  canonical skeleton.
- **CSS reuse:** reach for `.table-card`, `.status-tabs`, `.dash-grid`/
  `.dash-stack`, `.modal`/`.modal--wide` before adding a page-specific
  variant.
- **DRY, check before writing new logic:** `generate_temp_password()`
  (deliberately duplicated per-file, copy the shape, don't share into
  helpers.php), `fetch_order_audit_trail()`, `transition_order_status()`,
  `customer_display_name()`, `field_class()`/`field_error()`,
  `toast_flash()`, `csrf_field()`/`verify_csrf()`, `form_action()`,
  `paginate()` (consume its `rangeStart`/`rangeEnd`, don't recompute),
  `DEFAULT_PAGE_SIZE`/`PAGE_SIZE_OPTIONS`, `build_query()` (list-page
  links/actions that preserve filter+page state), `bootstrap_session()`
  (hardened session_start(), never call it bare), `csv_safe()` (CSV
  formula-injection neutralization), `app_setting()` (reads
  `config/app_settings.php`), `like_contains()`, `where_clause()`,
  `order_search_conditions()` (order-search term -> orders-only ID-set
  predicate).

## Git Workflow

Branch → PR → merge. Never push directly to `main` (branch-protected).

## Deployment Target

- RHEL 8 (PHP 7.4, MariaDB 10.11). No root access; hand off as schema +
  app files + config template + deployment doc.
- HTTPS: self-signed locally, real cert on RHEL (IT-provided).
- No external CDN; all assets local.

## Verification Policy (firm, do not deviate)

Claude Code must NOT start background servers, spin up scratch/temp MySQL
instances, or run live HTTP verification (curl, PHP built-in server, etc.)
for any task, including "just to verify." This includes resetting temp
passwords or modifying any database without explicit instruction.

Verification is limited to: `php -l` (syntax check), static code
review/diffs, and grep-based checks. The user handles all live
browser-based testing themselves, manually, in MAMP.

---

**This file is the source of truth.** If code contradicts it, fix the
file first, then the code.
