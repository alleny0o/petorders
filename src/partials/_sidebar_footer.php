<?php
/**
 * Shared sidebar footer (account button + profile-edit modal + its init
 * script) for the staff and admin layouts -- byte-identical between the
 * two before this extraction. Included (not called), same convention as
 * table_pagination.php: reads $petordersLayout directly from the caller's
 * scope rather than taking parameters.
 */
?>
<!-- Sidebar Footer -->
<div class="sidebar-footer">
  <button type="button" class="sidebar-account" id="profile-edit-trigger" aria-haspopup="dialog">
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

<!-- Profile edit modal: self-service first/last name edit, opened from
     the sidebar account block above. Separate from admin/account_detail.php,
     which edits *other* accounts -- this always targets $_SESSION['user_id']. -->
<div class="modal-overlay" id="profile-edit-modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="profile-edit-modal-title">
    <form method="post" action="/account_profile.php">
      <?= csrf_field() ?>
      <input type="hidden" name="redirect_to" id="profile-redirect-to" value="">
      <div class="modal__body">
        <h2 class="modal__title" id="profile-edit-modal-title">Edit profile</h2>
        <div class="field-row">
          <div class="field">
            <label for="profile-first-name">First name <span class="required-mark">*</span></label>
            <input type="text" id="profile-first-name" name="first_name" value="<?= e($petordersLayout['account']['first_name']) ?>" required data-modal-focus>
          </div>
          <div class="field">
            <label for="profile-last-name">Last name <span class="required-mark">*</span></label>
            <input type="text" id="profile-last-name" name="last_name" value="<?= e($petordersLayout['account']['last_name']) ?>" required>
          </div>
        </div>
        <div class="field">
          <label for="profile-phone">Phone <span class="required-mark">*</span></label>
          <input type="text" id="profile-phone" name="phone" value="<?= e($petordersLayout['account']['phone'] ?? '') ?>" required>
        </div>
        <p class="field-hint mb-0">Need to update your password? <a href="/change_password.php">Change Password</a></p>
      </div>
      <div class="modal__footer">
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= asset_url('/assets/js/script.js') ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var trigger = document.getElementById('profile-edit-trigger');
  var modal = document.getElementById('profile-edit-modal');
  var redirectInput = document.getElementById('profile-redirect-to');

  trigger.addEventListener('click', function (e) {
    redirectInput.value = window.location.pathname + window.location.search;
    window.petordersOpenModal(modal, { opener: e.currentTarget });
  });
});
</script>
