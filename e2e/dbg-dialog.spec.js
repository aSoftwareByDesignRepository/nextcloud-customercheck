import { test } from '@playwright/test'
import { login } from '../tests/e2e/helpers/auth.js'
import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
const __dirname = path.dirname(fileURLToPath(import.meta.url))
const NC_ROOT = path.resolve(__dirname, '../../..')
const APP = '/index.php/apps/customercheck'
function occ(args, env) {
	const envArgs = Object.entries(env || {}).flatMap(([k, v]) => ['-e', `${k}=${v}`])
	return execFileSync('docker', ['compose','exec','-T',...envArgs,'-u','www-data','nextcloud','php','occ',...args],
		{ stdio:['ignore','pipe','pipe'], timeout:60_000, cwd:NC_ROOT }).toString().trim()
}
test('dbg dialog margin', async ({ page }) => {
	test.setTimeout(120000)
	const uid='cucdbg'+randomBytes(4).toString('hex'); const pw='Dbg-'+randomBytes(6).toString('hex')
	occ(['user:add',uid,'--password-from-env','--display-name','Dbg'],{OC_PASS:pw})
	occ(['group:adduser','admin',uid])
	await page.setViewportSize({ width:1280, height:800 })
	await login(page,{username:uid,password:pw})
	await page.goto(`${APP}/app-settings/transfer`,{waitUntil:'domcontentloaded'})
	await page.locator('#crm-transfer-export-card').waitFor({timeout:20000})
	const out = await page.evaluate(() => {
		const d = document.querySelector('#crm-transfer-report')
		d.showModal()
		const before = getComputedStyle(d)
		const r0 = d.getBoundingClientRect()
		const snapshot = (label) => { const cs=getComputedStyle(d); const r=d.getBoundingClientRect();
			return {label, left:r.left, top:r.top, marginLeft:cs.marginLeft, width:cs.width, maxWidth:cs.maxWidth, position:cs.position, display:cs.display, boxSizing:cs.boxSizing, inset:cs.inset} }
		const res = [snapshot('before')]
		d.style.margin = 'auto'
		res.push(snapshot('inline-margin-auto'))
		d.style.cssText = ''
		// fresh bare dialog, same cascade, no id
		const d2 = document.createElement('dialog')
		d2.className = 'crm-dialog'
		d2.textContent = 'probe'
		document.body.appendChild(d2)
		d2.showModal()
		const cs2 = getComputedStyle(d2); const r2 = d2.getBoundingClientRect()
		res.push({label:'fresh .crm-dialog', left:r2.left, top:r2.top, marginLeft:cs2.marginLeft, width:cs2.width})
		// fresh dialog NO class — pure UA centering
		const d3 = document.createElement('dialog')
		d3.textContent='probe'
		document.body.appendChild(d3)
		d3.showModal()
		const cs3 = getComputedStyle(d3); const r3 = d3.getBoundingClientRect()
		res.push({label:'fresh bare dialog', left:r3.left, top:r3.top, marginLeft:cs3.marginLeft, width:cs3.width, margin:cs3.margin})
		return res
	})
	console.log(JSON.stringify(out,null,1))
	occ(['user:delete',uid])
})
