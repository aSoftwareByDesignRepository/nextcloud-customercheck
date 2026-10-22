(function () {
  'use strict';

  /**
   * window.t / OC.L10N.translate fills {name} tokens only — %s/%d pass
   * through raw. Wrap an injected translator so leftover sprintf
   * placeholders are always filled (idempotent when already filled).
   * @param {unknown} base
   * @returns {(msg: string, args?: string[]) => string}
   */
  function sprintfT(base) {
    const baseT = typeof base === 'function' ? base : function (m) { return m; };
    return function (msg, args) {
      let out = baseT(msg, args);
      const list = Array.isArray(args) ? args : [];
      if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
        let i = 0;
        out = out.replace(/%%|%(?:\d+\$)?[sd]/g, function (m) {
          if (m === '%%') { return '%'; }
          const v = list[i];
          i += 1;
          return v == null ? '' : String(v);
        });
      }
      return out;
    };
  }

  const OPEN_STAGES = ['lead', 'contacted', 'quoted', 'negotiation'];

  const WIN_CHANCE = [
    { value: 'pipeline', percent: 25, label: '25%' },
    { value: 'upside', percent: 50, label: '50%' },
    { value: 'commit', percent: 75, label: '75%' },
    { value: 'certain', percent: 100, label: '100%' },
  ];

  function winChanceOptionsHtml(t, escapeHtml, selectedValue) {
    t = sprintfT(t);
    const selected = String(selectedValue || '');
    const parts = ['<option value="">' + escapeHtml(t('Not set')) + '</option>'];
    WIN_CHANCE.forEach(function (o) {
      const sel = selected === o.value ? ' selected' : '';
      parts.push(
        '<option value="' + o.value + '"' + sel + '>' + escapeHtml(o.label) + '</option>'
      );
    });
    return parts.join('');
  }

  function defaultMountOwnerPicker(opts) {
    const Api = opts.api;
    const search = document.getElementById(opts.searchId);
    const suggest = document.getElementById(opts.suggestId);
    const hidden = document.getElementById(opts.hiddenId);
    const clearBtn = opts.clearId ? document.getElementById(opts.clearId) : null;
    if (!search || !suggest || !hidden || !Api) {
      return { selectedUid: function () { return ''; } };
    }
    let timer = null;
    let searchGeneration = 0;
    const nav = (window.CustomerCheckUserPickerNav && window.CustomerCheckUserPickerNav.bindListboxKeyboard)
      ? window.CustomerCheckUserPickerNav.bindListboxKeyboard(search, suggest)
      : { reset: function () {} };
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        hidden.value = '';
        search.value = '';
        suggest.innerHTML = '';
        suggest.hidden = true;
        clearBtn.hidden = true;
        search.setAttribute('aria-expanded', 'false');
        nav.reset();
        search.focus();
      });
    }
    search.addEventListener('input', function () {
      if (hidden.value) {
        hidden.value = '';
        if (clearBtn) {
          clearBtn.hidden = true;
        }
      }
      clearTimeout(timer);
      const q = search.value.trim();
      if (q.length < 2) {
        searchGeneration += 1;
        suggest.hidden = true;
        suggest.innerHTML = '';
        search.setAttribute('aria-expanded', 'false');
        nav.reset();
        return;
      }
      searchGeneration += 1;
      const myGeneration = searchGeneration;
      timer = setTimeout(async function () {
        try {
          let url = '/settings/users?q=' + encodeURIComponent(q);
          const wid = typeof opts.workspaceIdFn === 'function' ? opts.workspaceIdFn() : 0;
          if (wid > 0) {
            url += '&workspaceId=' + encodeURIComponent(String(wid));
          }
          const res = await Api.get(url);
          if (myGeneration !== searchGeneration) {
            return;
          }
          const users = (res.data && res.data.users) || [];
          suggest.innerHTML = '';
          users.forEach(function (u) {
            const opt = document.createElement('li');
            opt.className = 'user-picker__item';
            opt.setAttribute('role', 'option');
            opt.setAttribute('aria-selected', 'false');
            const name = document.createElement('span');
            name.className = 'user-picker__name';
            name.textContent = u.displayName || u.id;
            const meta = document.createElement('span');
            meta.className = 'user-picker__meta';
            meta.textContent = u.id || '';
            opt.appendChild(name);
            opt.appendChild(meta);
            opt.addEventListener('click', function () {
              hidden.value = String(u.id || '');
              search.value = (u.displayName || u.id || '') + (u.id ? ' (' + u.id + ')' : '');
              suggest.innerHTML = '';
              suggest.hidden = true;
              search.setAttribute('aria-expanded', 'false');
              nav.reset();
              if (clearBtn) {
                clearBtn.hidden = !hidden.value;
              }
            });
            suggest.appendChild(opt);
          });
          suggest.hidden = users.length === 0;
          search.setAttribute('aria-expanded', users.length === 0 ? 'false' : 'true');
          nav.reset();
        } catch (e) {
          suggest.hidden = true;
          search.setAttribute('aria-expanded', 'false');
          nav.reset();
        }
      }, 250);
    });
    return {
      selectedUid: function () { return String(hidden.value || '').trim(); },
    };
  }

  function parseJsonScript(id) {
    const el = document.getElementById(id);
    if (!el) {
      return [];
    }
    try {
      const data = JSON.parse(el.textContent || '[]');
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  function isoDatePlusDays(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function buildSelectOptions(options, selectedValue, includeEmpty, emptyLabel) {
    const parts = [];
    if (includeEmpty) {
      parts.push('<option value="">' + emptyLabel + '</option>');
    }
    (options || []).forEach(function (o) {
      const val = String(o.value || '');
      const sel = String(selectedValue || '') === val ? ' selected' : '';
      parts.push(
        '<option value="' + (o.value != null ? String(o.value).replace(/"/g, '&quot;') : '') + '"' + sel + '>' +
        String(o.label || o.value || '').replace(/</g, '&lt;') + '</option>'
      );
    });
    return parts.join('');
  }

  /**
   * @param {Object} opts
   * @returns {Promise<boolean>} true when a deal was created
   */
  async function openNewDealForm(opts) {
    const C = opts.components;
    const Api = opts.api;
    const t = sprintfT(opts.t);
    const escapeHtml = opts.escapeHtml;
    const mountOwnerPicker = opts.mountOwnerPicker;
    const activeWorkspaceId = opts.activeWorkspaceId;

    if (!C || !Api || typeof t !== 'function' || typeof escapeHtml !== 'function') {
      return false;
    }

    const mode = opts.mode === 'company360' ? 'company360' : 'pipeline';
    const fixedCompanyId = parseInt(String(opts.fixedCompanyId || '0'), 10) || 0;
    const fixedCompanyName = String(opts.fixedCompanyName || '').trim();

    const stageOptions = (opts.stageOptions || parseJsonScript('crm-deal-form-stage-options'))
      .filter(function (o) { return OPEN_STAGES.indexOf(String(o.value || '')) !== -1; });
    const leadSourceOptions = opts.leadSourceOptions || parseJsonScript('crm-deal-form-lead-source-options');

    const id = 'crm-new-deal-' + Date.now();
    const stageOpts = buildSelectOptions(stageOptions, 'lead', false, '');
    const forecastOpts = winChanceOptionsHtml(t, escapeHtml, '');
    const leadOpts = buildSelectOptions(leadSourceOptions, 'manual', false, '');

    let companyBlock = '';
    if (mode === 'pipeline') {
      companyBlock =
        '<fieldset class="crm-form-section crm-new-deal__section crm-new-deal__section--company">' +
        '<legend class="crm-form-section__legend">' + escapeHtml(t('Company')) + '</legend>' +
        '<div class="form-group crm-new-deal__company">' +
        '<span class="visually-hidden" id="' + id + '-company-label">' + escapeHtml(t('Company')) + '</span>' +
        '<div class="crm-company-picker crm-deal-company-picker" id="' + id + '-company-picker" ' +
        'aria-labelledby="' + id + '-company-label">' +
        '<div class="crm-company-choice" id="' + id + '-company-choice" hidden>' +
        '<div class="crm-company-choice__card">' +
        '<p class="crm-company-choice__name" id="' + id + '-company-choice-name"></p>' +
        '<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm crm-company-choice__change" id="' +
        id + '-company-change">' + escapeHtml(t('Change company')) + '</button></div></div>' +
        '<div class="crm-company-browse" id="' + id + '-company-browse">' +
        '<div class="crm-company-picker__filter-wrap" id="' + id + '-company-filter-wrap" hidden>' +
        '<label class="visually-hidden" for="' + id + '-company-filter">' + escapeHtml(t('Filter companies')) + '</label>' +
        '<input type="search" id="' + id + '-company-filter" class="form-input crm-input crm-company-picker__filter" ' +
        'autocomplete="off" placeholder="' + escapeHtml(t('Search by company name…')) + '" ' +
        'aria-controls="' + id + '-company-list" /></div>' +
        '<ul id="' + id + '-company-list" class="crm-company-list" role="listbox" aria-labelledby="' +
        id + '-company-label"></ul>' +
        '<p class="crm-muted crm-company-picker__status" id="' + id + '-company-status" role="status" aria-live="polite" hidden></p>' +
        '</div>' +
        '<input type="hidden" id="' + id + '-company" name="companyId" value="" required />' +
        '</div></div></fieldset>';
    } else {
      companyBlock =
        '<input type="hidden" id="' + id + '-company" name="companyId" value="' + escapeHtml(String(fixedCompanyId)) + '" />' +
        '<p class="crm-muted crm-new-deal__company-fixed" role="status">' +
        escapeHtml(t('Company')) + ': <strong>' + escapeHtml(fixedCompanyName || ('#' + fixedCompanyId)) + '</strong></p>';
    }

    const html =
      '<form id="' + id + '-form" class="crm-form crm-form--stack crm-form--new-deal" novalidate>' +
      companyBlock +
      '<fieldset class="crm-form-section crm-new-deal__section crm-new-deal__section--basics">' +
      '<legend class="crm-form-section__legend">' + escapeHtml(t('Basics')) + '</legend>' +
      '<div class="form-group crm-new-deal__title">' +
      '<label class="form-label" for="' + id + '-title">' + escapeHtml(t('What is this deal?')) + '</label>' +
      '<input class="form-input crm-input" id="' + id + '-title" name="title" required maxlength="255" ' +
      'placeholder="' + escapeHtml(t('e.g. Quote for workshop tools')) + '" />' +
      '<p class="form-help">' + escapeHtml(t('A title is enough. Add notes, value, or a close date if you have them.')) + '</p></div>' +
      '<div class="form-group crm-new-deal__person">' +
      '<label class="form-label" for="' + id + '-person">' + escapeHtml(t('Primary contact')) + '</label>' +
      '<select class="crm-input form-select" id="' + id + '-person" name="personId" disabled>' +
      '<option value="">' + escapeHtml(mode === 'pipeline' ? t('Pick a company first.') : t('Loading…')) + '</option></select>' +
      '<p class="form-help">' + escapeHtml(t('Optional — who is your main contact for this deal?')) + '</p></div>' +
      '<div class="form-group crm-new-deal__lead-source">' +
      '<label class="form-label" for="' + id + '-lead-source">' + escapeHtml(t('Lead source')) + '</label>' +
      '<select class="crm-input form-select" id="' + id + '-lead-source" name="leadSource">' + leadOpts + '</select></div>' +
      '</fieldset>' +
      '<fieldset class="crm-form-section crm-new-deal__section crm-new-deal__section--notes">' +
      '<legend class="crm-form-section__legend">' + escapeHtml(t('Notes')) + '</legend>' +
      '<div class="form-group crm-new-deal__notes">' +
      '<label class="visually-hidden" for="' + id + '-notes">' + escapeHtml(t('Notes')) + '</label>' +
      '<textarea class="crm-input form-input form-textarea" id="' + id + '-notes" name="notes" rows="3" maxlength="4000"></textarea>' +
      '<p class="form-help">' + escapeHtml(t('Internal notes for your team — the customer does not see these.')) + '</p></div>' +
      '</fieldset>' +
      '<details class="crm-advanced crm-new-deal__more">' +
      '<summary class="crm-advanced__summary">' + escapeHtml(t('More options')) + '</summary>' +
      '<div class="crm-advanced__body">' +
      '<div class="form-group"><label class="form-label" for="' + id + '-value">' + escapeHtml(t('Value (EUR, optional)')) + '</label>' +
      '<input class="form-input crm-input" id="' + id + '-value" name="valueEur" type="number" min="0" step="0.01" /></div>' +
      '<div class="form-group"><label class="form-label" for="' + id + '-products">' + escapeHtml(t('Products (optional)')) + '</label>' +
      '<input class="form-input crm-input" id="' + id + '-products" name="products" maxlength="255" /></div>' +
      '<div class="form-group"><label class="form-label" for="' + id + '-close">' + escapeHtml(t('Expected close')) + '</label>' +
      '<input class="crm-input form-input datepicker-input" id="' + id + '-close" name="expectedClose" type="date" autocomplete="off" ' +
      'placeholder="' + escapeHtml(t('dd.mm.yyyy')) + '" /></div>' +
      '<div class="form-group"><label class="form-label" for="' + id + '-stage">' + escapeHtml(t('Stage')) + '</label>' +
      '<select class="crm-input form-select" id="' + id + '-stage" name="stage">' + stageOpts + '</select></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + id + '-owner-search">' + escapeHtml(t('Owner')) + '</label>' +
      '<div class="user-picker" id="' + id + '-owner-picker">' +
      '<div class="user-picker__control">' +
      '<input type="search" id="' + id + '-owner-search" class="form-input crm-input user-picker__search" ' +
      'role="combobox" aria-autocomplete="list" aria-controls="' + id + '-owner-suggest" aria-expanded="false" ' +
      'autocomplete="off" placeholder="' + escapeHtml(t('Search by name or login…')) + '" />' +
      '<button type="button" class="user-picker__clear" id="' + id + '-owner-clear" hidden aria-label="' +
      escapeHtml(t('Clear')) + '">×</button></div>' +
      '<ul id="' + id + '-owner-suggest" class="user-picker__list" role="listbox" hidden></ul>' +
      '<input type="hidden" id="' + id + '-owner" name="ownerUserId" value="" /></div>' +
      '<p class="form-help">' + escapeHtml(t('Leave empty to assign the deal to yourself.')) + '</p></div>' +
      '<div class="form-group"><label class="form-label" for="' + id + '-forecast">' + escapeHtml(t('Win chance')) + '</label>' +
      '<select class="crm-input form-select" id="' + id + '-forecast" name="forecastBucket">' + forecastOpts + '</select>' +
      '<p class="form-help">' + escapeHtml(t('How sure are you this deal will close? 100%% is almost sure — it is not marked won.')) + '</p></div>' +
      '</div></details>' +
      '<div class="crm-form-actions crm-new-deal__actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Create deal')) + '</button>' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>';

    let companyPanel = null;
    let personPanel = null;

    C.openDialog({
      id: id,
      title: t('New deal'),
      content: html,
      size: 'md',
      onClose: function () {
        if (companyPanel && typeof companyPanel.destroy === 'function') {
          companyPanel.destroy();
        }
        if (personPanel && typeof personPanel.destroy === 'function') {
          personPanel.destroy();
        }
        companyPanel = null;
        personPanel = null;
      },
    });

    const form = document.getElementById(id + '-form');
    const dialogEl = document.getElementById(id);
    if (dialogEl) {
      dialogEl.classList.add('crm-dialog--new-deal');
    }
    const close = function () { C.closeModal(dialogEl); };
    form.querySelector('[data-cancel]').addEventListener('click', function () { close(); });

    const titleInput = form.elements.title;
    const personSelect = document.getElementById(id + '-person');
    const PersonPicker = window.CustomerCheckPersonPicker;

    const ownerPickerFactory = typeof mountOwnerPicker === 'function' ? mountOwnerPicker : defaultMountOwnerPicker;
    const ownerPicker = ownerPickerFactory({
      searchId: id + '-owner-search',
      suggestId: id + '-owner-suggest',
      hiddenId: id + '-owner',
      clearId: id + '-owner-clear',
      initialUid: '',
      initialLabel: '',
      workspaceIdFn: activeWorkspaceId,
      api: Api,
    });

    function currentCompanyId() {
      if (mode === 'company360') {
        return fixedCompanyId;
      }
      return parseInt(String(form.elements.companyId.value || '0'), 10) || 0;
    }

    function refreshContacts(preselectId) {
      if (!PersonPicker || typeof PersonPicker.mountPersonSelect !== 'function' || !personSelect) {
        return;
      }
      if (!personPanel) {
        personPanel = PersonPicker.mountPersonSelect({
          selectEl: personSelect,
          api: Api,
          emptyLabel: t('Not set'),
          pickCompanyLabel: t('Pick a company first.'),
          noContactsLabel: t('No contacts yet'),
          errorLabel: t('Could not load contacts'),
        });
      }
      return personPanel.refresh(currentCompanyId(), preselectId);
    }

    const focusTitle = function () {
      if (titleInput && typeof titleInput.focus === 'function') {
        setTimeout(function () { titleInput.focus(); }, 40);
      }
    };

    if (mode === 'company360' && fixedCompanyId > 0) {
      await refreshContacts(0);
      focusTitle();
    }

    if (mode === 'pipeline') {
      const Picker = window.CustomerCheckCompanyPicker;
      let emptied = false;
      if (Picker && typeof Picker.mountListPanel === 'function') {
        companyPanel = Picker.mountListPanel({
          listEl: document.getElementById(id + '-company-list'),
          filterEl: document.getElementById(id + '-company-filter'),
          filterWrapEl: document.getElementById(id + '-company-filter-wrap'),
          browseEl: document.getElementById(id + '-company-browse'),
          choiceEl: document.getElementById(id + '-company-choice'),
          choiceNameEl: document.getElementById(id + '-company-choice-name'),
          changeBtn: document.getElementById(id + '-company-change'),
          hiddenEl: document.getElementById(id + '-company'),
          statusEl: document.getElementById(id + '-company-status'),
          api: Api,
          workspaceIdFn: activeWorkspaceId,
          t: t,
          browseLimit: 40,
          filterMinCount: 8,
          onReady: async function (count) {
            if (count > 0) {
              if (count === 1) {
                await refreshContacts(0);
                focusTitle();
              }
              return;
            }
            if (emptied) {
              return;
            }
            emptied = true;
            if (companyPanel && typeof companyPanel.destroy === 'function') {
              companyPanel.destroy();
            }
            close();
            if (typeof opts.onNoCompanies === 'function') {
              await opts.onNoCompanies();
            }
          },
          onSelect: async function () {
            await refreshContacts(0);
            focusTitle();
          },
        });
      } else {
        close();
        if (typeof opts.onNoCompanies === 'function') {
          await opts.onNoCompanies();
        }
        return false;
      }
    }

    return new Promise(function (resolve) {
      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn && submitBtn.disabled) {
          return;
        }
        const companyId = currentCompanyId();
        const title = String(form.elements.title.value || '').trim();
        const valueCents = Math.round(parseFloat(form.elements.valueEur.value || '0') * 100) || 0;
        const products = String(form.elements.products.value || '').trim();
        const notes = String(form.elements.notes.value || '').trim();
        const stage = String(form.elements.stage.value || 'lead').trim() || 'lead';
        const expectedClose = String((window.CustomerCheckDatepicker && typeof window.CustomerCheckDatepicker.isoValue === 'function')
          ? window.CustomerCheckDatepicker.isoValue(form.elements.expectedClose)
          : (form.elements.expectedClose.value || '')).trim();
        const ownerUserId = ownerPicker.selectedUid();
        const forecastBucket = String(form.elements.forecastBucket.value || '').trim();
        const leadSource = String(form.elements.leadSource.value || 'manual').trim() || 'manual';
        const personId = personPanel ? personPanel.selectedId() : 0;

        if (!companyId || title.length < 1) {
          await C.alertDialog({
            title: t('Almost there'),
            message: mode === 'pipeline'
              ? t('Choose a company and enter a title.')
              : t('Enter a short title for this deal.'),
            variant: 'info',
          });
          return;
        }

        if (submitBtn) {
          submitBtn.disabled = true;
        }
        try {
          const payload = {
            companyId: companyId,
            title: title,
            stage: stage,
            valueCents: valueCents,
            products: products,
            leadSource: leadSource,
          };
          if (notes) {
            payload.notes = notes;
          }
          if (expectedClose) {
            payload.expectedClose = expectedClose;
          }
          if (ownerUserId) {
            payload.ownerUserId = ownerUserId;
          }
          if (forecastBucket) {
            payload.forecastBucket = forecastBucket;
          }
          if (personId > 0) {
            payload.personId = personId;
          }

          const res = await Api.post('/deals', payload);
          const deal = (res && res.data) || {};
          if (companyPanel && typeof companyPanel.destroy === 'function') {
            companyPanel.destroy();
          }
          if (personPanel && typeof personPanel.destroy === 'function') {
            personPanel.destroy();
          }
          close();

          const addedStep = await offerNextStep({
            components: C,
            api: Api,
            t: t,
            escapeHtml: escapeHtml,
            deal: deal,
            companyId: companyId,
            personId: personId > 0 ? personId : (deal.personId || null),
            title: title,
          });

          if (typeof opts.onCreated === 'function') {
            await opts.onCreated(deal, addedStep);
          } else if (typeof opts.onReload === 'function') {
            opts.onReload();
          } else {
            window.location.reload();
          }
          resolve(true);
        } catch (e) {
          if (submitBtn) {
            submitBtn.disabled = false;
          }
          await C.alertDialog({
            title: t('Could not create deal'),
            message: (e.payload && e.payload.message) || e.message,
            variant: 'danger',
          });
        }
      }, { once: false });
    });
  }

  /**
   * Post-create nudge — optional to-do on Today board.
   */
  async function offerNextStep(ctx) {
    const C = ctx.components;
    const Api = ctx.api;
    const t = sprintfT(ctx.t);
    const escapeHtml = ctx.escapeHtml;
    const deal = ctx.deal || {};
    const dealId = parseInt(String(deal.id || '0'), 10) || 0;
    const companyId = parseInt(String(ctx.companyId || deal.companyId || '0'), 10) || 0;
    if (dealId < 1 || companyId < 1 || !C) {
      return false;
    }

    const dialogId = 'crm-next-step-' + Date.now();
    const defaultTitle = t('Follow up: %s', [String(ctx.title || deal.title || '').trim() || t('deal')]);
    const defaultDue = isoDatePlusDays(3);

    const html =
      '<form id="' + dialogId + '-form" class="crm-form crm-form--stack crm-next-step-form">' +
      '<p class="crm-muted" role="status">' + escapeHtml(t('Deal created! Add a next step for your Today board?')) + '</p>' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + dialogId + '-title">' + escapeHtml(t('Next step')) + '</label>' +
      '<input class="form-input crm-input" id="' + dialogId + '-title" name="title" required maxlength="255" ' +
      'value="' + escapeHtml(defaultTitle) + '" /></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + dialogId + '-due">' + escapeHtml(t('Due date')) + '</label>' +
      '<input class="crm-input form-input datepicker-input" id="' + dialogId + '-due" name="dueDate" type="date" ' +
      'value="' + escapeHtml(defaultDue) + '" autocomplete="off" required /></div>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Add to Today')) + '</button> ' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-skip>' + escapeHtml(t('Skip')) + '</button></div></form>';

    return new Promise(function (resolve) {
      C.openDialog({
        id: dialogId,
        title: t('Next step'),
        content: html,
        size: 'sm',
      });
      const form = document.getElementById(dialogId + '-form');
      const dialogEl = document.getElementById(dialogId);
      const close = function () { C.closeModal(dialogEl); };

      form.querySelector('[data-skip]').addEventListener('click', function () {
        close();
        resolve(false);
      });

      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
        }
        const stepTitle = String(form.elements.title.value || '').trim();
        const dueDate = String((window.CustomerCheckDatepicker && typeof window.CustomerCheckDatepicker.isoValue === 'function')
          ? window.CustomerCheckDatepicker.isoValue(form.elements.dueDate)
          : (form.elements.dueDate.value || '')).trim();
        if (!stepTitle || !dueDate) {
          if (submitBtn) {
            submitBtn.disabled = false;
          }
          await C.alertDialog({
            title: t('Almost there'),
            message: t('Enter a title and due date for the next step.'),
            variant: 'info',
          });
          return;
        }
        try {
          const body = {
            companyId: companyId,
            dealId: dealId,
            title: stepTitle,
            dueDate: dueDate,
            type: 'todo',
          };
          const personId = parseInt(String(ctx.personId || deal.personId || '0'), 10);
          if (personId > 0) {
            body.personId = personId;
          }
          await Api.post('/activities', body);
          const live = document.getElementById('crm-live-region');
          if (live) {
            live.textContent = t('Next step added to Today.');
          }
          close();
          resolve(true);
        } catch (e) {
          if (submitBtn) {
            submitBtn.disabled = false;
          }
          await C.alertDialog({
            title: t('Could not add next step'),
            message: (e.payload && e.payload.message) || e.message,
            variant: 'danger',
          });
        }
      });
    });
  }

  window.CustomerCheckNewDealForm = {
    open: openNewDealForm,
    offerNextStep: offerNextStep,
    OPEN_STAGES: OPEN_STAGES,
    WIN_CHANCE: WIN_CHANCE,
    winChanceOptionsHtml: winChanceOptionsHtml,
  };
})();
