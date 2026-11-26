<?php
$pageTitle = $pageTitle ?? SITE_NAME;
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $lightDashboard = isLoggedIn() && appTheme() === 'light'; ?>
<meta name="theme-color" content="<?php echo appTheme() === 'dark' ? '#141a2e' : '#2a338f'; ?>">
<title><?php echo htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<!-- Inter (variable weight), the interface typeface -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/inter@5.1.1/index.css">
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
<?php if (appTheme() === 'dark'): ?>
<!-- Dark mode (page background, titles, tabs...). -->
<meta name="color-scheme" content="dark">
<link rel="stylesheet" href="assets/css/theme-dark.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/theme-dark.css'); ?>">
<?php endif; ?>
<?php if ($lightDashboard): ?>
<!-- Light mode for the signed-in dashboards: NEMSU logo colours instead of dark navy. Never on sign-in pages. -->
<link rel="stylesheet" href="assets/css/theme-light.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/theme-light.css'); ?>">
<?php endif; ?>
<!-- Progressive Web App: install on phones/computers, offline reading (sw.js, assets/js/pwa.js). -->
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="assets/images/app-apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="OSWD Complaints">
<!-- Visual refresh (palette, typography, spacing): loaded last so it wins. -->
<link rel="stylesheet" href="assets/css/refresh.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/refresh.css'); ?>">
<?php if (appTheme() === 'dark'): ?>
<link rel="stylesheet" href="assets/css/refresh-dark.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/refresh-dark.css'); ?>">
<?php endif; ?>
<?php if (defined('SITE_FAVICON') && is_file(__DIR__ . '/../' . SITE_FAVICON)): ?>
<link rel="icon" href="<?php echo htmlspecialchars(SITE_FAVICON, ENT_QUOTES, 'UTF-8'); ?>" type="image/png">
<?php endif; ?>
