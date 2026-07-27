<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin');

$pdo = get_db();

// Read-only overview numbers. "Staff" here means non-admin staff, the
// same split the accounts page uses (admins are staff rows too).
$pendingCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM customer_registration_requests WHERE status = 'pending'"
)->fetchColumn();

$customerCounts = $pdo->query(
    'SELECT COALESCE(SUM(u.active = 1), 0) AS active_count, COUNT(*) AS total_count
     FROM customers c
     JOIN users u ON u.user_id = c.user_id'
)->fetch();

$staffCounts = $pdo->query(
    'SELECT COALESCE(SUM(u.active = 1), 0) AS active_count, COUNT(*) AS total_count
     FROM staff s
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN admins a ON a.user_id = s.user_id
     WHERE a.user_id IS NULL'
)->fetch();

$adminCounts = $pdo->query(
    'SELECT COALESCE(SUM(u.active = 1), 0) AS active_count, COUNT(*) AS total_count
     FROM admins a
     JOIN users u ON u.user_id = a.user_id'
)->fetch();

$pendingPreview = $pdo->query(
    "SELECT r.request_id, r.first_name, r.last_name, r.email, r.submitted_at, l.lab_name
     FROM customer_registration_requests r
     JOIN labs l ON l.lab_id = r.lab_id
     WHERE r.status = 'pending'
     ORDER BY r.submitted_at DESC
     LIMIT 5"
)->fetchAll();

$recentCustomers = $pdo->query(
    'SELECT c.user_id, u.first_name, u.last_name, u.created_at
     FROM customers c
     JOIN users u ON u.user_id = c.user_id
     ORDER BY u.created_at DESC
     LIMIT 5'
)->fetchAll();

$recentLockouts = $pdo->query(
    'SELECT u.username, le.locked_at
     FROM lockout_events le
     JOIN users u ON u.user_id = le.user_id
     WHERE le.locked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     ORDER BY le.locked_at DESC
     LIMIT 5'
)->fetchAll();

