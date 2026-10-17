(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const t = (msg, args) => {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
    // Nextcloud t() sometimes leaves %s / %1$s — fill locally (same as settings.js).
    if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
      let i = 0;
      out = out.replace(/%%|%(?:\d+\$)?s/g, function (m) {
        if (m === '%%') { return '%'; }
        const v = list[i];
        i += 1;
        return v == null ? '' : String(v);
      });
    }
    return out;
  };
  function escapeHtml(s) {
    return String(s || '').replace(/[<>&"]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[ch]));
  }
  /** Local calendar YYYY-MM-DD — never toISOString (UTC day shift). */
  function localYmd(d) {
    const x = d instanceof Date ? d : new Date();
    const y = x.getFullYear();
    const m = String(x.getMonth() + 1).padStart(2, '0');
    const day = String(x.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function durationMinuteOptionsHtml(selected) {
    const DP = window.CustomerCheckDatepicker;
    const mins = (DP && DP.DURATION_MINUTES) ? DP.DURATION_MINUTES : [15, 30, 45, 60, 90, 120, 180, 240, 360, 480];
    const picked = DP && typeof DP.snapDurationMinutes === 'function'
      ? DP.snapDurationMinutes(selected)
      : 60;
    return mins.map((m) => {
      const label = DP && typeof DP.formatDurationLabel === 'function'
        ? DP.formatDurationLabel(m)
        : String(m);
      return '<option value="' + m + '"' + (m === picked ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
    }).join('');
  }
  if (C && C.init) { C.init(); }

  const ListToolbar = window.CustomerCheckListToolbar;
  if (ListToolbar && typeof ListToolbar.bind === 'function') {
    ListToolbar.bind({
      controlsId: 'crm-agreements-controls',
      toggleId: 'crm-agreements-filter-toggle',
      panelId: 'crm-agreements-filter',
    });
  }

  const Api = window.CustomerCheckApi;
  const SharedMail = window.CustomerCheckSharedMail || null;

  async function openLogHours(agreementId) {
    if (!C || !Api) return;
    const id = 'crm-hours-' + Date.now();
    const today = localYmd();
    C.openDialog({
      id: id,
      title: t('Log hours'),
      content: '<form id="' + id + '-form" class="crm-form crm-form--stack">' +
        '<div class="form-group"><label class="form-label" for="' + id + '-date">' + escapeHtml(t('Date')) + '</label><input class="crm-input form-input datepicker-input" id="' + id + '-date" name="entryDate" type="date" value="' + escapeHtml(today) + '" required autocomplete="off" /></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-minutes">' + escapeHtml(t('Minutes')) + '</label><select class="crm-input form-select" id="' + id + '-minutes" name="minutes" required>' + durationMinuteOptionsHtml(60) + '</select><p class="form-help">' + escapeHtml(t('Steps of 15. Default is one hour.')) + '</p></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-topic">' + escapeHtml(t('Topic')) + '</label><input class="crm-input form-input" id="' + id + '-topic" name="topic" required autofocus /></div>' +
        '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save')) + '</button> <button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>',
    });
    const form = document.getElementById(id + '-form');
    const close = () => C.closeModal(document.getElementById(id));
    form.querySelector('[data-cancel]').addEventListener('click', close);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn && (submitBtn.disabled || submitBtn.getAttribute('aria-busy') === 'true')) {
        return;
      }
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
      }
      let minutes = parseInt(form.elements.minutes.value, 10);
      const DP = window.CustomerCheckDatepicker;
      if (DP && typeof DP.snapDurationMinutes === 'function') {
        minutes = DP.snapDurationMinutes(minutes);
      } else {
        if (!Number.isFinite(minutes) || minutes < 15) minutes = 15;
        minutes = Math.round(minutes / 15) * 15;
      }
      try {
        const dateEl = form.elements.entryDate;
        await Api.post('/agreements/' + agreementId + '/hours', {
          entryDate: (DP && typeof DP.isoValue === 'function') ? DP.isoValue(dateEl) : dateEl.value,
          minutes: minutes,
          topic: form.elements.topic.value,
        });
        close();
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Hours logged');
        if (window.CustomerCheckFilterLive && typeof window.CustomerCheckFilterLive.refresh === 'function') {
          await window.CustomerCheckFilterLive.refresh();
        } else {
          location.reload();
        }
      } catch (e) {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.removeAttribute('aria-busy');
        }
        await C.alertDialog({ title: t('Could not log hours'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  document.addEventListener('click', async (ev) => {
    const target = ev.target;
    if (!target || typeof target.closest !== 'function') {
      return;
    }
    const partner = target.closest('[data-crm-partner-session], [data-crm-partner-mail]');
    const hours = target.closest('[data-crm-log-hours]');
    const pack = target.closest('[data-crm-delivery-pack]');
    if (hours && !partner && !pack) {
      ev.preventDefault();
      await openLogHours(hours.getAttribute('data-crm-log-hours'));
      return;
    }
    if (pack && !partner) {
      ev.preventDefault();
      if (pack.getAttribute('aria-busy') === 'true') {
        return;
      }
      pack.setAttribute('aria-busy', 'true');
      try {
        const res = await Api.post('/agreements/' + pack.getAttribute('data-crm-delivery-pack') + '/delivery-pack', {});
        const packData = res.data || {};
        if (!C) return;
        const companyId = parseInt(String(pack.getAttribute('data-company-id') || '0'), 10);
        if (SharedMail) {
          await SharedMail.offerPreparedMail(packData, { companyId: companyId, title: t('Delivery pack') });
          return;
        }
        await C.openDialog({
          id: 'crm-delivery-pack',
          title: t('Delivery pack'),
          content: (typeof C.buildMailPackDialogHtml === 'function')
            ? C.buildMailPackDialogHtml(packData, { t: t })
            : ('<p><strong>' + (C._escapeHtml ? C._escapeHtml(packData.subject || '') : '') + '</strong></p>'),
          size: 'md',
        });
      } catch (e) {
        if (C) await C.alertDialog({ title: t('Delivery pack failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      } finally {
        pack.removeAttribute('aria-busy');
      }
      return;
    }
    if (!partner) {
      return;
    }
    ev.preventDefault();
    if (partner.getAttribute('aria-busy') === 'true') {
      return;
    }
    partner.setAttribute('aria-busy', 'true');
    const agreementId = partner.getAttribute('data-crm-partner-session') || partner.getAttribute('data-crm-partner-mail');
    const skipConfirm = partner.hasAttribute('data-crm-partner-session');
    try {
      const res = await Api.post('/agreements/' + agreementId + '/partner-mail', { promptHours: true });
      const data = res.data || res;
      const companyId = parseInt(String(partner.getAttribute('data-company-id') || '0'), 10);
      if (SharedMail) {
        await SharedMail.offerPreparedMail(data, { companyId: companyId, title: t('Partner mail') });
      } else if (data.mailAppUrl) {
        window.open(data.mailAppUrl, '_blank');
      } else if (data.mailtoUrl) {
        window.location.href = data.mailtoUrl;
      }
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Partner mail prepared.');
      if (data.promptHours) {
        if (skipConfirm) {
          await openLogHours(agreementId);
        } else if (C) {
          const log = await C.confirmDialog({
            title: t('Log hours?'),
            message: t('Log hours for this Partner session?'),
            confirmLabel: t('Log hours'),
            cancelLabel: t('Not now'),
            variant: 'info',
          });
          if (log && log.confirmed) await openLogHours(agreementId);
        }
      }
    } catch (e) {
      if (C) await C.alertDialog({ title: t('Partner mail'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    } finally {
      partner.removeAttribute('aria-busy');
    }
  });
})();
