/**
 * Keep the shared Nextcloud instance out of “Update needed” during E2E.
 *
 * Parallel workspace agents often bump sibling app info.xml versions, which
 * flips needsDbUpgrade and blocks login for every CRM Playwright suite.
 * This helper runs `occ upgrade` from the host when status (or the guest page)
 * indicates an upgrade is pending. Retries + a file lock absorb races when
 * another agent is already upgrading.
 */
import { execFileSync } from 'node:child_process';
import {
  closeSync,
  existsSync,
  mkdirSync,
  openSync,
  unlinkSync,
  writeFileSync,
} from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const NEXTCLOUD_DIR = join(dirname(fileURLToPath(import.meta.url)), '../../../../../');
const LOCK_DIR = '/tmp/customercheck-e2e-occ-upgrade.lock';

function sleep(ms) {
  const seconds = Math.max(1, Math.ceil(ms / 1000));
  execFileSync('sleep', [String(seconds)], { stdio: 'ignore' });
}

function occ(args, { timeoutMs = 120000 } = {}) {
  try {
    return execFileSync(
      'docker',
      ['compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args],
      {
        cwd: NEXTCLOUD_DIR,
        encoding: 'utf8',
        timeout: timeoutMs,
        stdio: ['ignore', 'pipe', 'pipe'],
      },
    );
  } catch (err) {
    const stdout = String(err?.stdout || '');
    const stderr = String(err?.stderr || '');
    const wrapped = new Error(
      `occ ${args.join(' ')} failed: ${stderr || stdout || err.message}`,
    );
    wrapped.stdout = stdout;
    wrapped.stderr = stderr;
    throw wrapped;
  }
}

function withUpgradeLock(fn) {
  mkdirSync('/tmp', { recursive: true });
  const started = Date.now();
  while (Date.now() - started < 180000) {
    try {
      const fd = openSync(LOCK_DIR, 'wx');
      try {
        writeFileSync(fd, String(process.pid));
        return fn();
      } finally {
        closeSync(fd);
        try { unlinkSync(LOCK_DIR); } catch { /* ignore */ }
      }
    } catch {
      // Another E2E/process holds the lock — wait for them to finish upgrading.
      sleep(2000);
      if (!needsDbUpgrade()) {
        return false;
      }
    }
  }
  throw new Error('Timed out waiting for occ upgrade lock');
}

export function needsDbUpgrade() {
  try {
    const out = occ(['status']);
    // Some limited-mode states print “require upgrade” while the YAML line
    // still says needsDbUpgrade: false — treat either as pending.
    return /needsDbUpgrade:\s*true|require upgrade/i.test(out);
  } catch (err) {
    const msg = String(err?.stdout || err?.stderr || err?.message || err);
    return /needsDbUpgrade:\s*true|require upgrade/i.test(msg);
  }
}

export function ensureUpgraded() {
  if (!needsDbUpgrade()) {
    return false;
  }
  return withUpgradeLock(() => {
    let lastErr = null;
    for (let attempt = 1; attempt <= 6; attempt++) {
      if (!needsDbUpgrade()) {
        return true;
      }
      try {
        occ(['upgrade'], { timeoutMs: 300000 });
        // Give Apache a moment after maintenance flips off.
        sleep(1500);
        if (!needsDbUpgrade()) {
          return true;
        }
      } catch (err) {
        lastErr = err;
        // Concurrent upgrade / brief docker blip — wait and retry.
        sleep(3000 * attempt);
      }
    }
    if (needsDbUpgrade()) {
      throw new Error(
        'occ upgrade did not clear needsDbUpgrade after retries. '
        + String(lastErr?.message || lastErr || ''),
      );
    }
    return true;
  });
}

// Touch path existence for static analysis / tests that import this module.
if (!existsSync(NEXTCLOUD_DIR)) {
  // Keep the failure obvious if the relative path drifts.
  throw new Error('ensure-upgraded: NEXTCLOUD_DIR missing: ' + NEXTCLOUD_DIR);
}
