<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('staff');

$pdo = get_db();

// Not lab-scoped -- staff triage spans every lab ("any staff, any
// order"), unlike the customer dashboard's own-lab-only stats.
// 4th tile (new_today_count) uses created_at, set once at insert and
// never modified -- an exact count, not a proxy. Its tile links
// unfiltered to the queue since staff/orders.php only date-filters on
// requested_datetime, not created_at.
$todayStart = date('Y-m-d 00:00:00');
$dashStatStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_count,
            COALESCE(SUM(status = 'pending'), 0) AS pending_count,
            COALESCE(SUM(status = 'accepted'), 0) AS accepted_count,
            COALESCE(SUM(created_at >= ?), 0) AS new_today_count
     FROM orders"
);
$dashStatStmt->execute([$todayStart]);
$dashStats = $dashStatStmt->fetch();
$dashPendingCount = (int) $dashStats['pending_count'];
$dashAcceptedCount = (int) $dashStats['accepted_count'];
$dashNewTodayCount = (int) $dashStats['new_today_count'];
$dashTotalCount = (int) $dashStats['total_count'];

// Due Today & Overdue: the operationally useful view for a radiotracer
// department -- pending/accepted orders (the two actionable statuses)
// whose tracer is needed today or was needed already. No lower bound on
// requested_datetime: an order that's already past its requested time and
// still pending/accepted is MORE urgent, not excluded -- flagged as
// overdue below rather than hidden. Bounded to end-of-today (same window
// as the stat tile above), not a multi-day lookahead -- this is a landing
// page, not a work surface; the Order Queue is where staff triage further out.
$dashDueStmt = $pdo->prepare(
    "SELECT o.order_id, o.status, o.requested_datetime,
            p.name AS product_name,
            l.lab_name
     FROM orders o
     JOIN customers c ON c.user_id = o.customer_id
     JOIN products p  ON p.product_id = o.product_id
     LEFT JOIN labs l ON l.lab_id = c.lab_id
     WHERE o.status IN ('pending', 'accepted')
       AND o.requested_datetime <= ?
     ORDER BY o.requested_datetime ASC
     LIMIT 5"
);
$dashDueStmt->execute([date('Y-m-d 23:59:59')]);
$dashDueOrders = $dashDueStmt->fetchAll();
$dashNow = time();

$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_staff.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <div>
                    <span class="page-header__eyebrow">Staff</span>
                    <h1>Dashboard</h1>
                    <span class="page-header__meta">Signed in as <?= e($_SESSION['username']) ?></span>
                </div>
                <div class="page-header__actions">
                    <a href="/staff/orders.php" class="btn btn--primary">Order Queue</a>
                </div>
            </div>

            <?php // No urgency dots on these tiles -- state is conveyed by
                  // the meta text alone (plain words read reliably for this
                  // user base; a small colored dot doesn't). ?>
            <div class="stat-grid">
                <a class="stat-tile" href="/staff/orders.php?status=pending">
                    <span class="stat-tile__label">Pending Orders</span>
                    <span class="stat-tile__value tabular"><?= $dashPendingCount ?></span>
                    <span class="stat-tile__meta"><?= $dashPendingCount > 0 ? 'Needs action' : 'None pending' ?></span>
                </a>
                <a class="stat-tile" href="/staff/orders.php?status=accepted">
                    <span class="stat-tile__label">Accepted Orders</span>
                    <span class="stat-tile__value tabular"><?= $dashAcceptedCount ?></span>
                    <span class="stat-tile__meta">In progress</span>
                </a>
                <?php // No exact deep-link match -- staff/orders.php only
                      // date-filters on requested_datetime, not created_at --
                      // so this links unfiltered rather than force a mismatched
                      // filter, same honest-partial-match convention used
                      // elsewhere on this page. ?>
                <a class="stat-tile" href="/staff/orders.php">
                    <span class="stat-tile__label">New Today</span>
                    <span class="stat-tile__value tabular"><?= $dashNewTodayCount ?></span>
                    <span class="stat-tile__meta"><?= $dashNewTodayCount > 0 ? 'New today' : 'None yet today' ?></span>
                </a>
                <a class="stat-tile" href="/staff/orders.php">
                    <span class="stat-tile__label">Total Orders</span>
                    <span class="stat-tile__value tabular"><?= $dashTotalCount ?></span>
                    <span class="stat-tile__meta">All labs, all time</span>
                </a>
            </div>

            <?php // Full-width table-card, no side column -- this is a landing
                  // page, not a work surface; staff triage happens on the
                  // Order Queue (/staff/orders.php). Unlike the customer
                  // dashboard's .dash-grid + .dash-stack, there's nothing to
                  // put beside this table, so it isn't wrapped in one. ?>
            <div class="table-card">
                <div class="table-card-header">
                    <span class="table-card-title">Due Today &amp; Overdue</span>
                    <div class="table-card-controls">
                        <a href="/staff/orders.php" class="table-action">View all</a>
                    </div>
                </div>
                <?php if (!$dashDueOrders): ?>
                    <div class="empty-state">
                        <div class="empty-state__icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <div class="empty-state__title">Nothing due today</div>
                        <p class="empty-state__hint">Pending and accepted orders needed today, or already overdue, will show up here.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Timing</th>
                                    <th>Requested</th>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Lab</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dashDueOrders as $o): ?>
                                    <?php
                                    // Two-tier urgency, conveyed as plain text in
                                    // its own column (no dot/color): past
                                    // requested time (any day) = "Overdue", later
                                    // today = "Due today". No neutral/future tier
                                    // -- the query is bounded to end-of-today, so
                                    // every row is one of these two.
                                    $dashDueTs = strtotime($o['requested_datetime']);
                                    $dashIsOverdue = $dashDueTs < $dashNow;
                                    ?>
                                    <tr>
                                        <td class="nowrap"><?= $dashIsOverdue ? 'Overdue' : 'Due today' ?></td>
                                        <td class="tabular nowrap"><?= e(date('M j, Y H:i', $dashDueTs)) ?></td>
                                        <td class="tabular"><?= (int) $o['order_id'] ?></td>
                                        <td><?= e($o['product_name']) ?></td>
                                        <td><?= e($o['lab_name'] ?? '—') ?></td>
                                        <td><span class="badge badge--<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span></td>
                                        <td><a href="/staff/order_detail.php?id=<?= (int) $o['order_id'] ?>" class="table-action">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
