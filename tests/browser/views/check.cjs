const { chromium } = require('/work/node_modules/playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const http = require('node:http')

const questions = [
	{ field: 'cv_object_ownership', category: 'Object', question: 'Who owns the object?', options: [{ value: '0', label: 'The client' }, { value: '1', label: 'A third party' }] },
	{ field: 'cv_trace_ownership', category: 'Route', question: 'Who owns the route?', options: [{ value: '0', label: 'Municipality' }, { value: '1', label: 'Province' }] },
	{ field: 'cv_building_type', category: 'Building', question: 'What type of building is involved?', options: [{ value: '0', label: 'Residential' }, { value: '1', label: 'Commercial' }] },
	{ field: 'cv_avp_location', category: 'Connection point', question: 'Where is the connection point located?', options: [{ value: '0', label: 'Indoors' }, { value: '1', label: 'Outdoors' }] },
]
const at = (daysAgo, h, m) => { const d = new Date(); d.setDate(d.getDate() - daysAgo); d.setHours(h, m, 0, 0); return d.toISOString() }
const events = [
	{ id: 1, actorUid: 'emma', actorDisplayName: 'Emma de Vries', source: 'deck', eventType: 'project_updated', occurredAt: at(0, 9, 42), payload: {} },
	{ id: 2, actorUid: 'thomas', actorDisplayName: 'Thomas Jansen', source: 'files', eventType: 'project_updated', occurredAt: at(0, 8, 15), payload: {} },
	{ id: 3, actorUid: 'lotte', actorDisplayName: 'Lotte Bakker', source: 'whiteboard', eventType: 'project_updated', occurredAt: at(1, 16, 28), payload: {} },
]
const processing = { 11: { ocr_status: 'done', document_type_id: 1 }, 12: { ocr_status: 'queued', document_type_id: 2 }, 13: { ocr_status: 'failed', document_type_id: 3 } }
const requests = []

const server = http.createServer((req, res) => {
	const url = new URL(req.url, 'http://localhost')
	requests.push(url.pathname + url.search)
	const send = (status, body) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(body)) }
	let m
	if ((m = url.pathname.match(/\/projects\/(\d+)\/activity$/))) {
		if (m[1] === '23') return send(403, { message: 'Forbidden' })
		const source = url.searchParams.get('source')
		const list = source ? events.filter(e => e.source === source) : events
		if (url.searchParams.get('cursor') === 'c2') return send(200, { events: [{ id: 9, actorUid: 'sanne', actorDisplayName: 'Sanne Jacobs', source: 'internal', eventType: 'project_updated', occurredAt: at(3, 14, 10), payload: {} }], hasMore: false, nextCursor: null })
		return send(200, { events: list, hasMore: !source, nextCursor: source ? null : 'c2' })
	}
	if (url.pathname.endsWith('/card-visibility')) return send(200, { questions, answers: { cv_object_ownership: 0, cv_trace_ownership: null, cv_building_type: 1, cv_avp_location: null } })
	if (url.pathname.endsWith('/ocr/document-types')) return send(200, { document_types: [{ id: 1, name: 'Intakeformulier' }, { id: 2, name: 'Offerte' }, { id: 3, name: 'Kadaster' }] })
	if ((m = url.pathname.match(/\/files\/(\d+)\/ocr$/))) return send(200, { processing: processing[m[1]] || null })
	if (url.pathname.includes('/apps/projectcreatoraio/api/v1/')) return send(200, {})
	const path = '/check/dist' + (url.pathname === '/' ? '/index.html' : url.pathname)
	if (fs.existsSync(path)) {
		res.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'text/html')
		return res.end(fs.readFileSync(path))
	}
	res.statusCode = 404
	res.end('{}')
})

const css = (locator, pseudo) => locator.evaluate((node, p) => {
	const s = getComputedStyle(node, p || null)
	return { content: s.content, color: s.color, background: s.backgroundColor, fontWeight: s.fontWeight, boxShadow: s.boxShadow, borderTopWidth: s.borderTopWidth, textTransform: s.textTransform, position: s.position, overflow: s.overflow }
}, pseudo)

