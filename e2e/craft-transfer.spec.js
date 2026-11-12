// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/customercheck/craft/web/.
 * Flow: app-settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply.
 * Recaptured after the pack re-sync @03a1dddb34/d8c5a2e593: the rendered
 * strings must show interpolated values (never %d/%s/{0} residue), the
 * replace token row must escape the NC-core 130px pin, the mode/file
 * controls must not clip mid-word at 320px, the receipt mode label must be
 * localized (never raw "merge"/"replace"), and the dialog footer
 * (token row + Cancel + Apply import) must paint inside the dialog client
 * box at 1280/375/320 (required_field_or_step_undiscoverable donor fix —
 * the .crm-dialog flex-column + .crm-dialog__body scroller).
 * Recaptured again after the donor margin:auto fix: the NC core reset
 * 'html,body,…,dialog,…{margin:0}' collapses the UA top-layer centering;
 * ≥481px dialogs must be centered on both axes over the dimmed backdrop
 * (never flush viewport top-left — native_dialog_margin_reset_uncentered),
 * while the ≤480px media query keeps the full-bleed margin:0 sheet.
 *
 * Session note: SessionCsrfMiddleware demands the session requesttoken on
 * every staff non-GET — API calls here go through the page's own
 * authenticated request context + OC.requestToken, never bare basic auth.
 *
 * Fixture: a throwaway system-admin user (replace preview is sysadmin-only),
 * provisioned via occ and deleted in afterAll — never a shared mutable uid.
 */
import { test, expect } from '@playwright/test'
import { execFileSync, execSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { login } from '../tests/e2e/helpers/auth.js'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract.js')
const __dirname = path.dirname(fileURLToPath(import.meta.url))

const NC_ROOT = path.resolve(__dirname, '../../..')
const APP = '/index.php/apps/customercheck'
const PASS = 'atlas-e2e-passphrase-2026'
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/customercheck/craft/web')
fs.mkdirSync(OUT, { recursive: true })

function occ(args, env) {
	const envArgs = Object.entries(env || {}).flatMap(([k, v]) => ['-e', `${k}=${v}`])
	return execFileSync(
		'docker', ['compose', 'exec', '-T', ...envArgs, '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args],
		{ stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000, cwd: NC_ROOT },
	).toString().trim()
}

function queryDb(q) {
	const b64 = Buffer.from(q, 'utf8').toString('base64')
	return execSync(
		`docker compose exec -T mariadb bash -lc 'echo ${b64} | base64 -d | mariadb -u root -p"$MYSQL_ROOT_PASSWORD" nextcloud -N'`,
		{ stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000, cwd: NC_ROOT },
	).toString().trim()
}

function provisionFixture() {
	const uid = 'cucxfer' + randomBytes(5).toString('hex')
	const password = 'CucXfer-' + randomBytes(8).toString('hex')
	occ(['user:add', '--password-from-env', uid], { OC_PASS: password })
	// Replace preview requires a system admin — the radio is rendered
	// disabled for plain app admins.
	occ(['group:adduser', 'admin', uid])
	// Pin the fixture language: without it the user inherits whatever
	// default_language a sibling lane last mutated on the shared instance —
	// specs asserting rendered copy then flake under arbitrary locales.
	occ(['user:setting', uid, 'core', 'lang', 'en'])
	return { uid, password }
}

function deprovisionFixture(uid) {
	try { occ(['user:delete', uid]) } catch { /* already gone */ }
}

async function shot(page, name) {
	await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false })
}

/** overlay_toast_button_armor_gap: the body-mounted toast close is a real
 * <button> — NC core's button:not(.button-vue) repaint (0,1,1) would paint a
 * ~130px pale block without the overlay-scoped armor rule in css/app.css.
 * Assert computed style: transparent fill, no border, ~44px box, width auto. */
