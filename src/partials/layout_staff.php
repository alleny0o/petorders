<?php
// $petordersLayout namespaces every value this layout produces (account
// identity, current page) so it can never silently collide with a
// page's own same-named variable -- see CLAUDE.md's
// reserved-layout-variables table.
$petordersLayout = layout_account_data((int) $_SESSION['user_id'], $_SESSION['role']);

// The profile-edit modal below always redirects back here, tagging the
// outcome via a query flag (no session-flash mechanism in this app —
// mirrors the ?placed=1 pattern in customer/order_detail.php).
if (($_GET['profile_updated'] ?? null) === '1') {
    echo toast_flash('success', 'Profile updated.');
} elseif (($_GET['profile_error'] ?? null) === '1') {
    echo toast_flash('error', 'Please check your profile details and try again.');
}
?>
<!-- App topbar: always present (see layout/sidebar.css). The
     hamburger button inside it is the only mobile-specific part. -->
<div class="app-topbar u-mobile-only">
  <button class="hamburger-toggle" type="button" aria-label="Open menu">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <line x1="3" y1="6" x2="21" y2="6"></line>
      <line x1="3" y1="12" x2="21" y2="12"></line>
      <line x1="3" y1="18" x2="21" y2="18"></line>
    </svg>
  </button>
</div>

<!-- Backdrop: only shown while the mobile sidebar is open -->
<div class="sidebar-backdrop"></div>

<aside class="sidebar">
  <!-- Sidebar Header -->
  <header class="sidebar-header">

    <div class="sidebar-logo"><a href="/staff/dashboard.php"><img src="/favicons/android-chrome-192x192.png" alt="<?= e(app_setting('app_name')) ?>"></a></div>
    <button class="sidebar-toggle">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="15 18 9 12 15 6"></polyline>
      </svg>
    </button>

  </header>

  <!-- Sidebar Content -->
  <div class="sidebar-content">
    <nav class="sidebar-nav">
      <ul class="menu-list">

        <li class="menu-item">
          <a href="/staff/dashboard.php" class="menu-link <?= $petordersLayout['current_page'] === 'dashboard' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="3" width="7" height="7"></rect>
              <rect x="14" y="3" width="7" height="7"></rect>
              <rect x="14" y="14" width="7" height="7"></rect>
              <rect x="3" y="14" width="7" height="7"></rect>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Dashboard</span></span>
          </a>
        </li>

        <li class="menu-item">
          <a href="/staff/orders.php" class="menu-link <?= in_array($petordersLayout['current_page'], ['orders', 'order_detail'], true) ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
              <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Order Queue</span></span>
          </a>
        </li>

      </ul>
    </nav>
  </div>

  <?php if (($_SESSION['role'] ?? null) === 'admin'): ?>
  <div class="sidebar-mode-toggle">
    <a href="/admin/dashboard.php" class="sidebar-mode-toggle__option">Admin</a>
    <a href="/staff/dashboard.php" class="sidebar-mode-toggle__option is-active">Staff</a>
  </div>
  <?php endif; ?>

  <?php include __DIR__ . '/_sidebar_footer.php'; ?>
