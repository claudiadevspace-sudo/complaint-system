(function () {
    'use strict';

    function initPasswordToggle() {
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-toggle-password');
                var input = document.getElementById(targetId);
                if (!input) {
                    return;
                }
                var isPassword = input.getAttribute('type') === 'password';
                input.setAttribute('type', isPassword ? 'text' : 'password');
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                }
                btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        });
    }

    function initOtpInputs() {
        var form = document.getElementById('otpForm');
        if (!form) {
            return;
        }
        var hidden = document.getElementById('otp_code');
        var inputs = form.querySelectorAll('.otp-digit');
        if (!hidden || !inputs.length) {
            return;
        }

        function syncHidden() {
            var code = '';
            inputs.forEach(function (inp) {
                code += (inp.value || '').replace(/\D/g, '').slice(0, 1);
            });
            hidden.value = code;
        }

        inputs.forEach(function (input, index) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D/g, '').slice(0, 1);
                syncHidden();
                if (input.value && inputs[index + 1]) {
                    inputs[index + 1].focus();
                }
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && !input.value && inputs[index - 1]) {
                    inputs[index - 1].focus();
                }
            });
            input.addEventListener('paste', function (event) {
                event.preventDefault();
                var text = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                text.split('').forEach(function (ch, i) {
                    if (inputs[i]) {
                        inputs[i].value = ch;
                    }
                });
                syncHidden();
                if (inputs[Math.min(text.length, inputs.length - 1)]) {
                    inputs[Math.min(text.length, inputs.length - 1)].focus();
                }
            });
        });

        form.addEventListener('submit', function () {
            syncHidden();
        });
    }

    function initResendTimer() {
        var resendBtn = document.getElementById('resendOtpBtn');
        var timerEl = document.getElementById('resendOtpTimer');
        if (!resendBtn || !timerEl) {
            return;
        }
        var seconds = parseInt(resendBtn.getAttribute('data-cooldown') || '60', 10);
        var remaining = seconds;
        resendBtn.disabled = true;

        function tick() {
            timerEl.textContent = 'Resend available in ' + remaining + 's';
            if (remaining <= 0) {
                resendBtn.disabled = false;
                timerEl.textContent = '';
                return;
            }
            remaining -= 1;
            window.setTimeout(tick, 1000);
        }
        tick();
    }

    // Sign-in cooldown after a failed attempt: count down, then unlock the
    // form so the user can try again (the next failure locks the account).
    function initLoginCooldown() {
        var timerEl = document.getElementById('loginCooldownTimer');
        var form = document.getElementById('loginForm');
        if (!timerEl || !form) {
            return;
        }
        var remaining = parseInt(timerEl.getAttribute('data-seconds') || '0', 10);
        var endsAt = Date.now() + remaining * 1000;

        function unlock() {
            form.classList.remove('login-form--locked');
            var username = form.querySelector('#username');
            var password = form.querySelector('#password');
            if (username) {
                username.readOnly = false;
            }
            form.querySelectorAll('[disabled]').forEach(function (el) {
                el.disabled = false;
            });
            var submit = document.getElementById('loginSubmitBtn');
            if (submit) {
                submit.textContent = 'Sign in';
            }
            timerEl.classList.remove('text-muted');
            timerEl.classList.add('text-danger');
            timerEl.textContent = ('You can sign in again. ' + (timerEl.getAttribute('data-warning') || '')).trim();
            if (password) {
                password.focus();
            }
        }

        function tick() {
            // Based on the clock, so a background tab still unlocks on time.
            var left = Math.ceil((endsAt - Date.now()) / 1000);
            if (left <= 0) {
                unlock();
                return;
            }
            timerEl.textContent = 'Try again in ' + left + 's';
            window.setTimeout(tick, 250);
        }
        tick();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPasswordToggle();
        initOtpInputs();
        initResendTimer();
        initLoginCooldown();
    });
})();
