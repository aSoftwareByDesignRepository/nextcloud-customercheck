/**
 * Company list panel for dialogs.
 * Browse → tap a name → calm “chosen” card (Change returns to the list).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  function normalizeCompanyHits(raw) {
    var list = raw;
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      list = raw.items || raw.data || raw.companies || [];
    }
    if (!Array.isArray(list)) {
      return [];
    }
    return list.map(function (row) {
      var r = row && typeof row === 'object' ? row : {};
      var id = Math.max(0, parseInt(String(r.id || 0), 10) || 0);
      var name = String(r.name || r.label || '').trim() || (id > 0 ? ('#' + id) : '');
      var updatedAt = String(r.updatedAt || r.updated_at || '').trim();
      return { id: id, name: name, updatedAt: updatedAt };
    }).filter(function (c) {
      return c.id > 0;
    });
  }

  function filterLocalCompanies(items, query, limit) {
    var capped = typeof limit === 'number' ? limit : 40;
    var q = String(query || '').trim().toLowerCase();
    var rows = Array.isArray(items) ? items : [];
    if (!q) {
      return rows.slice(0, Math.max(0, capped));
    }
    return rows.filter(function (c) {
      return String(c.name || '').toLowerCase().indexOf(q) !== -1;
    }).slice(0, Math.max(0, capped));
  }

  function applyExclude(rows, excludeIds) {
    var ban = {};
    (Array.isArray(excludeIds) ? excludeIds : []).forEach(function (raw) {
      var id = Math.max(0, parseInt(String(raw || 0), 10) || 0);
      if (id > 0) {
        ban[id] = true;
      }
    });
    if (!Object.keys(ban).length) {
      return rows;
    }
    return rows.filter(function (c) {
      return !ban[c.id];
    });
  }

  /**
   * @param {{
   *   listEl: HTMLElement,
   *   filterEl: HTMLInputElement,
   *   hiddenEl: HTMLInputElement,
   *   browseEl?: HTMLElement|null,
   *   choiceEl?: HTMLElement|null,
   *   choiceNameEl?: HTMLElement|null,
   *   changeBtn?: HTMLElement|null,
   *   filterWrapEl?: HTMLElement|null,
   *   statusEl?: HTMLElement|null,
   *   api?: { get: (path: string) => Promise<any> },
   *   workspaceIdFn?: () => number,
   *   t?: (msg: string, args?: string[]|Record<string, string>) => string,
   *   browseLimit?: number,
   *   filterMinCount?: number,
   *   excludeIds?: number[],
   *   onReady?: (count: number) => void,
   *   onSelect?: (company: { id: number, name: string, updatedAt?: string }) => void,
   *   onChangeRequest?: () => void,
   * }} opts
   */
  function mountListPanel(opts) {
    var listEl = opts.listEl;
    var filterEl = opts.filterEl;
    var hiddenEl = opts.hiddenEl;
    var browseEl = opts.browseEl || null;
    var choiceEl = opts.choiceEl || null;
    var choiceNameEl = opts.choiceNameEl || null;
    var changeBtn = opts.changeBtn || null;
    var filterWrapEl = opts.filterWrapEl || null;
    var statusEl = opts.statusEl || null;
    var Api = opts.api || global.CustomerCheckApi;
    var t = typeof opts.t === 'function' ? opts.t : function (m) { return m; };
    var browseLimit = typeof opts.browseLimit === 'number' ? opts.browseLimit : 40;
    var filterMinCount = typeof opts.filterMinCount === 'number' ? opts.filterMinCount : 8;
    var excludeIds = Array.isArray(opts.excludeIds) ? opts.excludeIds : [];
    var browseCache = [];
    var generation = 0;
    var timer = null;
    var destroyed = false;
    var readyFired = false;
    var selectedName = '';

    if (!listEl || !filterEl || !hiddenEl) {
      return { selectedId: function () { return 0; }, destroy: function () {}, refresh: function () {} };
    }

    function setStatus(msg) {
      if (!statusEl) {
        return;
      }
      var text = msg || '';
      statusEl.textContent = text;
      statusEl.hidden = text === '';
    }

    function setFilterVisible(show) {
      if (filterWrapEl) {
        filterWrapEl.hidden = !show;
      } else {
        filterEl.hidden = !show;
      }
    }

    function showChoice(name) {
      selectedName = name || '';
      if (choiceNameEl) {
        choiceNameEl.textContent = selectedName;
      }
      if (choiceEl) {
        choiceEl.hidden = false;
      }
      if (browseEl) {
        browseEl.hidden = true;
      }
    }

    function showBrowse() {
      if (choiceEl) {
        choiceEl.hidden = true;
      }
      if (browseEl) {
        browseEl.hidden = false;
      }
      if (choiceNameEl) {
        choiceNameEl.textContent = '';
      }
      selectedName = '';
      setFilterVisible(browseCache.length >= filterMinCount);
    }

    function paint(rows, selectedId) {
      while (listEl.firstChild) {
        listEl.removeChild(listEl.firstChild);
      }
      listEl.setAttribute('role', 'listbox');
      listEl.setAttribute('aria-label', t('Companies'));
      listEl.setAttribute('aria-busy', 'false');

      if (!rows.length) {
        var empty = document.createElement('li');
        empty.className = 'crm-company-list__empty';
        empty.setAttribute('role', 'presentation');
        empty.textContent = t('No matching companies.');
        listEl.appendChild(empty);
        return;
      }

      rows.forEach(function (c) {
        var li = document.createElement('li');
        li.className = 'crm-company-list__item';
        li.setAttribute('role', 'option');
        li.setAttribute('data-company-id', String(c.id));
        var isSel = selectedId > 0 && c.id === selectedId;
        li.setAttribute('aria-selected', isSel ? 'true' : 'false');
        if (isSel) {
          li.classList.add('is-selected');
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'crm-company-list__pick';
        btn.textContent = c.name;
        btn.addEventListener('click', function () {
          pickCompany(c);
        });
        li.appendChild(btn);
        listEl.appendChild(li);
      });
    }

    function pickCompany(c) {
      if (!c || !(c.id > 0)) {
        return;
      }
      hiddenEl.value = String(c.id);
      if (hiddenEl.dataset) {
        hiddenEl.dataset.updatedAt = c.updatedAt || '';
        hiddenEl.dataset.companyName = c.name || '';
      }
      showChoice(c.name);
      if (typeof opts.onSelect === 'function') {
        opts.onSelect({ id: c.id, name: c.name, updatedAt: c.updatedAt || '' });
      }
    }

    function clearCompany() {
      hiddenEl.value = '';
      if (hiddenEl.dataset) {
        hiddenEl.dataset.updatedAt = '';
        hiddenEl.dataset.companyName = '';
      }
      filterEl.value = '';
      showBrowse();
      paint(browseCache, 0);
      if (typeof opts.onChangeRequest === 'function') {
        opts.onChangeRequest();
      }
      if (browseCache.length >= filterMinCount && typeof filterEl.focus === 'function') {
        filterEl.focus();
      } else {
        var first = listEl.querySelector('.crm-company-list__pick');
        if (first && typeof first.focus === 'function') {
          first.focus();
        }
      }
    }

    function currentSelectedId() {
      return Math.max(0, parseInt(String(hiddenEl.value || '0'), 10) || 0);
    }

    function fireReady(count) {
      if (readyFired || destroyed) {
        return;
      }
      readyFired = true;
      if (typeof opts.onReady === 'function') {
        opts.onReady(count);
      }
    }

    async function loadBrowse() {
      var gen = ++generation;
      setStatus('');
      listEl.setAttribute('aria-busy', 'true');
      setStatus(t('Loading…'));
      if (!Api || typeof Api.get !== 'function') {
        paint([], 0);
        setStatus(t('Could not load companies'));
        fireReady(0);
        return;
      }
      try {
        var path = '/companies?limit=' + encodeURIComponent(String(browseLimit));
        var wid = typeof opts.workspaceIdFn === 'function' ? opts.workspaceIdFn() : 0;
        if (wid > 0) {
          path += '&workspaceId=' + encodeURIComponent(String(wid));
        }
        var res = await Api.get(path);
        if (destroyed || gen !== generation) {
          return;
        }
        browseCache = applyExclude(
          normalizeCompanyHits(res && res.data !== undefined ? res.data : res),
          excludeIds,
        );
        setStatus('');
        if (browseCache.length === 1) {
          pickCompany(browseCache[0]);
          fireReady(1);
          return;
        }
        showBrowse();
        paint(browseCache, currentSelectedId());
        fireReady(browseCache.length);
      } catch (_err) {
        if (destroyed || gen !== generation) {
          return;
        }
        browseCache = [];
        showBrowse();
        setFilterVisible(false);
        paint([], 0);
        setStatus(t('Could not load companies'));
        fireReady(0);
      }
    }

    async function runFilter(q) {
      var gen = ++generation;
      var local = filterLocalCompanies(browseCache, q, browseLimit);
      if (!q) {
        paint(browseCache, currentSelectedId());
        setStatus('');
        return;
      }
      if (local.length) {
        paint(local, currentSelectedId());
      }
      if (!Api || typeof Api.get !== 'function') {
        if (!local.length) {
          paint([], currentSelectedId());
        }
        return;
      }
      try {
        var path = '/companies?q=' + encodeURIComponent(q) + '&limit=' + encodeURIComponent(String(browseLimit));
        var wid = typeof opts.workspaceIdFn === 'function' ? opts.workspaceIdFn() : 0;
        if (wid > 0) {
          path += '&workspaceId=' + encodeURIComponent(String(wid));
        }
        var res = await Api.get(path);
        if (destroyed || gen !== generation) {
          return;
        }
        var rows = applyExclude(
          normalizeCompanyHits(res && res.data !== undefined ? res.data : res),
          excludeIds,
        );
        paint(rows.length ? rows : local, currentSelectedId());
        setStatus('');
      } catch (_err) {
        if (destroyed || gen !== generation) {
          return;
        }
        if (!local.length) {
          paint([], currentSelectedId());
          setStatus(t('Could not load companies'));
        }
      }
    }

    filterEl.addEventListener('input', function () {
      clearTimeout(timer);
      var q = filterEl.value.trim();
      timer = setTimeout(function () {
        void runFilter(q);
      }, 180);
    });

    if (changeBtn) {
      changeBtn.addEventListener('click', function () {
        clearCompany();
      });
    }

    showBrowse();
    setFilterVisible(false);
    void loadBrowse();

    return {
      selectedId: currentSelectedId,
      selectedName: function () { return selectedName; },
      refresh: loadBrowse,
      destroy: function () {
        destroyed = true;
        generation += 1;
        clearTimeout(timer);
      },
    };
  }

  global.CustomerCheckCompanyPicker = {
    normalizeCompanyHits: normalizeCompanyHits,
    filterLocalCompanies: filterLocalCompanies,
    applyExclude: applyExclude,
    mountListPanel: mountListPanel,
  };
}(typeof window !== 'undefined' ? window : globalThis));
