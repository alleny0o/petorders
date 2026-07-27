<?php
/**
 * Shared helper functions used across all three roles.
 */

// Every page includes this file, so this is the one place that needs to run
// to keep date()/time() off PHP's UTC default -- without it, any "now"-based
// timestamp (not read back from a stored DB value) renders hours off from
// local time.
date_default_timezone_set('America/New_York');

require_once __DIR__ . '/config.php';

// display_errors is PHP_INI_ALL (runtime-changeable), so this forces
// error detail out of the browser regardless of the server's php.ini --
// the set_exception_handler() below still logs the real message via
// error_log() so nothing is lost, just moved server-side.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Global backstop for anything uncaught (most commonly a PDOException --
// db.php runs with ERRMODE_EXCEPTION app-wide). Registered here because
// helpers.php is the first file every entry point requires, so this is
// live before db.php/config.php's constants are even used to connect.
set_exception_handler(function (Throwable $e): void {
    error_log('[UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    if (!headers_sent()) {
        http_response_code(500);
    }

    echo '<!DOCTYPE html><html><head><title>Something went wrong</title></head><body>'
        . '<p>Something went wrong. Please try again, and contact your administrator if the problem continues.</p>'
        . '</body></html>';
});

/**
 * Reads a value from config/app_settings.php, the static app-wide
 * settings file (no DB table, no admin UI -- see that file).
 */
function app_setting(string $key, $default = null)
{
    static $settings = null;
    if ($settings === null) {
        $settings = require __DIR__ . '/../config/app_settings.php';
    }
    return $settings[$key] ?? $default;
}

/**
 * Starts the session with hardened cookie flags. Every page must call
 * this instead of a bare session_start() so HttpOnly/Secure are never
 * missed (see CLAUDE.md page template).
 */
