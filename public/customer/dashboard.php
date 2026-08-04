<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('customer');

$pdo = get_db();
$myUserId = (int) $_SESSION['user_id'];

// Pre-setting $labId here means layout_customer.php's guarded lookup
// never re-queries -- same convention as orders.php.
$labId = current_customer_lab_id($pdo, $myUserId);

// Shared with orders.php: previous last-seen marker for the row dots
// (null = first visit this session, no dots), and this visit becomes
// the new marker for whichever of the two pages loads next.
$lastOrdersSeen = mark_orders_seen();

// "This month" is the requested_datetime lens (when the tracer is needed),
// not created_at -- same lens orders.php filters on, which is what lets the
// tile below click through to the exact matching filtered list.
$monthStart = date('Y-m-01 00:00:00');
$nextMonthStart = date('Y-m-01 00:00:00', strtotime('first day of next month'));

// Overdue lens: a pending order is overdue the moment its requested time
// passes. Upcoming lens: start-of-today, not now() -- deliberately looser,
// so the count matches the tile's ?status=accepted&requested_from=today
// deep link exactly (orders.php applies requested_from as >= 00:00:00).
$now = date('Y-m-d H:i:s');
$todayStart = date('Y-m-d 00:00:00');

$stats = [
    'total_count' => 0,
    'pending_count' => 0,
    'pending_overdue_count' => 0,
    'upcoming_count' => 0,
    'next_upcoming' => null,
    'month_count' => 0,
];
$recentOrders = [];

if ($labId > 0) {
    // Lab-scoped: the c.lab_id join condition IS the access control, same
    // as orders.php -- "view own lab's orders".
    // Four bounded queries, not one WHERE-less aggregate: predicates
    // buried inside SUM()/CASE can't use an index, which scanned the
    // lab's entire order history on every load (same fix as
    // staff/dashboard.php). The datetime queries ride
    // idx_orders_customer_requested per lab member; the status counts
    // are inherently all-time, bounded by that index's customer_id
    // prefix.
    $labJoin =
        'FROM orders o
         JOIN customers c ON c.user_id = o.customer_id AND c.lab_id = ?';

    // Zero-seeded: GROUP BY omits absent statuses.
    $statusCounts = ['pending' => 0, 'accepted' => 0, 'completed' => 0, 'cancelled' => 0];
    $statusStmt = $pdo->prepare("SELECT o.status, COUNT(*) AS c $labJoin GROUP BY o.status");
    $statusStmt->execute([$labId]);
    foreach ($statusStmt->fetchAll() as $row) {
        $statusCounts[$row['status']] = (int) $row['c'];
    }
    $stats['pending_count'] = $statusCounts['pending'];
    $stats['total_count'] = array_sum($statusCounts);

    // COUNT and MIN deliberately share one query/predicate: the tile
    // meta renders next_upcoming whenever upcoming_count > 0, so the
    // two must never come from diverging bounds.
    $upcomingStmt = $pdo->prepare(
        "SELECT COUNT(*) AS upcoming_count, MIN(o.requested_datetime) AS next_upcoming
         $labJoin
         WHERE o.requested_datetime >= ?"
    );
    $upcomingStmt->execute([$labId, $todayStart]);
    $upcoming = $upcomingStmt->fetch();
    $stats['upcoming_count'] = (int) $upcoming['upcoming_count'];
    $stats['next_upcoming'] = $upcoming['next_upcoming'];

    $monthStmt = $pdo->prepare(
        "SELECT COUNT(*) $labJoin
         WHERE o.requested_datetime >= ? AND o.requested_datetime < ?"
    );
    $monthStmt->execute([$labId, $monthStart, $nextMonthStart]);
    $stats['month_count'] = (int) $monthStmt->fetchColumn();

    $overdueStmt = $pdo->prepare(
        "SELECT COUNT(*) $labJoin
         WHERE o.status = 'pending' AND o.requested_datetime < ?"
    );
    $overdueStmt->execute([$labId, $now]);
    $stats['pending_overdue_count'] = (int) $overdueStmt->fetchColumn();

    // Deferred join (house pattern for order-list queries): the inner
    // subquery resolves the 5 order_ids against orders + the lab-scope
    // join alone; the products display join attaches to just those 5
    // rows. ORDER BY re-applied outside -- a derived table has no
    // guaranteed order. Same sort + tiebreak as orders.php page 1, so
    // this preview stays byte-identical to that list's first 5 rows.
    // o.updated_at stays selected: it drives the "updated since your
    // last visit" dot below.
    $recentStmt = $pdo->prepare(
        'SELECT o.order_id, o.status, o.requested_datetime, o.updated_at, o.chargeable,
                p.name AS product_name
         FROM (
             SELECT o.order_id
             FROM orders o
             JOIN customers c ON c.user_id = o.customer_id AND c.lab_id = ?
             ORDER BY o.requested_datetime DESC, o.order_id DESC
             LIMIT 5
         ) page
         JOIN orders o   ON o.order_id = page.order_id
         JOIN products p ON p.product_id = o.product_id
         ORDER BY o.requested_datetime DESC, o.order_id DESC'
    );
    $recentStmt->execute([$labId]);
    $recentOrders = $recentStmt->fetchAll();
}

