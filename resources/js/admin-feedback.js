(function (window, document) {
  'use strict';

  var timers = [];
  var rootId = 'mmh-admin-feedback-root';

  function getRoot() {
    var root = document.getElementById(rootId);
    if (!root) {
      root = document.createElement('div');
      root.id = rootId;
      root.className = 'mmh-admin-feedback-root';
      root.setAttribute('aria-label', 'Admin feedback');
      (document.body || document.documentElement).appendChild(root);
    }
    return root;
  }

  function removeToast(toast) {
    if (!toast || !toast.parentNode) return;
    toast.classList.remove('is-visible');
    window.setTimeout(function () {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 160);
  }

  function show(type, message, options) {
    var text = String(message == null ? '' : message).trim();
    if (!text) return null;
    var kind = ['success', 'error', 'warning', 'info'].indexOf(type) >= 0 ? type : 'info';
    var settings = options || {};
    var root = getRoot();
    var toast = document.createElement('div');
    var icon = document.createElement('span');
    var content = document.createElement('span');
    var close = document.createElement('button');
    var timeout = Number(settings.duration) > 0 ? Number(settings.duration) : 4200;

    toast.className = 'mmh-admin-feedback mmh-admin-feedback--' + kind;
    toast.setAttribute('role', kind === 'error' || kind === 'warning' ? 'alert' : 'status');
    toast.setAttribute('aria-live', kind === 'error' || kind === 'warning' ? 'assertive' : 'polite');
    icon.className = 'mmh-admin-feedback__icon fas ' + ({ success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' }[kind]);
    icon.setAttribute('aria-hidden', 'true');
    content.className = 'mmh-admin-feedback__message';
    content.textContent = text;
    close.type = 'button';
    close.className = 'mmh-admin-feedback__close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = '&times;';
    close.addEventListener('click', function () { removeToast(toast); });
    toast.appendChild(icon);
    toast.appendChild(content);
    toast.appendChild(close);
    root.appendChild(toast);
    window.requestAnimationFrame(function () { toast.classList.add('is-visible'); });
    timers.push(window.setTimeout(function () { removeToast(toast); }, timeout));
    return toast;
  }

  window.mmhAdminFeedback = {
    show: show,
    success: function (message, options) { return show('success', message, options); },
    error: function (message, options) { return show('error', message, options); },
    warning: function (message, options) { return show('warning', message, options); },
    info: function (message, options) { return show('info', message, options); }
  };

  /* Keep legacy admin calls safe while leaving confirmations and input dialogs intact. */
  function bridgeLegacySweetAlert() {
    if (!window.Swal || typeof window.Swal.fire !== 'function' || window.Swal.__mmhFeedbackBridge) return;
    var originalFire = window.Swal.fire;
    window.Swal.fire = function (options) {
      var config = options && typeof options === 'object' ? options : null;
      var icon = config && config.icon;
      var ordinary = config && (config.toast === true ||
        (['success', 'error', 'info'].indexOf(icon) >= 0 &&
          !config.showCancelButton && !config.input && !config.html && !config.preConfirm));
      if (ordinary) {
        show(icon, config.title || config.text || 'Operation completed.', { duration: config.timer });
        return Promise.resolve({ isConfirmed: false, isDenied: false, isDismissed: true });
      }
      return originalFire.apply(this, arguments);
    };
    window.Swal.__mmhFeedbackBridge = true;
  }
  bridgeLegacySweetAlert();
}(window, document));
