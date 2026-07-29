(function () {
    'use strict';

    var storageKey = 'math-mastery-student-theme';
    var documentRoot = document.documentElement;

    function normalizeTheme(theme) {
        return theme === 'light' ? 'light' : 'dark';
    }

    function updateToggleLabels(theme) {
        var isDark = theme === 'dark';
        var buttons = document.querySelectorAll('[data-student-theme-toggle]');

        buttons.forEach(function (button) {
            var icon = button.querySelector('[data-student-theme-icon]') || button.querySelector('.far');
            var label = button.querySelector('.student-theme-toggle-label');
            var nextTheme = isDark ? 'light' : 'dark';

            button.setAttribute('aria-pressed', String(isDark));
            button.setAttribute('aria-label', 'Switch to ' + nextTheme + ' theme');
            button.setAttribute('title', 'Switch to ' + nextTheme + ' theme');

            if (icon) {
                icon.className = isDark ? 'far fa-moon' : 'far fa-sun';
                icon.setAttribute('aria-hidden', 'true');
            }

            if (label) {
                label.textContent = isDark ? 'Dark' : 'Light';
            }
        });
    }

    function applyTheme(theme, persist) {
        var normalizedTheme = normalizeTheme(theme);

        documentRoot.dataset.studentTheme = normalizedTheme;
        documentRoot.style.colorScheme = normalizedTheme;

        if (persist) {
            try {
                window.localStorage.setItem(storageKey, normalizedTheme);
            } catch (error) {
                // Theme selection still works for the current page when storage is unavailable.
            }
        }

        updateToggleLabels(normalizedTheme);
    }

    function currentTheme() {
        return normalizeTheme(documentRoot.dataset.studentTheme);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.body.classList.add('student-ui');
        applyTheme(currentTheme(), false);

        document.querySelectorAll('[data-student-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                applyTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
            }, { once: false });
        });
    }, { once: true });
}());
