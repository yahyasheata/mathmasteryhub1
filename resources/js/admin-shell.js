(() => {
  'use strict';

  // Some legacy admin pages still load the compiled dashboard module after
  // this shell. That module bundles its own jQuery and can replace the
  // instance already extended with DataTables, leaving $(...).DataTable()
  // unavailable. Keep the canonical page instance and restore it before any
  // DOM-ready handlers run; this is presentation/runtime compatibility only.
  const legacyJQuery = window.jQuery;
  const restoreLegacyJQuery = () => {
    if (!legacyJQuery) return;
    window.jQuery = legacyJQuery;
    window.$ = legacyJQuery;
  };
  if (legacyJQuery) {
    document.addEventListener('DOMContentLoaded', restoreLegacyJQuery, true);
    window.addEventListener('load', restoreLegacyJQuery, { once: true });
    window.setTimeout(restoreLegacyJQuery, 0);
  }

  const desktopQuery = window.matchMedia('(min-width: 701px)');
  const storageKey = 'mmh-admin-sidebar-collapsed';
  const submenuStorageKey = 'mmh-admin-sidebar-submenus';

  const readStoredDesktopState = () => {
    try { return window.localStorage.getItem(storageKey) === 'true'; } catch (_) { return false; }
  };
  const storeDesktopState = (collapsed) => {
    try { window.localStorage.setItem(storageKey, String(collapsed)); } catch (_) {}
  };
  const readSubmenuState = () => {
    try {
      const value = JSON.parse(window.localStorage.getItem(submenuStorageKey) || '{}');
      return value && typeof value === 'object' ? value : {};
    } catch (_) { return {}; }
  };
  const storeSubmenuState = (state) => {
    try { window.localStorage.setItem(submenuStorageKey, JSON.stringify(state)); } catch (_) {}
  };

  const initialise = () => {
    const body = document.body;
    const sidebar = document.getElementById('admin-sidebar');
    const main = document.querySelector('.main-content');
    const controls = [...document.querySelectorAll('.admin-sidebar-toggle')];
    const loader = document.getElementById('loading-image-container');
    if (!sidebar || !main || controls.length === 0) {
      if (loader) loader.remove();
      return;
    }

    let desktopCollapsed = readStoredDesktopState();
    let mobileOpen = false;
    const submenuState = readSubmenuState();
    const setSubmenu = (toggle, submenu, open, persist) => {
      if (!toggle || !submenu) return;
      const id = submenu.id;
      submenu.hidden = !open;
      submenu.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      if (persist && id) {
        submenuState[id] = !!open;
        storeSubmenuState(submenuState);
      }
    };
    const initialiseSubmenus = () => {
      document.querySelectorAll('[data-admin-submenu-toggle]').forEach((toggle) => {
        const submenu = document.getElementById(toggle.getAttribute('data-admin-submenu-toggle') || '');
        if (!submenu) return;
        const routeActive = submenu.dataset.routeActive === 'true';
        const stored = Object.prototype.hasOwnProperty.call(submenuState, submenu.id) ? !!submenuState[submenu.id] : false;
        setSubmenu(toggle, submenu, routeActive || stored, false);
        toggle.addEventListener('click', (event) => {
          event.preventDefault();
          setSubmenu(toggle, submenu, submenu.hidden, true);
        });
      });
    };
    const setControlState = (expanded) => {
      controls.forEach((control) => {
        control.setAttribute('aria-expanded', String(expanded));
        control.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
      });
    };
    const applyState = () => {
      if (desktopQuery.matches) {
        body.classList.toggle('admin-sidebar-collapsed', desktopCollapsed);
        body.classList.remove('admin-sidebar-mobile-open');
        sidebar.classList.toggle('active', !desktopCollapsed);
        sidebar.classList.toggle('in-active', desktopCollapsed);
        main.classList.toggle('active', desktopCollapsed);
        main.classList.toggle('in-active', !desktopCollapsed);
        setControlState(!desktopCollapsed);
        return;
      }

      body.classList.remove('admin-sidebar-collapsed');
      body.classList.toggle('admin-sidebar-mobile-open', mobileOpen);
      sidebar.classList.toggle('active', !mobileOpen);
      sidebar.classList.toggle('in-active', mobileOpen);
      main.classList.remove('active');
      main.classList.add('in-active');
      setControlState(mobileOpen);
    };

    controls.forEach((control) => control.addEventListener('click', (event) => {
      event.preventDefault();
      if (desktopQuery.matches) {
        desktopCollapsed = !desktopCollapsed;
        storeDesktopState(desktopCollapsed);
      } else {
        mobileOpen = !mobileOpen;
      }
      applyState();
    }));
    desktopQuery.addEventListener('change', () => applyState());
    initialiseSubmenus();
    applyState();

    if (loader) {
      loader.classList.add('admin-page-loader--ready');
      loader.setAttribute('aria-hidden', 'true');
      window.setTimeout(() => loader.remove(), 180);
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise, { once: true });
  else initialise();
})();