$pendingCount = (int) $stats['pending_count'];
$pendingOverdueCount = (int) $stats['pending_overdue_count'];
$upcomingCount = (int) $stats['upcoming_count'];
$monthCount = (int) $stats['month_count'];
$totalCount = (int) $stats['total_count'];

$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <?php // The include also sets $petordersLayout['account'] (name/username/
              // lab/institute for the page header + My Lab card below) --
              // no re-querying needed here. ?>
        <?php include __DIR__ . '/../../src/partials/layout_customer.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <div>
                    <span class="page-header__eyebrow">Customer</span>
                    <h1>Dashboard</h1>
                    <span class="page-header__meta">Signed in as <?= e($petordersLayout['account']['username']) ?></span>
                </div>
                <div class="page-header__actions">
                    <?php // Plain link to the orders page -- the New Order
                          // trigger itself lives there, not here. ?>
                    <a href="/customer/orders.php" class="btn btn--primary">Go to Orders</a>
                </div>
            </div>

            <?php if ($labId > 0): ?>
                <div class="stat-grid">
                    <?php // No urgency dots on these tiles -- state is conveyed
                          // by the meta text alone (plain words read reliably
                          // for this user base; a small colored dot doesn't). ?>
                    <a class="stat-tile" href="/customer/orders.php?status=pending">
                        <span class="stat-tile__label">Pending Orders</span>
                        <span class="stat-tile__value tabular"><?= $pendingCount ?></span>
                        <span class="stat-tile__meta"><?php
                            if ($pendingOverdueCount > 0) {
                                echo $pendingOverdueCount . ' past requested time';
                            } elseif ($pendingCount > 0) {
                                echo 'Awaiting processing';
                            } else {
                                echo 'None pending';
                            }
                        ?></span>
                    </a>
                    <a class="stat-tile" href="/customer/orders.php?requested_from=<?= e(date('Y-m-d')) ?>">
                        <span class="stat-tile__label">Upcoming Orders</span>
                        <span class="stat-tile__value tabular"><?= $upcomingCount ?></span>
                        <span class="stat-tile__meta"><?= $upcomingCount > 0 ? e('Next: ' . date('M j, H:i', strtotime($stats['next_upcoming']))) : 'None scheduled' ?></span>
                    </a>
                    <a class="stat-tile" href="/customer/orders.php?requested_from=<?= e(date('Y-m-01')) ?>&amp;requested_to=<?= e(date('Y-m-t')) ?>">
                        <span class="stat-tile__label">Requested This Month</span>
                        <span class="stat-tile__value tabular"><?= $monthCount ?></span>
                        <span class="stat-tile__meta"><?= e(date('F Y')) ?></span>
                    </a>
                    <a class="stat-tile" href="/customer/orders.php">
                        <span class="stat-tile__label">Total Orders</span>
                        <span class="stat-tile__value tabular"><?= $totalCount ?></span>
                        <span class="stat-tile__meta">All time</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php // Plain CSS Grid (no JS masonry): wide Recent Orders column
                  // plus a fixed side stack -- heights are independent, no
                  // measuring, no post-paint reflow. ?>
            <div class="dash-grid">
                <?php if ($labId <= 0): ?>
                    <div class="card">
                        <p class="muted">No lab assigned to your account yet &mdash; contact an administrator.</p>
                    </div>
                <?php else: ?>
                    <div class="table-card">
                        <div class="table-card-header">
                            <span class="table-card-title">Recent Orders</span>
                            <div class="table-card-controls">
                                <a href="/customer/orders.php" class="table-action">View all</a>
                            </div>
                        </div>
                        <?php if (!$recentOrders): ?>
                            <div class="empty-state">
                                <div class="empty-state__icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                        <line x1="12" y1="22.08" x2="12" y2="12"></line>
                                    </svg>
                                </div>
                                <div class="empty-state__title">Your lab hasn't placed any orders yet</div>
                                <p class="empty-state__hint">Orders placed by anyone in your lab will show up here.</p>
                                <div class="empty-state__action">
                                    <a href="/customer/orders.php" class="btn btn--secondary btn--sm">Go to Orders</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-scroll">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Requested</th>
                                            <th>Product</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentOrders as $o): ?>
                                            <?php $isUpdated = $lastOrdersSeen !== null && strtotime($o['updated_at']) > $lastOrdersSeen; ?>
                                            <tr>
                                                <td class="tabular">
                                                    <span class="table-flag"><?php if ($isUpdated): ?><span class="dot dot--info" title="Updated since your last visit"></span><span class="sr-only">Updated since your last visit</span><?php endif; ?></span><?= (int) $o['order_id'] ?>
                                                </td>
                                                <td class="tabular"><?= e(date('M j, Y H:i', strtotime($o['requested_datetime']))) ?></td>
                                                <td><?= e($o['product_name']) ?></td>
                                                <?php // Plain text, always rendered (not a second badge) --
                                                      // matches customer/orders.php's treatment:
                                                      // chargeable is the default (muted), "Not
                                                      // chargeable" the full-weight exception. ?>
                                                <td>
                                                    <div><span class="badge badge--<?= e($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></div>
                                                    <?php if ($o['chargeable']): ?>
                                                        <div class="muted text-sm">Chargeable</div>
                                                    <?php else: ?>
                                                        <div class="text-sm">Not chargeable</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><a href="/customer/order_detail.php?id=<?= (int) $o['order_id'] ?>" class="table-action">View</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="dash-stack">
                    <div class="card">
                        <span class="card__title">My Lab</span>
                        <?php // Compact identity block (same classes as the My
                              // Info modal header) -- PI and phone live in that
                              // modal, opened by the button below, rather than
                              // being duplicated here. ?>
                        <div class="my-info-identity">
                            <div class="my-info-identity__avatar">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                            </div>
                            <div>
                                <div class="my-info-identity__name"><?= e($petordersLayout['account']['lab_name'] ?? '—') ?></div>
                                <div class="my-info-identity__username"<?= $petordersLayout['account']['institute_name'] !== null ? ' title="' . e($petordersLayout['account']['institute_name']) . '"' : '' ?>><?= e($petordersLayout['account']['institute_shorthand'] ?? '—') ?></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn--secondary btn--sm" data-my-info-trigger>View full info</button>
                    </div>

                    <div class="card">
                        <span class="card__title">Quick Links</span>
                        <?php // Description stacked under each link, not in a
                              // side-by-side mini-list__meta -- that class is
                              // nowrap (built for short dates in the admin's
                              // wide panels) and would overflow this narrow
                              // side column. ?>
                        <ul class="mini-list">
                            <li>
                                <div>
                                    <a class="mini-list__main" href="/customer/lab_delivery_locations.php">Delivery Locations</a>
                                    <div class="muted text-sm">Where direct-delivery doses go</div>
                                </div>
                            </li>
                            <li>
                                <div>
                                    <a class="mini-list__main" href="/customer/lab_product_users.php">Product Users</a>
                                    <div class="muted text-sm">Who receives doses in your lab</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