function bootstrap_session(): void
{
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => REQUIRE_SECURE_COOKIES,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Neutralizes CSV formula injection: if $value starts with a character
 * spreadsheet apps (Excel/Sheets) treat as a formula trigger (=, +, -,
 * @) or a raw tab/CR, prefixes it with a single quote so it's forced to
 * render as literal text instead of executing as a formula when the
 * export is opened. Byte-level check is safe on multibyte input --
 * UTF-8 continuation/lead bytes are always >= 0x80, so they never
 * collide with these ASCII trigger characters.
 */
function csv_safe(?string $value): string
{
    $value = (string) $value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Emits a JSON response and stops. Success: {ok:true, redirect}.
 * Failure: {ok:false, errors:{field: message}} and/or {ok:false, message}
 * with a non-200 status. The contract behind every AJAX form submit:
 * new_order.php (a dedicated JSON-only endpoint) and the CRUD pages'
 * request_wants_json() branches (which keep their full-page POST
 * fallback -- see below).
 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * True when the request came from script.js's AJAX form submit
 * (initAjaxForms sets this header explicitly on every fetch). Pages
 * check it to answer a POST with json_response() instead of the normal
 * PRG redirect / full-page re-render -- which both stay in place as the
 * no-JS fallback, not as dead code.
 */
function request_wants_json(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

/**
 * Escaped asset URL with the file's mtime baked in as ?v=, so a changed
 * CSS/JS file always busts the browser cache while an unchanged one
 * keeps its cached copy. $path is root-relative (e.g. "/assets/css/style.css").
 */
function asset_url(string $path): string
{
    $mtime = @filemtime(dirname(__DIR__) . '/public' . $path);
    return e($mtime ? $path . '?v=' . $mtime : $path);
}

/**
 * Emits a script tag that pops a toast once the page has loaded.
 * Used for transient success feedback -- both on the PRG (redirect-after-
 * POST with an arrival-flag query param) convention most pages use, and on
 * the handful of pages that re-render the same POST response inline
 * instead (a one-time secret like a temp password can't safely round-trip
 * through a redirect); persistent messages (errors, temp passwords) stay
 * as inline .alert markup instead.
 * json_encode with the HEX flags makes the values safe to embed in
 * an inline <script> (no </script> or quote breakouts).
 */
function toast_flash(string $type, string $message): string
{
    $args = json_encode(
        [$type, $message],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    );

    return '<script>document.addEventListener("DOMContentLoaded",function(){'
        . 'window.showToast.apply(null,' . $args . ');});</script>';
}

/**
 * Renders the inline error message(s) for one form field, or nothing
 * when the field is clean. Values may be a string or a list of
 * strings (e.g. several password-strength failures on one field).
 */
function field_error(array $fieldErrors, string $key): string
{
    if (!isset($fieldErrors[$key])) {
        return '';
    }

    $html = '';
    foreach ((array) $fieldErrors[$key] as $message) {
        $html .= '<span class="field-error">' . e($message) . '</span>';
    }
    return $html;
}

/**
 * Class list for a .field wrapper: adds the invalid modifier (red
 * border + red label via CSS) when the field has an error.
 */
function field_class(array $fieldErrors, string $key, string $base = 'field'): string
{
    return $base . (isset($fieldErrors[$key]) ? ' field--invalid' : '');
}

/**
 * Builds a display name like "Alice Carter" from a customer's
 * first/last name. Falls back to the username when the customer has no
 * name on file (not-yet-approved rows).
 */
function customer_display_name(?string $firstName, ?string $lastName, string $usernameFallback): string
{
    if ($firstName === null || $firstName === '' || $lastName === null || $lastName === '') {
        return $usernameFallback;
    }

    return $firstName . ' ' . $lastName;
}

/**
 * Nuclides/products/locations/product-users backing the new-order form's
 * cascading selects. Shared by new_order.php's own full-page render and
 * the new-order modal (src/partials/new_order_modal.php) so both pull
 * from the same queries instead of duplicating them. locations/
 * product_users come back empty when $labId <= 0 -- same as the inline
 * behavior this was extracted from. Each product row is one flat catalog
 * row (nuclide + name + its one fixed delivery_method); like nuclides,
 * products are global catalog data -- every active product is available
 * to every lab/institute, gated only by the active flags.
 *
 * Availability is COMPUTED, never cascaded: a product is orderable iff
 * products.active = 1 AND nuclides.active = 1, checked live here (and in
 * validate_order_input() below). Deactivating a nuclide writes nothing
 * to product rows -- any future availability check must apply the same
 * two-flag rule rather than reading products.active alone.
 */
function get_new_order_form_data(PDO $pdo, int $labId): array
{
    $nuclides = $pdo->query('SELECT nuclide_id, name FROM nuclides WHERE active = 1 ORDER BY name')->fetchAll();

    $products = $pdo->query(
        'SELECT p.product_id, p.nuclide_id, p.name, p.delivery_method
         FROM products p
         JOIN nuclides n ON n.nuclide_id = p.nuclide_id AND n.active = 1
         WHERE p.active = 1
         ORDER BY p.name, p.delivery_method'
    )->fetchAll();

    $locations = [];
    $productUsers = [];

    if ($labId > 0) {
        $stmt = $pdo->prepare('SELECT location_id, name, room FROM lab_delivery_locations WHERE lab_id = ? AND active = 1 ORDER BY name');
        $stmt->execute([$labId]);
        $locations = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT product_user_id, first_name, last_name, email FROM lab_product_users WHERE lab_id = ? AND active = 1 ORDER BY last_name, first_name');
        $stmt->execute([$labId]);
        $productUsers = $stmt->fetchAll();
    }

    return [
        'nuclides' => $nuclides,
        'products' => $products,
        'locations' => $locations,
        'product_users' => $productUsers,
    ];
}

/**
 * Validates one submitted set of order-form fields against the live
 * catalog and the customer's own lab scope, and normalizes them into
 * column-ready values. $input holds the raw trimmed strings keyed
 * nuclide_id, product_id, activity_mci, requested_date,
 * requested_time, notes, location_id, product_user_id. Shared by order
 * creation (customer/new_order.php) and the pending-order edit form
 * (customer/order_detail.php) so the security-sensitive checks --
 * lab-scoped locations and product users -- exist exactly once.
 * Returns ['errors' => [field => message], 'values' => [...]]; values
 * are only meaningful when errors is empty.
 *
 * Effective availability (products.active AND nuclides.active -- see
 * get_new_order_form_data()) is enforced transitively here: the nuclide
 * check requires active = 1, and the product check requires active = 1
 * plus membership in that already-verified nuclide.
 */
function validate_order_input(PDO $pdo, array $input, int $labId): array
{
    $fieldErrors = [];

    // ---- Nuclide ----
    $nuclideId = ctype_digit($input['nuclide_id']) ? (int) $input['nuclide_id'] : 0;
    if ($nuclideId <= 0) {
        $fieldErrors['nuclide_id'] = 'Select a nuclide.';
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM nuclides WHERE nuclide_id = ? AND active = 1');
        $stmt->execute([$nuclideId]);
        if (!$stmt->fetchColumn()) {
            $fieldErrors['nuclide_id'] = 'Select a valid nuclide.';
        }
    }

    // ---- Product: the customer picks the flat product row directly, so
    // one query resolves everything -- it must be active and belong to
    // the chosen nuclide (any customer may order any active product; no
    // institute/lab catalog scoping exists). The row's delivery_method
    // comes back with it: delivery method is a fixed property of the
    // product, never chosen per-order. ----
    $productId = ctype_digit($input['product_id']) ? (int) $input['product_id'] : 0;
    $deliveryMethod = null;
    if ($productId <= 0) {
        $fieldErrors['product_id'] = 'Select a product.';
    } elseif (!isset($fieldErrors['nuclide_id'])) {
        $stmt = $pdo->prepare(
            'SELECT delivery_method FROM products
             WHERE product_id = ? AND nuclide_id = ? AND active = 1'
        );
        $stmt->execute([$productId, $nuclideId]);
        $deliveryMethod = $stmt->fetchColumn();
        if ($deliveryMethod === false) {
            $deliveryMethod = null;
            $fieldErrors['product_id'] = 'Select a valid product for the chosen nuclide.';
        }
    }

    // ---- Activity ----
    $activityMci = null;
    if ($input['activity_mci'] === '' || !is_numeric($input['activity_mci']) || (float) $input['activity_mci'] <= 0) {
        $fieldErrors['activity_mci'] = 'Enter a valid activity (mCi).';
    } else {
        $activityMci = (float) $input['activity_mci'];
    }

    // ---- Requested date & time. Military-time (24-hour HH:MM) is a
    // pattern-validated text input, never a native time/datetime-local
    // picker, per CLAUDE.md -- the date portion alone uses the native
    // date picker since that rule is about time display, not date. ----
    $requestedDatetimeSql = null;
    if ($input['requested_date'] === '') {
        $fieldErrors['requested_date'] = 'Select a requested date.';
    } else {
        $requestedDate = DateTime::createFromFormat('Y-m-d', $input['requested_date']);
        if ($requestedDate === false || $requestedDate->format('Y-m-d') !== $input['requested_date']) {
            $fieldErrors['requested_date'] = 'Enter a valid date.';
        }
    }
    if ($input['requested_time'] === '' || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $input['requested_time'])) {
        $fieldErrors['requested_time'] = 'Enter a time in 24-hour HH:MM format.';
    }
    if (!isset($fieldErrors['requested_date']) && !isset($fieldErrors['requested_time'])) {
        $requestedDt = DateTime::createFromFormat('Y-m-d H:i', $input['requested_date'] . ' ' . $input['requested_time']);
        $requestedDatetimeSql = $requestedDt->format('Y-m-d H:i:00');
    }

    // ---- Notes (optional) -- cyclotron-run specifics (beam current,
    // bombardment time, EOB activity, destination) go here like any
    // other order note, per the unified-form business rule. ----
    $notes = $input['notes'];
    if (mb_strlen($notes) > 500) {
        $fieldErrors['notes'] = 'Notes must be 500 characters or fewer.';
    }

    // ---- Location: required only when the resolved product's
    // delivery_method is direct_delivery -- every other method carries no
    // location, so anything posted for one is a stale/tampered value (the
    // form disables the field in that state) and is normalized to NULL
    // rather than errored. location_id itself stays nullable at the DB
    // level; this conditional requirement is enforced here, not as a DB
    // constraint. Must belong to the customer's own lab. Skipped entirely
    // while the product itself failed validation ($deliveryMethod null)
    // -- the response carries the product error either way. ----
    $locationId = null;
    if ($deliveryMethod === 'direct_delivery') {
        if ($input['location_id'] === '') {
            $fieldErrors['location_id'] = 'Select a delivery location for this product.';
        } else {
            $locationId = ctype_digit($input['location_id']) ? (int) $input['location_id'] : 0;
            if ($locationId <= 0) {
                $fieldErrors['location_id'] = 'Select a valid location.';
            } else {
                $stmt = $pdo->prepare('SELECT 1 FROM lab_delivery_locations WHERE location_id = ? AND lab_id = ?');
                $stmt->execute([$locationId, $labId]);
                if (!$stmt->fetchColumn()) {
                    $fieldErrors['location_id'] = 'Select a valid location for your lab.';
                }
            }
        }
    }

    // ---- Product user (optional): NULL means the ordering customer is
    // the recipient. Must belong to the customer's own lab. ----
    $productUserId = null;
    if ($input['product_user_id'] !== '') {
        $productUserId = ctype_digit($input['product_user_id']) ? (int) $input['product_user_id'] : 0;
        if ($productUserId <= 0) {
            $fieldErrors['product_user_id'] = 'Select a valid product user.';
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM lab_product_users WHERE product_user_id = ? AND lab_id = ?');
            $stmt->execute([$productUserId, $labId]);
            if (!$stmt->fetchColumn()) {
                $fieldErrors['product_user_id'] = 'Select a valid product user for your lab.';
            }
        }
    }

    return [
        'errors' => $fieldErrors,
        'values' => [
            'product_id'         => $productId,
            'delivery_method'    => $deliveryMethod,
            'activity_mci'       => $activityMci,
            'requested_datetime' => $requestedDatetimeSql,
            'notes'              => $notes,
            'location_id'        => $locationId,
            'product_user_id'    => $productUserId,
        ],
    ];
}

/**
 * Display name for a products.delivery_method enum value. The single
 * home for this mapping -- form option labels, the delivery hint, and
 * future surfaces (order detail rebuild, staff dashboard) all format
 * through here rather than repeating the mapping inline. Falls back to
 * the raw value for anything unrecognized rather than guessing.
 */
function delivery_method_label(string $deliveryMethod): string
{
    switch ($deliveryMethod) {
        case 'radiopharmacy':
            return 'Radiopharmacy';
        case 'pick_up':
            return 'Pick Up';
        case 'direct_delivery':
            return 'Direct Delivery';
        default:
            return $deliveryMethod;
    }
}

/**
 * DECIMAL(8,3) without noise: 10.000 -> 10, 2.500 -> 2.5. Shared by
 * customer/order_detail.php and staff/order_detail.php, which both
 * format orders.activity_mci the same way.
 */
function format_activity_mci(string $activity): string
{
    $formatted = rtrim(rtrim(number_format((float) $activity, 3, '.', ''), '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

/**
 * Whether $role may edit an order's Notes field. Staff/admin always can,
 * on any order; a customer only on their own order. This is the
 * confirmed permission model for orders.notes (the single shared,
 * overwritable communication channel).
 */
function can_edit_order_notes(string $role, bool $isOwnOrder): bool
{
    if ($role === 'staff' || $role === 'admin') {
        return true;
    }

    return $role === 'customer' && $isOwnOrder;
}

/**
 * The single validated path for every order status change -- customer
 * cancel and the staff accept/return/complete/cancel transitions all go
 * through here, so the legal state machine exists in exactly one place.
 * $actorRole is 'customer' or 'staff' ('admin' normalizes to 'staff'
 * below, same as can_edit_order_notes() above -- $_SESSION['role'] holds
 * the literal role, and admin can do everything staff can per the Roles
 * table). $cancellationReason is required (non-empty, <=500 chars)
 * whenever $toStatus is 'cancelled', from either actor -- structured data
 * tied to the cancel event, distinct from the general notes field.
 * Returns ['ok' => true] on success, or ['ok' => false, 'reason' =>
 * 'reason_required' | 'not_transitionable'] so each call site can render
 * its own message rather than a generic one.
 */
function transition_order_status(PDO $pdo, int $orderId, string $toStatus, string $actorRole, int $actorUserId, ?string $cancellationReason = null): array
{
    if ($actorRole === 'admin') {
        $actorRole = 'staff';
    }

    $transitions = [
        'customer' => [
            'cancelled' => ['pending'],
        ],
        'staff' => [
            'accepted'  => ['pending'],
            // 'return' (accepted -> pending) and 'reopen' (cancelled ->
            // pending) share this one target -- the state machine doesn't
            // distinguish them, only the UI copy does (see
            // describe_order_transition() below). completed is the only
            // truly terminal status now that cancelled can be reopened.
            'pending'   => ['accepted', 'cancelled'],
            'completed' => ['accepted'],
            'cancelled' => ['pending', 'accepted'],
        ],
    ];

    if (!isset($transitions[$actorRole][$toStatus])) {
        return ['ok' => false, 'reason' => 'not_transitionable'];
    }
    $allowedFrom = $transitions[$actorRole][$toStatus];

    $reason = null;
    if ($toStatus === 'cancelled') {
        $reason = trim((string) $cancellationReason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            return ['ok' => false, 'reason' => 'reason_required'];
        }
    }

    $pdo->beginTransaction();
    try {
        // Lock the row and read its current status before deciding
        // whether the transition is legal -- same two-step
        // read-then-conditionally-write pattern as the save_details
        // branch on customer/order_detail.php. Ownership is folded into
        // the lock query itself for the customer role (defense in depth,
        // not just a caller-side gate), matching how cancel_order already
        // repeats customer_id in its WHERE clause.
        if ($actorRole === 'customer') {
            $stmt = $pdo->prepare('SELECT status FROM orders WHERE order_id = ? AND customer_id = ? FOR UPDATE');
            $stmt->execute([$orderId, $actorUserId]);
        } else {
            $stmt = $pdo->prepare('SELECT status FROM orders WHERE order_id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
        }
        $fromStatus = $stmt->fetchColumn();

        if ($fromStatus === false || !in_array($fromStatus, $allowedFrom, true)) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_transitionable'];
        }

        if ($toStatus === 'cancelled') {
            $pdo->prepare('UPDATE orders SET status = ?, cancellation_reason = ? WHERE order_id = ?')
                ->execute([$toStatus, $reason, $orderId]);
        } elseif ($toStatus === 'pending' && $fromStatus === 'cancelled') {
            // Reopening: clear the stale reason so it doesn't keep showing
            // on an order that's active again -- the cancel event itself
            // stays visible as its own permanent row in order_audit_log,
            // only this derived "current reason" field is cleared. The
            // ordinary accepted -> pending return has no reason to clear.
            $pdo->prepare('UPDATE orders SET status = ?, cancellation_reason = NULL WHERE order_id = ?')
                ->execute([$toStatus, $orderId]);
        } else {
            $pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?')
                ->execute([$toStatus, $orderId]);
        }

        $pdo->prepare(
            'INSERT INTO order_audit_log (order_id, status_from, status_to, changed_by_user_id) VALUES (?, ?, ?, ?)'
        )->execute([$orderId, $fromStatus, $toStatus, $actorUserId]);

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Who performed the -> cancelled transition on this order, per
 * order_audit_log (written atomically by transition_order_status()
 * above). is_customer distinguishes customer from staff/admin via the
 * same table-membership check determine_role() (src/auth.php) uses --
 * staff and admin both collapse to "Staff" for customer-facing display
 * (this app has no reason to name an individual staff member to a
 * customer); shared by customer/order_detail.php and
 * staff/order_detail.php, which both need this exact lookup. Only
 * meaningful for a cancelled order, so the single -> cancelled row
 * (cancel is terminal) is exactly the one we want.
 */
function fetch_order_cancellation_actor(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.first_name, u.last_name, u.username, (c.user_id IS NOT NULL) AS is_customer
         FROM order_audit_log al
         JOIN users u ON u.user_id = al.changed_by_user_id
         LEFT JOIN customers c ON c.user_id = al.changed_by_user_id
         WHERE al.order_id = ? AND al.status_to = \'cancelled\'
         ORDER BY al.changed_at DESC
         LIMIT 1'
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Full order_audit_log history for one order, oldest first, each row
 * resolved to its actor's name/username and customer-vs-staff role (same
 * join shape as fetch_order_cancellation_actor() above, generalized to
 * every row instead of just the -> cancelled one). Built for
 * staff/order_detail.php's Activity card -- staff sees the real actor
 * name there (not the customer-facing "Staff" collapse), since knowing
 * which colleague did what is the whole point of an internal audit
 * trail.
 */
function fetch_order_audit_trail(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT al.status_from, al.status_to, al.changed_at, al.changed_by_user_id,
                u.first_name, u.last_name, u.username,
                (c.user_id IS NOT NULL) AS is_customer
         FROM order_audit_log al
         JOIN users u ON u.user_id = al.changed_by_user_id
         LEFT JOIN customers c ON c.user_id = al.changed_by_user_id
         WHERE al.order_id = ?
         ORDER BY al.changed_at ASC, al.audit_id ASC'
    );
    $stmt->execute([$orderId]);

    return $stmt->fetchAll();
}

/**
 * Plain-English description of one order_audit_log transition. Shared by
 * staff/order_detail.php's per-order Activity card and
 * staff/dashboard.php's system-wide Recent Activity card.
 */
function describe_order_transition(?string $from, string $to): string
{
    if ($from === null) {
        return 'Order placed';
    }
    $descriptions = [
        'pending_accepted'   => 'Accepted',
        'accepted_pending'   => 'Returned to pending',
        'cancelled_pending'  => 'Reopened',
        'accepted_completed' => 'Marked completed',
        'pending_cancelled'  => 'Cancelled',
        'accepted_cancelled' => 'Cancelled',
    ];

    return $descriptions[$from . '_' . $to] ?? (ucfirst($from) . ' → ' . ucfirst($to));
}

/**
 * Returns the previous "last looked at the lab's orders" unix timestamp
 * (null on the first order-page visit of the session) and stamps now as
 * the new one. ONE shared mechanism for the updated-since-last-visit row
 * dots on customer/dashboard.php AND customer/orders.php -- both pages
 * call this on load, so visiting either one advances the same marker and
 * clears the dots the other would have shown; they can never disagree.
 * Session-only by design (resets on logout, accepted); a null return
 * means "nothing to compare against yet" -- no dots, not an error state.
 */
function mark_orders_seen(): ?int
{
    $previous = isset($_SESSION['last_orders_seen']) ? (int) $_SESSION['last_orders_seen'] : null;
    $_SESSION['last_orders_seen'] = time();

    return $previous;
}

// Allowed page-size choices and the shared default for every list page's
// page-size selector -- identical across all roles.
const PAGE_SIZE_OPTIONS = [10, 20, 50, 100];
const DEFAULT_PAGE_SIZE = 10;

/**
 * Builds a query string from the current $_GET with the given overrides
 * applied, dropping empty/null values. Shared by every list page's status
 * tabs, pagination links, and POST-form actions, so paging/filtering never
 * drops the rest of the active view.
 */
function build_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return http_build_query($params);
}

/**
 * Builds a list page's POST-form action: the given path with the current
 * search/filter/page state (via build_query()) appended, so create/edit/
 * toggle handlers redirect back to the exact view the person was on, not
 * page 1. Call after paginate() + canonicalize_get(['page' => $page]) so
 * the clamped page number is what gets embedded.
 */
function form_action(string $path): string
{
    $queryString = build_query();

    return $queryString !== '' ? $path . '?' . $queryString : $path;
}

/**
 * Writes each already-whitelisted/clamped filter value back into $_GET, so
 * build_query() (which reads $_GET) reflects the real applied values
 * rather than raw/invalid query-string input -- e.g. an out-of-enum
 * ?status=garbage gets overwritten with the validated '' before any link
 * on this render is built. build_query() already drops empty values when
 * assembling a query string, so values are written as-is here -- no
 * unset-if-empty branching needed.
 */
function canonicalize_get(array $values): void
{
    foreach ($values as $key => $value) {
        $_GET[$key] = (string) $value;
    }
}

/**
 * Clamped pagination math shared by every list page: total pages (at
 * least 1), the current page clamped into range, the LIMIT offset, and the
 * human "Showing X-Y of Z" range endpoints.
 */
function paginate(int $totalCount, int $page, int $pageSize): array
{
    $totalPages = max(1, (int) ceil($totalCount / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;

    return [
        'page' => $page,
        'totalPages' => $totalPages,
        'offset' => $offset,
        'rangeStart' => $totalCount > 0 ? $offset + 1 : 0,
        'rangeEnd' => min($offset + $pageSize, $totalCount),
    ];
}

/**
 * Escapes LIKE wildcards in a raw search term and wraps it in %...% --
 * shared by every list page's search box so a literal % or _ in the
 * search term is matched literally rather than as a wildcard. Pair with
 * `LIKE ? ESCAPE '\\\\'` at the call site (the escape character itself is
 * SQL text, not part of this helper).
 */
function like_contains(string $q): string
{
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    return '%' . $escaped . '%';
}

/**
 * The signed-in customer's lab_id, or 0 if none is assigned yet. Shared
 * by every customer-role page (and layout_customer.php's own guarded
 * lookup) that needs to scope a query to "my lab".
 */
function current_customer_lab_id(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT lab_id FROM customers WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Backs the sidebar avatar/name/initials and the My Info / profile-edit
 * modals on all 3 layouts -- one query shape for customers (needs
 * lab/institute/PI via joins), another for staff/admin (plain users
 * row), selected by $role so an admin viewing staff pages still reports
 * accurately.
 */
function layout_account_data(int $userId, string $role): array
{
    $pdo = get_db();
    if ($role === 'customer') {
        $stmt = $pdo->prepare(
            'SELECT u.first_name, u.last_name, u.phone, u.username,
                    l.lab_name, i.name AS institute_name, p.pi_name
             FROM customers c
             JOIN users u ON u.user_id = c.user_id
             LEFT JOIN labs l ON l.lab_id = c.lab_id
             LEFT JOIN institutes i ON i.institute_id = l.institute_id
             LEFT JOIN pis p ON p.pi_id = c.supervising_pi_id
             WHERE c.user_id = ?'
        );
    } else {
        $stmt = $pdo->prepare('SELECT first_name, last_name, phone FROM users WHERE user_id = ?');
    }
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    $name = $account['first_name'] . ' ' . $account['last_name'];
    $initials = implode('', array_map(
        fn($w) => mb_substr($w, 0, 1),
        array_slice(explode(' ', $name), 0, 2)
    ));

    return [
        'account' => $account,
        'name' => $name,
        'initials' => $initials,
        'current_page' => basename($_SERVER['PHP_SELF'], '.php'),
    ];
}

/**
 * Captures one-shot PRG arrival-toast flags (?created=1 etc.) into a
 * boolean map and strips them from $_GET, so this render's own
 * pagination/tab links (built via build_query()) never carry a stale
 * flag forward. The client-side petordersCleanArrivalFlags() (script.js)
 * handles the other half -- a manual reload/back-nav of the arrived-at
 * URL, which this server-side strip alone can't prevent.
 */
function consume_arrival_flags(array $flags): array
{
    $result = [];
    foreach ($flags as $flag) {
        $result[$flag] = ($_GET[$flag] ?? null) === '1';
        unset($_GET[$flag]);
    }
    return $result;
}

/**
 * Turns a list of SQL condition fragments into a WHERE clause, or '' if
 * the list is empty. Shared scaffolding for every filtered list page's
 * two-step count-then-list query pair (build WHERE without status, count,
 * extend WHERE with status, list) -- the per-shape status-counting SQL
 * itself (GROUP BY status / GROUP BY active / derived CASE) stays
 * page-specific, only this WHERE-assembly step is identical everywhere.
 */
function where_clause(array $conditions): string
{
    return $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
}
