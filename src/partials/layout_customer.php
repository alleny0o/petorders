<?php
// $petordersLayout namespaces every value this layout produces (account
// identity, current page, New Order backing data) so it can never
// silently collide with a page's own same-named variable -- see
// CLAUDE.md's reserved-layout-variables table.
$petordersLayout = layout_account_data((int) $_SESSION['user_id'], $_SESSION['role']);

// $labId itself stays a loose variable, not namespaced: every customer
// page that includes this layout already presets it before the include
// for its own query-scoping (unrelated to the layout), so this is a
// page-owned input the layout optionally reads -- not layout-owned
// output leaking downward. Guarded so a page that already computed it
// (new_order.php, lab_delivery_locations.php, lab_product_users.php,
// order_detail.php) doesn't pay for the lookup twice.
if (!isset($labId)) {
    $labId = current_customer_lab_id(get_db(), (int) $_SESSION['user_id']);
}

// Backing data for the New Order modal below, needed on every customer
// page since the sidebar trigger opens it from anywhere. Guarded so
// get_new_order_form_data() only ever runs once per request.
if (!isset($petordersLayout['nuclides'])) {
    $newOrderFormData = get_new_order_form_data(get_db(), $labId);
    $petordersLayout['nuclides'] = $newOrderFormData['nuclides'];
    $petordersLayout['products'] = $newOrderFormData['products'];
    $petordersLayout['locations'] = $newOrderFormData['locations'];
    $petordersLayout['product_users'] = $newOrderFormData['product_users'];
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

    <div class="sidebar-logo"><img src="/favicons/android-chrome-192x192.png" alt="<?= e(app_setting('app_name')) ?>"></div>
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
          <a href="/customer/dashboard.php" class="menu-link <?= $petordersLayout['current_page'] === 'dashboard' ? 'active' : '' ?>">
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
          <?php // order_detail.php counts as part of the Orders section --
                // the natural path there is Orders -> detail. ?>
          <a href="/customer/orders.php" class="menu-link <?= in_array($petordersLayout['current_page'], ['orders', 'order_detail'], true) ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
              <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Orders</span></span>
          </a>
        </li>

        <li class="menu-item">
          <a href="/customer/lab_delivery_locations.php" class="menu-link <?= $petordersLayout['current_page'] === 'lab_delivery_locations' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Delivery Locations</span></span>
          </a>
        </li>

        <li class="menu-item">
          <a href="/customer/lab_product_users.php" class="menu-link <?= $petordersLayout['current_page'] === 'lab_product_users' ? 'active' : '' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
            </svg>
            <span class="menu-label"><span class="menu-label__text">Product Users</span></span>
          </a>
        </li>

      </ul>
    </nav>
  </div>

  <!-- Sidebar Footer -->
  <div class="sidebar-footer">
    <button type="button" class="sidebar-account" data-my-info-trigger aria-haspopup="dialog">
      <div class="account-avatar"><?= e($petordersLayout['initials']) ?></div>
      <span class="account-name"><?= e($petordersLayout['name']) ?></span>
    </button>

    <div class="sidebar-footer-actions">
      <form method="post" action="/logout.php" class="logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="logout-link" aria-label="Log out">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
        </button>
      </form>
    </div>
  </div>
</aside>

<!-- My Info modal: read-only display of the signed-in customer's own
     profile, opened from the sidebar account block above. Self-service
     editing was removed -- profile changes are admin-only now
     (admin/customer_detail.php); this is view-only, no form. Header +
     X button mirror the New Order modal's chrome exactly -- same
     data-modal-close hookup, which petordersOpenModal (script.js) wires
     automatically alongside Esc and backdrop-click, so no separate
     close logic is needed here. -->
<div class="modal-overlay" id="my-info-modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="my-info-modal-title">
    <div class="modal__header">
      <h2 class="modal__title" id="my-info-modal-title">My Info</h2>
      <button type="button" class="modal__close" data-modal-close aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>
    </div>
    <div class="modal__body">
      <?php // Full name + username live here as a compact identity-card
            // header (avatar reuses the sidebar's own initials) instead
            // of being split into flat rows below -- still the same
            // data as the previous pass, just read at a glance rather
            // than key-value scanned. ?>
      <div class="my-info-identity">
        <div class="my-info-identity__avatar"><?= e($petordersLayout['initials']) ?></div>
        <div>
          <div class="my-info-identity__name"><?= e($petordersLayout['name']) ?></div>
          <div class="my-info-identity__username"><?= e($petordersLayout['account']['username']) ?> (used to log in)</div>
        </div>
      </div>

      <div class="form-section">
        <span class="form-section__title">Contact</span>
        <div class="detail-list">
          <div class="detail-list__row">
            <span class="detail-list__label">Phone</span>
            <span class="detail-list__value tabular"><?= e($petordersLayout['account']['phone'] ?? '—') ?></span>
          </div>
        </div>
      </div>

      <div class="form-section">
        <span class="form-section__title">Affiliation</span>
        <div class="detail-list">
          <div class="detail-list__row">
            <span class="detail-list__label">Lab</span>
            <span class="detail-list__value"><?= e($petordersLayout['account']['lab_name'] ?? '—') ?></span>
          </div>
          <div class="detail-list__row">
            <span class="detail-list__label">Institute</span>
            <span class="detail-list__value"><?= e($petordersLayout['account']['institute_name'] ?? '—') ?></span>
          </div>
          <div class="detail-list__row">
            <span class="detail-list__label">Supervising PI</span>
            <span class="detail-list__value"><?= e($petordersLayout['account']['pi_name'] ?? '—') ?></span>
          </div>
        </div>
      </div>

      <p class="field-hint mt-4 mb-0">Need to update this info? Contact an administrator. To change your password instead, see <a href="/change_password.php">Change Password</a>.</p>
    </div>
    <div class="modal__footer">
      <button type="button" class="btn btn--secondary" data-modal-close>Close</button>
    </div>
  </div>
</div>

<?php
// Included unconditionally: the modal is the only place the order form
// renders (new_order.php is a POST-only JSON endpoint with no page of
// its own), so there is no duplicate-ID risk on any customer page.
include __DIR__ . '/new_order_modal.php';
?>

<script src="<?= asset_url('/assets/js/script.js') ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // My Info triggers are marked with data-my-info-trigger -- same
  // convention as data-new-order-trigger below. The sidebar account
  // block always has one; the dashboard's My Lab card adds another.
  var myInfoModal = document.getElementById('my-info-modal');
  document.querySelectorAll('[data-my-info-trigger]').forEach(function (trigger) {
    trigger.addEventListener('click', function (e) {
      window.petordersOpenModal(myInfoModal, { opener: e.currentTarget });
    });
  });

  // New Order triggers are marked with data-new-order-trigger (orders.php
  // has one in its page header and one in its no-orders empty state); the
  // modal itself is present on every customer page, so a new trigger
  // anywhere just needs the attribute.
  var newOrderModal = document.getElementById('new-order-modal');
  if (newOrderModal) {
    document.querySelectorAll('[data-new-order-trigger]').forEach(function (trigger) {
      trigger.addEventListener('click', function (e) {
        window.petordersOpenModal(newOrderModal, { opener: e.currentTarget });
      });
    });
  }
});
</script>
