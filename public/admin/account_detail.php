<?php
require __DIR__ . '/../../src/helpers.php';
bootstrap_session();
require __DIR__ . '/../../src/auth.php';
require_role('admin');

$pdo = get_db();

/**
 * Single-use temp password: doesn't need to satisfy the full strength
 * policy (validate_password_strength()) since it's never kept -- the
 * account is forced to change it on first login. Same helper as
 * registrations.php / customer_detail.php; not shared out to
 * src/helpers.php for three call sites.
 */
function generate_temp_password(): string
{
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(16))), 0, 16);
}

function fetch_account(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.username, u.active, u.created_at,
                u.first_name, u.last_name, u.phone,
                (a.user_id IS NOT NULL) AS is_admin
         FROM staff s
         JOIN users u ON u.user_id = s.user_id
         LEFT JOIN admins a ON a.user_id = s.user_id
         WHERE s.user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

$userId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$account = $userId > 0 ? fetch_account($pdo, $userId) : null;
$isSelf = $account !== null && $userId === (int) $_SESSION['user_id'];
// Same server-side edit toggle as customer/order_detail.php's Order
// Details card: ?edit=1 swaps the read-only Profile card for the form.
// The form's action URL keeps edit=1 so a no-JS validation-error
// re-render lands back in editing state.
$editing = $account !== null && ($_GET['edit'] ?? null) === '1';

$flash = null;
$profileErrors = [];
$tempPasswordReveal = null;

$profileOld = $account !== null
    ? ['first_name' => $account['first_name'], 'last_name' => $account['last_name'], 'phone' => $account['phone'] ?? '', 'email' => $account['username']]
    : ['first_name' => '', 'last_name' => '', 'phone' => '', 'email' => ''];

if ($account !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'edit_profile') {
        $profileOld['first_name'] = trim($_POST['first_name'] ?? '');
        $profileOld['last_name'] = trim($_POST['last_name'] ?? '');
        $profileOld['phone'] = trim($_POST['phone'] ?? '');
        $profileOld['email'] = trim($_POST['email'] ?? '');

        if ($profileOld['first_name'] === '') {
            $profileErrors['first_name'] = 'First name is required.';
        } elseif (mb_strlen($profileOld['first_name']) > 100) {
            $profileErrors['first_name'] = 'First name must be 100 characters or fewer.';
        }
        if ($profileOld['last_name'] === '') {
            $profileErrors['last_name'] = 'Last name is required.';
        } elseif (mb_strlen($profileOld['last_name']) > 100) {
            $profileErrors['last_name'] = 'Last name must be 100 characters or fewer.';
        }
        if ($profileOld['phone'] === '') {
            $profileErrors['phone'] = 'Phone is required.';
        } elseif (!preg_match('/^[0-9()+.\-\s]+$/', $profileOld['phone']) || !preg_match('/[0-9]/', $profileOld['phone'])) {
            $profileErrors['phone'] = 'Phone must contain only digits, spaces, dashes, parentheses, and an optional leading +.';
        } elseif (mb_strlen($profileOld['phone']) > 20) {
            $profileErrors['phone'] = 'Phone must be 20 characters or fewer.';
        }
        if ($profileOld['email'] === '' || !filter_var($profileOld['email'], FILTER_VALIDATE_EMAIL)) {
            $profileErrors['email'] = 'A valid email is required.';
        } elseif (mb_strlen($profileOld['email']) > 50) {
            // The email becomes users.username, which is VARCHAR(50).
            $profileErrors['email'] = 'Email must be 50 characters or fewer.';
        }

        if (!$profileErrors) {
            // Pre-check, same convention as accounts.php -- the catch
            // block below is the race-condition backstop.
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND user_id <> ? AND active = 1');
            $stmt->execute([$profileOld['email'], $userId]);
            if ($stmt->fetchColumn()) {
                $profileErrors['email'] = 'An account already exists for this email.';
            }
        }

        if (!$profileErrors) {
            try {
                $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ?, username = ? WHERE user_id = ?')
                    ->execute([$profileOld['first_name'], $profileOld['last_name'], $profileOld['phone'], $profileOld['email'], $userId]);
                // PRG back to the read-only view (dropping edit=1), with
                // an arrival-flag toast -- same shape as toggle_active
                // below.
                $dest = '/admin/account_detail.php?id=' . $userId . '&updated=1';
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            } catch (PDOException $e) {
                $profileErrors['email'] = 'Could not save changes. An account for this email may already exist.';
            }
        }

        if ($profileErrors && request_wants_json()) {
            json_response(['ok' => false, 'errors' => $profileErrors], 422);
        }
    } elseif ($action === 'toggle_active') {
        if ($isSelf && $account['active']) {
            // Not reachable through the UI (the button is disabled for
            // this case below) -- defensive server-side twin, same shape
            // as reset_password's isSelf guard.
            $flash = ['type' => 'error', 'message' => 'You cannot deactivate your own account.'];
            if (request_wants_json()) {
                json_response(['ok' => false, 'message' => $flash['message']], 422);
            }
        } else {
            $newActive = $account['active'] ? 0 : 1;

            if ($newActive === 0 && $account['is_admin']) {
                // Last-admin protection: never allow a deactivation that
                // would leave zero active admins. Checked inside a
                // transaction with FOR UPDATE so two admins deactivating
                // each other concurrently can't both slip past the count.
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM admins a
                     JOIN users u ON u.user_id = a.user_id
                     WHERE u.active = 1 AND u.user_id <> ?
                     FOR UPDATE'
                );
                $stmt->execute([$userId]);
                $otherActiveAdmins = (int) $stmt->fetchColumn();

                if ($otherActiveAdmins === 0) {
                    $pdo->rollBack();
                    $flash = ['type' => 'error', 'message' => 'Cannot deactivate the last active admin account.'];
                    if (request_wants_json()) {
                        json_response(['ok' => false, 'message' => $flash['message']], 422);
                    }
                } else {
                    $pdo->prepare('UPDATE users SET active = 0 WHERE user_id = ?')->execute([$userId]);
                    $pdo->commit();

                    // PRG like every other converted action on this page --
                    // no secret to preserve here, so a plain arrival flag
                    // is enough (unlike reset_password's session flash).
                    $dest = '/admin/account_detail.php?id=' . $userId . '&deactivated=1';
                    if (request_wants_json()) {
                        json_response(['ok' => true, 'redirect' => $dest]);
                    }
                    redirect($dest);
                }
            } elseif ($newActive === 1) {
                // Reactivating: the freed-up email may have since been
                // claimed by a different active account (username is
                // only unique among active accounts) -- pre-check, same
                // convention as accounts.php, with the catch below as
                // the race-condition backstop.
                $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND user_id <> ? AND active = 1');
                $stmt->execute([$account['username'], $userId]);
                if ($stmt->fetchColumn()) {
                    $flash = ['type' => 'error', 'message' => 'Cannot reactivate: another active account already uses this email.'];
                    if (request_wants_json()) {
                        json_response(['ok' => false, 'message' => $flash['message']], 422);
                    }
                } else {
                    try {
                        $pdo->prepare('UPDATE users SET active = 1 WHERE user_id = ?')->execute([$userId]);

                        $dest = '/admin/account_detail.php?id=' . $userId . '&reactivated=1';
                        if (request_wants_json()) {
                            json_response(['ok' => true, 'redirect' => $dest]);
                        }
                        redirect($dest);
                    } catch (PDOException $e) {
                        $flash = ['type' => 'error', 'message' => 'Cannot reactivate: another active account already uses this email.'];
                        if (request_wants_json()) {
                            json_response(['ok' => false, 'message' => $flash['message']], 422);
                        }
                    }
                }
            } else {
                $pdo->prepare('UPDATE users SET active = 0 WHERE user_id = ?')->execute([$userId]);

                $dest = '/admin/account_detail.php?id=' . $userId . '&deactivated=1';
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            }
        }
    } elseif ($action === 'toggle_role') {
        if ($isSelf) {
            // Not reachable through the UI (the button is disabled for
            // this case below) -- defensive server-side twin, same shape
            // as toggle_active/reset_password's isSelf guards. Demoting
            // yourself would strand you on the admin page you're
            // standing on; promoting yourself is a no-op only an admin
            // could attempt anyway.
            $flash = ['type' => 'error', 'message' => 'You cannot change your own role.'];
            if (request_wants_json()) {
                json_response(['ok' => false, 'message' => $flash['message']], 422);
            }
        } elseif ($account['is_admin']) {
            // Demote: same last-admin protection as deactivation above --
            // never leave zero active admins, serialized with FOR UPDATE
            // so two admins demoting each other can't both slip past.
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM admins a
                 JOIN users u ON u.user_id = a.user_id
                 WHERE u.active = 1 AND u.user_id <> ?
                 FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $otherActiveAdmins = (int) $stmt->fetchColumn();

            if ($otherActiveAdmins === 0) {
                $pdo->rollBack();
                $flash = ['type' => 'error', 'message' => 'Cannot demote the last active admin account.'];
                if (request_wants_json()) {
                    json_response(['ok' => false, 'message' => $flash['message']], 422);
                }
            } else {
                $pdo->prepare('DELETE FROM admins WHERE user_id = ?')->execute([$userId]);
                $pdo->commit();

                $dest = '/admin/account_detail.php?id=' . $userId . '&demoted=1';
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            }
        } else {
            // Promote. The staff row is guaranteed (this whole page is
            // scoped to staff), so this is a single insert; the catch
            // covers the stale-page race where another admin promoted
            // them first -- a duplicate-PK violation must surface as a
            // clean error, not the global 500 page.
            try {
                $pdo->prepare('INSERT INTO admins (user_id) VALUES (?)')->execute([$userId]);

                $dest = '/admin/account_detail.php?id=' . $userId . '&promoted=1';
                if (request_wants_json()) {
                    json_response(['ok' => true, 'redirect' => $dest]);
                }
                redirect($dest);
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'message' => 'Could not change the role. Reload the page and try again.'];
                if (request_wants_json()) {
                    json_response(['ok' => false, 'message' => $flash['message']], 422);
                }
            }
        }
    } elseif ($action === 'reset_password') {
        if ($isSelf) {
            // Resetting your own password while logged in has no
            // legitimate use (use Change Password instead) and is a
            // lockout foot-gun for the sole admin -- the session's
            // must_change_password flag stays stale, so nothing visibly
            // changes while your real password is already gone. Not
            // reachable through the UI (the button is disabled/hidden for
            // $isSelf below) -- this is the defensive server-side twin.
            $flash = ['type' => 'error', 'message' => 'You cannot reset your own password here. Use Change Password instead.'];
            if (request_wants_json()) {
                json_response(['ok' => false, 'message' => $flash['message']], 422);
            }
        } else {
            $tempPassword = generate_temp_password();
            $tempHash = password_hash($tempPassword, PASSWORD_BCRYPT);

            // Archive the outgoing hash so the pre-reset password still
            // counts toward the last-5 reuse check on the forced change.
            // The temp itself needs no history row: is_password_reused()
            // already rejects it via the current users.password_hash.
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
            $stmt->execute([$userId]);
            $outgoingHash = (string) $stmt->fetchColumn();

            // Also clears any lockout: login checks locked_until before the
            // password, so a fresh temp would otherwise stay unusable for
            // up to 15 minutes after a fumbled-old-password lockout.
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, failed_login_count = 0, locked_until = NULL WHERE user_id = ?')
                ->execute([$tempHash, $userId]);

            record_password_history($pdo, $userId, $outgoingHash);

            // PRG like every other converted action on this page. The temp
            // password can't safely round-trip through a redirect URL, so
            // the reveal is session-flashed instead -- plaintext lives
            // server-side only, read-once with a short TTL, consumed by
            // the ?reset=1 arrival below. Same one-time-secret pattern as
            // accounts.php's New Account modal, distinct session key so
            // the two flashes can never collide.
            $_SESSION['password_reset_reveal'] = [
                'user_id'      => $userId,
                'tempPassword' => $tempPassword,
                'at'           => time(),
            ];

            $dest = '/admin/account_detail.php?id=' . $userId . '&reset=1';
            if (request_wants_json()) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
    }
}

