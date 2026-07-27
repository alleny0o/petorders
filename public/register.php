<?php
require __DIR__ . '/../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/auth.php';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    redirect(dashboard_path_for_role($_SESSION['role']));
}

$fieldErrors = [];
$submitted = (($_GET['submitted'] ?? '') === '1');
$old = [
    'institute_id'      => '',
    'lab_id'            => '',
    'first_name'        => '',
    'last_name'         => '',
    'email'             => '',
    'phone'             => '',
    'pi_id'             => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['institute_id']      = $_POST['institute_id'] ?? '';
    $old['lab_id']            = $_POST['lab_id'] ?? '';
    $old['first_name']        = trim($_POST['first_name'] ?? '');
    $old['last_name']         = trim($_POST['last_name'] ?? '');
    $old['email']             = trim($_POST['email'] ?? '');
    $old['phone']             = trim($_POST['phone'] ?? '');
    $old['pi_id']             = $_POST['pi_id'] ?? '';

    if ($old['institute_id'] === '') {
        $fieldErrors['institute_id'] = 'Institute is required.';
    }
    if ($old['lab_id'] === '') {
        $fieldErrors['lab_id'] = 'Lab is required.';
    }
    if ($old['first_name'] === '') {
        $fieldErrors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($old['first_name']) > 100) {
        $fieldErrors['first_name'] = 'First name must be 100 characters or fewer.';
    }
    if ($old['last_name'] === '') {
        $fieldErrors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($old['last_name']) > 100) {
        $fieldErrors['last_name'] = 'Last name must be 100 characters or fewer.';
    }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'A valid email is required.';
    } elseif (mb_strlen($old['email']) > 50) {
        // 50, not the column's 254: on approval this email becomes
        // users.username, which is VARCHAR(50).
        $fieldErrors['email'] = 'Email must be 50 characters or fewer.';
    }
    if ($old['phone'] === '') {
        $fieldErrors['phone'] = 'Phone is required.';
    } elseif (!preg_match('/^[0-9()+.\-\s]+$/', $old['phone']) || !preg_match('/[0-9]/', $old['phone'])) {
        $fieldErrors['phone'] = 'Phone must contain only digits, spaces, dashes, parentheses, and an optional leading +.';
    } elseif (mb_strlen($old['phone']) > 20) {
        $fieldErrors['phone'] = 'Phone must be 20 characters or fewer.';
    }
    if ($old['pi_id'] === '') {
        $fieldErrors['pi_id'] = 'Supervising PI is required.';
    }

    $pdo = get_db();

    if (!$fieldErrors) {
        $stmt = $pdo->prepare('SELECT 1 FROM institutes WHERE institute_id = ? AND active = 1');
        $stmt->execute([$old['institute_id']]);
        if (!$stmt->fetchColumn()) {
            $fieldErrors['institute_id'] = 'Select a valid institute.';
        }
    }
    if (!$fieldErrors) {
        $stmt = $pdo->prepare('SELECT 1 FROM labs WHERE lab_id = ? AND institute_id = ? AND active = 1');
        $stmt->execute([$old['lab_id'], $old['institute_id']]);
        if (!$stmt->fetchColumn()) {
            $fieldErrors['lab_id'] = 'Select a valid lab for the chosen institute.';
        }
    }
    if (!$fieldErrors) {
        // PI must be active AND actually linked to the chosen lab via
        // lab_pis — the client-side filter narrows the dropdown to this
        // same set, this is just the server-side backstop.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM pis
             JOIN lab_pis ON lab_pis.pi_id = pis.pi_id
             WHERE pis.pi_id = ? AND pis.active = 1 AND lab_pis.lab_id = ?'
        );
        $stmt->execute([$old['pi_id'], $old['lab_id']]);
        if (!$stmt->fetchColumn()) {
            $fieldErrors['pi_id'] = 'Select a valid supervising PI for the chosen lab.';
        }
    }
    if (!$fieldErrors) {
        // Duplicate prevention: a pending request already exists, or an
        // account already exists. A rejected prior request does not
        // block resubmission. These are the only two account-existence
        // signals shown to an unauthenticated visitor -- yes, this lets a
        // visitor enumerate which emails are registered (SECURITY_AUDIT.md
        // 5.3). Reviewed deliberately in the Batch 4 security pass and
        // kept as-is: the distinct messages have real self-service UX
        // value (a genuine user can tell whether to wait for review or
        // just log in) and this is an acceptable trade-off on an internal
        // intranet app. Formally accepted risk, not an oversight.
        $stmt = $pdo->prepare("SELECT 1 FROM customer_registration_requests WHERE email = ? AND status = 'pending'");
        $stmt->execute([$old['email']]);
        if ($stmt->fetchColumn()) {
            $fieldErrors['email'] = 'A registration for this email is already pending.';
        }
    }
    if (!$fieldErrors) {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND active = 1');
        $stmt->execute([$old['email']]);
        if ($stmt->fetchColumn()) {
            $fieldErrors['email'] = 'An account already exists for this email.';
        }
    }

    if (!$fieldErrors) {
        $pdo->prepare(
            "INSERT INTO customer_registration_requests
                (lab_id, pi_id, first_name, last_name, email, phone, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $old['lab_id'],
            $old['pi_id'],
            $old['first_name'],
            $old['last_name'],
            $old['email'],
            $old['phone'],
        ]);

        if (request_wants_json()) {
            json_response(['ok' => true, 'redirect' => '/register.php?submitted=1']);
        }
        redirect('/register.php?submitted=1');
    }

    if ($fieldErrors && request_wants_json()) {
        json_response(['ok' => false, 'errors' => $fieldErrors], 422);
    }
}

