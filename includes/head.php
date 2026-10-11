<?php
$pageTitle = $pageTitle ?? SITE_NAME;
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $lightDashboard = isLoggedIn() && appTheme() === 'light'; ?>
<meta name="theme-color" content="<?php echo $lightDashboard ? '#1f10d9' : '#0a3d7a'; ?>">
<title><?php echo htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
<?php if (appTheme() === 'dark'): ?>
<!-- Dark mode for the signed-in dashboards (page background, titles, tabs...). Never on sign-in pages. -->
<meta name="color-scheme" content="dark">
<link rel="stylesheet" href="assets/css/theme-dark.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/theme-dark.css'); ?>">
<?php endif; ?>
<?php if ($lightDashboard): ?>
<!-- Light mode for the signed-in dashboards: NEMSU logo colours instead of dark navy. Never on sign-in pages. -->
<link rel="stylesheet" href="assets/css/theme-light.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/theme-light.css'); ?>">
<?php endif; ?>
<?php if (defined('SITE_LOGO') && is_file(__DIR__ . '/../' . SITE_LOGO)): ?>
<link rel="icon" href="<?php echo htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8'); ?>" type="image/png">
<?php endif; ?>