async function assertToastCloseArmored(page, where) {
	const close = page.locator('.toast-container .toast-close, #toast-container .toast-close').first()
	await expect(close).toBeVisible({ timeout: 10000 })
	const m = await close.evaluate((el) => {
		const cs = getComputedStyle(el)
		const r = el.getBoundingClientRect()
		return {
			width: r.width, height: r.height,
			bg: cs.backgroundColor, borderW: cs.borderTopWidth,
			paddingLeft: cs.paddingLeft, marginLeft: cs.marginLeft,
		}
	})
	expect(m.bg, `${where}: toast-close repainted ${m.bg} — core pale fill won (armor gap)`)
		.toBe('rgba(0, 0, 0, 0)')
	expect(parseFloat(m.borderW), `${where}: toast-close border ${m.borderW} — core border won`)
		.toBe(0)
	expect(m.width, `${where}: toast-close width ${Math.round(m.width)}px — core ~130px pin won`)
		.toBeLessThan(80)
	expect(m.width, `${where}: toast-close width ${Math.round(m.width)}px below 44px target`)
		.toBeGreaterThanOrEqual(44)
	expect(m.height, `${where}: toast-close height ${Math.round(m.height)}px below 44px target`)
		.toBeGreaterThanOrEqual(44)
}
/** native_dialog_margin_reset_uncentered: NC core's html,body,…,dialog
 * reset zeroes the UA 'margin:auto' that centers a top-layer <dialog> —
 * a pack dialog without a margin re-declaration anchors to the viewport
 * top-left. mode 'centered' proves the pack margin:auto restored both-axis
 * centering over the dimmed backdrop; mode 'fullbleed' proves the ≤480px
 * media query margin:0 sheet still wins at narrow widths. */
async function assertDialogTopLayerGeometry(dialog, mode, where) {
	const m = await dialog.evaluate((d) => {
		const r = d.getBoundingClientRect()
		return { l: r.left, t: r.top, w: r.width, h: r.height, vpW: window.innerWidth, vpH: window.innerHeight }
	})
	if (mode === 'centered') {
		const expL = Math.max(0, (m.vpW - m.w) / 2)
		const expT = Math.max(0, (m.vpH - m.h) / 2)
		expect(Math.abs(m.l - expL),
			`${where}: dialog left ${Math.round(m.l)} ≠ centered ${Math.round(expL)} — margin:auto did not survive the NC reset`)
			.toBeLessThanOrEqual(2)
		expect(Math.abs(m.t - expT),
			`${where}: dialog top ${Math.round(m.t)} ≠ centered ${Math.round(expT)}`)
			.toBeLessThanOrEqual(2)
		expect(m.l, `${where}: flush-left top-left anchor regression`).toBeGreaterThan(1)
	} else {
		expect(Math.abs(m.l), `${where}: ≤480 full-bleed sheet must pin to the left edge`).toBeLessThanOrEqual(1)
		expect(m.w, `${where}: ≤480 full-bleed sheet must span viewport width`).toBeGreaterThanOrEqual(m.vpW - 1)
		expect(m.t, `${where}: ≤480 full-bleed sheet must pin to the top edge`).toBeLessThanOrEqual(1)
	}
}
/** Authenticated request through the page's own cookie jar + the session
 * CSRF token the app middleware demands on non-GET. */
async function authedPost(page, url, data) {
	const token = await page.evaluate(() => (window.OC && OC.requestToken) || '')
	return page.context().request.post(url, {
		data,
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	})
}
async function authedGet(page, url) {
	return page.context().request.get(url, { headers: { 'OCS-APIRequest': 'true' } })
}