$pdo = get_db();
$institutes = $pdo->query('SELECT institute_id, name FROM institutes WHERE active = 1 ORDER BY name')->fetchAll();
// Effective availability, computed at read time like the catalog's
// nuclide/product gate: a lab is offered iff its own flag AND its
// institute's flag are on. The cascading UI and the validation above
// already made labs under inactive institutes unreachable; this filters
// them out of the shipped option data too.
$labs = $pdo->query(
    'SELECT l.lab_id, l.institute_id, l.lab_name
     FROM labs l
     JOIN institutes i ON i.institute_id = l.institute_id AND i.active = 1
     WHERE l.active = 1
     ORDER BY l.lab_name'
)->fetchAll();
$pis = $pdo->query('SELECT pi_id, pi_name, email FROM pis WHERE active = 1 ORDER BY pi_name')->fetchAll();
$labPiMap = $pdo->query(
    'SELECT lab_pis.lab_id, lab_pis.pi_id
     FROM lab_pis
     JOIN pis ON pis.pi_id = lab_pis.pi_id
     WHERE pis.active = 1'
)->fetchAll();

// Build pi_id => "lab_id lab_id …" for the client-side filter, mirroring
// the institute_id -> lab_id filter below (a PI can oversee multiple labs).
$piLabIds = [];
foreach ($labPiMap as $row) {
    $piLabIds[$row['pi_id']][] = $row['lab_id'];
}

