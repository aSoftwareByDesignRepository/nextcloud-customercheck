/**
 * Keyboard navigation for directory user-picker listboxes (WCAG 2.1.1 / 4.1.2).
 * ArrowUp/Down, Home/End, Enter to commit, Escape to close.
 */
(function () {
  'use strict';

  /**
   * @param {HTMLInputElement|HTMLElement} search
   * @param {HTMLElement} suggest
   * @returns {{ reset: function(): void }}
   */
  function bindListboxKeyboard(search, suggest) {
    if (!search || !suggest) {
      return { reset: function () {} };
    }
    var active = -1;

    function options() {
      return Array.prototype.slice.call(suggest.querySelectorAll('[role="option"]'));
    }

    function setActive(index) {
      var opts = options();
      if (opts.length === 0) {
        active = -1;
        search.removeAttribute('aria-activedescendant');
        return;
      }
      if (index < 0) index = opts.length - 1;
      if (index >= opts.length) index = 0;
      opts.forEach(function (el, idx) {
        var on = idx === index;
        el.setAttribute('aria-selected', on ? 'true' : 'false');
        el.classList.toggle('user-picker__item--active', on);
        if (on) {
          if (!el.id) {
            el.id = 'crm-user-opt-' + String(Date.now()) + '-' + String(idx);
          }
          search.setAttribute('aria-activedescendant', el.id);
          if (typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ block: 'nearest' });
          }
        }
      });
      active = index;
    }

    function reset() {
      active = -1;
      search.removeAttribute('aria-activedescendant');
      options().forEach(function (el) {
        el.setAttribute('aria-selected', 'false');
        el.classList.remove('user-picker__item--active');
      });
    }

    search.addEventListener('keydown', function (ev) {
      var opts = options();
      var open = !suggest.hidden && opts.length > 0;
      if (ev.key === 'ArrowDown') {
        if (!open) return;
        ev.preventDefault();
        setActive(active < 0 ? 0 : active + 1);
      } else if (ev.key === 'ArrowUp') {
        if (!open) return;
        ev.preventDefault();
        setActive(active < 0 ? opts.length - 1 : active - 1);
      } else if (ev.key === 'Home') {
        if (!open) return;
        ev.preventDefault();
        setActive(0);
      } else if (ev.key === 'End') {
        if (!open) return;
        ev.preventDefault();
        setActive(opts.length - 1);
      } else if (ev.key === 'Enter') {
        if (!open || active < 0 || !opts[active]) return;
        ev.preventDefault();
        opts[active].click();
        reset();
      } else if (ev.key === 'Escape') {
        if (suggest.hidden) return;
        ev.preventDefault();
        suggest.hidden = true;
        suggest.innerHTML = '';
        search.setAttribute('aria-expanded', 'false');
        reset();
      }
    });

    return { reset: reset };
  }

  window.CustomerCheckUserPickerNav = {
    bindListboxKeyboard: bindListboxKeyboard,
  };
})();
