<?php
require __DIR__ . '/../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    redirect(dashboard_path_for_role($_SESSION['role']));
}

$email = '';
$searched = false;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $searched = true;

    if ($email !== '') {
        // Only ever reads customer_registration_requests — never users/
        // customers — so this page can't be used to enumerate whether an
        // approved account exists for a given email (same principle as the
        // Phase B login hardening). rejection_reason IS shown here: a
        // deliberate reversal of Security review #57, which withheld it
        // from this unauthenticated page. Revisited the same way as the
        // #96 lockout-countdown restore — the app is intranet-only behind
        // badge access, the reason is admin-authored under a no-PHI hint
        // (admin/registrations.php's reject modal), and it renders
        // e()-escaped. Don't re-hide it without revisiting that decision.
        $stmt = get_db()->prepare(
            'SELECT status, rejection_reason
             FROM customer_registration_requests
             WHERE email = ?
             ORDER BY request_id DESC
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $result = $stmt->fetch() ?: null;
    }
}

$pageTitle = 'Registration Status';
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
              <div class="auth-card__subtitle">Registration Status</div>
            </div>
          </div>
        </div>
        <div class="auth-card__body">

          <?php if ($searched): ?>
            <?php if ($result === null): ?>
              <div class="alert alert--warning"><strong>No registration found</strong> for this email. Check the address, or <a href="/register.php">register here</a>.</div>
            <?php elseif ($result['status'] === 'pending'): ?>
              <div class="alert alert--warning">
                <div><span class="badge badge--pending">Pending</span></div>
                <div>Your registration is awaiting review. An administrator will contact you.</div>
              </div>
            <?php elseif ($result['status'] === 'rejected'): ?>
              <div class="alert alert--error">
                <div><span class="badge badge--rejected">Rejected</span></div>
                <div>Your registration was not approved.</div>
                <?php // Reason is required on reject, but the column is nullable — guard anyway. ?>
                <?php if (!empty($result['rejection_reason'])): ?>
                  <div><strong>Reason:</strong> <?= e($result['rejection_reason']) ?></div>
                <?php endif; ?>
                <div>You may submit a new registration if you'd like, or contact an administrator if you have questions.</div>
              </div>
            <?php elseif ($result['status'] === 'approved'): ?>
              <div class="alert alert--success">
                <div><span class="badge badge--approved">Approved</span></div>
                <div>Your login details come from an administrator via NIH email. If you haven't received them, please contact an administrator.</div>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <form method="post" novalidate>
            <?= csrf_field() ?>

            <div class="field">
              <label for="email">Email used at registration</label>
              <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
            </div>

            <button type="submit" class="btn btn--primary btn--lg btn--block">Check Status</button>
          </form>

        </div>
        <div class="auth-card__foot">
          Already have an account? <a href="/login.php">Log in</a>
        </div>
      </div>
    </div>
</body>
<script src="<?= asset_url('/assets/js/script.js') ?>" defer></script>
</html>