$pageTitle = 'Register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../src/partials/head.php'; ?>
</head>
<body>
    <div class="auth-wrap">
      <div class="auth-card auth-card--wide">
        <div class="auth-card__head">
          <div class="auth-card__brand">
            <div class="auth-card__logo">
              <img src="/favicons/android-chrome-192x192.png" alt="<?= e(app_setting('app_name')) ?>">
            </div>
            <div>
              <div class="auth-card__title"><?= e(app_setting('app_name')) ?></div>
              <div class="auth-card__subtitle">Customer Registration</div>
            </div>
          </div>
        </div>
        <div class="auth-card__body">

          <?php if ($submitted): ?>
            <div class="success-panel">
              <div class="success-panel__icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
              </div>
              <div class="success-panel__title">Registration submitted</div>
              <p class="success-panel__text">An administrator will review your request. Once approved, your login details arrive by NIH email.</p>
              <div class="success-panel__action">
                <a href="/registration_status.php" class="btn btn--secondary">Check your status</a>
              </div>
            </div>
          <?php else: ?>

            <div class="alert alert--error" data-error-banner-for="register-form" <?= $fieldErrors ? '' : 'hidden' ?>>Some fields need attention — see the messages below.</div>

            <form method="post" id="register-form" novalidate data-ajax-submit>
              <?= csrf_field() ?>

              <div class="form-section">
                <span class="form-section__title">Institute &amp; Lab</span>

                <div class="<?= field_class($fieldErrors, 'institute_id') ?>">
                  <label for="institute_id">Institute <span class="required-mark">*</span></label>
                  <select id="institute_id" name="institute_id" required>
                    <option value="">Select institute…</option>
                    <?php foreach ($institutes as $institute): ?>
                      <option value="<?= (int) $institute['institute_id'] ?>" <?= (string) $institute['institute_id'] === $old['institute_id'] ? 'selected' : '' ?>><?= e($institute['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?= field_error($fieldErrors, 'institute_id') ?>
                </div>

                <div class="<?= field_class($fieldErrors, 'lab_id', 'field mb-0') ?>">
                  <label for="lab_id">Lab <span class="required-mark">*</span></label>
                  <select id="lab_id" name="lab_id" required>
                    <option value="">Select institute first…</option>
                    <?php foreach ($labs as $lab): ?>
                      <option value="<?= (int) $lab['lab_id'] ?>" data-institute-id="<?= (int) $lab['institute_id'] ?>" <?= (string) $lab['lab_id'] === $old['lab_id'] ? 'selected' : '' ?>><?= e($lab['lab_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <span class="field-hint">Don't see your lab? Contact an admin.</span>
                  <?= field_error($fieldErrors, 'lab_id') ?>
                </div>
              </div>

              <div class="form-section">
                <span class="form-section__title">Investigator</span>

                <div class="field-row">
                  <div class="<?= field_class($fieldErrors, 'first_name') ?>">
                    <label for="first_name">First name <span class="required-mark">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name']) ?>" required>
                    <?= field_error($fieldErrors, 'first_name') ?>
                  </div>
                  <div class="<?= field_class($fieldErrors, 'last_name') ?>">
                    <label for="last_name">Last name <span class="required-mark">*</span></label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name']) ?>" required>
                    <?= field_error($fieldErrors, 'last_name') ?>
                  </div>
                </div>

                <div class="field-row">
                  <div class="<?= field_class($fieldErrors, 'email') ?>">
                    <label for="email">Email <span class="required-mark">*</span></label>
                    <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
                    <span class="field-hint">This becomes your username.</span>
                    <?= field_error($fieldErrors, 'email') ?>
                  </div>
                  <div class="<?= field_class($fieldErrors, 'phone') ?>">
                    <label for="phone">Phone <span class="required-mark">*</span></label>
                    <input type="text" id="phone" name="phone" value="<?= e($old['phone']) ?>" required>
                    <?= field_error($fieldErrors, 'phone') ?>
                  </div>
                </div>

                <div class="<?= field_class($fieldErrors, 'pi_id', 'field mb-0') ?>">
                  <label for="pi_id">Supervising PI <span class="required-mark">*</span></label>
                  <select id="pi_id" name="pi_id" required>
                    <option value="">Select lab first…</option>
                    <?php foreach ($pis as $pi): ?>
                      <option value="<?= (int) $pi['pi_id'] ?>" data-lab-ids="<?= e(implode(' ', $piLabIds[$pi['pi_id']] ?? [])) ?>" <?= (string) $pi['pi_id'] === $old['pi_id'] ? 'selected' : '' ?>><?= e($pi['pi_name']) ?> (<?= e($pi['email']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                  <span class="field-hint">Don't see your PI? Contact an admin.</span>
                  <?= field_error($fieldErrors, 'pi_id') ?>
                </div>
              </div>

              <button type="submit" class="btn btn--primary btn--lg btn--block">Submit Registration</button>
            </form>

          <?php endif; ?>

        </div>
        <div class="auth-card__foot">
          Already have an account? <a href="/login.php">Log in</a>
        </div>
      </div>
    </div>
</body>
<script src="<?= asset_url('/assets/js/script.js') ?>" defer></script>
<script>
(function () {
  var instituteSelect = document.getElementById('institute_id');
  var labSelect = document.getElementById('lab_id');
  var piSelect = document.getElementById('pi_id');
  if (!instituteSelect || !labSelect || !piSelect) return;

  var labOptions = Array.prototype.slice.call(labSelect.querySelectorAll('option[data-institute-id]'));
  var piOptions = Array.prototype.slice.call(piSelect.querySelectorAll('option[data-lab-ids]'));

  function filterLabs() {
    var instituteId = instituteSelect.value;
    labOptions.forEach(function (opt) {
      var matches = opt.dataset.instituteId === instituteId;
      opt.hidden = !matches;
      opt.disabled = !matches;
    });
    if (labSelect.selectedOptions[0] && labSelect.selectedOptions[0].hidden) {
      labSelect.value = '';
    }
    labSelect.disabled = !instituteId;
    filterPis();
  }

  function filterPis() {
    var labId = labSelect.value;
    piOptions.forEach(function (opt) {
      var labIds = opt.dataset.labIds ? opt.dataset.labIds.split(' ') : [];
      var matches = labIds.indexOf(labId) !== -1;
      opt.hidden = !matches;
      opt.disabled = !matches;
    });
    if (piSelect.selectedOptions[0] && piSelect.selectedOptions[0].hidden) {
      piSelect.value = '';
    }
    piSelect.disabled = !labId;
  }

  instituteSelect.addEventListener('change', filterLabs);
  labSelect.addEventListener('change', filterPis);
  filterLabs();
})();
</script>
</html>
