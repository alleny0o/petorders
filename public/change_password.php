<?php
require __DIR__ . '/../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';
require_role(['customer', 'staff', 'admin']);

$fieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT username, password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $username = $row['username'];
    $currentHash = $row['password_hash'];

    if (!password_verify($currentPassword, $currentHash)) {
        $fieldErrors['current_password'] = 'Current password is incorrect.';
    }

    $strengthErrors = validate_password_strength($newPassword, $username);
    if ($strengthErrors) {
        $fieldErrors['new_password'] = $strengthErrors;
    }

    if ($newPassword !== $confirmPassword) {
        $fieldErrors['confirm_password'] = 'New password and confirmation do not match.';
    }

    if (!$fieldErrors && is_password_reused($pdo, (int) $_SESSION['user_id'], $currentHash, $newPassword)) {
        $fieldErrors['new_password'] = 'New password must not match any of your last 5 passwords.';
    }

    if (!$fieldErrors) {
        record_password_history($pdo, (int) $_SESSION['user_id'], $currentHash);

        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?')
            ->execute([password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_BCRYPT_COST]), $_SESSION['user_id']]);

        $_SESSION['must_change_password'] = false;

        $dest = dashboard_path_for_role($_SESSION['role']);
        if (request_wants_json()) {
            json_response(['ok' => true, 'redirect' => $dest]);
        }
        redirect($dest);
    }

    if ($fieldErrors && request_wants_json()) {
        json_response(['ok' => false, 'errors' => $fieldErrors], 422);
    }
}

$pageTitle = 'Change Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../src/partials/head.php'; ?>
</head>
<body>
    <div class="auth-wrap">
      <div class="auth-card">
        <div class="auth-card__head">
          <div class="auth-card__brand">
            <div class="auth-card__logo">
              <img src="/favicons/android-chrome-192x192.png" alt="<?= e(app_setting('app_name')) ?>">
            </div>
            <div>
              <div class="auth-card__title"><?= e(app_setting('app_name')) ?></div>
              <div class="auth-card__subtitle">Change Password</div>
            </div>
          </div>
        </div>
        <div class="auth-card__body">

          <?php if (!empty($_SESSION['must_change_password'])): ?>
            <div class="alert alert--info">You're signed in with a temporary password. Set a new one to continue.</div>
          <?php endif; ?>

          <div class="alert alert--error" data-error-banner-for="change-password-form" <?= $fieldErrors ? '' : 'hidden' ?>>Some fields need attention — see the messages below.</div>

          <form method="post" id="change-password-form" novalidate data-ajax-submit>
            <?= csrf_field() ?>

            <div class="<?= field_class($fieldErrors, 'current_password') ?>">
              <label for="current_password">Current password</label>
              <input type="password" id="current_password" name="current_password" required autofocus>
              <?= field_error($fieldErrors, 'current_password') ?>
            </div>

            <div class="<?= field_class($fieldErrors, 'new_password') ?>">
              <label for="new_password">New password</label>
              <input type="password" id="new_password" name="new_password" required minlength="12" maxlength="72">
              <span class="field-hint">At least 12 characters (max 72), with a letter and a number. Must not contain your username or email.</span>
              <span class="field-hint">For security, please do not reuse your NIH network password.</span>
              <span class="field-hint char-count" id="new-password-char-count">0/72</span>
              <?= field_error($fieldErrors, 'new_password') ?>
            </div>

            <div class="<?= field_class($fieldErrors, 'confirm_password') ?>">
              <label for="confirm_password">Confirm new password</label>
              <input type="password" id="confirm_password" name="confirm_password" required>
              <?= field_error($fieldErrors, 'confirm_password') ?>
            </div>

            <button type="submit" class="btn btn--primary btn--lg btn--block">Change Password</button>
          </form>

        </div>
      </div>
    </div>
<script nonce="<?= e(csp_nonce()) ?>">
// DOMContentLoaded (app convention -- script.js, layout partials).
document.addEventListener('DOMContentLoaded', function () {
    // ---- Live character counter for the new password: same pattern as
    // the Notes counters (new_order_form.php, order_detail.php) --
    // plain input listener writing a span's textContent, called once
    // immediately so a bfcache-restored value on browser back/forward
    // reflects the real current length. Purely visual; maxlength and
    // the server-side check in auth.php stay authoritative. ----
    var newPasswordField = document.getElementById('new_password');
    var newPasswordCounter = document.getElementById('new-password-char-count');
    if (newPasswordField && newPasswordCounter) {
        var updateNewPasswordCounter = function () {
            newPasswordCounter.textContent = newPasswordField.value.length + '/' + newPasswordField.maxLength;
        };
        newPasswordField.addEventListener('input', updateNewPasswordCounter);
        updateNewPasswordCounter();
    }
});
</script>
</body>
<script src="<?= asset_url('/assets/js/script.js') ?>" defer></script>
</html>