test.describe('craft transfer', () => {
	let fixture
	test.beforeAll(() => {
		fixture = provisionFixture()
	})
	test.afterAll(() => {
		deprovisionFixture(fixture?.uid)
	})

	test('admin transfer surface + report/progress dialogs 1280/375', async ({ page }) => {
		test.setTimeout(240_000)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, { username: fixture.uid, password: fixture.password })
		await page.goto(`${APP}/app-settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#crm-transfer-export-card')).toBeVisible({ timeout: 20000 })

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		expect(bytes.length).toBeGreaterThan(200)

		await page.waitForTimeout(600)
		await shot(page, 'cuc-admin-transfer-light-1280')

		// uniform file error — wrong passphrase on a real container
		await page.locator('#crm-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#crm-transfer-import-passphrase').fill('wrong-passphrase-000')
		// dead_platform_toast_api: the failure must reach a VISIBLE app-owned
		// toast, not only the visually-hidden live region (OC.Notification is
		// gone from NC≥31 bundles — the old bridge silently no-ops there).
		await expect(page.locator('.toast--error').first()).toBeVisible({ timeout: 30000 })
		await expect(page.locator('#crm-transfer-result-live')).not.toBeEmpty()
		// overlay_toast_button_armor_gap: body-mounted close must keep the
		// declared transparent 44px icon look, not core's ~130px pale repaint.
		await assertToastCloseArmored(page, '1280')
		await shot(page, 'cuc-admin-transfer-fileerror-light-1280')

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#crm-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#crm-transfer-report')
		await expect(report).toBeVisible({ timeout: 30000 })
		await expect(page.locator('#crm-transfer-report-verdict')).toContainText(/\d/)
		// positional t() interpolation: localized values, never raw tokens
		const fileLine = await page.locator('#crm-transfer-report-file').textContent()
		expect(fileLine || '').not.toMatch(/\{\d+\}|%\d+\$s|%d(?!\w)/)
		await assertDialogTopLayerGeometry(report, 'centered', 'report 1280')
		await shot(page, 'cuc-admin-transfer-report-dialog-light-1280')

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#crm-transfer-confirm').click()
		const progress = page.locator('#crm-transfer-progress')
		await expect(progress).toBeVisible({ timeout: 10000 })
		await expect(page.locator('#crm-transfer-progress-text')).not.toBeEmpty()
		await assertDialogTopLayerGeometry(progress, 'centered', 'progress 1280')
		await shot(page, 'cuc-admin-transfer-progress-light-1280')

		// app-namespaced class id — other apps' TransferApplyJob rows never match
		const jobId = queryDb("SELECT id FROM oc_jobs WHERE class LIKE '%CustomerCheck%TransferApplyJob%' ORDER BY id DESC LIMIT 1")
		expect(jobId).toMatch(/^\d+$/)
		const priorReceiptId = ((((await (await authedGet(page, `${APP}/api/admin/transfer/status`)).json()).data || {}).lastReceipt) || {}).id || ''
		occ(['background-job:execute', jobId])

		let receipt = {}
		for (let i = 0; i < 60; i++) {
			const s = await authedGet(page, `${APP}/api/admin/transfer/status`)
			receipt = (((await s.json()).data || {}).lastReceipt) || {}
			if (receipt.id && receipt.id !== priorReceiptId
				&& (receipt.result === 'applied' || receipt.result === 'failed')) break
			await new Promise((r) => setTimeout(r, 1000))
		}
		expect(receipt.result).toBe('applied')

		// page reloads after apply; the server-rendered receipt line localizes
		// the mode (donor 03a1dddb34) — never the raw stored value.
		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('#crm-transfer-last')).toBeVisible({ timeout: 20000 })
		const lastLine = await page.locator('#crm-transfer-last').textContent()
		expect(lastLine || '').not.toMatch(/\bmerge\b|\breplace\b/)
		expect(lastLine || '').toContain('Merge')
		await page.waitForTimeout(600)
		await shot(page, 'cuc-admin-transfer-after-apply-light-1280')
	})

	test('admin transfer surface mobile 375 + 320 narrow controls', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page, { username: fixture.uid, password: fixture.password })
		await page.goto(`${APP}/app-settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#crm-transfer-export-card')).toBeVisible({ timeout: 20000 })
		await page.waitForTimeout(600)
		await shot(page, 'cuc-admin-transfer-light-375')

		// 320px clipped-narrow-text donor fix (03a1dddb34): mode radio labels
		// and the file input wrap instead of clipping mid-word.
		await page.setViewportSize({ width: 320, height: 700 })
		await page.waitForTimeout(400)
		for (const sel of [
			'#crm-transfer-import-form input[name="mode"][value="merge"]',
			'#crm-transfer-import-form input[name="mode"][value="replace"]',
			'#crm-transfer-file',
		]) {
			const clip = await page.locator(sel).evaluate((el) => {
				const host = el.closest('label') || el
				return { scrollW: host.scrollWidth, clientW: host.clientWidth }
			})
			expect(clip.scrollW - clip.clientW,
				`${sel}: ${clip.scrollW - clip.clientW}px clipped mid-word at 320px`)
				.toBeLessThanOrEqual(1)
		}
		await shot(page, 'cuc-admin-transfer-light-320')

		// dead_platform_toast_api at narrow width: a failed preview must paint
		// a visible .toast--error (not only the hidden live region).
		await page.setViewportSize({ width: 375, height: 812 })
		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		await page.locator('#crm-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#crm-transfer-import-passphrase').fill('wrong-passphrase-000')
		await expect(page.locator('.toast--error').first()).toBeVisible({ timeout: 30000 })
		await expect(page.locator('#crm-transfer-result-live')).not.toBeEmpty()
		await assertToastCloseArmored(page, '375')
		await shot(page, 'cuc-admin-transfer-fileerror-light-375')
	})

	test('admin transfer replace-confirm token row wide + footer painted (320/375/1280)', async ({ page }) => {
		test.setTimeout(180_000)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, { username: fixture.uid, password: fixture.password })
		await page.goto(`${APP}/app-settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#crm-transfer-export-card')).toBeVisible({ timeout: 20000 })

		const exp = await authedPost(page, `${APP}/api/admin/transfer/export`, { passphrase: PASS })
		expect(exp.status()).toBe(200)
		const bytes = await exp.body()
		expect(bytes.length).toBeGreaterThan(200)

		// Full-form pinned-control sweep (cramped_form_controls).
		await assertAtlasRenderedSurface(page, { content: '#app-content.crm-app', listAllow: '.crm-quickstart__list, .crm-card__list' })

		// Replace mode → real preview → report dialog footer shows token row.
		await page.locator('#crm-transfer-import-form input[type="radio"][value="replace"]').check()
		await page.locator('#crm-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#crm-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#crm-transfer-report')
		await expect(report).toBeVisible({ timeout: 30000 })
		const tokenRow = page.locator('#crm-transfer-replace-confirm')
		await expect(tokenRow).toBeVisible()
		const token = page.locator('#crm-transfer-replace-token')
		await expect(token).toBeVisible()

		// typed-REPLACE gating: Apply import stays disabled until the literal
		// token is typed (donor confirm re-arm contract).
		const confirm = page.locator('#crm-transfer-confirm')
		await expect(confirm).toBeDisabled()
		await token.fill('REPLAC')
		await expect(confirm).toBeDisabled()
		await token.fill('REPLACE')
		await expect(confirm).toBeEnabled()

		// cramped_form_controls fix live: the input escapes the core 130px pin.
		const w = await token.evaluate((el) => el.getBoundingClientRect().width)
		expect(w, `token input ${Math.round(w)}px still on the NC-core 130px pin`).toBeGreaterThan(140)

		// required_field_or_step_undiscoverable: the report dialog's footer —
		// token row + Cancel + Apply import — must paint INSIDE the dialog's
		// client box at every viewport; .crm-dialog__body absorbs overflow.
		const assertDialogFooterPainted = async (where) => {
			const g = await report.evaluate((d) => {
				const cs = getComputedStyle(d)
				const r = d.getBoundingClientRect()
				const bt = parseFloat(cs.borderTopWidth) || 0
				const bl = parseFloat(cs.borderLeftWidth) || 0
				const client = { top: r.top + bt, left: r.left + bl, bottom: r.top + bt + d.clientHeight, right: r.left + bl + d.clientWidth }
				const f = d.querySelector('.crm-dialog__footer').getBoundingClientRect()
				const a = d.querySelector('#crm-transfer-confirm').getBoundingClientRect()
				return { client, dScrollH: d.scrollHeight, dClientH: d.clientHeight,
					footer: { top: f.top, bottom: f.bottom, left: f.left, right: f.right },
					apply: { top: a.top, bottom: a.bottom, left: a.left, right: a.right } }
			})
			for (const [name, box] of Object.entries({ footer: g.footer, apply: g.apply })) {
				expect(box.bottom, `${where}: ${name} bottom ${Math.round(box.bottom)} hangs below dialog client box ${Math.round(g.client.bottom)}`).toBeLessThanOrEqual(g.client.bottom + 0.5)
				expect(box.top, `${where}: ${name} top above dialog client box`).toBeGreaterThanOrEqual(g.client.top - 0.5)
				expect(box.right, `${where}: ${name} right outside dialog client box`).toBeLessThanOrEqual(g.client.right + 0.5)
				expect(box.left, `${where}: ${name} left outside dialog client box`).toBeGreaterThanOrEqual(g.client.left - 0.5)
			}
			expect(g.dScrollH - g.dClientH,
				`${where}: dialog shell scrolls (scrollHeight ${g.dScrollH} > clientHeight ${g.dClientH}) — .crm-dialog__body must absorb the overflow`)
				.toBeLessThanOrEqual(1)
		}
		await assertDialogFooterPainted('1280')
		await assertDialogTopLayerGeometry(report, 'centered', 'replace-confirm 1280')

		await shot(page, 'cuc-admin-transfer-replace-confirm-light-1280')
		await page.setViewportSize({ width: 375, height: 812 })
		await page.waitForTimeout(300)
		await assertDialogFooterPainted('375')
		await assertDialogTopLayerGeometry(report, 'fullbleed', 'replace-confirm 375')
		await shot(page, 'cuc-admin-transfer-replace-confirm-light-375')
		await page.setViewportSize({ width: 320, height: 700 })
		await page.waitForTimeout(300)
		await assertDialogFooterPainted('320')
		await assertDialogTopLayerGeometry(report, 'fullbleed', 'replace-confirm 320')
		await shot(page, 'cuc-admin-transfer-replace-confirm-light-320')
		// Never apply — replace is destructive; close the dialog instead.
		await page.keyboard.press('Escape')
		await expect(report).toBeHidden()
	})
})
