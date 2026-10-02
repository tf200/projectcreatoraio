const { chromium } = require('/work/node_modules/playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const http = require('node:http')

// The real questionnaire, exported from lib/Service/CardVisibility.php: long Dutch sentences, as users see them.
const questions = require('/check/questions.json')
const at = (daysAgo, h, m) => { const d = new Date(); d.setDate(d.getDate() - daysAgo); d.setHours(h, m, 0, 0); return d.toISOString() }
const events = [
	{ id: 1, actorUid: 'emma', actorDisplayName: 'Emma de Vries', source: 'deck', eventType: 'project_updated', occurredAt: at(0, 9, 42), payload: {} },
	{ id: 2, actorUid: 'thomas', actorDisplayName: 'Thomas Jansen', source: 'files', eventType: 'project_updated', occurredAt: at(0, 8, 15), payload: {} },
	{ id: 3, actorUid: 'lotte', actorDisplayName: 'Lotte Bakker', source: 'whiteboard', eventType: 'project_updated', occurredAt: at(1, 16, 28), payload: {} },
]
const processing = { 11: { ocr_status: 'done', document_type_id: 1 }, 12: { ocr_status: 'queued', document_type_id: 2 }, 13: { ocr_status: 'failed', document_type_id: 3 } }
const requests = []
// Whiteboard saves as the autosave writes them: every minute or so while someone draws.
const boardSaves = (() => {
	const list = []
	let size = 4100000
	const run = (daysAgo, h, m, uid, name, count, stepS) => { const t = new Date(); t.setDate(t.getDate() - daysAgo); t.setHours(h, m, 0, 0); for (let i = 0; i < count; i++) { size += 37; list.push({ id: list.length + 1, actorUid: uid, actorDisplayName: name, eventType: 'whiteboard_updated', source: 'whiteboard', occurredAt: new Date(t.getTime() + i * stepS * 1000).toISOString(), payload: { fileSize: size } }) } }
	run(5, 13, 40, 'thomas', 'Thomas Jansen', 30, 100)
	run(3, 10, 2, 'emma', 'Emma de Vries', 29, 95)
	run(3, 11, 20, 'lotte', 'Lotte Bakker', 4, 120)
	run(1, 16, 10, 'emma', 'Emma de Vries', 41, 80)
	run(1, 16, 14, 'thomas', 'Thomas Jansen', 22, 120)
	run(0, 9, 15, 'thomas', 'Thomas Jansen', 11, 102)
	run(0, 14, 2, 'emma', 'Emma de Vries', 38, 62)
	return list.sort((a, b) => b.occurredAt.localeCompare(a.occurredAt))
})()
const bodies = []
// A Combi board part-way through its process, as Deck's /apps/deck/stacks returns it.
const deckStacks = (() => {
	const day = n => new Date(Date.now() + n * 86400000).toISOString()
	const crit = [{ title: 'Kritieke Processtap' }]
	let id = 500
	const c = (title, extra = {}) => ({ id: ++id, title, labels: crit, archived: false, deletedAt: 0, dependentCards: [], ...extra })
	const plain = (title, extra = {}) => c(title, { labels: [], ...extra })
	const intake = c('Intakeformulier', { done: day(-20) }), quick = c('Quickscan', { done: day(-16) }), peak = c('Piekvermogensformulier', { done: day(-10) })
	const situ = c('Situatie tekening', { duedate: day(1) }), avp = c('AVP', { duedate: day(4) })
	const vo = c('VO', { duedate: day(7), dependentCards: [] }), house = c('Huisnummerbesluit', { duedate: day(-3) })
	const report = c('Verslag inpandig overleg', { duedate: day(18) }), doc = c('DO'), soil = c('Bodemrapport', { duedate: day(33) })
	vo.dependentCards = [situ.id, avp.id]
	doc.dependentCards = [vo.id]
	return [
		{ id: 1, title: 'Process steps', order: 0, cards: [doc, soil, plain('Garantie overeenkomst'), plain('Blokkenschema'), plain('Zakelijkrecht', { archived: true })] },
		{ id: 2, title: 'Next priority', order: 1, cards: [report, plain('Intakeverslag', { duedate: day(-1) })] },
		{ id: 3, title: 'In progress', order: 2, cards: [vo, house] },
		{ id: 4, title: 'To review', order: 3, cards: [avp] },
		{ id: 5, title: 'Approved', order: 4, cards: [situ] },
		{ id: 6, title: 'Done', order: 5, cards: [intake, quick, peak, plain('Intake inplannen & hosten')] },
	]
})()
// The local #22 proposal as the Calendar app returns it, plus two meetings around today.
const calendarItems = (() => {
	const at = (days, h, m) => { const d = new Date(); d.setUTCDate(d.getUTCDate() + days); d.setUTCHours(h, m, 0, 0); return d.toISOString() }
	return [
		{ '@type': 'MeetingProposal', id: 1, projectId: 22, title: 'test', description: 'test', location: 'Talk conversation', duration: 30,
			participants: [{ name: 'admin2', address: 'fasd@gmail.com', status: 'needs-action' }, { name: 'Admin3', address: 'admin3@gmail.com', status: 'needs-action' }, { name: 'taha@yba.ai', address: 'taha@yba.ai', status: 'responded' }],
			dates: ['2026-08-24T09:00:00+00:00', '2026-08-25T08:30:00+00:00', '2026-08-27T09:00:00+00:00', '2026-08-28T09:30:00+00:00', '2026-08-31T10:45:00+00:00', '2026-09-02T10:00:00+00:00', '2026-09-04T07:15:00+00:00'].map((date, i) => ({ '@type': 'MeetingProposalDate', id: i + 1, date })) },
		{ '@type': 'MeetingProposal', id: 2, projectId: 22, title: 'Intake met klant', description: '', location: '', duration: 60,
			participants: [{ name: 'Thomas Jansen', address: 'thomas@firma.nl', status: 'needs-action' }, { name: 'Lotte Bakker', address: 'lotte@firma.nl', status: 'responded' }],
			dates: [{ '@type': 'MeetingProposalDate', id: 11, date: at(9, 9, 0) }, { '@type': 'MeetingProposalDate', id: 12, date: at(10, 13, 30) }] },
		{ '@type': 'Meeting', id: 'kick', projectId: 22, title: 'Kick-off Combi', description: '', location: 'Talk conversation', duration: 60, startDate: at(5, 10, 0), endDate: at(5, 11, 0),
			participants: [{ name: 'Emma de Vries', address: 'emma@firma.nl', status: 'accepted' }, { name: 'Thomas Jansen', address: 'thomas@firma.nl', status: 'accepted' }, { name: 'Lotte Bakker', address: 'lotte@firma.nl', status: 'tentative' }] },
		{ '@type': 'Meeting', id: 'schouw', projectId: 22, title: 'Schouw locatie', description: 'Locatie bekijken met de aannemer.', location: 'Kruiskade 12, Rotterdam', duration: 90, startDate: at(-8, 9, 0), endDate: at(-8, 10, 30),
			participants: [{ name: 'Emma de Vries', address: 'emma@firma.nl', status: 'accepted' }, { name: 'Mark Koster', address: 'mark@elektra.nl', status: 'declined' }] },
	]
})()
const team = [
	{ id: 'emma', displayName: 'Emma de Vries', isOwner: true, drascivsRoles: ['driver', 'accountable'], functionalRoleKeys: ['cpl'] },
	{ id: 'thomas', displayName: 'Thomas Jansen', drascivsRoles: ['responsible'], functionalRoleKeys: ['cpl', 'client'] },
	{ id: 'lotte', displayName: 'Lotte Bakker', drasciRole: 'consulted', functionalRoleKeys: ['grid'] },
]
const functionalRoles = [{ key: 'cpl', name: 'CPL' }, { key: 'client', name: 'Client/Developer' }, { key: 'grid', name: 'Grid operator (Elektra)' }]

const server = http.createServer((req, res) => {
	const url = new URL(req.url, 'http://localhost')
	requests.push(url.pathname + url.search)
	const send = (status, body) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(body)) }
	let m
	if (req.method !== 'GET') {
		let body = ''
		req.on('data', chunk => { body += chunk })
		req.on('end', () => {
			bodies.push({ method: req.method, path: url.pathname, body: JSON.parse(body || '{}') })
			if ((m = url.pathname.match(/\/members\/([^/]+)\/role$/))) return send(200, { member: { id: decodeURIComponent(m[1]), ...JSON.parse(body) } })
			return send(200, { alreadyMember: false })
		})
		return
	}
	if (url.pathname === '/apps/deck/stacks/31') return send(200, deckStacks)
	if ((m = url.pathname.match(/\/ocs\/v2\.php\/calendar\/proposal\/project\/(\d+)$/))) {
		if (m[1] === '24') return send(500, { message: 'down' })
		return send(200, { ocs: { data: m[1] === '22' ? calendarItems : [] } })
	}
	if (url.pathname.endsWith('/projects/21/whiteboard/activity')) {
		const limit = Number(url.searchParams.get('limit')), offset = Number(url.searchParams.get('offset'))
		return send(200, { events: boardSaves.slice(offset, offset + limit), hasMore: offset + limit < boardSaves.length })
	}
	if ((m = url.pathname.match(/\/projects\/(\d+)\/members$/))) {
		if (m[1] === '23') return send(403, { message: 'Forbidden' })
		return send(200, { members: team, functionalRoles })
	}
	if (url.pathname.endsWith('/users/search')) return send(200, { users: [{ id: 'thomas', displayName: 'Thomas Jansen' }, { id: 'jeroen', displayName: 'Jeroen Visser', subname: 'jeroen.visser@firma.nl' }] })
	if ((m = url.pathname.match(/\/projects\/(\d+)\/activity$/))) {
		if (m[1] === '23') return send(403, { message: 'Forbidden' })
		const source = url.searchParams.get('source')
		const list = source ? events.filter(e => e.source === source) : events
		if (url.searchParams.get('cursor') === 'c2') return send(200, { events: [{ id: 9, actorUid: 'sanne', actorDisplayName: 'Sanne Jacobs', source: 'internal', eventType: 'project_updated', occurredAt: at(3, 14, 10), payload: {} }], hasMore: false, nextCursor: null })
		return send(200, { events: list, hasMore: !source, nextCursor: source ? null : 'c2' })
	}
	if (url.pathname.endsWith('/card-visibility')) return send(200, { questions, answers: { cv_object_ownership: questions[0].options[0].value, cv_trace_ownership: null, cv_building_type: questions[2].options[1].value, cv_avp_location: null } })
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

// A control whose fill is the same as the surface under it, with no border or
// shadow to set it apart, disappears: the chips in the member editor did.
const camouflaged = scope => scope.evaluate(root => {
	const ctx = Object.assign(document.createElement('canvas'), { width: 1, height: 1 }).getContext('2d', { willReadFrequently: true })
	// Resolve any CSS colour (the theme uses oklch) to sRGB by painting it.
	const rgba = c => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = '#000'; ctx.fillStyle = c; ctx.fillRect(0, 0, 1, 1); return ctx.getImageData(0, 0, 1, 1).data }
	const clear = c => rgba(c)[3] === 0
	const lum = c => { const [r, g, b] = [...rgba(c)].slice(0, 3).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4 }); return 0.2126 * r + 0.7152 * g + 0.0722 * b }
	const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05) }
	const surfaceOf = el => { for (let p = el.parentElement; p; p = p.parentElement) { const bg = getComputedStyle(p).backgroundColor; if (!clear(bg)) return bg } return 'rgb(255, 255, 255)' }
	const found = []
	for (const el of root.querySelectorAll('.iz-chip, .iz-pill, .iz-btn, .iz-input, .iz-tab, .iz-segment, .iz-meter, .pc-intake-option, button, input:not([type=radio]):not([type=checkbox]), select, textarea')) {
		const box = el.getBoundingClientRect()
		if (!box.width || !box.height) continue
		const s = getComputedStyle(el)
		if (clear(s.backgroundColor)) continue
		const surface = surfaceOf(el)
		if (ratio(s.backgroundColor, surface) >= 1.06) continue
		const border = ['Top', 'Right', 'Bottom', 'Left'].some(side => parseFloat(s['border' + side + 'Width']) > 0 && !clear(s['border' + side + 'Color']) && ratio(s['border' + side + 'Color'], surface) >= 1.15)
		if (border || s.boxShadow !== 'none') continue
		found.push(el.className + ' "' + el.textContent.trim().slice(0, 30) + '" ' + s.backgroundColor + ' on ' + surface + ' (' + ratio(s.backgroundColor, surface).toFixed(3) + ')')
	}
	// And an enabled control whose label is too faint to read looks disabled.
	// 3:1, not AA's 4.5:1: the theme's brand pink as text (4.1–4.4:1) and its
	// warning pill (3.9:1) are the theme's to settle, not this app's.
	for (const el of root.querySelectorAll('button:not(:disabled), .iz-chip, .iz-pill, .iz-tab, a.iz-btn')) {
		const box = el.getBoundingClientRect()
		if (!box.width || !box.height || !el.textContent.trim() || el.closest('[disabled]')) continue
		const s = getComputedStyle(el)
		const fill = clear(s.backgroundColor) ? surfaceOf(el) : s.backgroundColor
		const r = ratio(s.color, fill)
		if (r < 3 && parseFloat(s.opacity) === 1) found.push('faint text: ' + el.className + ' "' + el.textContent.trim().slice(0, 30) + '" ' + s.color + ' on ' + fill + ' (' + r.toFixed(2) + ')')
	}
	return found
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
		// The theme's palette applies only under Nextcloud's theme attributes; without
		// them the page runs on the fallback colours above and hides real clashes.
		const theme = name => page.evaluate(id => { document.body.setAttribute('data-themes', id); for (const a of ['light', 'dark']) document.body.toggleAttribute('data-theme-' + a, a === id) }, name)
		await theme('light')
		// Colours are read mid-transition otherwise, right after the theme switch.
		const blends = async scope => {
			const still = await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; }' })
			const light = await camouflaged(scope)
			await theme('dark')
			const dark = await camouflaged(scope)
			await theme('light')
			await still.evaluate(node => node.remove())
			return [...light.map(x => 'light: ' + x), ...dark.map(x => 'dark: ' + x)]
		}

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
		assert.equal(await page.locator('#intake .pc-intake-option').count(), questions.reduce((n, q) => n + q.options.length, 0))
		// The failure that shipped: sentence-length answers pushed out of the panel.
		const panel = page.locator('#intake .pc-intake-list')
		assert.ok(await panel.evaluate(n => n.scrollWidth <= n.clientWidth), 'no answer may run past the panel')
		for (const option of await page.locator('#intake .pc-intake-option').all()) {
			const box = await option.boundingBox(), outer = await panel.boundingBox()
			assert.ok(box.x >= outer.x && box.x + box.width <= outer.x + outer.width + 1, 'every answer card stays inside the panel')
		}
		const head = await page.locator('#intake .pc-intake-row__head').first().boundingBox(), list = await panel.boundingBox()
		assert.ok(head.width > list.width * 0.8, 'the question heading spans the row instead of a narrow column beside the answers')
		// The category is the heading and is not repeated in the question.
		assert.equal(await page.locator('#intake .pc-intake-row__question').first().innerText(), questions[0].category)
		assert.equal(await page.locator('#intake .pc-intake-row__label').count(), 0)
		assert.match(await page.locator('#intake .pc-intake-row__hint').first().innerText(), /^Antwoord met ja op de situatie die van toepassing is\.$/)
		assert.equal(await page.locator('#intake .iz-pill--warning').count(), 2, 'both unanswered questions are marked')
		const traceRow = page.locator('#intake .pc-intake-row').nth(1)
		await traceRow.locator('.pc-intake-option').nth(1).click()
		assert.ok(await traceRow.locator('input').nth(1).isChecked(), 'the card checks its real radio')
		assert.ok(await traceRow.locator('.pc-intake-option').nth(1).evaluate(n => n.classList.contains('pc-intake-option--active')))
		assert.match(await page.locator('#intake .pc-view__state').innerText(), /1 unsaved change/)
		assert.equal((await page.locator('#intake .pc-intake-row__dirty .pc-sr-only').boundingBox()).width <= 1, true, 'the changed marker is a dot, its text is for screen readers')
		assert.equal((await css(page.locator('#intake .pc-intake-row__pill').first())).textTransform, 'none')
		assert.match(await page.locator('#intake .pc-intake-progress').innerText(), /3 of 4/)
		assert.equal(await page.locator('#intake .iz-btn--primary').isDisabled(), false)
		await traceRow.locator('.pc-intake-option').nth(2).focus().catch(() => {})
		await traceRow.locator('input').nth(2).focus()
		assert.notEqual((await css(traceRow.locator('.pc-intake-option').nth(2))).boxShadow + (await traceRow.locator('.pc-intake-option').nth(2).evaluate(n => getComputedStyle(n).outlineStyle)), 'nonenone', 'a keyboard-focused answer shows a focus ring')

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

		// ---- Calendar ----
		const calendar = page.locator('#calendar')
		await calendar.locator('.pc-calendar__item').nth(2).waitFor()
		assert.equal((await calendar.locator('.iz-tab').allInnerTexts()).map(t => t.replace(/\s+/g, ' ')).join('|'), 'All 4|Proposals 2|Meetings 2')
		assert.equal((await calendar.locator('.pc-calendar__group-title').allTextContents()).map(t => t.trim()).join('|'), 'Needs a date · 2|Upcoming · 1|Past · 1', 'proposals, then what is coming, then the past')
		const stale = calendar.locator('.pc-calendar__item').first()
		assert.match(await stale.innerText(), /test\s*All 7 dates have passed/)
		assert.match(await stale.innerText(), /7 date options · 30 min · Talk conversation/)
		assert.equal(await stale.locator('.pc-calendar__status').innerText(), 'No date left to pick', 'every date has passed, so nobody is waited for')
		assert.equal(await stale.locator('.pc-calendar__meter').count(), 0)
		assert.equal(await calendar.locator('.pc-calendar__detail').count(), 0, 'rows start closed')
		await stale.locator('.pc-calendar__row').click()
		assert.equal(await stale.locator('.pc-calendar__row').getAttribute('aria-expanded'), 'true')
		assert.equal(await stale.locator('.pc-calendar__options li').count(), 7)
		assert.equal(await stale.locator('.pc-calendar__option--passed').count(), 7)
		const [o1, o2, o5] = await Promise.all([0, 1, 4].map(i => stale.locator('.pc-calendar__options li').nth(i).boundingBox()))
		assert.ok(o2.y > o1.y && Math.abs(o2.x - o1.x) < 1 && o5.x > o1.x, 'options read down the first column, then the second')
		assert.match(await stale.locator('.pc-calendar__options li').first().innerText(), /^1\s*Mon 24 Aug · \d\d:\d\d/)
		const people = await stale.locator('.pc-calendar__person').allInnerTexts()
		assert.equal(people.length, 3)
		assert.match(people[0], /admin2 fasd@gmail.com\s*Not answered yet/)
		assert.match(people[2], /^taha@yba.ai\s*Answered$/, 'an address equal to the name is not repeated')
		assert.match(await stale.locator('.pc-calendar__hint').innerText(), /Every date offered has passed\. Send new dates from Calendar, or remove the proposal there\.\s*Open Calendar/)
		assert.match(await stale.locator('.pc-calendar__link').getAttribute('href'), /\/apps\/calendar\/$/)
		const waiting = calendar.locator('.pc-calendar__item', { hasText: 'Intake met klant' })
		assert.equal(await waiting.locator('.pc-calendar__status-text').innerText(), 'Waiting for Thomas Jansen')
		const fill = await waiting.locator('.pc-calendar__meter .iz-meter__fill').evaluate(n => n.getBoundingClientRect().width / n.parentElement.getBoundingClientRect().width)
		assert.ok(Math.abs(fill - 0.5) < 0.02, 'the answered bar is half full')
		assert.equal(await waiting.locator('.pc-calendar__meter').getAttribute('aria-label'), '1 of 2 answered')
		await waiting.locator('.pc-calendar__row').click()
		assert.match(await waiting.locator('.pc-calendar__hint').innerText(), /^Participants mark the dates that suit them/)
		await waiting.locator('.pc-calendar__row').click()
		const kick = calendar.locator('.pc-calendar__item', { hasText: 'Kick-off Combi' })
		assert.match(await kick.innerText(), /2 accepted · 1 maybe/)
		assert.match(await kick.locator('.pc-calendar__block').innerText(), /\w{3}\s*\d+\s*\w{3}/)
		await kick.locator('.pc-calendar__row').click()
		assert.match(await kick.locator('.pc-calendar__when').innerText(), /\w+day \d+ \w+ · \d\d:\d\d – \d\d:\d\d \(60 min\)/)
		assert.ok(await calendar.locator('.pc-calendar__item--past', { hasText: 'Schouw locatie' }).count())
		await calendar.locator('.iz-tab', { hasText: 'Meetings' }).click()
		assert.equal((await calendar.locator('.pc-calendar__group-title').allTextContents()).map(t => t.trim()).join('|'), 'Upcoming · 1|Past · 1')
		await calendar.locator('.iz-tab', { hasText: 'All' }).click()
		assert.deepEqual(await blends(calendar), [], 'calendar')
		await page.waitForTimeout(150)
		await calendar.screenshot({ path: '/check/calendar.png' })
		const empty = page.locator('#calendar-empty')
		await empty.locator('.pc-calendar__empty').waitFor()
		assert.match(await empty.innerText(), /Nothing planned yet/)
		assert.equal(await empty.locator('.iz-tab').count(), 0, 'no tabs on an empty calendar')
		const failed = page.locator('#calendar-failed')
		await failed.locator('.pc-view__failure').waitFor()
		assert.match(await failed.innerText(), /Failed to retrieve calendar events/)
		assert.equal(await failed.locator('.iz-btn', { hasText: 'Try again' }).count(), 1)

		// ---- Tasks progress ----
		const tasks = page.locator('#tasks-progress')
		await tasks.locator('.pc-progress__table tbody tr').nth(9).waitFor()
		assert.equal(await tasks.locator('.pc-progress__kpis, .pc-progress__kpi').count(), 0, 'no KPI row: the panel says it all')
		const headlines = await tasks.locator('.pc-progress__headline').allInnerTexts()
		assert.match(headlines[0], /29%\s*of all tasks done · 4 of 14\s*· 2 overdue/)
		assert.equal(await tasks.locator('.pc-progress__overdue').evaluate(n => getComputedStyle(n).fontWeight), '700')
		assert.match(headlines[1], /3 of 10\s*critical process steps done/)
		assert.equal(await tasks.locator('.pc-progress__segment').count(), 10)
		assert.equal((await tasks.locator('.pc-progress__stops > span').allInnerTexts()).join('|'), 'Process steps|Next priority|In progress|To review|Approved|Done')
		const stepRows = (await tasks.locator('.pc-progress__table tbody th').allInnerTexts()).map(t => t.trim())
		assert.equal(stepRows.join(' > '), 'Situatie tekening > AVP > VO > DO > Verslag inpandig overleg > Bodemrapport > Huisnummerbesluit > Intakeformulier > Piekvermogensformulier > Quickscan', 'open first in process order (DO waits on VO), done last')
		assert.equal(await tasks.locator('.pc-progress__due--late').innerText(), '3 days late')
		assert.equal(await tasks.locator('tbody tr').nth(3).locator('.pc-progress__due').innerText(), 'Not planned')
		assert.match(await tasks.locator('.pc-progress__due--done').first().innerText(), /^Done \d+ \w+/)
		assert.equal(await tasks.locator('tbody tr').nth(0).locator('.pc-progress__stop--here').count(), 1, 'one stop marks the column')
		const box = await tasks.locator('.pc-progress__scroll').evaluate(n => ({ h: n.clientHeight, sh: n.scrollHeight }))
		assert.ok(box.h <= 300 && box.sh > box.h, 'ten steps scroll inside the box')
		assert.ok(await tasks.locator('.pc-progress__wrap--more').count(), 'with a fade while there is more below')
		await tasks.locator('.pc-progress__scroll').evaluate(n => { n.scrollTop = n.scrollHeight })
		await page.waitForTimeout(100)
		assert.equal(await tasks.locator('.pc-progress__wrap--more').count(), 0, 'and none at the end')
		const headTop = await tasks.locator('.pc-progress__table thead th').first().evaluate(n => n.getBoundingClientRect().top - n.closest('.pc-progress__scroll').getBoundingClientRect().top)
		assert.ok(Math.abs(headTop) < 1, 'the column header stays at the top while scrolling')
		await tasks.locator('.pc-progress__scroll').evaluate(n => { n.scrollTop = 0 })
		assert.deepEqual(await blends(tasks), [], 'tasks progress')
		await page.waitForTimeout(150)
		await tasks.screenshot({ path: '/check/tasks-progress.png' })

		// ---- Whiteboard activity ----
		const wb = page.locator('#wb-activity')
		await wb.locator('.pc-wb-day').nth(3).waitFor()
		assert.ok(requests.some(r => r.includes('whiteboard/activity?limit=100&offset=0')))
		const dayToggles = wb.locator('.pc-wb-day__toggle')
		assert.equal(await dayToggles.count(), 4, '175 saves, 4 days')
		assert.match(await dayToggles.nth(0).innerText(), /Today\s*2 sessions · 55 min/)
		assert.match(await dayToggles.nth(1).innerText(), /Yesterday\s*1 session/)
		assert.equal((await dayToggles.evaluateAll(n => n.map(b => b.getAttribute('aria-expanded')))).join(), 'true,true,false,false', 'today and yesterday open, older days closed')
		assert.equal(await wb.locator('.pc-wb-session').count(), 3)
		assert.equal(await wb.locator('.pc-wb-session__detail').count(), 0, 'sessions start closed')
		const together = wb.locator('.pc-wb-session', { hasText: 'edited together' })
		assert.match(await together.innerText(), /Emma de Vries and Thomas Jansen edited together\s*16:10 – \d\d:\d\d/)
		await together.locator('.pc-wb-session__toggle').click()
		assert.equal(await together.locator('.pc-wb-session__toggle').getAttribute('aria-expanded'), 'true')
		const lanes = await together.locator('.pc-wb-lane').allInnerTexts()
		assert.equal(lanes.length, 2)
		assert.match(lanes[0], /Emma de Vries\s*16:10 – \d\d:\d\d\s*41 saves/)
		assert.match(lanes[1], /Thomas Jansen\s*16:14 – \d\d:\d\d\s*22 saves/)
		assert.match(await together.locator('.pc-wb-session__facts').innerText(), /63 saves\s*Board grew by \d+ KB/)
		assert.equal(await wb.locator('.pc-wb-tick, [class*=tick]').count(), 0, 'no per-save ticks')
		await dayToggles.nth(2).click()
		assert.equal(await wb.locator('.pc-wb-session').count(), 5)
		await wb.locator('.iz-btn', { hasText: 'Collapse all' }).click()
		assert.equal(await wb.locator('.pc-wb-session').count(), 0)
		await wb.locator('.iz-btn', { hasText: 'Expand all' }).click()
		assert.equal(await wb.locator('.pc-wb-session__detail').count(), 6)
		assert.equal(await wb.locator('.iz-btn', { hasText: 'Older activity' }).count(), 0, 'everything fits in one read')
		assert.deepEqual(await blends(wb), [], 'whiteboard activity')
		await wb.locator('.iz-btn', { hasText: 'Collapse all' }).click()
		await dayToggles.nth(0).click()
		await dayToggles.nth(1).click()
		await together.locator('.pc-wb-session__toggle').click()
		await page.waitForTimeout(250)
		await wb.screenshot({ path: '/check/wb-activity.png' })

		// ---- Members ----
		const members = page.locator('#members')
		await members.locator('.pc-member').nth(2).waitFor()
		assert.match(await members.locator('.pc-member').nth(0).innerText(), /Emma de Vries\s*Owner/)
		assert.match(await members.locator('.pc-member__meta').nth(0).innerText(), /emma · you/)
		assert.equal(await members.locator('.pc-member').nth(0).locator('.iz-btn', { hasText: 'Chat' }).count(), 0, 'no chat with yourself')
		assert.equal((await css(members.locator('.pc-member').nth(0).locator('.pc-pill').first())).textTransform, 'none')
		// The matrix: one column per responsibility, a tick where a member holds it.
		const heads = members.locator('.pc-members__role-head')
		assert.equal(await heads.count(), 8)
		assert.match(await heads.nth(7).innerText(), /S\s*Signer/)
		assert.equal(await members.locator('.pc-members__role-head--empty').count(), 4, 'Supportive, Informed, Verifier and Signer have nobody')
		const ticks = row => members.locator('.pc-member').nth(row).locator('.pc-cell .pc-tick')
		assert.equal(await ticks(0).count(), 2)
		assert.equal(await members.locator('.pc-member').nth(2).locator('.pc-cell').nth(4).locator('.pc-tick').count(), 1, 'the older single-role field still ticks Consulted')
		assert.match(await members.locator('.pc-member').nth(2).innerText(), /Grid operator \(Elektra\)/)
		assert.equal(await members.locator('.pc-member__pills--drascivs').first().isVisible(), false, 'the pills are the narrow fallback only')
		const holders = await members.locator('.pc-members__holders').allInnerTexts()
		assert.equal(holders.join(','), '1,1,1,0,1,0,0,0')
		assert.equal(await members.locator('.pc-members__holders .iz-pill--warning').count(), 4)
		assert.match(await members.locator('.pc-members__unassigned').innerText(), /Not assigned: Supportive, Informed, Verifier, Signer/)
		const heads1 = await heads.first().boundingBox(), cell1 = await members.locator('.pc-member').nth(1).locator('.pc-cell').first().boundingBox()
		assert.ok(Math.abs((heads1.x + heads1.width / 2) - (cell1.x + cell1.width / 2)) < 1, 'ticks sit under their column')
		assert.match(await members.locator('.pc-members__count').innerText(), /3 members/)
		await members.locator('.pc-member').nth(1).locator('.iz-btn', { hasText: 'Chat' }).click()
		assert.equal(await page.evaluate(() => window.chatWith), 'thomas', 'Chat hands the member to Notes')

		await members.locator('.iz-btn', { hasText: 'Add member' }).click()
		const addBtn = members.locator('.iz-btn', { hasText: 'Add to project' })
		assert.ok(await addBtn.isDisabled())
		assert.match(await members.locator('.pc-member-add__foot').innerText(), /Choose a person to add\./)
		await members.locator('#pc-member-search').fill('je')
		await members.locator('.pc-member-result').first().waitFor()
		assert.equal(await members.locator('.pc-member-result').count(), 1, 'Thomas is already a member')
		assert.ok(requests.some(r => r.includes('users/search') && r.includes('organizationId=4')))
		await members.locator('.pc-member-result').first().click()
		assert.match(await members.locator('.pc-member-picked').innerText(), /Jeroen Visser/)
		const addGroup = members.locator('.pc-member-add')
		await addGroup.locator('.iz-chip', { hasText: 'Supportive' }).click()
		assert.match(await members.locator('.pc-member-add__foot').innerText(), /Choose at least one project role\./)
		await addGroup.locator('.iz-chip', { hasText: 'Client/Developer' }).click()
		assert.equal(await addGroup.locator('.iz-chip', { hasText: 'Client/Developer' }).getAttribute('aria-pressed'), 'true')
		assert.ok(!(await addBtn.isDisabled()))
		await members.screenshot({ path: '/check/members-add.png' })
		assert.deepEqual(await blends(members), [], 'add panel')
		await addBtn.click()
		await members.locator('.pc-view__notice--ok').waitFor()
		assert.match(await members.locator('.pc-view__notice--ok').innerText(), /Jeroen Visser was added/)
		const posted = bodies.find(b => b.method === 'POST')
		assert.equal(JSON.stringify(posted.body), JSON.stringify({ userId: 'jeroen', drascivsRoles: ['supportive'], functionalRoleKeys: ['client'] }))

		const lotte = members.locator('.pc-member').nth(2)
		await lotte.locator('.iz-btn', { hasText: 'Edit' }).click()
		assert.equal(await lotte.locator('.pc-cell--toggle').count(), 8, 'in Edit the row\'s cells are the toggles')
		assert.equal(await lotte.locator('.pc-cell--toggle[aria-pressed="true"]').count(), 1, 'starting from the member\'s roles')
		assert.equal(await lotte.locator('.pc-member__editor-drascivs').isVisible(), false, 'no second set of DRASCIVS chips beside the matrix')
		assert.equal(await lotte.locator('.pc-member__editor .iz-chip--active').count(), 2)
		assert.equal(await members.locator('.pc-member').nth(1).locator('.iz-btn', { hasText: 'Edit' }).isDisabled(), true, 'one editor at a time')
		await members.screenshot({ path: '/check/members-edit.png' })
		assert.deepEqual(await blends(members), [], 'member editor')
		await lotte.locator('.pc-cell--toggle[title="Verifier"]').click()
		assert.equal(await lotte.locator('.pc-cell--toggle[aria-pressed="true"]').count(), 2)
		await lotte.locator('.iz-btn', { hasText: 'Save' }).click()
		await members.locator('.pc-view__notice--ok', { hasText: 'Roles updated for Lotte Bakker' }).waitFor()
		const put = bodies.find(b => b.method === 'PUT')
		assert.equal(put.path, '/apps/projectcreatoraio/api/v1/projects/21/members/lotte/role')
		assert.equal(JSON.stringify(put.body), JSON.stringify({ drascivsRoles: ['consulted', 'verifier'], functionalRoleKeys: ['grid'] }))
		assert.equal(await lotte.locator('.pc-cell .pc-tick').count(), 2)
		assert.equal((await members.locator('.pc-members__holders').allInnerTexts())[6], '1', 'the Verifier count follows the save')

		const readonly = page.locator('#members-readonly')
		await readonly.locator('.pc-member').nth(2).waitFor()
		assert.equal(await readonly.locator('.iz-btn', { hasText: 'Add member' }).count(), 0)
		assert.equal(await readonly.locator('.iz-btn', { hasText: 'Edit' }).count(), 0)
		assert.equal(await readonly.locator('.iz-btn', { hasText: 'Chat' }).count(), 2, 'everyone but yourself')
		await page.locator('#members-denied .pc-view__failure').waitFor()
		assert.match(await page.locator('#members-denied .pc-view__failure').innerText(), /do not have access/)
		await members.screenshot({ path: '/check/members.png' })
		// The tightest width that still gets the matrix, and one that does not.
		for (const [width, matrix] of [[1100, true], [1000, false]]) {
			await page.setViewportSize({ width, height: 1200 })
			await page.waitForTimeout(100)
			assert.equal(await members.locator('.pc-members__head').isVisible(), matrix, `matrix at ${width}px: ${matrix}`)
			assert.ok(await members.locator('.pc-members').evaluate(n => n.scrollWidth <= n.clientWidth), `nothing runs out of the table at ${width}px`)
			assert.equal(await members.locator('.pc-member__pills--drascivs').first().isVisible(), !matrix)
		}
		await members.screenshot({ path: '/check/members-narrow.png' })
		await page.setViewportSize({ width: 1440, height: 1200 })

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

		assert.deepEqual(await blends(page.locator('.pc-new')), [], 'every new view')
		await page.screenshot({ path: '/check/desktop.png', fullPage: true })
		await page.setViewportSize({ width: 390, height: 844 })
		await page.evaluate(() => { document.getElementById('legacy-host').style.display = 'none' })
		await page.waitForTimeout(400)
		const widest = await page.evaluate(() => [...document.querySelectorAll('.pc-new > *')].map(el => ({ id: el.id || el.className, w: el.scrollWidth })).sort((a, b) => b.w - a.w)[0])
		assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= 390), `mobile overflow: ${JSON.stringify(widest)}`)
		await page.screenshot({ path: '/check/mobile.png', fullPage: true })
		await page.locator('#members').screenshot({ path: '/check/members-mobile.png' })
		await page.locator('#wb-activity').screenshot({ path: '/check/wb-activity-mobile.png' })
		assert.equal(await page.locator('#tasks-progress .pc-progress__track').first().isVisible(), false, 'no track on a phone')
		assert.equal(await page.locator('#tasks-progress .pc-progress__pill').first().isVisible(), true, 'the column as a pill instead')
		assert.ok(await page.locator('#tasks-progress .pc-progress__scroll').evaluate(n => n.scrollHeight <= n.clientHeight), 'and no scroll box inside the page')
		await page.locator('#tasks-progress').screenshot({ path: '/check/tasks-progress-mobile.png' })
		await page.locator('#calendar').screenshot({ path: '/check/calendar-mobile.png' })

		assert.deepEqual(errors, [])
		console.log('PASS: documents on theme tokens with real OCR labels, intake segments and progress, activity chips/paging/clock/sticky days and access failure, overview My/All tasks, members list/add/edit/chat/read-only/denied, whiteboard sessions by day, tasks progress, calendar agenda/empty/failure, 390px — current interface unchanged.')
	} finally {
		await browser.close()
		server.close()
	}
})().catch(e => { console.error(e); process.exitCode = 1; server.close() })