$recentRejections = $pdo->query(
    "SELECT first_name, last_name, rejection_reason, reviewed_at
     FROM customer_registration_requests
     WHERE status = 'rejected'
     ORDER BY reviewed_at DESC
     LIMIT 5"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Dashboard'; include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_admin.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <div>
                    <span class="page-header__eyebrow">Admin</span>
                    <h1>Dashboard</h1>
                    <span class="page-header__meta">Signed in as <?= e($_SESSION['username']) ?></span>
                </div>
                <div class="page-header__actions">
                    <?php // New Account's trigger lives on the Accounts page
                          // now (it opens a modal there), not here -- same
                          // convention as customer/dashboard.php's "Go to
                          // Orders" link -- so Review Registrations is the
                          // one primary action left on this page. ?>
                    <a href="/admin/registrations.php" class="btn btn--primary">Review Registrations</a>
                </div>
            </div>

            <div class="stat-grid">
                <a class="stat-tile" href="/admin/registrations.php">
                    <span class="stat-tile__label">Pending Registrations</span>
                    <span class="stat-tile__value tabular"><?= $pendingCount ?></span>
                    <span class="stat-tile__meta"><?= $pendingCount > 0 ? 'Awaiting review' : 'Queue is clear' ?></span>
                </a>
                <a class="stat-tile" href="/admin/customers.php?status=active">
                    <span class="stat-tile__label">Active Customers</span>
                    <span class="stat-tile__value tabular"><?= (int) $customerCounts['active_count'] ?></span>
                    <span class="stat-tile__meta"><?= (int) $customerCounts['total_count'] - (int) $customerCounts['active_count'] ?> inactive</span>
                </a>
                <a class="stat-tile" href="/admin/accounts.php?role=staff&amp;status=active">
                    <span class="stat-tile__label">Active Staff</span>
                    <span class="stat-tile__value tabular"><?= (int) $staffCounts['active_count'] ?></span>
                    <span class="stat-tile__meta"><?= (int) $staffCounts['total_count'] - (int) $staffCounts['active_count'] ?> inactive</span>
                </a>
                <a class="stat-tile" href="/admin/accounts.php?role=admin">
                    <span class="stat-tile__label">Admins</span>
                    <span class="stat-tile__value tabular"><?= (int) $adminCounts['active_count'] ?></span>
                    <span class="stat-tile__meta"><?= (int) $adminCounts['total_count'] - (int) $adminCounts['active_count'] ?> inactive</span>
                </a>
            </div>

            <?php // Plain CSS Grid (no JS masonry), same dash-grid pattern as
                  // customer/dashboard.php. Both columns are .dash-stack --
                  // that class is just "a vertical run of cards inside one
                  // dash-grid column" (dashboard.css), not side-only, so
                  // reusing it for the main column's table-card + card pair
                  // needs no new CSS. Main column: Pending Registrations +
                  // Recently Rejected Registrations directly below it (both
                  // registration-flow panels). Side column: the other two,
                  // unrelated to registrations. Heights are independent, no
                  // measuring, no post-paint reflow. ?>
            <div class="dash-grid">
                <div class="dash-stack">
                    <div class="table-card">
                        <div class="table-card-header">
                            <span class="table-card-title">Pending Registrations</span>
                            <div class="table-card-controls">
                                <a href="/admin/registrations.php" class="table-action">View all</a>
                            </div>
                        </div>
                        <?php if (!$pendingPreview): ?>
                            <div class="empty-state empty-state--compact">
                                <div class="empty-state__icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </div>
                                <div class="empty-state__title">You're all caught up</div>
                                <p class="empty-state__hint">New registration requests will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-scroll">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Lab</th>
                                            <th>Submitted</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingPreview as $r): ?>
                                            <tr>
                                                <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                                <td><?= e($r['lab_name']) ?></td>
                                                <td class="text-sm muted"><?= e(date('M j, g:i A', strtotime($r['submitted_at']))) ?></td>
                                                <td><a href="/admin/registrations.php" class="table-action">Review</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <span class="card__title">Recently Rejected Registrations</span>
                        <?php if (!$recentRejections): ?>
                            <p class="muted text-sm mb-0">No recent rejections.</p>
                        <?php else: ?>
                            <ul class="mini-list">
                                <?php foreach ($recentRejections as $r):
                                    $reason = trim((string) $r['rejection_reason']);
                                    if ($reason !== '' && mb_strlen($reason) > 60) {
                                        $reason = mb_substr($reason, 0, 60) . '…';
                                    }
                                ?>
                                    <li>
                                        <span class="mini-list__main"><?= e($r['first_name'] . ' ' . $r['last_name']) ?><?= $reason !== '' ? ': ' . e($reason) : '' ?></span>
                                        <span class="mini-list__meta"><?= e(date('M j, Y', strtotime($r['reviewed_at']))) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dash-stack">
                    <div class="card">
                        <div class="card__header">
                            <span class="card__title">Recently Added Customers</span>
                            <a href="/admin/customers.php" class="table-action">View all</a>
                        </div>
                        <?php if (!$recentCustomers): ?>
                            <p class="muted text-sm mb-0">No customers yet. Approve a registration to create one.</p>
                        <?php else: ?>
                            <ul class="mini-list">
                                <?php foreach ($recentCustomers as $c): ?>
                                    <li>
                                        <a class="mini-list__main" href="/admin/customer_detail.php?id=<?= (int) $c['user_id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></a>
                                        <span class="mini-list__meta"><?= e(date('M j, Y', strtotime($c['created_at']))) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <span class="card__title">Lockouts: Past 7 Days</span>
                        <?php if (!$recentLockouts): ?>
                            <p class="muted text-sm mb-0">No accounts have been locked out recently.</p>
                        <?php else: ?>
                            <ul class="mini-list">
                                <?php foreach ($recentLockouts as $l): ?>
                                    <li>
                                        <span class="mini-list__main"><?= e($l['username']) ?></span>
                                        <span class="mini-list__meta"><?= e(date('M j, g:i A', strtotime($l['locked_at']))) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
