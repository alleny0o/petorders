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
        // Phase B login hardening).
        //
        // SECURITY (finding M4): rejection_reason is no longer selected or
        // displayed. It was withheld by security review #57, then restored on
        // the reasoning that the app is intranet-only behind badge access.
        // That reasoning is a deployment precondition, not a property of the
        // code: the field is admin-authored free text on an UNAUTHENTICATED
        // page, so the only thing keeping PHI or third-party detail out of it
        // is admin discipline plus a UI hint. Escaping prevents XSS; it does
        // not prevent disclosure. Rejected applicants are directed to an
        // administrator instead. See docs/ARCHITECTURE.md "Threat model".
        $stmt = get_db()->prepare(
            'SELECT status
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
                <?php // Reason intentionally not shown on this unauthenticated page (finding M4). ?>
                <div>Please contact an administrator for details. You may also submit a new registration.</div>
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
