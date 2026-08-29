<?php
require __DIR__ . '/../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
  redirect(dashboard_path_for_role($_SESSION['role']));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();

  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  // Per-IP throttle (finding H1). The per-account lockout in attempt_login()
  // cannot see one source spraying attempts across many usernames, and it is
  // itself abusable as a denial-of-service against known accounts. This caps
  // total failures per source regardless of which account is targeted, and is
  // checked BEFORE attempt_login() so a blocked source cannot keep driving
  // other people's accounts into lockout.
  if (throttle_is_blocked(get_db(), 'login')) {
    $error = 'Too many failed sign-in attempts from this location. Please wait a few minutes and try again.';
    if (request_wants_json()) {
      json_response(['ok' => false, 'message' => $error], 429);
    }
    http_response_code(429);
  } else {
    $result = attempt_login($username, $password);

    if ($result['success']) {
      throttle_clear(get_db(), 'login');
      $dest = dashboard_path_for_role($_SESSION['role']);
      if (request_wants_json()) {
        json_response(['ok' => true, 'redirect' => $dest]);
      }
      redirect($dest);
    }

    throttle_record(get_db(), 'login', THROTTLE_LOGIN_MAX);

    $error = $result['reason'];
    if (request_wants_json()) {
      json_response(['ok' => false, 'message' => $error], 422);
    }
  }
}

$pageTitle = 'Log In';
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
            <div class="auth-card__subtitle">Sign In</div>
          </div>
        </div>
      </div>
      <div class="auth-card__body">

        <div class="alert alert--error" data-ajax-error<?= $error ? '' : ' hidden' ?>><?= $error ? e($error) : '' ?></div>

        <form method="post" id="login-form" novalidate data-ajax-submit data-ajax-inline-error data-reset-on-error>
          <?= csrf_field() ?>

          <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="" required autofocus>
          </div>

          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" value="" required>
          </div>

          <button type="submit" class="btn btn--primary btn--lg btn--block">Log In</button>
        </form>

      </div>
      <div class="auth-card__foot">
        <div>New customer? <a href="/register.php">Register here</a></div>
        <div>Already registered? <a href="/registration_status.php">Check your status</a></div>
      </div>
    </div>
  </div>
</body>
<script src="<?= asset_url('/assets/js/script.js') ?>" defer></script>

</html>