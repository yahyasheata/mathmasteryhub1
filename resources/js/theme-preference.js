(function () {
  'use strict';

  var root = document.documentElement;
  var canonicalKey = 'theme';
  var legacyKey = 'math-mastery-student-theme';

  function normalize(value) {
    return value === 'light' || value === 'dark' ? value : '';
  }

  function readSavedTheme() {
    try {
      return normalize(localStorage.getItem(canonicalKey)) || normalize(localStorage.getItem(legacyKey));
    } catch (error) {
      return '';
    }
  }

  function resolveTheme() {
    var saved = readSavedTheme();
    if (saved) return saved;

    // Keep the existing Admin setting as a first-visit fallback, then use the
    // OS preference for pages that did not have a server-side theme default.
    var serverTheme = normalize(root.getAttribute('data-bs-theme')) ||
      normalize(root.getAttribute('data-student-theme')) ||
      normalize(root.getAttribute('data-theme')) ||
      (root.classList.contains('dark') ? 'dark' : '');
    if (serverTheme) return serverTheme;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function updateControls(theme) {
    document.querySelectorAll('[data-mmh-theme-toggle]').forEach(function (button) {
      var next = theme === 'dark' ? 'light' : 'dark';
      var icon = button.querySelector('.mmh-theme-switch__icon');
      if (icon && icon.namespaceURI !== 'http://www.w3.org/2000/svg') {
        icon.setAttribute('class', 'fas ' + (theme === 'dark' ? 'fa-moon' : 'fa-sun') + ' mmh-theme-switch__icon');
      }
      button.setAttribute('aria-label', 'Switch to ' + next + ' mode');
      button.setAttribute('title', 'Switch to ' + next + ' mode');
      button.setAttribute('aria-pressed', String(theme === 'dark'));
    });
  }

  function applyTheme(theme, persist) {
    var value = normalize(theme) || 'light';
    root.classList.toggle('dark', value === 'dark');
    root.dataset.theme = value;
    root.dataset.studentTheme = value;
    root.setAttribute('data-bs-theme', value);
    root.style.colorScheme = value;
    if (persist) {
      try {
        localStorage.setItem(canonicalKey, value);
        localStorage.setItem(legacyKey, value);
      } catch (error) {}
    }
    updateControls(value);
    window.dispatchEvent(new CustomEvent('mmh:themechange', { detail: { theme: value } }));
  }

  window.mmhApplyTheme = applyTheme;
  window.mmhCurrentTheme = function () { return root.dataset.theme || 'light'; };
  applyTheme(resolveTheme(), false);

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-mmh-theme-toggle]');
    if (!button) return;
    event.preventDefault();
    applyTheme(window.mmhCurrentTheme() === 'dark' ? 'light' : 'dark', true);
  });

  window.addEventListener('storage', function (event) {
    if (event.key === canonicalKey || event.key === legacyKey) {
      applyTheme(resolveTheme(), false);
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    updateControls(window.mmhCurrentTheme());
  }, { once: true });
}());
