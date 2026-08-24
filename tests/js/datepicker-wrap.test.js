/**
 * @vitest-environment jsdom
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

function loadDatepicker() {
  window.t = (s) => s;
  const corePath = join(root, 'js/common/datepicker-core.js');
  // eslint-disable-next-line no-eval
  eval(readFileSync(corePath, 'utf8'));
  const path = join(root, 'js/common/datepicker.js');
  // eslint-disable-next-line no-eval
  eval(readFileSync(path, 'utf8'));
  return window.CustomerCheckDatepicker;
}

describe('datepicker host wrap', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    delete window.CustomerCheckDatepicker;
    delete window.CustomerCheckDatepickerCore;
    delete window.t;
  });

  it('uses full-width host inside form-group even within crm-form-row-2', () => {
    const dp = loadDatepicker();
    document.body.innerHTML =
      '<div class="crm-form-row-2 crm-team-capacity__dates">' +
      '<div class="form-group"><label for="d">From</label>' +
      '<input id="d" name="from" type="date" class="crm-input form-input" /></div>' +
      '</div>';
    dp.initializeDatepicker(document.getElementById('d'));
    const host = document.querySelector('.crm-datepicker-host');
    expect(host).toBeTruthy();
    expect(host.classList.contains('crm-datepicker-host--flex')).toBe(false);
    expect(host.querySelector('.crm-datepicker-toggle')).toBeTruthy();
    expect(host.querySelector('.datepicker-input')).toBeTruthy();
  });

  it('uses --flex when input is a direct child of crm-form-row-2', () => {
    const dp = loadDatepicker();
    document.body.innerHTML =
      '<div class="crm-form-row-2">' +
      '<input id="d2" name="until" type="date" class="crm-input form-input" />' +
      '</div>';
    dp.initializeDatepicker(document.getElementById('d2'));
    const host = document.querySelector('.crm-datepicker-host');
    expect(host.classList.contains('crm-datepicker-host--flex')).toBe(true);
  });
});
