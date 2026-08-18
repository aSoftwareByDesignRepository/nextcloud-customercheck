(function () {
  'use strict';

  /**
   * Company-scoped contact select — loads GET /companies/{id}/persons.
   */
  function mountPersonSelect(opts) {
    const select = opts && opts.selectEl;
    if (!select || !opts.api || typeof opts.api.get !== 'function') {
      return {
        refresh: async function () {},
        selectedId: function () { return 0; },
        destroy: function () {},
      };
    }

    let generation = 0;
    let cachedCompanyId = 0;

    function setBusy(busy) {
      if (busy) {
        select.setAttribute('aria-busy', 'true');
        select.disabled = true;
      } else {
        select.removeAttribute('aria-busy');
      }
    }

    function singleOption(value, label, disabled) {
      select.innerHTML = '';
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label;
      select.appendChild(opt);
      select.disabled = !!disabled;
    }

    function renderPeople(people, preselectId) {
      select.innerHTML = '';
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = opts.emptyLabel || '—';
      select.appendChild(empty);

      let primaryId = 0;
      people.forEach(function (p) {
        if (p && p.isPrimary) {
          primaryId = parseInt(String(p.id || '0'), 10) || 0;
        }
      });
      const pick = preselectId > 0 ? preselectId : primaryId;

      people.forEach(function (p) {
        const id = parseInt(String(p.id || '0'), 10);
        if (id < 1) {
          return;
        }
        const opt = document.createElement('option');
        opt.value = String(id);
        opt.textContent = String(p.name || ('#' + id));
        if (id === pick) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });

      select.disabled = false;
      select.removeAttribute('aria-busy');
    }

    async function refresh(companyId, preselectId) {
      const cid = parseInt(String(companyId || '0'), 10) || 0;
      if (cid <= 0) {
        cachedCompanyId = 0;
        singleOption('', opts.pickCompanyLabel || 'Pick a company first', true);
        return;
      }
      generation += 1;
      const myGen = generation;
      setBusy(true);
      try {
        const res = await opts.api.get('/companies/' + encodeURIComponent(String(cid)) + '/persons');
        if (myGen !== generation) {
          return;
        }
        const people = (res && res.data) || [];
        if (people.length < 1) {
          singleOption('', opts.noContactsLabel || 'No contacts yet', false);
          cachedCompanyId = cid;
          return;
        }
        renderPeople(people, parseInt(String(preselectId || '0'), 10) || 0);
        cachedCompanyId = cid;
      } catch (e) {
        if (myGen !== generation) {
          return;
        }
        singleOption('', opts.errorLabel || 'Could not load contacts', true);
        cachedCompanyId = 0;
      }
    }

    return {
      refresh: refresh,
      selectedId: function () {
        return parseInt(String(select.value || '0'), 10) || 0;
      },
      destroy: function () {
        generation += 1;
        cachedCompanyId = 0;
      },
    };
  }

  window.CustomerCheckPersonPicker = {
    mountPersonSelect: mountPersonSelect,
  };
})();
