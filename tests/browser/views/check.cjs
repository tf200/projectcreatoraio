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
const bodies = []
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

		// ---- Members ----
		const members = page.locator('#members')
		await members.locator('.pc-member').nth(2).waitFor()
		assert.match(await members.locator('.pc-member').nth(0).innerText(), /Emma de Vries\s*Owner/)
		assert.match(await members.locator('.pc-member__meta').nth(0).innerText(), /emma · you/)
		assert.equal(await members.locator('.pc-member').nth(0).locator('.iz-btn', { hasText: 'Chat' }).count(), 0, 'no chat with yourself')
		assert.equal((await css(members.locator('.pc-member').nth(0).locator('.pc-pill').first())).textTransform, 'none')
		assert.match(await members.locator('.pc-member').nth(2).innerText(), /Consulted/, 'the older single-role field still shows')
		assert.match(await members.locator('.pc-member').nth(2).innerText(), /Grid operator \(Elektra\)/)
		const cover = await members.locator('.pc-coverage__row').allInnerTexts()
		assert.equal(cover.length, 8)
		assert.match(cover[0], /Driver\s*Emma de Vries/)
		assert.match(cover[5], /Informed\s*Not assigned/)
		assert.equal(await members.locator('.pc-coverage__unset').first().evaluate(n => getComputedStyle(n).fontStyle), 'italic')
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
		await page.screenshot({ path: '/check/members-add.png', clip: await members.boundingBox() })
		await addBtn.click()
		await members.locator('.pc-view__notice--ok').waitFor()
		assert.match(await members.locator('.pc-view__notice--ok').innerText(), /Jeroen Visser was added/)
		const posted = bodies.find(b => b.method === 'POST')
		assert.equal(JSON.stringify(posted.body), JSON.stringify({ userId: 'jeroen', drascivsRoles: ['supportive'], functionalRoleKeys: ['client'] }))

		const lotte = members.locator('.pc-member').nth(2)
		await lotte.locator('.iz-btn', { hasText: 'Edit' }).click()
		assert.equal(await lotte.locator('.pc-member__editor .iz-chip--active').count(), 2, 'the editor starts from the member\'s roles')
		assert.equal(await members.locator('.pc-member').nth(1).locator('.iz-btn', { hasText: 'Edit' }).isDisabled(), true, 'one editor at a time')
		await lotte.locator('.pc-member__editor .iz-chip', { hasText: 'Verifier' }).click()
		await lotte.locator('.iz-btn', { hasText: 'Save' }).click()
		await members.locator('.pc-view__notice--ok', { hasText: 'Roles updated for Lotte Bakker' }).waitFor()
		const put = bodies.find(b => b.method === 'PUT')
		assert.equal(put.path, '/apps/projectcreatoraio/api/v1/projects/21/members/lotte/role')
		assert.equal(JSON.stringify(put.body), JSON.stringify({ drascivsRoles: ['consulted', 'verifier'], functionalRoleKeys: ['grid'] }))
		assert.match(await lotte.innerText(), /Consulted\s*Verifier/)

		const readonly = page.locator('#members-readonly')
		await readonly.locator('.pc-member').nth(2).waitFor()
		assert.equal(await readonly.locator('.iz-btn', { hasText: 'Add member' }).count(), 0)
		assert.equal(await readonly.locator('.iz-btn', { hasText: 'Edit' }).count(), 0)
		assert.equal(await readonly.locator('.iz-btn', { hasText: 'Chat' }).count(), 2, 'everyone but yourself')
		await page.locator('#members-denied .pc-view__failure').waitFor()
		assert.match(await page.locator('#members-denied .pc-view__failure').innerText(), /do not have access/)
		await page.screenshot({ path: '/check/members.png', clip: await members.boundingBox() })

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
		await page.screenshot({ path: '/check/members-mobile.png', clip: await page.locator('#members').boundingBox() })

		assert.deepEqual(errors, [])
		console.log('PASS: documents on theme tokens with real OCR labels, intake segments and progress, activity chips/paging/clock/sticky days and access failure, overview My/All tasks, members list/add/edit/chat/read-only/denied, 390px — current interface unchanged.')
	} finally {
		await browser.close()
		server.close()
	}
})().catch(e => { console.error(e); process.exitCode = 1; server.close() })
