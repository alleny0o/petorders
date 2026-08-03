<?php
// $petordersLayout namespaces every value this layout produces (account
// identity, current page, submenu expand/active state) so it can never
// silently collide with a page's own same-named variable -- see
// CLAUDE.md's reserved-layout-variables table.
$petordersLayout = layout_account_data((int) $_SESSION['user_id'], $_SESSION['role']);

// The profile-edit modal below always redirects back here, tagging the
// outcome via a query flag (no session-flash mechanism in this app —
// mirrors the ?placed=1 pattern in customer/order_detail.php).
if (($_GET['profile_updated'] ?? null) === '1') {
    echo toast_flash('success', 'Profile updated.');
} elseif (($_GET['profile_error'] ?? null) === '1') {
    echo toast_flash('error', 'Please check your profile details and try again.');
}

// The three account-workflow pages now live under one expandable
// "Accounts" parent — expand it server-side when we're already on one
// of them, so the correct state renders on first paint with no JS.
$petordersLayout['accounts_child_pages'] = ['registrations', 'customers', 'customer_detail', 'accounts', 'account_detail'];
$petordersLayout['accounts_section_active'] = in_array($petordersLayout['current_page'], $petordersLayout['accounts_child_pages'], true);

// Catalog pages live under their own expandable parent, same mechanism
// as Accounts above.
$petordersLayout['catalog_child_pages'] = ['nuclides', 'products'];
$petordersLayout['catalog_section_active'] = in_array($petordersLayout['current_page'], $petordersLayout['catalog_child_pages'], true);

// Directory pages (Institutes / Labs / PIs), same mechanism again.
$petordersLayout['directory_child_pages'] = ['labs', 'institutes', 'pis'];
$petordersLayout['directory_section_active'] = in_array($petordersLayout['current_page'], $petordersLayout['directory_child_pages'], true);
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

    <div class="sidebar-logo"><a href="/admin/dashboard.php"><img src="/favicons/android-chrome-192x192.png" alt="<?= e(app_setting('app_name')) ?>"></a></div>
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
          <a href="/admin/dashboard.php" class="menu-link <?= $petordersLayout['current_page'] === 'dashboard' ? 'active' : '' ?>">
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
          <a href="/admin/reports.php" class="menu-link <?= $petordersLayout['current_page'] === 'reports' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="20" x2="18" y2="10"></line>
              <line x1="12" y1="20" x2="12" y2="4"></line>
              <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Reports</span></span>
          </a>
        </li>

        <li class="menu-item menu-item--has-submenu <?= $petordersLayout['accounts_section_active'] ? 'is-expanded' : '' ?>">
          <button type="button" class="menu-link <?= $petordersLayout['accounts_section_active'] ? 'menu-link--section-active' : '' ?>" aria-expanded="<?= $petordersLayout['accounts_section_active'] ? 'true' : 'false' ?>" aria-controls="accounts-submenu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Accounts</span></span>
            <svg class="menu-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
          </button>
          <div class="submenu-wrapper">
            <ul class="submenu" id="accounts-submenu">
              <li>
                <a href="/admin/registrations.php" class="submenu-link <?= $petordersLayout['current_page'] === 'registrations' ? 'active' : '' ?>">Pending Registrations</a>
              </li>
              <li>
                <a href="/admin/customers.php" class="submenu-link <?= in_array($petordersLayout['current_page'], ['customers', 'customer_detail'], true) ? 'active' : '' ?>">Customers</a>
              </li>
              <li>
                <a href="/admin/accounts.php" class="submenu-link <?= in_array($petordersLayout['current_page'], ['accounts', 'account_detail'], true) ? 'active' : '' ?>">Staff &amp; Admins</a>
              </li>
            </ul>
          </div>
        </li>

        <li class="menu-item menu-item--has-submenu <?= $petordersLayout['catalog_section_active'] ? 'is-expanded' : '' ?>">
          <button type="button" class="menu-link <?= $petordersLayout['catalog_section_active'] ? 'menu-link--section-active' : '' ?>" aria-expanded="<?= $petordersLayout['catalog_section_active'] ? 'true' : 'false' ?>" aria-controls="catalog-submenu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
              <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Catalog</span></span>
            <svg class="menu-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
          </button>
          <div class="submenu-wrapper">
            <ul class="submenu" id="catalog-submenu">
              <li>
                <a href="/admin/nuclides.php" class="submenu-link <?= $petordersLayout['current_page'] === 'nuclides' ? 'active' : '' ?>">Nuclides</a>
              </li>
              <li>
                <a href="/admin/products.php" class="submenu-link <?= $petordersLayout['current_page'] === 'products' ? 'active' : '' ?>">Products</a>
              </li>
            </ul>
          </div>
        </li>

        <li class="menu-item menu-item--has-submenu <?= $petordersLayout['directory_section_active'] ? 'is-expanded' : '' ?>">
          <button type="button" class="menu-link <?= $petordersLayout['directory_section_active'] ? 'menu-link--section-active' : '' ?>" aria-expanded="<?= $petordersLayout['directory_section_active'] ? 'true' : 'false' ?>" aria-controls="directory-submenu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Directory</span></span>
            <svg class="menu-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
          </button>
          <?php // Institutes first: parent entity, then its labs, then PIs. ?>
          <div class="submenu-wrapper">
            <ul class="submenu" id="directory-submenu">
              <li>
                <a href="/admin/institutes.php" class="submenu-link <?= $petordersLayout['current_page'] === 'institutes' ? 'active' : '' ?>">Institutes</a>
              </li>
              <li>
                <a href="/admin/labs.php" class="submenu-link <?= $petordersLayout['current_page'] === 'labs' ? 'active' : '' ?>">Labs</a>
              </li>
              <li>
                <a href="/admin/pis.php" class="submenu-link <?= $petordersLayout['current_page'] === 'pis' ? 'active' : '' ?>">PIs</a>
              </li>
            </ul>
          </div>
        </li>

      </ul>
    </nav>
  </div>

  <?php // Always rendered: every page including this layout is behind
        // require_role('admin'). (layout_staff.php's same-shaped check IS
        // meaningful there -- staff pages are admin-reachable.) ?>
  <div class="sidebar-mode-toggle">
    <a href="/admin/dashboard.php" class="sidebar-mode-toggle__option is-active">Admin</a>
    <a href="/staff/dashboard.php" class="sidebar-mode-toggle__option">Staff</a>
  </div>

  <?php include __DIR__ . '/_sidebar_footer.php'; ?>