;(async () => {
	await new Promise(resolve => server.listen(8137, resolve))
	const browser = await chromium.launch({ headless: true, executablePath: '/root/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome', args: ['--no-sandbox'] })
	try {
		const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } })
		const errors = []
		page.on('pageerror', e => { errors.push(e.message); console.log('ERROR', e.message) })
		await page.goto('http://localhost:8137')
		// The real In Zicht theme: the iz- components and tokens under test.
		await page.addStyleTag({ content: fs.readFileSync('/theme/server.css', 'utf8') })

		// ---- Documents ----
		await page.locator('#documents .project-files__row').nth(3).waitFor()
		await page.waitForFunction(() => document.querySelectorAll('#documents .project-files__status-inline--success').length === 1)
		const status = page.locator('#documents .project-files__status-inline')
		assert.equal((await css(status.nth(0), '::after')).content, '"Ready"', 'the status pill reads the component\'s own label')
		assert.equal((await css(page.locator('#documents .project-files__status-inline--error'), '::after')).content, '"Failed"')
		assert.equal(await page.locator('#documents .project-files__type-label-inline').first().innerText(), 'Intakeformulier')
		const pane = await css(page.locator('#documents .project-files__pane--left'))
		assert.equal(pane.borderTopWidth, '0px')
		assert.notEqual(pane.boxShadow, 'none', 'panes are iz-panels: shadow, no border')
		const primaries = page.locator('#documents .project-files__actions .project-files__btn--primary')
		assert.notEqual((await css(primaries.nth(0))).background, (await css(primaries.nth(1))).background, 'only the first accent button keeps the accent')
		const labels = (await page.locator('#documents .project-files__actions').innerText()).replace(/\s+/g, ' ')
		for (const label of ['Open in Files', 'Upload for OCR', 'Upload for signing', 'Download ZIP']) assert.ok(labels.includes(label), `missing "${label}"`)
		assert.equal(await page.locator('#documents .project-files__grid').evaluate(n => getComputedStyle(n).minHeight), '0px', 'no empty 480px under short folders')
		const action = page.locator('#documents .project-files__row').nth(1).locator('.project-files__icon-btn').first()
		await action.focus()
		await page.waitForTimeout(300) // the component fades opacity over 150ms
		assert.equal(await action.evaluate(n => getComputedStyle(n).opacity), '1', 'a focused row action is visible to keyboard users')
		assert.equal((await css(page.locator('#documents .project-files__crumb').last())).borderTopWidth, '0px')
		assert.equal((await css(page.locator('#legacy-host .project-files__tab--active'))).fontWeight, '700', 'the current interface keeps its own tabs')
		assert.equal((await css(page.locator('#legacy-host .project-files__status-inline').first(), '::after')).content, 'none', 'and no status text')

		// ---- Intake ----
		await page.locator('#intake .pc-intake-row').nth(3).waitFor()
		assert.match(await page.locator('#intake .pc-intake-progress').innerText(), /2 of 4/)
		assert.equal(await page.locator('#intake .iz-pill--warning').count(), 2, 'both unanswered questions are marked')
		await page.locator('#intake .pc-intake-row').nth(1).locator('label', { hasText: 'Province' }).click()
		assert.ok(await page.locator('#intake .pc-intake-row').nth(1).locator('input').nth(1).isChecked(), 'the label checks its real radio')
		assert.match(await page.locator('#intake .pc-view__state').innerText(), /1 unsaved change/)
		assert.equal((await page.locator('#intake .pc-intake-row__dirty .pc-sr-only').boundingBox()).width <= 1, true, 'the changed marker is a dot, its text is for screen readers')
		assert.equal((await css(page.locator('#intake .pc-intake-row__pill').first())).textTransform, 'none')
		assert.match(await page.locator('#intake .pc-intake-progress').innerText(), /3 of 4/)
		assert.equal(await page.locator('#intake .iz-btn--primary').isDisabled(), false)

		// ---- Activity ----
		await page.locator('#activity .pc-activity-row').nth(2).waitFor()
		assert.equal(await page.locator('#activity .iz-chip').count(), 6)
		assert.equal(await page.locator('#activity .pc-activity-row__time').first().innerText(), '09:42')
		assert.match(await page.locator('#activity .pc-activity-row__text').first().innerText(), /^Emma de Vries \S/, 'a space between the name and what they did')
		assert.equal((await css(page.locator('#activity h3.iz-section-title').first())).textTransform, 'uppercase', 'the theme\'s section title, not the page h3')
		assert.equal((await css(page.locator('#activity h3.iz-section-title').first())).position, 'sticky')
		assert.equal((await css(page.locator('#activity .pc-activity-panel'))).overflow, 'clip', 'clip, so the day headers can stick')
		await page.locator('#activity .iz-btn', { hasText: 'Load more' }).click()
		await page.waitForFunction(() => document.querySelectorAll('#activity .pc-activity-row').length === 4)
		assert.ok(requests.some(r => r.includes('cursor=c2')), 'the next page follows the cursor')
		await page.locator('#activity .iz-chip', { hasText: 'Files' }).click()
		await page.waitForFunction(() => document.querySelectorAll('#activity .pc-activity-row').length === 1)
		assert.ok(requests.some(r => r.includes('source=files')))
		assert.equal(await page.locator('#activity .iz-chip--active').innerText(), 'Files')
		await page.locator('#activity-denied .pc-activity-state[role="alert"]').waitFor()
		assert.match(await page.locator('#activity-denied .pc-activity-state').innerText(), /do not have access/, 'no access reads as a failure, not an empty project')

		// ---- Overview tasks ----
		const tabs = page.locator('#overview .pc-task-tabs .iz-tab')
		assert.equal(await tabs.count(), 2)
		assert.match(await tabs.nth(0).innerText(), /My tasks\s*1/)
		assert.match(await tabs.nth(1).innerText(), /All tasks\s*3/)
		assert.match(await page.locator('#overview .pc-task-summary').innerText(), /Intake controleren/)
		await tabs.nth(1).click()
		assert.equal(await tabs.nth(1).getAttribute('aria-selected'), 'true')
		const all = await page.locator('#overview .pc-task-summary').innerText()
		for (const title of ['Intake controleren', 'Offerte opvragen', 'Situatieschets maken']) assert.ok(all.includes(title), `All tasks should list ${title}`)
		assert.ok(!all.includes('Oud werk') && !all.includes('Gearchiveerd'), 'done and archived cards stay out')

		await page.screenshot({ path: '/check/desktop.png', fullPage: true })
		await page.setViewportSize({ width: 390, height: 844 })
		await page.evaluate(() => { document.getElementById('legacy-host').style.display = 'none' })
		await page.waitForTimeout(400)
		const widest = await page.evaluate(() => [...document.querySelectorAll('.pc-new > *')].map(el => ({ id: el.id || el.className, w: el.scrollWidth })).sort((a, b) => b.w - a.w)[0])
		assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= 390), `mobile overflow: ${JSON.stringify(widest)}`)
		await page.screenshot({ path: '/check/mobile.png', fullPage: true })

		assert.deepEqual(errors, [])
		console.log('PASS: documents on theme tokens with real OCR labels, intake segments and progress, activity chips/paging/clock/sticky days and access failure, overview My/All tasks, 390px — current interface unchanged.')
	} finally {
		await browser.close()
		server.close()
	}
})().catch(e => { console.error(e); process.exitCode = 1; server.close() })
