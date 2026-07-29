/**
 * Persona OS pages — Pulse / Brief / My Accounts / Team / Strategy / Mail triage / Prepare.
 * AZC-simple: one card, clear list, text badges, ≥44px targets.
 */
(function () {
  'use strict';

  const t = (msg, args) => (window.t ? window.t('customercheck', msg, args || []) : msg);

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }


  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /** Chase reason codes — never show snake_case in the UI. */
  function chaseReasonLabel(code) {
    const key = String(code || '');
    const map = {
      lead_sla: t('Lead SLA overdue'),
      close_overdue: t('Close date overdue'),
      no_next_action: t('No next action'),
      quote_followup_due: t('Quote follow-up due'),
      close_this_week: t('Closing this week'),
      stale_stage: t('Stage went stale'),
      unowned: t('No owner'),
    };
    return map[key] || (key ? key : t('Needs chase'));
  }

  /** Commitment status — never show raw API codes. */
  function commitmentStatusLabel(status) {
    const key = String(status || '');
    const map = {
      committed: t('Committed'),
      done: t('Done'),
      missed: t('Missed'),
      carried: t('Carried'),
      abandoned: t('Abandoned'),
    };
    return map[key] || (key ? key : t('Unknown'));
  }

  /** Brief commitment kinds — human titles for 1-click suggestions. */
  function commitmentKindLabel(kind) {
    const key = String(kind || '');
    const map = {
      ar_chase: t('Chase overdue money'),
      renewal_act: t('Act on renewals'),
      plan_push: t('Push a stalled plan'),
      link_debt: t('Fix missing links'),
      unbilled_review: t('Review unbilled hours'),
      custom: t('Custom promise'),
    };
    return map[key] || (key ? key : t('Custom promise'));
  }

  /** Pulse attention kind fallback when API omits kindLabel. */
  function pulseAttentionKindLabel(kind) {
    const key = String(kind || '');
    const map = {
      renewal_hot: t('Renewal soon'),
      overdue_invoice: t('Overdue invoice'),
      plan_stalled: t('Plan stalled'),
      plan_at_risk: t('Plan at risk'),
    };
    return map[key] || (key ? key : t('Attention'));
  }

  function workspaceId() {
    const fromUrl = new URLSearchParams(window.location.search).get('workspaceId');
    if (fromUrl) return parseInt(fromUrl, 10);
    const root = qs('[data-crm-workspace-switch]');
    if (root) {
      const attr = root.getAttribute('data-crm-active-workspace');
      if (attr) {
        const n = parseInt(attr, 10);
        if (n > 0) return n;
      }
    }
    const sw = qs('#crm-workspace-switch');
    if (sw && sw.value) return parseInt(sw.value, 10);
    return 0;
  }

  /**
   * Resolve workspace after navigation.js populates the switcher (race-safe).
   * Falls back to /api/v1/workspaces current id.
   */
  async function resolveWorkspaceId() {
    let id = workspaceId();
    if (id > 0) {
      return id;
    }
    for (let i = 0; i < 40; i++) {
      await new Promise(function (r) { setTimeout(r, 50); });
      id = workspaceId();
      if (id > 0) {
        return id;
      }
    }
    try {
      const data = await apiGet('/api/v1/workspaces');
      const current = data && data.current && data.current.id;
      if (current) {
        return parseInt(current, 10);
      }
      const first = (data.workspaces || [])[0];
      if (first && first.id) {
        return parseInt(first.id, 10);
      }
    } catch (e) {
      /* leave id 0 — callers show Select a workspace */
    }
    return 0;
  }

  function companyIdFromContext(mount) {
    const attr = mount && mount.getAttribute('data-company-id');
    if (attr && parseInt(attr, 10) > 0) {
      return parseInt(attr, 10);
    }
    const m = window.location.pathname.match(/\/companies\/(\d+)\/prepare-meeting/);
    return m ? parseInt(m[1], 10) : 0;
  }

  async function apiGet(path) {
    const res = await fetch(OC.generateUrl('/apps/customercheck' + path), {
      credentials: 'same-origin',
      headers: { requesttoken: OC.requestToken },
    });
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.message || 'Request failed');
    }
    return json.data;
  }

  async function apiPost(path, body) {
    const res = await fetch(OC.generateUrl('/apps/customercheck' + path), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        requesttoken: OC.requestToken,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body || {}),
    });
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.message || 'Request failed');
    }
    return json.data;
  }

  async function apiPut(path, body) {
    const res = await fetch(OC.generateUrl('/apps/customercheck' + path), {
      method: 'PUT',
      credentials: 'same-origin',
      headers: {
        requesttoken: OC.requestToken,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body || {}),
    });
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.message || 'Request failed');
    }
    return json.data;
  }

  function euros(cents) {
    if (cents == null) return '—';
    return (Number(cents) / 100).toLocaleString(undefined, { style: 'currency', currency: 'EUR' });
  }

  function showAlert(parent, message) {
    const existing = parent.querySelector('[data-crm-inline-alert]');
    if (existing) existing.remove();
    const alert = document.createElement('p');
    alert.setAttribute('role', 'alert');
    alert.setAttribute('data-crm-inline-alert', '1');
    alert.textContent = message || String(message);
    parent.prepend(alert);
  }

  function clearMountBusy(mount) {
    if (!mount) return;
    mount.removeAttribute('aria-busy');
    mount.classList.remove('crm-skeleton');
  }

  function renderList(mount, items, emptyMsg) {
    renderSuiteTable(mount, items, emptyMsg, t('Items'));
  }

  /**
   * Suite table — same pattern as Today / Companies (What · Detail · Action).
   * @param {HTMLElement} mount
   * @param {Array<{title?: string, name?: string, why?: string, meta?: string, href?: string, cta?: string, actionHtml?: string}>} items
   * @param {string} emptyMsg
   * @param {string} ariaLabel
   */
  function renderSuiteTable(mount, items, emptyMsg, ariaLabel) {
    clearMountBusy(mount);
    if (!items || !items.length) {
      mount.innerHTML = '<div class="crm-callout crm-callout--success" role="status"><p>' +
        escapeHtml(emptyMsg) + '</p></div>';
      return;
    }
    const hasRichActions = items.some(function (it) { return !!it.actionHtml; });
    const actionColClass = 'crm-table-actions-col' + (hasRichActions ? ' crm-table-actions-col--wide' : '');
    let rows = '';
    items.forEach(function (it) {
      const title = it.title || it.name || it.why || '';
      const meta = it.meta || '';
      const href = it.href || '';
      const cta = it.cta || t('Open');
      let action;
      if (it.actionHtml) {
        action = it.actionHtml;
      } else if (href) {
        action = '<a class="crm-btn crm-btn--sm crm-btn--primary" href="' + escapeHtml(href) + '">' + escapeHtml(cta) + '</a>';
      } else {
        action = '<span class="crm-muted">—</span>';
      }
      rows += '<tr class="crm-today-row">' +
        '<td data-label="' + escapeHtml(t('What')) + '"><span class="crm-today-what">' + escapeHtml(title) + '</span></td>' +
        '<td data-label="' + escapeHtml(t('Detail')) + '">' + (meta ? escapeHtml(meta) : '—') + '</td>' +
        '<td data-label="' + escapeHtml(t('Action')) + '" class="' + actionColClass + '"><div class="crm-table-actions">' +
        action + '</div></td></tr>';
    });
    mount.innerHTML =
      '<div class="table-container crm-table--responsive" role="region" aria-label="' + escapeHtml(ariaLabel) + '">' +
      '<table class="table table--hover">' +
      '<thead><tr>' +
      '<th scope="col">' + escapeHtml(t('What')) + '</th>' +
      '<th scope="col">' + escapeHtml(t('Detail')) + '</th>' +
      '<th scope="col" class="' + actionColClass + '">' + escapeHtml(t('Action')) + '</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  function pulseStat(value, label, hint, variant) {
    const tip = hint ? String(hint) : '';
    const titleAttr = tip ? ' title="' + escapeHtml(tip) + '"' : '';
    const aria = tip
      ? ' aria-label="' + escapeHtml(String(label) + ': ' + String(value) + '. ' + tip) + '"'
      : '';
    const mod = variant === 'alert' ? ' crm-today-stat--alert' : '';
    return '<div class="crm-today-stat' + mod + '" role="listitem"' + titleAttr + aria + '>' +
      '<span class="crm-today-stat__value"' + (tip ? ' aria-hidden="true"' : '') + '>' +
      escapeHtml(String(value)) + '</span>' +
      '<span class="crm-today-stat__label"' + (tip ? ' aria-hidden="true"' : '') + '>' +
      escapeHtml(label) + '</span>' +
      '</div>';
  }

  function focusCapacityIfHash() {
    if (window.location.hash !== '#capacity') return;
    const el = document.getElementById('capacity');
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.focus({ preventScroll: true });
  }

  async function loadPulse(mount) {
    const ws = await resolveWorkspaceId();
    if (!ws) {
      clearMountBusy(mount);
      mount.innerHTML = '<div class="crm-callout crm-callout--danger" role="alert"><p>' +
        escapeHtml(t('Select a workspace first.')) + '</p></div>';
      return;
    }
    const data = await apiGet('/api/v1/workspaces/' + ws + '/pulse');
    const m = data.metrics || {};
    const unbilled = m.unbilled || {};
    let unbilledValue = '—';
    if (unbilled.state === 'ok' && unbilled.openBillableMinutes != null) {
      unbilledValue = (unbilled.openBillableMinutes / 60).toFixed(1) + 'h';
    } else if (unbilled.displayValue) {
      unbilledValue = String(unbilled.displayValue);
    }
    const unbilledHint = unbilled.hint || (
      unbilled.state === 'honesty_blocked'
        ? t('Unbilled hours are not ready yet — we will not guess zero.')
        : (unbilled.state === 'unavailable'
          ? t('ProjectCheck is off — unbilled hours cannot be shown.')
          : '')
    );
    const todayHref = OC.generateUrl('/apps/customercheck/today') + '?workspaceId=' + ws;
    const debt = (data.linkDebt || []).map(function (d) {
      return {
        title: d.name,
        meta: t('Missing') + ': ' + (d.missing || []).join(', '),
        href: OC.generateUrl('/apps/customercheck/companies/' + d.companyId) + '?workspaceId=' + ws,
      };
    });
    const attention = (data.attention || []).slice(0, 25).map(function (a) {
      const metaParts = [];
      const kindLabel = a.kindLabel || pulseAttentionKindLabel(a.kind);
      if (kindLabel) metaParts.push(String(kindLabel));
      if (a.daysUntil != null) metaParts.push(t('%s days left', [String(a.daysUntil)]));
      if (a.amountCents != null) metaParts.push(euros(a.amountCents));
      return {
        title: a.label || a.why || t('Attention'),
        meta: metaParts.join(' · '),
        href: a.companyId
          ? OC.generateUrl('/apps/customercheck/companies/' + a.companyId) + '?workspaceId=' + ws
          : undefined,
      };
    });

    const fed =
      t('Data from') + ': ProjectCheck ' + (data.fedBy && data.fedBy.projectcheck ? '✓' : '—') +
      ' · InvoiceCheck ' + (data.fedBy && data.fedBy.invoicecheck ? '✓' : '—') +
      ' · TicketCheck ' + (data.fedBy && data.fedBy.ticketcheck ? '✓' : '—');
    const companiesN = m.activeCustomers || 0;
    const overdueCents = Number(m.overdueCents) || 0;
    const glanceLead = t('Companies') + ': ' + String(companiesN) + ' · ' + fed;

    clearMountBusy(mount);
    mount.innerHTML =
      '<nav class="crm-quick-filters" aria-label="' + escapeHtml(t('Jump to section')) + '">' +
      '<a class="crm-quick-filter" href="#crm-pulse-metrics">' + escapeHtml(t('At a glance')) + '</a>' +
      '<a class="crm-quick-filter" href="#crm-pulse-attention">' + escapeHtml(t('Attention')) +
      '<span class="crm-quick-filter__count" aria-hidden="true">' + attention.length + '</span></a>' +
      '<a class="crm-quick-filter" href="#crm-pulse-debt">' + escapeHtml(t('Missing links')) +
      '<span class="crm-quick-filter__count" aria-hidden="true">' + debt.length + '</span></a>' +
      '</nav>' +

      '<section class="crm-card crm-pulse-glance" id="crm-pulse-metrics" aria-labelledby="crm-pulse-metrics-title">' +
      '<header class="crm-card__header">' +
      '<div class="crm-card__header-text">' +
      '<h2 id="crm-pulse-metrics-title" class="crm-card__title">' + escapeHtml(t('At a glance')) + '</h2>' +
      '<p class="crm-card__lead">' + escapeHtml(glanceLead) + '</p>' +
      '</div>' +
      '<a class="crm-btn crm-btn--primary" href="' + escapeHtml(todayHref) + '">' + escapeHtml(t('Go to Today')) + '</a>' +
      '</header>' +
      '<div class="crm-card__body">' +
      '<div class="crm-pulse-glance__group" role="group" aria-labelledby="crm-pulse-money-title">' +
      '<h3 id="crm-pulse-money-title" class="crm-pulse-glance__heading">' + escapeHtml(t('Cash')) + '</h3>' +
      '<div class="crm-today-role__stats crm-pulse-glance__stats" role="list">' +
      pulseStat(euros(overdueCents), t('Overdue'), null, overdueCents > 0 ? 'alert' : '') +
      pulseStat(euros(m.openCents), t('Unpaid')) +
      pulseStat(euros(m.paid30dCents), t('Paid lately')) +
      pulseStat(euros(m.pipelineCents), t('Pipeline')) +
      '</div></div>' +
      '<div class="crm-pulse-glance__group" role="group" aria-labelledby="crm-pulse-work-title">' +
      '<h3 id="crm-pulse-work-title" class="crm-pulse-glance__heading">' + escapeHtml(t('Work')) + '</h3>' +
      '<div class="crm-today-role__stats crm-pulse-glance__stats" role="list">' +
      pulseStat(unbilledValue, t('Unbilled hours'), unbilledHint) +
      pulseStat(m.waitingTickets || 0, t('Waiting tickets')) +
      pulseStat(m.renewals30d || 0, t('Renewals soon')) +
      pulseStat(m.openProjects != null ? m.openProjects : '—', t('Open projects')) +
      '</div></div>' +
      (unbilled.state && unbilled.state !== 'ok' && unbilledHint
        ? '<p class="crm-muted" role="status">' + escapeHtml(unbilledHint) + '</p>'
        : '') +
      '</div></section>' +

      '<section class="crm-card" id="crm-pulse-attention" aria-labelledby="crm-pulse-attention-title">' +
      '<header class="crm-card__header">' +
      '<h2 id="crm-pulse-attention-title" class="crm-card__title">' + escapeHtml(t('Needs attention')) +
      ' <span class="crm-badge crm-badge--neutral">' + attention.length + '</span></h2>' +
      '</header>' +
      '<div class="crm-card__body" data-crm-pulse-attention></div></section>' +

      '<section class="crm-card" id="crm-pulse-debt" aria-labelledby="crm-pulse-debt-title">' +
      '<header class="crm-card__header">' +
      '<h2 id="crm-pulse-debt-title" class="crm-card__title">' + escapeHtml(t('Missing links')) +
      ' <span class="crm-badge crm-badge--neutral">' + debt.length + '</span></h2>' +
      '</header>' +
      '<div class="crm-card__body" data-crm-pulse-debt></div></section>';

    renderSuiteTable(
      mount.querySelector('[data-crm-pulse-attention]'),
      attention,
      t('Nothing needs attention right now.'),
      t('Attention queue'),
    );
    renderSuiteTable(
      mount.querySelector('[data-crm-pulse-debt]'),
      debt,
      t('No link debt — nice.'),
      t('Missing links'),
    );
  }

  function verifyActionsHtml(id) {
    return '<div class="crm-brief-verify" role="group" aria-label="' + escapeHtml(t('Check this promise')) + '">' +
      '<label class="crm-sr-only" for="crm-evidence-' + id + '">' + t('Evidence') + '</label>' +
      '<select id="crm-evidence-' + id + '" class="crm-input crm-input--sm" data-evidence-for="' + id + '" aria-label="' + escapeHtml(t('Evidence for done')) + '">' +
      '<option value="">' + t('Evidence for done…') + '</option>' +
      '<option value="invoice_paid">' + t('Invoice paid') + '</option>' +
      '<option value="activity_done">' + t('Activity done') + '</option>' +
      '<option value="links_fixed">' + t('Links fixed') + '</option>' +
      '<option value="plan_healthy">' + t('Plan healthy') + '</option>' +
      '<option value="manager_override">' + t('Manager override') + '</option>' +
      '</select>' +
      '<label class="crm-sr-only" for="crm-evidence-note-' + id + '">' + t('Reason for manager override') + '</label>' +
      '<input id="crm-evidence-note-' + id + '" type="text" class="crm-input crm-input--sm" data-evidence-note-for="' + id + '" placeholder="' + escapeHtml(t('Reason for manager override')) + '" autocomplete="off" />' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-verify="' + id + '" data-action="done">' + t('Done') + '</button>' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-verify="' + id + '" data-action="missed">' + t('Missed') + '</button>' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-verify="' + id + '" data-action="carry">' + t('Carry') + '</button>' +
      '<button type="button" class="crm-btn crm-btn--ghost" data-verify="' + id + '" data-action="abandon">' + t('Abandon') + '</button>' +
      '</div>';
  }

  async function loadBrief(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/brief');
    const cash = data.cash || {};
    clearMountBusy(mount);
    mount.innerHTML =
      '<section class="crm-card crm-today-role" aria-labelledby="crm-brief-wip-title">' +
      '<header class="crm-card__header"><div class="crm-card__header-text">' +
      '<h2 id="crm-brief-wip-title" class="crm-card__title">' + escapeHtml(t('Weekly Brief')) + '</h2>' +
      '<p class="crm-card__lead">' + escapeHtml(t('WIP')) + ' ' + (data.wipUsed || 0) + '/' + (data.wipLimit || 5) +
      (data.weekKey ? ' · ' + escapeHtml(data.weekKey) : '') + '</p></div></header>' +
      (data.forecastDisclaimer
        ? '<div class="crm-card__body"><p class="crm-muted">' + escapeHtml(data.forecastDisclaimer) + '</p></div>'
        : '') +
      '</section>' +
      '<section class="crm-card" aria-labelledby="crm-brief-cash-title">' +
      '<header class="crm-card__header"><h2 id="crm-brief-cash-title" class="crm-card__title">' +
      escapeHtml(t('Cash calendar')) + '</h2></header>' +
      '<div class="crm-card__body">' +
      '<div class="crm-today-role__stats" role="group" aria-label="' + escapeHtml(t('Cash calendar')) + '">' +
      pulseStat(euros(cash.overdueArCents), t('Overdue AR')) +
      pulseStat(euros(cash.arDueThisMonthCents), t('AR due this month')) +
      pulseStat(euros(cash.arDueNextMonthCents), t('AR due next month')) +
      pulseStat(euros(cash.renewalsThisMonthCents), t('Renewals this month')) +
      pulseStat(euros(cash.renewalsNextMonthCents), t('Renewals next month')) +
      pulseStat(euros(cash.expectedInThisMonthCents), t('Expected cash this month')) +
      '</div>' +
      '<p class="crm-muted" role="status">' + escapeHtml(t('pipeline excluded')) + ': ' + euros(cash.pipelineExcludedCents) +
      ' · ' + escapeHtml(t('Never multiply pipeline by hope.')) + '</p>' +
      '</div></section>' +
      '<section class="crm-card" aria-labelledby="crm-brief-last-title">' +
      '<header class="crm-card__header"><h2 id="crm-brief-last-title" class="crm-card__title">' +
      escapeHtml(t('Last week')) + '</h2></header><div class="crm-card__body" data-crm-brief-last></div></section>';

    const last = mount.querySelector('[data-crm-brief-last]');
    const lastItems = data.lastWeek || [];
    renderSuiteTable(
      last,
      lastItems.map(function (c) {
        const status = String(c.status || '');
        const needsVerify = status === 'committed' || status === 'missed';
        const metaParts = [commitmentStatusLabel(status)];
        if (c.dueOn) metaParts.push(String(c.dueOn));
        return {
          title: c.title || t('Commitment'),
          meta: metaParts.join(' · '),
          actionHtml: needsVerify && c.id
            ? verifyActionsHtml(c.id)
            : '<span class="crm-muted">—</span>',
        };
      }),
      t('No commitments from last week.'),
      t('Last week'),
    );
    if (lastItems.length) {
      last.addEventListener('click', async function (ev) {
        const btn = ev.target.closest('[data-verify][data-action]');
        if (!btn) return;
        const id = btn.getAttribute('data-verify');
        const action = btn.getAttribute('data-action');
        const payload = { action: action };
        if (action === 'done') {
          const sel = last.querySelector('[data-evidence-for="' + id + '"]');
          const evidence = sel ? String(sel.value || '').trim() : '';
          if (!evidence) {
            showAlert(mount, t('Choose evidence before marking done.'));
            return;
          }
          payload.evidence = evidence;
          if (evidence === 'manager_override') {
            const noteEl = last.querySelector('[data-evidence-note-for="' + id + '"]');
            const note = noteEl ? String(noteEl.value || '').trim() : '';
            if (!note) {
              showAlert(mount, t('Manager override needs a reason.'));
              return;
            }
            payload.note = note;
          }
        }
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/brief/' + id + '/verify', payload);
          await loadBrief(mount);
        } catch (e) {
          showAlert(mount, e.message || String(e));
        }
      });
    }

    const thisWeek = document.createElement('section');
    thisWeek.className = 'crm-card';
    thisWeek.setAttribute('aria-labelledby', 'crm-brief-this-title');
    thisWeek.innerHTML =
      '<header class="crm-card__header"><h2 id="crm-brief-this-title" class="crm-card__title">' +
      escapeHtml(t('This week')) + '</h2></header><div class="crm-card__body" data-crm-brief-this></div>';
    mount.appendChild(thisWeek);
    const list = thisWeek.querySelector('[data-crm-brief-this]');
    renderList(list, (data.commitments || []).map(function (c) {
      return {
        title: c.title,
        meta: commitmentStatusLabel(c.status) + (c.dueOn ? ' · ' + c.dueOn : ''),
      };
    }), t('No promises yet this week.'));

    const wipUsed = Number(data.wipUsed) || 0;
    const wipLimit = Number(data.wipLimit) || 5;
    const wipLeft = Math.max(0, wipLimit - wipUsed);
    const canConfirm = wipLeft > 0;
    const suggestedKinds = (data.suggestedKinds || []).filter(function (k) {
      return k && k !== 'custom';
    });

    const formCard = document.createElement('section');
    formCard.className = 'crm-card';
    formCard.id = 'crm-brief-add';
    formCard.setAttribute('aria-labelledby', 'crm-brief-add-title');

    if (!canConfirm) {
      formCard.innerHTML =
        '<header class="crm-card__header"><div class="crm-card__header-text">' +
        '<h2 id="crm-brief-add-title" class="crm-card__title">' + escapeHtml(t('Add a promise')) + '</h2>' +
        '<p class="crm-card__lead">' + escapeHtml(t('Your week is full.')) + '</p></div></header>' +
        '<div class="crm-card__body">' +
        '<div class="crm-callout" role="status"><p>' +
        escapeHtml(t('Weekly WIP limit reached. Finish or abandon a commitment first.')) +
        '</p></div></div>';
      mount.appendChild(formCard);
      return;
    }

    let suggestHtml = '';
    if (suggestedKinds.length) {
      suggestHtml =
        '<div class="crm-brief-suggest" role="group" aria-label="' + escapeHtml(t('Quick promises')) + '">' +
        '<p class="crm-brief-suggest__lead">' + escapeHtml(t('Or tap a ready-made promise:')) + '</p>' +
        '<div class="crm-brief-suggest__actions">';
      suggestedKinds.forEach(function (kind) {
        suggestHtml +=
          '<button type="button" class="crm-btn crm-btn--secondary" data-crm-brief-suggest="' +
          escapeHtml(String(kind)) + '">' + escapeHtml(commitmentKindLabel(kind)) + '</button>';
      });
      suggestHtml += '</div></div>';
    }

    const slotsLead = t('Slots left') + ': ' + String(wipLeft) + '/' + String(wipLimit) +
      '. ' + t('One clear sentence about what you will finish.');

    formCard.innerHTML =
      '<header class="crm-card__header"><div class="crm-card__header-text">' +
      '<h2 id="crm-brief-add-title" class="crm-card__title">' + escapeHtml(t('Add a promise')) + '</h2>' +
      '<p class="crm-card__lead">' + escapeHtml(slotsLead) + '</p></div></header>' +
      '<div class="crm-card__body">' +
      suggestHtml +
      '<form class="crm-form crm-form--stack" id="crm-brief-confirm-form" aria-labelledby="crm-brief-add-title">' +
      '<div class="form-group">' +
      '<label class="form-label" for="crm-brief-title">' + escapeHtml(t('What will you finish?')) + '</label>' +
      '<input id="crm-brief-title" name="title" type="text" required maxlength="255" ' +
      'class="crm-input form-input" autocomplete="off" aria-describedby="crm-brief-title-help" ' +
      'placeholder="' + escapeHtml(t('Example: Chase Acme overdue invoice')) + '" />' +
      '<p class="form-help" id="crm-brief-title-help">' +
      escapeHtml(t('Name the company or task so your future self understands it.')) + '</p>' +
      '</div>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary" id="crm-brief-confirm-submit">' +
      escapeHtml(t('Add to this week')) + '</button>' +
      '</div></form></div>';
    mount.appendChild(formCard);

    async function postCommitment(title, kind) {
      const clean = String(title || '').trim();
      if (!clean) return;
      const submitBtn = formCard.querySelector('#crm-brief-confirm-submit');
      const suggestBtns = formCard.querySelectorAll('[data-crm-brief-suggest]');
      if (submitBtn) submitBtn.disabled = true;
      suggestBtns.forEach(function (b) { b.disabled = true; });
      try {
        await apiPost('/api/v1/workspaces/' + ws + '/brief/confirm', {
          weekKey: data.weekKey,
          title: clean,
          kind: kind || 'custom',
        });
        await loadBrief(mount);
      } catch (e) {
        if (submitBtn) submitBtn.disabled = false;
        suggestBtns.forEach(function (b) { b.disabled = false; });
        showAlert(formCard, e.message || String(e));
      }
    }

    const form = formCard.querySelector('#crm-brief-confirm-form');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      const title = form.querySelector('#crm-brief-title').value;
      postCommitment(title, 'custom');
    });
    formCard.addEventListener('click', function (ev) {
      const btn = ev.target.closest('[data-crm-brief-suggest]');
      if (!btn) return;
      const kind = btn.getAttribute('data-crm-brief-suggest');
      postCommitment(commitmentKindLabel(kind), kind);
    });
  }

  async function loadAccounts(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/my-accounts');
    renderList(mount, (data.items || []).map(function (a) {
      const cover = a.covering
        ? (' · ' + t('Covering') + (a.coveringPrimary ? ' ' + a.coveringPrimary : ''))
        : '';
      return {
        title: a.name,
        meta: (a.gap ? t('Gap') : t('Covered')) +
          (a.nextTouchOn ? ' · ' + a.nextTouchOn : '') + cover,
        href: OC.generateUrl('/apps/customercheck/companies/' + a.id) + '?workspaceId=' + ws,
      };
    }), t('No accounts assigned to you yet.'));
  }

  function teamFormsHtml() {
    return '' +
      '<div class="crm-team-capacity__grid">' +
      '<article class="crm-team-capacity__card">' +
      '<h3 class="crm-team-capacity__title" id="crm-ooo-title">' + escapeHtml(t('Cover while away')) + '</h3>' +
      '<p class="crm-team-capacity__lead">' + escapeHtml(t('Pick who is out and who covers them. Ownership stays with the primary.')) + '</p>' +
      '<form class="crm-form crm-form--stack" data-crm-ooo-form aria-labelledby="crm-ooo-title">' +
      '<div class="form-group"><label class="form-label" for="crm-ooo-primary">' + escapeHtml(t('Person away')) + '</label>' +
      '<input id="crm-ooo-primary" name="primaryUserId" required class="crm-input form-input" autocomplete="username" /></div>' +
      '<div class="form-group"><label class="form-label" for="crm-ooo-backup">' + escapeHtml(t('Covering colleague')) + '</label>' +
      '<input id="crm-ooo-backup" name="backupUserId" required class="crm-input form-input" autocomplete="username" /></div>' +
      '<div class="crm-form-row-2">' +
      '<div class="form-group"><label class="form-label" for="crm-ooo-from">' + escapeHtml(t('From')) + '</label>' +
      '<input id="crm-ooo-from" name="from" type="date" required class="crm-input form-input" /></div>' +
      '<div class="form-group"><label class="form-label" for="crm-ooo-until">' + escapeHtml(t('Until')) + '</label>' +
      '<input id="crm-ooo-until" name="until" type="date" required class="crm-input form-input" /></div>' +
      '</div>' +
      '<p class="form-help">' + escapeHtml(t('Cover lasts at most 60 days.')) + '</p>' +
      '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save cover')) + '</button></div>' +
      '</form></article>' +
      '<article class="crm-team-capacity__card">' +
      '<h3 class="crm-team-capacity__title" id="crm-bulk-title">' + escapeHtml(t('Move work to someone else')) + '</h3>' +
      '<p class="crm-team-capacity__lead">' + escapeHtml(t('Reassign deals, accounts, and promises in one safe step.')) + '</p>' +
      '<form class="crm-form crm-form--stack" data-crm-bulk-form aria-labelledby="crm-bulk-title">' +
      '<div class="form-group"><label class="form-label" for="crm-bulk-from">' + escapeHtml(t('From user')) + '</label>' +
      '<input id="crm-bulk-from" name="fromUser" required class="crm-input form-input" autocomplete="username" /></div>' +
      '<div class="form-group"><label class="form-label" for="crm-bulk-to">' + escapeHtml(t('To user')) + '</label>' +
      '<input id="crm-bulk-to" name="toUser" required class="crm-input form-input" autocomplete="username" /></div>' +
      '<div class="form-group"><label class="form-label" for="crm-bulk-confirm">' + escapeHtml(t('Type the from-user name to confirm')) + '</label>' +
      '<input id="crm-bulk-confirm" name="confirmFromUserName" required class="crm-input form-input" autocomplete="off" /></div>' +
      '<fieldset class="crm-fieldset">' +
      '<legend>' + escapeHtml(t('What to move')) + '</legend>' +
      '<label class="crm-check"><input type="checkbox" name="entityTypes" value="deals" checked> ' + escapeHtml(t('Deals')) + '</label>' +
      '<label class="crm-check"><input type="checkbox" name="entityTypes" value="accounts" checked> ' + escapeHtml(t('Account owners')) + '</label>' +
      '<label class="crm-check"><input type="checkbox" name="entityTypes" value="promises" checked> ' + escapeHtml(t('Open promises')) + '</label>' +
      '<label class="crm-check"><input type="checkbox" name="entityTypes" value="commitments"> ' + escapeHtml(t('Commitments')) + '</label>' +
      '</fieldset>' +
      '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Reassign')) + '</button></div>' +
      '</form></article>' +
      '<article class="crm-team-capacity__card crm-team-capacity__card--wide">' +
      '<h3 class="crm-team-capacity__title" id="crm-esc-title">' + escapeHtml(t('Escalate for help')) + '</h3>' +
      '<p class="crm-team-capacity__lead">' + escapeHtml(t('Flag a teammate who needs a manager’s attention.')) + '</p>' +
      '<form class="crm-form crm-form--stack" data-crm-escalate-form aria-labelledby="crm-esc-title">' +
      '<div class="form-group"><label class="form-label" for="crm-esc-subject">' + escapeHtml(t('Teammate')) + '</label>' +
      '<input id="crm-esc-subject" name="subjectUserId" required class="crm-input form-input" autocomplete="username" /></div>' +
      '<div class="form-group"><label class="form-label" for="crm-esc-reason">' + escapeHtml(t('Why')) + '</label>' +
      '<textarea id="crm-esc-reason" name="reason" required class="crm-input form-input" rows="3" maxlength="2000"></textarea></div>' +
      '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Create escalation')) + '</button></div>' +
      '</form></article>' +
      '</div>';
  }

  async function loadTeam(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/team');
    clearMountBusy(mount);
    mount.innerHTML = '';

    const capacityHost = document.querySelector('[data-crm-team-capacity]');
    const rows = data.rows || [];
    const summary = data.summary || {};
    const people = summary.people != null ? summary.people : rows.length;
    const stuckN = summary.stuck != null ? summary.stuck : rows.filter(function (r) { return !!r.stuck; }).length;

    const stats = document.createElement('div');
    stats.className = 'crm-today-role__stats';
    stats.setAttribute('role', 'group');
    stats.setAttribute('aria-label', t('Team snapshot'));
    stats.innerHTML =
      pulseStat(people, t('People')) +
      pulseStat(stuckN, t('Need help'));
    mount.appendChild(stats);

    if (!rows.length) {
      const empty = document.createElement('div');
      empty.className = 'crm-empty-state';
      empty.setAttribute('role', 'status');
      empty.innerHTML =
        '<h3 class="crm-empty-state__title">' + escapeHtml(t('No teammates here yet')) + '</h3>' +
        '<p class="crm-empty-state__lead">' + escapeHtml(t('Add people to this workspace in Settings, then come back to coach them.')) + '</p>';
      mount.appendChild(empty);
    } else {
      const wrap = document.createElement('div');
      wrap.className = 'table-container crm-table--responsive';
      wrap.setAttribute('role', 'region');
      wrap.setAttribute('aria-label', t('Team coaching board'));
      const table = document.createElement('table');
      table.className = 'table table--hover crm-table crm-table--responsive crm-table--team';
      table.innerHTML =
        '<thead><tr>' +
        '<th scope="col">' + escapeHtml(t('Person')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Status')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Open chase')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Old quotes')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Coverage gaps')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Overdue promises')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Commit pipeline')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Out of office')) + '</th>' +
        '<th scope="col" class="crm-table-actions-col">' + escapeHtml(t('Actions')) + '</th>' +
        '</tr></thead>';
      const tbody = document.createElement('tbody');
      rows.forEach(function (r) {
        const tr = document.createElement('tr');
        const name = r.displayName || r.userId || '';
        const stuckLabel = r.stuck
          ? (t('Needs help') + (r.stuckScore ? ' · ' + r.stuckScore : ''))
          : t('On track');
        const stuckBadge = r.stuck ? 'warning' : 'success';
        let oooHtml = '<span class="crm-muted" aria-hidden="true">—</span><span class="crm-sr-only">' + escapeHtml(t('Not scheduled')) + '</span>';
        if (r.ooo) {
          const oooLabel = r.ooo.covering
            ? (t('Covering') + ' ' + (r.ooo.coveringPrimary || ''))
            : t('Away');
          oooHtml = '<span class="crm-badge crm-badge--info">' + escapeHtml(oooLabel) + '</span>';
        }
        tr.innerHTML =
          '<td data-label="' + escapeHtml(t('Person')) + '">' +
          '<span class="crm-team-person">' + escapeHtml(name) + '</span>' +
          (r.roleLabel ? (' <span class="crm-muted">· ' + escapeHtml(r.roleLabel) + '</span>') : '') +
          (r.userId && r.userId !== name ? (' <span class="crm-sr-only">' + escapeHtml(r.userId) + '</span>') : '') +
          '</td>' +
          '<td data-label="' + escapeHtml(t('Status')) + '"><span class="crm-badge crm-badge--' + stuckBadge + '">' + escapeHtml(stuckLabel) + '</span></td>' +
          '<td data-label="' + escapeHtml(t('Open chase')) + '">' + (r.chase || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Old quotes')) + '">' + (r.quote14 || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Coverage gaps')) + '">' + (r.coverageGaps || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Overdue promises')) + '">' + (r.overdueWork || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Commit pipeline')) + '">' + euros(r.commitCents) +
          ' <span class="crm-sr-only">' + escapeHtml(r.forecastDisclaimer || t('Planning labels — not expected cash.')) + '</span></td>' +
          '<td data-label="' + escapeHtml(t('Out of office')) + '">' + oooHtml + '</td>' +
          '<td data-label="' + escapeHtml(t('Actions')) + '" class="crm-table-actions">' +
          (!data.selfOnly
            ? ('<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-one-on-one="' +
              escapeHtml(r.userId || '') + '">' + escapeHtml(t('1:1 prep')) + '</button> ')
            : '') +
          '<a class="crm-btn crm-btn--sm crm-btn--ghost" href="#capacity">' + escapeHtml(t('Tools')) + '</a>' +
          '</td>';
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
      mount.appendChild(wrap);
      const note = document.createElement('p');
      note.className = 'crm-muted crm-team-forecast-note';
      note.textContent = t('Commit pipeline is a planning label — not expected cash.');
      mount.appendChild(note);
    }

    if ((data.escalations || []).length) {
      const escCard = document.createElement('section');
      escCard.className = 'crm-team-escalations';
      escCard.setAttribute('aria-labelledby', 'crm-team-esc-list-title');
      escCard.innerHTML = '<h3 id="crm-team-esc-list-title" class="crm-card__title">' + escapeHtml(t('Open escalations')) + '</h3>';
      const escMount = document.createElement('div');
      escCard.appendChild(escMount);
      mount.appendChild(escCard);
      renderList(escMount, data.escalations.map(function (e) {
        return {
          title: e.subjectUserId,
          meta: e.reason,
        };
      }), '');
    }

    if (capacityHost) {
      if (!data.selfOnly) {
        capacityHost.innerHTML = teamFormsHtml();
        wireTeamCapacityForms(mount, capacityHost, ws);
      } else {
        capacityHost.innerHTML =
          '<div class="crm-callout crm-callout--info" role="status"><p>' +
          escapeHtml(t('Managers set cover and reassign here. You are viewing your own row only.')) +
          '</p></div>';
      }
    }

    focusCapacityIfHash();
  }

  function wireTeamCapacityForms(mount, capacityHost, ws) {
    const oooForm = capacityHost.querySelector('[data-crm-ooo-form]');
    if (oooForm) {
      oooForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const fd = new FormData(oooForm);
        const from = String(fd.get('from') || '');
        const until = String(fd.get('until') || '');
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/ooo', {
            primaryUserId: String(fd.get('primaryUserId') || '').trim(),
            backupUserId: String(fd.get('backupUserId') || '').trim(),
            from: from,
            until: until,
            startsOn: from,
            endsOn: until,
          });
          await loadTeam(mount);
          focusCapacityIfHash();
        } catch (e) {
          showAlert(oooForm, e.message || String(e));
        }
      });
    }

    const bulkForm = capacityHost.querySelector('[data-crm-bulk-form]');
    if (bulkForm) {
      bulkForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const fd = new FormData(bulkForm);
        const entityTypes = Array.from(bulkForm.querySelectorAll('input[name="entityTypes"]:checked'))
          .map(function (el) { return el.value; });
        const fromUser = String(fd.get('fromUser') || '').trim();
        const toUser = String(fd.get('toUser') || '').trim();
        const confirmName = String(fd.get('confirmFromUserName') || '').trim();
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/bulk-reassign', {
            fromUser: fromUser,
            toUser: toUser,
            confirmFromUserName: confirmName,
            fromUserId: fromUser,
            toUserId: toUser,
            confirmName: confirmName,
            entityTypes: entityTypes,
          });
          await loadTeam(mount);
        } catch (e) {
          showAlert(bulkForm, e.message || String(e));
        }
      });
    }

    const escForm = capacityHost.querySelector('[data-crm-escalate-form]');
    if (escForm) {
      escForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const fd = new FormData(escForm);
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/escalations', {
            subjectUserId: String(fd.get('subjectUserId') || '').trim(),
            reason: String(fd.get('reason') || '').trim(),
          });
          await loadTeam(mount);
        } catch (e) {
          showAlert(escForm, e.message || String(e));
        }
      });
    }

    if (!mount.dataset.crmOneOnOneBound) {
      mount.dataset.crmOneOnOneBound = '1';
      mount.addEventListener('click', async function (ev) {
        const btn = ev.target.closest('[data-crm-one-on-one]');
        if (!btn) return;
        const report = btn.getAttribute('data-crm-one-on-one') || '';
        if (!report) return;
        try {
          const prep = await apiGet('/api/v1/workspaces/' + ws + '/one-on-one/' + encodeURIComponent(report));
          openOneOnOneDialog(mount, ws, report, prep);
        } catch (e) {
          showAlert(mount, e.message || String(e));
        }
      });
    }
  }

  function openOneOnOneDialog(mount, ws, report, prep) {
    const existing = mount.querySelector('[data-crm-one-on-one-panel]');
    if (existing) existing.remove();
    const panel = document.createElement('section');
    panel.className = 'crm-card';
    panel.setAttribute('data-crm-one-on-one-panel', '1');
    panel.setAttribute('aria-labelledby', 'crm-11-title');
    const chaseLines = (prep.chase || []).slice(0, 8).map(function (d) {
      const reason = chaseReasonLabel(d.primaryReason || '');
      return '<li>' + escapeHtml((d.title || '') + (reason ? ' · ' + reason : '')) + '</li>';
    }).join('') || '<li class="crm-muted">' + escapeHtml(t('No open chase items.')) + '</li>';
    const promiseLines = (prep.promisesDue || []).slice(0, 8).map(function (p) {
      return '<li>' + escapeHtml((p.title || '') + (p.dueOn ? ' · ' + p.dueOn : '')) + '</li>';
    }).join('') || '<li class="crm-muted">' + escapeHtml(t('No overdue promises.')) + '</li>';
    const prior = (prep.notes && prep.notes.notes) ? prep.notes.notes : '';
    panel.innerHTML =
      '<header class="crm-card__header"><div class="crm-card__header-text">' +
      '<h3 id="crm-11-title" class="crm-card__title">' +
      escapeHtml(t('1:1 prep') + ' — ' + report) + '</h3>' +
      '<p class="crm-card__lead">' + escapeHtml(t('Chase and promises for this person — then save what you discussed.')) +
      '</p></div></header>' +
      '<div class="crm-card__body">' +
      '<div class="crm-11-prep__block">' +
      '<h4 class="crm-pulse-glance__heading" id="crm-11-chase">' + escapeHtml(t('Chase')) + '</h4>' +
      '<ul class="crm-list" aria-labelledby="crm-11-chase">' + chaseLines + '</ul></div>' +
      '<div class="crm-11-prep__block">' +
      '<h4 class="crm-pulse-glance__heading" id="crm-11-promises">' + escapeHtml(t('Promises')) + '</h4>' +
      '<ul class="crm-list" aria-labelledby="crm-11-promises">' + promiseLines + '</ul></div>' +
      '<form class="crm-form crm-form--stack" data-crm-11-form aria-labelledby="crm-11-notes-title">' +
      '<h4 class="crm-pulse-glance__heading" id="crm-11-notes-title">' + escapeHtml(t('Meeting notes')) + '</h4>' +
      '<div class="form-group">' +
      '<label class="form-label" for="crm-11-notes">' + escapeHtml(t('What did you discuss?')) + '</label>' +
      '<textarea id="crm-11-notes" name="notes" class="crm-input form-input" rows="4" maxlength="8000" ' +
      'aria-describedby="crm-11-notes-help">' + escapeHtml(prior) + '</textarea>' +
      '<p class="form-help" id="crm-11-notes-help">' +
      escapeHtml(t('Short notes are enough — next week’s you will thank you.')) + '</p></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="crm-11-date">' + escapeHtml(t('Meeting date')) + '</label>' +
      '<input id="crm-11-date" name="meetingOn" type="date" class="crm-input form-input" value="' +
      escapeHtml((prep.notes && prep.notes.meetingOn) || new Date().toISOString().slice(0, 10)) + '" /></div>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save notes')) + '</button>' +
      '<button type="button" class="crm-btn crm-btn--ghost" data-crm-11-close="1">' + escapeHtml(t('Close')) + '</button>' +
      '</div></form></div>';
    mount.appendChild(panel);
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const notes = panel.querySelector('#crm-11-notes');
    if (notes) notes.focus();
    panel.querySelector('[data-crm-11-close]').addEventListener('click', function () {
      panel.remove();
    });
    panel.querySelector('[data-crm-11-form]').addEventListener('submit', async function (ev) {
      ev.preventDefault();
      const fd = new FormData(ev.target);
      try {
        await apiPost('/api/v1/workspaces/' + ws + '/one-on-one/' + encodeURIComponent(report), {
          notes: String(fd.get('notes') || ''),
          meetingOn: String(fd.get('meetingOn') || ''),
        });
        panel.remove();
      } catch (e) {
        showAlert(panel, e.message || String(e));
      }
    });
  }

  async function loadStrategy(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/strategy');
    clearMountBusy(mount);
    const items = data.items || [];
    if (!items.length) {
      mount.innerHTML = '<p class="crm-empty">' + escapeHtml(t('No account plans yet — open a company to add one.')) + '</p>';
      return;
    }
    const ul = document.createElement('ul');
    ul.className = 'crm-list';
    items.forEach(function (p) {
      const li = document.createElement('li');
      li.className = 'crm-list__item crm-list__item--stack';
      const companyHref = OC.generateUrl('/apps/customercheck/companies/' + p.companyId) + '?workspaceId=' + ws;
      li.innerHTML =
        '<div class="crm-list__main">' +
        '<strong><a href="' + escapeHtml(companyHref) + '">' +
        escapeHtml(p.companyName || ('#' + p.companyId)) + '</a></strong>' +
        '<span class="crm-muted"> · ' + escapeHtml((p.health || '') + ' · ' + (p.priority || '') +
          (p.nextReviewOn ? ' · ' + p.nextReviewOn : '')) + '</span></div>' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-edit-plan="' +
        escapeHtml(String(p.companyId)) + '">' + t('Edit') + '</button>' +
        '<form class="crm-form crm-form--inline" hidden data-plan-form="' +
        escapeHtml(String(p.companyId)) + '" aria-label="' + escapeHtml(t('Edit account plan')) + '">' +
        '<label>' + t('Goal') + '<textarea name="goal" class="crm-input" rows="2" maxlength="2000">' +
        escapeHtml(p.goal || '') + '</textarea></label>' +
        '<label>' + t('Priority') +
        '<select name="priority" class="crm-input">' +
        '<option value="p1"' + (p.priority === 'p1' ? ' selected' : '') + '>P1</option>' +
        '<option value="p2"' + (p.priority === 'p2' || !p.priority ? ' selected' : '') + '>P2</option>' +
        '<option value="p3"' + (p.priority === 'p3' ? ' selected' : '') + '>P3</option>' +
        '</select></label>' +
        '<label>' + t('Next review') +
        '<input type="date" name="nextReviewOn" class="crm-input" value="' +
        escapeHtml(p.nextReviewOn || '') + '" /></label>' +
        '<label>' + t('Status') +
        '<select name="status" class="crm-input">' +
        '<option value="active"' + ((p.status || 'active') === 'active' ? ' selected' : '') + '>' + t('Active') + '</option>' +
        '<option value="paused"' + (p.status === 'paused' ? ' selected' : '') + '>' + t('Paused') + '</option>' +
        '<option value="done"' + (p.status === 'done' ? ' selected' : '') + '>' + t('Done') + '</option>' +
        '</select></label>' +
        '<button type="submit" class="crm-btn crm-btn--primary">' + t('Save') + '</button>' +
        '<button type="button" class="crm-btn crm-btn--ghost" data-cancel-plan="' +
        escapeHtml(String(p.companyId)) + '">' + t('Cancel') + '</button>' +
        '</form>';
      ul.appendChild(li);
    });
    mount.innerHTML = '';
    mount.appendChild(ul);

    if (!mount.dataset.crmStrategyBound) {
      mount.dataset.crmStrategyBound = '1';
      mount.addEventListener('click', function (ev) {
        const editBtn = ev.target.closest('[data-edit-plan]');
        if (editBtn) {
          const id = editBtn.getAttribute('data-edit-plan');
          const form = mount.querySelector('[data-plan-form="' + CSS.escape(id) + '"]');
          if (form) {
            form.hidden = false;
            editBtn.hidden = true;
            const first = form.querySelector('textarea, input, select');
            if (first) first.focus();
          }
          return;
        }
        const cancelBtn = ev.target.closest('[data-cancel-plan]');
        if (cancelBtn) {
          const id = cancelBtn.getAttribute('data-cancel-plan');
          const form = mount.querySelector('[data-plan-form="' + CSS.escape(id) + '"]');
          const edit = mount.querySelector('[data-edit-plan="' + CSS.escape(id) + '"]');
          if (form) form.hidden = true;
          if (edit) edit.hidden = false;
        }
      });
      mount.addEventListener('submit', async function (ev) {
        const form = ev.target.closest('[data-plan-form]');
        if (!form || !mount.contains(form)) return;
        ev.preventDefault();
        const companyId = form.getAttribute('data-plan-form');
        const fd = new FormData(form);
        try {
          await apiPut('/api/v1/companies/' + companyId + '/account-plan', {
            goal: String(fd.get('goal') || '').trim(),
            priority: String(fd.get('priority') || 'p2'),
            nextReviewOn: String(fd.get('nextReviewOn') || ''),
            status: String(fd.get('status') || 'active'),
          });
          await loadStrategy(mount);
        } catch (e) {
          showAlert(form, e.message || String(e));
        }
      });
    }
  }

  async function loadMail(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/mail/unmatched');
    clearMountBusy(mount);
    mount.innerHTML = '';
    if (data.disclosure) {
      const disclosure = document.createElement('section');
      disclosure.className = 'crm-card';
      disclosure.innerHTML = '<div class="crm-card__body"><p class="crm-muted" role="status">' +
        escapeHtml(data.disclosure) + '</p></div>';
      mount.appendChild(disclosure);
    }
    const tableHost = document.createElement('section');
    tableHost.className = 'crm-card';
    tableHost.setAttribute('aria-labelledby', 'crm-mail-triage-title');
    tableHost.innerHTML =
      '<header class="crm-card__header"><h2 id="crm-mail-triage-title" class="crm-card__title">' +
      escapeHtml(t('Mail triage')) + '</h2></header>' +
      '<div class="crm-card__body" data-crm-mail-triage-body></div>';
    mount.appendChild(tableHost);
    const body = tableHost.querySelector('[data-crm-mail-triage-body]');
    const items = data.items || [];
    renderSuiteTable(
      body,
      items.map(function (m) {
        const mid = m.id;
        const inputId = 'crm-triage-' + mid;
        return {
          title: m.subject || m.from || t('Shared mail'),
          meta: t('Shared') + (m.sentAt ? ' · ' + m.sentAt : '') + (m.from && m.subject ? ' · ' + m.from : ''),
          actionHtml:
            '<form class="crm-form crm-form--inline crm-form--row" data-triage-id="' + escapeHtml(String(mid)) + '">' +
            '<label for="' + escapeHtml(inputId) + '">' + t('Company ID') + '</label>' +
            '<input id="' + escapeHtml(inputId) + '" name="companyId" type="number" min="1" required class="crm-input" inputmode="numeric" autocomplete="off" />' +
            '<button type="submit" class="crm-btn crm-btn--primary">' + t('Assign') + '</button>' +
            '</form>',
        };
      }),
      t('No unmatched shared mail.'),
      t('Mail triage'),
    );
    body.querySelectorAll('[data-triage-id]').forEach(function (form) {
      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const id = form.getAttribute('data-triage-id');
        const companyId = parseInt(form.querySelector('[name="companyId"]').value, 10);
        if (!companyId || companyId < 1) {
          showAlert(mount, t('Enter a valid company ID.'));
          return;
        }
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/mail/' + id + '/triage', { companyId: companyId });
          await loadMail(mount);
        } catch (e) {
          showAlert(form, e.message || String(e));
        }
      });
    });
  }

  function sectionList(title, items, emptyMsg, mapFn) {
    const block = document.createElement('section');
    block.className = 'crm-card crm-prepare-section';
    const header = document.createElement('header');
    header.className = 'crm-card__header';
    const h = document.createElement('h3');
    h.className = 'crm-card__title';
    h.textContent = title;
    header.appendChild(h);
    block.appendChild(header);
    const body = document.createElement('div');
    body.className = 'crm-card__body';
    const mapped = (items || []).map(mapFn || function (it) {
      return { title: it.title || it.name || it.subject || String(it), meta: it.meta || '' };
    });
    renderList(body, mapped, emptyMsg);
    block.appendChild(body);
    return block;
  }

  async function loadPrepare(mount) {
    const companyId = companyIdFromContext(mount);
    if (!companyId) {
      clearMountBusy(mount);
      mount.innerHTML = '<div class="crm-callout crm-callout--danger" role="alert"><p>' +
        escapeHtml(t('Missing company id for prepare meeting.')) + '</p></div>';
      return;
    }
    const data = await apiGet('/api/v1/companies/' + companyId + '/prepare-meeting');
    clearMountBusy(mount);
    mount.innerHTML = '';

    if (data.companyName) {
      const lead = document.createElement('p');
      lead.className = 'crm-muted';
      lead.setAttribute('role', 'status');
      lead.textContent = data.companyName;
      mount.appendChild(lead);
    }

    const cov = data.coverage || {};
    const flagText = (cov.flagLabels && cov.flagLabels.length)
      ? cov.flagLabels.join(' · ')
      : ((cov.flags || []).map(function (f) {
        const map = {
          champion_gap: t('Missing champion'),
          buyer_gap: t('Missing budget decision-maker'),
          champion_left: t('Champion left'),
          touch_overdue: t('Follow-up overdue'),
          promise_overdue: t('Promise overdue'),
          partner_hours_hot: t('Partner hours running low'),
          renewal_hot: t('Renewal soon'),
        };
        return map[f] || f;
      }).join(' · '));
    const covBlock = document.createElement('section');
    covBlock.className = 'crm-card crm-prepare-section';
    covBlock.innerHTML =
      '<header class="crm-card__header"><h3 class="crm-card__title">' + t('Coverage') + '</h3></header>' +
      '<div class="crm-card__body"><p><span class="crm-badge">' + escapeHtml(cov.gap ? t('Gap') : t('Covered')) + '</span>' +
      (flagText ? ' <span class="crm-muted">' + escapeHtml(flagText) + '</span>' : '') +
      '</p></div>';
    mount.appendChild(covBlock);

    mount.appendChild(sectionList(t('People'), data.people || [], t('No people linked yet.'), function (p) {
      return {
        title: p.name,
        meta: [p.buyingRoleLabel || p.buyingRole, p.statusLabel || p.status].filter(Boolean).join(' · '),
      };
    }));

    mount.appendChild(sectionList(t('Communication'), data.communication || [], t('No recent communication.'), function (c) {
      return {
        title: c.subject || c.snippet || c.kindLabel || t('Touch'),
        meta: [c.occurredAt, c.directionLabel || c.direction, c.kindLabel || c.kind].filter(Boolean).join(' · '),
      };
    }));

    mount.appendChild(sectionList(t('Open promises'), data.openPromises || [], t('No open promises.'), function (p) {
      return {
        title: p.title || p.text || t('Promise'),
        meta: [p.statusLabel || p.status, p.dueOn || p.dueDate].filter(Boolean).join(' · '),
      };
    }));

    mount.appendChild(sectionList(t('Action plan'), data.map || [], t('No MAP steps yet.'), function (s) {
      return { title: s.title, meta: s.statusLabel || s.status || '' };
    }));

    const ren = data.renewals90d;
    const renBlock = document.createElement('section');
    renBlock.className = 'crm-card crm-prepare-section';
    renBlock.innerHTML =
      '<header class="crm-card__header"><h3 class="crm-card__title">' + t('Renewals ≤90d') + '</h3></header>' +
      '<div class="crm-card__body">' +
      (ren && (ren.validUntil || ren.daysLeft != null)
        ? '<p>' + escapeHtml(
          (ren.validUntil ? String(ren.validUntil) : '') +
          (ren.daysLeft != null ? ' · ' + t('%s days left', [String(ren.daysLeft)]) : '')
        ) + '</p>'
        : '<p class="crm-empty">' + escapeHtml(t('No renewal runway in the next 90 days.')) + '</p>') +
      '</div>';
    mount.appendChild(renBlock);

    const ph = data.partnerHours;
    const phBlock = document.createElement('section');
    phBlock.className = 'crm-card crm-prepare-section';
    phBlock.innerHTML =
      '<header class="crm-card__header"><h3 class="crm-card__title">' + t('Partner hours') + '</h3></header>' +
      '<div class="crm-card__body">' +
      (ph
        ? '<p>' + escapeHtml(
          [
            ph.usedMinutes != null ? t('%sh used', [(ph.usedMinutes / 60).toFixed(1)]) : '',
            ph.quotaMinutes != null ? t('%sh quota', [(ph.quotaMinutes / 60).toFixed(1)]) : '',
          ].filter(Boolean).join(' / ') +
          (ph.alert ? ' · ' + t('Alert') : '')
        ) + '</p>'
        : '<p class="crm-empty">' + escapeHtml(t('No partner hours data.')) + '</p>') +
      '</div>';
    mount.appendChild(phBlock);

    const money = data.money || {};
    const moneyBlock = document.createElement('section');
    moneyBlock.className = 'crm-card crm-prepare-section';
    moneyBlock.innerHTML =
      '<header class="crm-card__header"><h3 class="crm-card__title">' + escapeHtml(t('Money')) + '</h3></header>' +
      '<div class="crm-card__body">' +
      '<p>' + escapeHtml(t('Overdue')) + ': ' + euros(money.overdueCents != null ? money.overdueCents : money.overdueAmountCents) +
      ' · ' + escapeHtml(t('Open')) + ': ' + euros(money.openCents != null ? money.openCents : money.openAmountCents) + '</p></div>';
    mount.appendChild(moneyBlock);

    const waitBlock = document.createElement('section');
    waitBlock.className = 'crm-card crm-prepare-section';
    waitBlock.innerHTML =
      '<header class="crm-card__header"><h3 class="crm-card__title">' + t('Waiting tickets') + '</h3></header>' +
      '<div class="crm-card__body"><p>' + (data.waitingTickets || 0) + '</p></div>';
    mount.appendChild(waitBlock);

    const goalBlock = document.createElement('section');
    goalBlock.className = 'crm-card crm-prepare-section';
    goalBlock.innerHTML =
      '<header class="crm-card__header"><h3 class="crm-card__title">' + t('Plan goal') + '</h3></header>' +
      '<div class="crm-card__body">' +
      (data.planGoal
        ? '<p>' + escapeHtml(String(data.planGoal)) + '</p>'
        : '<p class="crm-empty">' + escapeHtml(t('No account plan goal yet.')) + '</p>') +
      '</div>';
    mount.appendChild(goalBlock);

    const ctaBlock = document.createElement('section');
    ctaBlock.className = 'crm-card crm-prepare-section';
    ctaBlock.innerHTML = '<header class="crm-card__header"><h3 class="crm-card__title">' + t('Next actions') + '</h3></header><div class="crm-card__body" data-crm-prepare-cta></div>';
    const ctaRow = document.createElement('p');
    ctaRow.className = 'crm-persona-cta';
    (data.ctas || []).forEach(function (cta) {
      const a = document.createElement('a');
      a.className = 'crm-btn crm-btn--primary';
      a.href = cta.href || '#';
      a.textContent = cta.label || t('Open');
      ctaRow.appendChild(a);
      ctaRow.appendChild(document.createTextNode(' '));
    });
    if (!(data.ctas || []).length) {
      const a = document.createElement('a');
      a.className = 'crm-btn crm-btn--primary';
      a.href = OC.generateUrl('/apps/customercheck/companies/' + companyId);
      a.textContent = t('Open company 360');
      ctaRow.appendChild(a);
    }
    const ctaBody = ctaBlock.querySelector('[data-crm-prepare-cta]');
    (ctaBody || ctaBlock).appendChild(ctaRow);
    mount.appendChild(ctaBlock);
  }

  async function boot() {
    const mount = qs('[data-crm-persona-mount]');
    if (!mount) return;
    const kind = mount.getAttribute('data-crm-persona-mount');
    try {
      if (kind === 'pulse') await loadPulse(mount);
      else if (kind === 'brief') await loadBrief(mount);
      else if (kind === 'my-accounts') await loadAccounts(mount);
      else if (kind === 'team') await loadTeam(mount);
      else if (kind === 'strategy') await loadStrategy(mount);
      else if (kind === 'mail-triage') await loadMail(mount);
      else if (kind === 'prepare-meeting') await loadPrepare(mount);
      else {
        clearMountBusy(mount);
        mount.innerHTML = '<p class="crm-muted">' + t('Open a company to continue.') + '</p>';
      }
    } catch (e) {
      clearMountBusy(mount);
      mount.innerHTML = '<div class="crm-callout crm-callout--danger" role="alert"><p>' +
        escapeHtml(e.message || String(e)) + '</p></div>';
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Workspace switcher lives in common/navigation.js (all pages).
    boot();
    window.addEventListener('hashchange', focusCapacityIfHash);
  });
})();
