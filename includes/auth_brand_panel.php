<?php $authIsDark = appTheme() === 'dark'; ?>
<!-- Light/dark switch (assets/js/theme.js). Shows the mode it switches TO. -->
<button type="button" class="nemsu-auth-theme-toggle" id="themeToggle"
        data-current-theme="<?php echo $authIsDark ? 'dark' : 'light'; ?>"
        aria-label="<?php echo $authIsDark ? 'Switch to light mode' : 'Switch to dark mode'; ?>"
        title="<?php echo $authIsDark ? 'Switch to light mode' : 'Switch to dark mode'; ?>">
    <i class="bi bi-<?php echo $authIsDark ? 'sun' : 'moon-stars'; ?>" aria-hidden="true"></i>
</button>
<aside class="auth-panel-brand" aria-label="NEMSU Cantilan">
    <div class="auth-panel-brand__inner">
        <div class="auth-brand-icon auth-brand-icon--lg"><?php echo nemsuLogoImg('nemsu-logo nemsu-logo--auth'); ?></div>
        <h1 class="auth-panel-brand__title">OSWD Student Complaint System</h1>
        <p class="auth-panel-brand__campus">NEMSU · Cantilan Campus</p>
        <p class="auth-panel-brand__tagline">A calm, confidential channel to report concerns and track your case with the Office of Student Welfare and Development.</p>
        <ul class="auth-panel-brand__list">
            <li><i class="bi bi-shield-check" aria-hidden="true"></i> Secure sign-in</li>
            <li><i class="bi bi-bell" aria-hidden="true"></i> Status notifications</li>
            <li><i class="bi bi-diagram-3" aria-hidden="true"></i> Clear routing to the right office</li>
        </ul>
    </div>
</aside>
