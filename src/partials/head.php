<?php
// Usage: set $pageTitle before including this partial, e.g.:
//   <?php $pageTitle = 'Home'; include '../src/partials/head.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | <?= e(app_setting('app_name')) ?></title>

<!-- Favicons: static files in public/favicons/, no PHP processing needed -->
<link rel="icon" type="image/png" sizes="32x32" href="/favicons/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicons/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png">
<link rel="manifest" href="/favicons/site.webmanifest">

<script>
    if (localStorage.getItem('petorders:sidebar') === 'collapsed') {
        document.documentElement.dataset.sidebar = 'collapsed';
    }
</script>

<!-- Leading slash: resolves from site root regardless of which folder
     the current page lives in (public/, public/customer/, etc).
     asset_url() appends ?v=<mtime> so edited stylesheets bust the
     browser cache instead of serving stale. -->
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">

<link rel="stylesheet" href="<?= asset_url('/assets/css/layout/shell.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/layout/sidebar.css') ?>">

<link rel="stylesheet" href="<?= asset_url('/assets/css/components/auth.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/page-structure.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/forms.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/buttons.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/tables.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/alerts.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/badges.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/utilities.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/toasts.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/modals.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/feedback.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/dashboard.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/radio-cards.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/components/order-page.css') ?>">