/**
 * @vitest-environment jsdom
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

function loadTeamCapacityUi() {
  const path = join(root, 'js/common/team-capacity-ui.js');
  const code = readFileSync(path, 'utf8');
  // eslint-disable-next-line no-eval
  eval(code);
  return window.CustomerCheckTeamCapacityUi;
}

describe('team-capacity-ui', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    delete window.CustomerCheckTeamCapacityUi;
  });

  it('bindExclusiveAccordion opens only the default step and closes others on toggle', () => {
    document.body.innerHTML =
      '<div id="host">' +
      '<details class="crm-team-capacity__panel" open><summary>1</summary></details>' +
      '<details class="crm-team-capacity__panel" open><summary>2</summary></details>' +
      '<details class="crm-team-capacity__panel" open><summary>3</summary></details>' +
      '<details class="crm-team-capacity__options" open><summary>opts</summary></details>' +
      '</div>';
    const host = document.getElementById('host');
    const ui = loadTeamCapacityUi();
    ui.bindExclusiveAccordion(host, 0);

    const panels = host.querySelectorAll('details.crm-team-capacity__panel');
    expect(panels[0].open).toBe(true);
    expect(panels[1].open).toBe(false);
    expect(panels[2].open).toBe(false);
    expect(host.querySelector('details.crm-team-capacity__options').open).toBe(false);

    panels[2].open = true;
    panels[2].dispatchEvent(new Event('toggle'));
    expect(panels[0].open).toBe(false);
    expect(panels[1].open).toBe(false);
    expect(panels[2].open).toBe(true);
  });

  it('withSubmitGuard blocks concurrent submits and restores the button', async () => {
    document.body.innerHTML =
      '<form id="f"><button type="submit">Go</button></form>';
    const form = document.getElementById('f');
    const btn = form.querySelector('button');
    const ui = loadTeamCapacityUi();
    let gate = 0;
    const first = ui.withSubmitGuard(form, async () => {
      gate += 1;
      expect(btn.disabled).toBe(true);
      expect(btn.getAttribute('aria-busy')).toBe('true');
      await new Promise((r) => setTimeout(r, 20));
    });
    const second = ui.withSubmitGuard(form, async () => {
      gate += 1;
    });
    await Promise.all([first, second]);
    expect(gate).toBe(1);
    expect(btn.disabled).toBe(false);
    expect(btn.getAttribute('aria-busy')).toBeNull();
  });

  it('validateOooCoverInput mirrors server rules', () => {
    const v = loadTeamCapacityUi().validateOooCoverInput;
    expect(v({ primaryUserId: 'a', backupUserId: 'a', startsOn: '2026-08-01', endsOn: '2026-08-02' })).toBe('same_person');
    expect(v({ primaryUserId: 'a', backupUserId: 'b', startsOn: '', endsOn: '2026-08-02' })).toBe('missing_dates');
    expect(v({ primaryUserId: 'a', backupUserId: 'b', startsOn: '2026-08-10', endsOn: '2026-08-01' })).toBe('invalid_range');
    expect(v({ primaryUserId: 'a', backupUserId: 'b', startsOn: '2026-08-01', endsOn: '2026-10-15' })).toBe('too_long');
    expect(v({ primaryUserId: 'a', backupUserId: 'b', startsOn: '2026-08-01', endsOn: '2026-08-31' })).toBeNull();
  });

  it('validateBulkReassignInput catches empty selection and same user', () => {
    const v = loadTeamCapacityUi().validateBulkReassignInput;
    expect(v({ fromUser: 'a', toUser: 'a', confirmed: true, entityTypes: ['deals'] })).toBe('same_person');
    expect(v({ fromUser: 'a', toUser: 'b', confirmed: true, entityTypes: [] })).toBe('nothing_selected');
    expect(v({ fromUser: 'a', toUser: 'b', confirmed: false, entityTypes: ['deals'] })).toBe('not_confirmed');
    expect(v({ fromUser: 'a', toUser: 'b', confirmed: true, entityTypes: ['deals'] })).toBeNull();
  });
});
