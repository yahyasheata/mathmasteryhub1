(function () {
  'use strict';

  var triggerSelector = '[data-admin-course-actions-toggle]';
  var itemSelector = 'a.dropdown-item, button.dropdown-item';
  var openMenus = new Map();

  function menuItems(menu) {
    return Array.from(menu.querySelectorAll(itemSelector)).filter(function (item) {
      return !item.disabled && item.getAttribute('aria-disabled') !== 'true';
    });
  }

  function placeMenu(trigger, menu) {
    var triggerRect = trigger.getBoundingClientRect();
    var menuRect = menu.getBoundingClientRect();
    var viewportWidth = document.documentElement.clientWidth;
    var viewportHeight = document.documentElement.clientHeight;
    var margin = 12;
    var left = Math.max(margin, Math.min(triggerRect.right - menuRect.width, viewportWidth - menuRect.width - margin));
    var below = viewportHeight - triggerRect.bottom - margin;
    var above = triggerRect.top - margin;
    var top = below >= Math.min(menuRect.height, 260) || below >= above
      ? triggerRect.bottom + 4
      : triggerRect.top - menuRect.height - 4;
    top = Math.max(margin, Math.min(top, viewportHeight - menuRect.height - margin));
    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
    menu.style.maxHeight = Math.max(120, Math.min(420, viewportHeight - margin * 2)) + 'px';
  }

  function closeMenu(trigger, menu, restoreFocus) {
    if (!openMenus.has(trigger)) return;
    menu.hidden = true;
    menu.style.display = '';
    menu.style.left = '';
    menu.style.top = '';
    menu.style.maxHeight = '';
    trigger.setAttribute('aria-expanded', 'false');
    openMenus.delete(trigger);
    if (restoreFocus) trigger.focus();
  }

  function closeOthers(exceptTrigger) {
    openMenus.forEach(function (menu, trigger) {
      if (trigger !== exceptTrigger) closeMenu(trigger, menu, false);
    });
  }

  function openMenu(trigger, menu, focusFirst) {
    closeOthers(trigger);
    openMenus.set(trigger, menu);
    menu.hidden = false;
    menu.style.display = 'block';
    trigger.setAttribute('aria-expanded', 'true');
    placeMenu(trigger, menu);
    if (focusFirst) {
      var first = menuItems(menu)[0];
      if (first) first.focus();
    }
  }

  document.querySelectorAll(triggerSelector).forEach(function (trigger) {
    var menu = document.getElementById(trigger.getAttribute('aria-controls') || '');
    if (!menu) return;

    trigger.addEventListener('click', function () {
      if (openMenus.has(trigger)) closeMenu(trigger, menu, false);
      else openMenu(trigger, menu, false);
    });

    trigger.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        openMenu(trigger, menu, true);
      } else if (event.key === 'Escape' && openMenus.has(trigger)) {
        event.preventDefault();
        closeMenu(trigger, menu, true);
      }
    });

    menu.addEventListener('keydown', function (event) {
      var items = menuItems(menu);
      var index = items.indexOf(document.activeElement);
      if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu(trigger, menu, true);
      } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        var delta = event.key === 'ArrowDown' ? 1 : -1;
        items[(index + delta + items.length) % items.length]?.focus();
      } else if (event.key === 'Home' && items.length) {
        event.preventDefault();
        items[0].focus();
      } else if (event.key === 'End' && items.length) {
        event.preventDefault();
        items[items.length - 1].focus();
      }
    });

    menu.addEventListener('click', function (event) {
      if (event.target.closest(itemSelector)) closeMenu(trigger, menu, false);
    });

    menu.addEventListener('focusout', function () {
      window.setTimeout(function () {
        if (openMenus.has(trigger) && !menu.contains(document.activeElement)) closeMenu(trigger, menu, false);
      }, 0);
    });
  });

  document.addEventListener('pointerdown', function (event) {
    openMenus.forEach(function (menu, trigger) {
      if (!trigger.contains(event.target) && !menu.contains(event.target)) closeMenu(trigger, menu, false);
    });
  });

  function repositionMenus() {
    openMenus.forEach(function (menu, trigger) {
      var rect = trigger.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > document.documentElement.clientHeight || rect.right < 0 || rect.left > document.documentElement.clientWidth) {
        closeMenu(trigger, menu, false);
      } else {
        placeMenu(trigger, menu);
      }
    });
  }

  window.addEventListener('resize', repositionMenus);
  window.addEventListener('scroll', repositionMenus, true);
}());