// Server half of the arrival-flag convention (see accounts.php) -- the
// client half is petordersCleanArrivalFlags() near the bottom.
$arrival = consume_arrival_flags(['updated', 'reset', 'reactivated', 'deactivated', 'promoted', 'demoted']);

// Consume the flash: cleared on ANY load that finds it (read-once
// hygiene), shown only on a fresh ?reset=1 arrival for the SAME account
// the reveal was generated for -- guards against a stale flash bleeding
// into a different account_detail.php?id=... visit.
if (isset($_SESSION['password_reset_reveal'])) {
    $reveal = $_SESSION['password_reset_reveal'];
    unset($_SESSION['password_reset_reveal']);
    if ($arrival['reset'] && (int) $reveal['user_id'] === $userId && time() - (int) $reveal['at'] <= 60) {
        $tempPasswordReveal = $reveal['tempPassword'];
    }
}

$pageTitle = $account !== null ? ($account['first_name'] . ' ' . $account['last_name']) : 'Account not found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../../src/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../../src/partials/layout_admin.php'; ?>
        <main class="app-main">
            <?php if ($account === null): ?>
                <?php http_response_code(404); ?>
                <div class="page-header">
                    <h1>Account not found</h1>
                </div>
                <div class="card">
                    <p class="muted">This account doesn't exist.</p>
                    <a href="/admin/accounts.php" class="btn btn--secondary">Back to Accounts</a>
                </div>
            <?php else: ?>
                <div class="page-header">
                    <div>
                        <a href="/admin/accounts.php" class="page-header__back mb-4">&larr; Back to Accounts</a>
                        <span class="badge badge--<?= $account['active'] ? 'active' : 'inactive' ?> page-header__status"><?= $account['active'] ? 'Active' : 'Inactive' ?></span>
                        <span class="badge badge--role-<?= $account['is_admin'] ? 'admin' : 'staff' ?> page-header__status"><?= $account['is_admin'] ? 'Admin' : 'Staff' ?></span>
                        <h1><?= e($account['first_name'] . ' ' . $account['last_name']) ?></h1>
                    </div>
                </div>

                <?php // Only error flashes remain now that edit_profile PRGs
                      // like the other actions. ?>
                <?php if ($flash): ?>
                    <div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                <?php endif; ?>
                <?= $arrival['updated'] ? toast_flash('success', 'Profile updated.') : '' ?>
                <?= $arrival['reactivated'] ? toast_flash('success', 'Account reactivated.') : '' ?>
                <?= $arrival['deactivated'] ? toast_flash('success', 'Account deactivated. They have been signed out and can no longer log in.') : '' ?>
                <?= $arrival['promoted'] ? toast_flash('success', 'Promoted to admin.') : '' ?>
                <?= $arrival['demoted'] ? toast_flash('success', 'Demoted to staff.') : '' ?>

                <?php if ($tempPasswordReveal !== null): ?>
                    <div class="temp-password-banner">
                        <div class="temp-password-banner__heading">Temporary password generated</div>
                        <div>Give this to <?= e($account['first_name'] . ' ' . $account['last_name']) ?> via NIH email &mdash; it will not be shown again.</div>
                        <div class="temp-password-banner__row">
                            <span class="temp-password-banner__password" id="temp-password-value"><?= e($tempPasswordReveal) ?></span>
                            <button type="button" class="btn btn--secondary btn--sm" data-copy-target="#temp-password-value">Copy</button>
                        </div>
                        <div class="temp-password-banner__warning">Copy it now &mdash; this password will not be shown again.</div>
                        <div class="mt-2">Missed it? Use Reset Password below to generate a new one.</div>
                    </div>
                <?php endif; ?>

                <?php // Read-only card swaps for the form via ?edit=1 -- the
                      // markup swap is the mode signal, same convention as
                      // customer/order_detail.php's Order Details card (no
                      // .is-editing class, no toggle JS). ?>
                <?php if (!$editing): ?>
                    <div class="card">
                        <div class="card__header">
                            <span class="card__title">Profile</span>
                            <a href="/admin/account_detail.php?id=<?= (int) $userId ?>&amp;edit=1" class="btn btn--secondary btn--sm">Edit Profile</a>
                        </div>
                        <div class="detail-list">
                            <div class="detail-list__row">
                                <span class="detail-list__label">Email (username)</span>
                                <span class="detail-list__value"><?= e($account['username']) ?></span>
                            </div>
                            <div class="detail-list__row">
                                <span class="detail-list__label">First name</span>
                                <span class="detail-list__value"><?= e($account['first_name']) ?></span>
                            </div>
                            <div class="detail-list__row">
                                <span class="detail-list__label">Last name</span>
                                <span class="detail-list__value"><?= e($account['last_name']) ?></span>
                            </div>
                            <div class="detail-list__row">
                                <span class="detail-list__label">Phone</span>
                                <span class="detail-list__value tabular"><?= e($account['phone'] ?? '—') ?></span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <span class="card__title">Edit Profile</span>
                        <form method="post" action="/admin/account_detail.php?id=<?= (int) $userId ?>&amp;edit=1" id="edit-profile-form" novalidate data-ajax-submit>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="edit_profile">
                            <div class="alert alert--error" data-error-banner-for="edit-profile-form" <?= $profileErrors ? '' : 'hidden' ?>>Please correct the errors below and resubmit.</div>

                            <div class="<?= field_class($profileErrors, 'email') ?>">
                                <label for="email">Email <span class="required-mark">*</span></label>
                                <input type="email" id="email" name="email" value="<?= e($profileOld['email']) ?>" required>
                                <span class="field-hint">This is also their username for logging in.</span>
                                <?= field_error($profileErrors, 'email') ?>
                            </div>

                            <div class="field-row">
                                <div class="<?= field_class($profileErrors, 'first_name') ?>">
                                    <label for="first_name">First name <span class="required-mark">*</span></label>
                                    <input type="text" id="first_name" name="first_name" value="<?= e($profileOld['first_name']) ?>" required>
                                    <?= field_error($profileErrors, 'first_name') ?>
                                </div>
                                <div class="<?= field_class($profileErrors, 'last_name') ?>">
                                    <label for="last_name">Last name <span class="required-mark">*</span></label>
                                    <input type="text" id="last_name" name="last_name" value="<?= e($profileOld['last_name']) ?>" required>
                                    <?= field_error($profileErrors, 'last_name') ?>
                                </div>
                            </div>

                            <div class="<?= field_class($profileErrors, 'phone') ?>">
                                <label for="phone">Phone <span class="required-mark">*</span></label>
                                <input type="text" id="phone" name="phone" value="<?= e($profileOld['phone']) ?>" required>
                                <?= field_error($profileErrors, 'phone') ?>
                            </div>

                            <div class="form-section flex gap-3">
                                <a href="/admin/account_detail.php?id=<?= (int) $userId ?>" class="btn btn--ghost">Cancel</a>
                                <button type="submit" class="btn btn--primary">Save Profile</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <span class="card__title">Account</span>
                    <div class="detail-list">
                        <div class="detail-list__row">
                            <span class="detail-list__label">Role</span>
                            <span class="detail-list__value"><?= $account['is_admin'] ? 'Admin' : 'Staff' ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Created</span>
                            <span class="detail-list__value"><?= e(date('M j, Y g:i A', strtotime($account['created_at']))) ?></span>
                        </div>
                        <div class="detail-list__row">
                            <span class="detail-list__label">Account status</span>
                            <span class="detail-list__value"><?= $account['active'] ? 'Active' : 'Inactive' ?></span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <span class="card__title">Account Actions</span>
                    <div class="flex gap-3">
                        <?php if ($isSelf && $account['active']): ?>
                            <button type="button" class="btn btn--danger" disabled title="You cannot deactivate your own account.">Deactivate Account</button>
                        <?php elseif ($account['active']): ?>
                            <form method="post" action="/admin/account_detail.php?id=<?= (int) $userId ?>" id="toggle-active-form" novalidate data-ajax-submit
                                  data-confirm="Deactivate <?= e($account['first_name'] . ' ' . $account['last_name']) ?>? They will be signed out immediately and unable to log in."
                                  data-confirm-title="Deactivate account"
                                  data-confirm-verb="Deactivate"
                                  data-confirm-danger>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <button type="submit" class="btn btn--danger">Deactivate Account</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/admin/account_detail.php?id=<?= (int) $userId ?>" id="toggle-active-form" novalidate data-ajax-submit
                                  data-confirm="Reactivate <?= e($account['first_name'] . ' ' . $account['last_name']) ?>? They will be able to log in again."
                                  data-confirm-title="Reactivate account"
                                  data-confirm-verb="Reactivate">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <button type="submit" class="btn btn--secondary">Reactivate Account</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($isSelf): ?>
                            <button type="button" class="btn btn--secondary" disabled title="You cannot change your own role."><?= $account['is_admin'] ? 'Demote to Staff' : 'Promote to Admin' ?></button>
                        <?php elseif ($account['is_admin']): ?>
                            <form method="post" action="/admin/account_detail.php?id=<?= (int) $userId ?>" id="toggle-role-form" novalidate data-ajax-submit
                                  data-confirm="Demote <?= e($account['first_name'] . ' ' . $account['last_name']) ?> to staff? They will lose admin access immediately."
                                  data-confirm-title="Demote to staff"
                                  data-confirm-verb="Demote"
                                  data-confirm-danger>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_role">
                                <button type="submit" class="btn btn--secondary">Demote to Staff</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/admin/account_detail.php?id=<?= (int) $userId ?>" id="toggle-role-form" novalidate data-ajax-submit
                                  data-confirm="Make <?= e($account['first_name'] . ' ' . $account['last_name']) ?> an admin? They will gain full administrative access immediately."
                                  data-confirm-title="Promote to admin"
                                  data-confirm-verb="Promote">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_role">
                                <button type="submit" class="btn btn--secondary">Promote to Admin</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($isSelf): ?>
                            <button type="button" class="btn btn--secondary" disabled title="You cannot reset your own password here. Use Change Password instead.">Reset Password</button>
                        <?php else: ?>
                            <form method="post" action="/admin/account_detail.php?id=<?= (int) $userId ?>" id="reset-password-form" novalidate data-ajax-submit
                                  data-confirm="Generate a new temporary password for <?= e($account['first_name'] . ' ' . $account['last_name']) ?>? Their current password will stop working immediately."
                                  data-confirm-title="Reset password"
                                  data-confirm-verb="Reset password"
                                  data-confirm-danger>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <button type="submit" class="btn btn--secondary">Reset Password</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($isSelf): ?>
                        <p class="field-hint mt-2 mb-0">This is your own account &mdash; deactivation and role changes are blocked, and password changes go through <a href="/change_password.php">Change Password</a>.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
<?php if ($account !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  window.petordersCleanArrivalFlags(['updated', 'reset', 'reactivated', 'deactivated', 'promoted', 'demoted']);
});
</script>
<?php endif; ?>
</html>
