const { chromium } = require('/work/node_modules/playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const http = require('node:http')

const boards = {
	21: { fileId: 4821, name: 'Firma de Testerij.whiteboard', path: '/Projects/Firma/Firma.whiteboard', mimetype: 'application/vnd.excalidraw+json', mtime: 1789800000, size: 2048 },
	22: { fileId: 4822, name: 'Legacy.whiteboard', path: '/Projects/Legacy/Legacy.whiteboard', mimetype: 'application/vnd.excalidraw+json', mtime: 1789800000, size: 2048 },
	23: { fileId: 4823, name: 'Denied.whiteboard', path: '/Projects/Denied/Denied.whiteboard', mimetype: 'application/vnd.excalidraw+json', mtime: 1789800000, size: 2048 },
}
const events = [
	{ id: 1, actorUid: 'emma', actorDisplayName: 'Emma de Vries', occurredAt: new Date().toISOString() },
	{ id: 2, actorUid: 'thomas', actorDisplayName: 'Thomas Jansen', occurredAt: new Date().toISOString() },
	{ id: 3, actorUid: 'lotte', actorDisplayName: 'Lotte Bakker', occurredAt: new Date(Date.now() - 864e5).toISOString() },
	{ id: 4, actorUid: 'emma', actorDisplayName: 'Emma de Vries', occurredAt: new Date(Date.now() - 864e5).toISOString() },
	{ id: 5, actorUid: 'thomas', actorDisplayName: 'Thomas Jansen', occurredAt: new Date(Date.now() - 1728e5).toISOString() },
]

const server = http.createServer((req, res) => {
	const url = new URL(req.url, 'http://localhost')
	const project = url.pathname.match(/\/projects\/(\d+)\//)?.[1] || url.pathname.match(/\/projects\/(\d+)$/)?.[1]
	let body
	if (url.pathname.endsWith('/whiteboard/activity')) {
		// 22 is the legacy board: its service swallows this 403 into an empty list.
		if (project === '23' || project === '22') { res.statusCode = 403; res.end('{}'); return }
		const offset = Number(url.searchParams.get('offset')) || 0
		body = { events: events.slice(offset, offset + 4), hasMore: offset + 4 < events.length }
	} else if (url.pathname.endsWith('/whiteboard')) {
		body = boards[project]
	}
	if (body !== undefined) { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(body)); return }
	const path = '/check/dist' + (url.pathname === '/' ? '/index.html' : url.pathname)
	if (fs.existsSync(path)) {
		res.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'text/html')
		res.end(fs.readFileSync(path))
		return
	}
	res.statusCode = 404
	res.end('{}')
})

const style = el => el.evaluate(node => {
	const computed = getComputedStyle(node)
	return { pointerEvents: computed.pointerEvents, display: computed.display, position: computed.position, overflowY: computed.overflowY, maxHeight: computed.maxHeight, fontSize: computed.fontSize, padding: computed.padding, borderTopWidth: computed.borderTopWidth }
})

;(async () => {
	await new Promise(resolve => server.listen(8135, resolve))
	const browser = await chromium.launch({ headless: true, executablePath: '/root/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome', args: ['--no-sandbox'] })
	try {
		const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } })
		const errors = []
		page.on('pageerror', e => { errors.push(e.message); console.log('ERROR', e.message) })
		page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE', m.text()) })
		await page.goto('http://localhost:8135')
		const css = '/app/src/new/whiteboard-theme.css'
		await page.addStyleTag({ content: fs.readFileSync(css, 'utf8') })
		await page.locator('#modern .stub-whiteboard').waitFor()
		await page.locator('#legacy .stub-whiteboard').waitFor()

		// The whiteboard app renders ReadOnlyViewer whenever isEmbedded is set,
		// so the modern tab must not claim to be embedding it: no toolbar there
		// means no palette or menu, whatever the CSS says.
		assert.equal(await page.locator('#modern .stub-whiteboard--editor .stub-toolbar').count(), 1, 'the modern tab must mount the editor, not the read-only viewer')
		assert.equal(await page.locator('#legacy .stub-whiteboard--read-only').count(), 1, 'the current interface must keep the read-only viewer')
		assert.equal(await page.locator('#legacy .stub-toolbar').count(), 0)

		// The board is usable in the modern tab and still a picture in the legacy one.
		assert.equal((await style(page.locator('#modern .whiteboard-board__embedded'))).pointerEvents, 'auto')
		assert.equal((await style(page.locator('#legacy .whiteboard-board__embedded'))).pointerEvents, 'none', 'the current interface must keep its inert preview')
		assert.equal((await style(page.locator('#modern .whiteboard-board__preview-overlay'))).display, 'none')
		assert.notEqual((await style(page.locator('#legacy .whiteboard-board__preview-overlay'))).display, 'none')

		// A real click has to reach the handler through the theme, and only there.
		await page.locator('#modern .stub-whiteboard').click({ position: { x: 40, y: 40 } })
		await page.locator('#legacy .whiteboard-board__preview-wrap').click({ position: { x: 40, y: 40 } })
		const hits = await page.evaluate(() => window.__hits)
		assert.equal(hits['4821'], 1, 'the modern canvas must receive pointer events')
		assert.equal(hits['4822'], undefined, 'the legacy overlay must keep swallowing them')

		// Theme applied to one board, untouched on the other.
		assert.equal((await style(page.locator('#modern .whiteboard-board__title-text'))).fontSize, '20px')
		assert.equal((await style(page.locator('#legacy .whiteboard-board__title-text'))).fontSize, '16px')
		assert.equal((await style(page.locator('#modern .whiteboard-activity'))).borderTopWidth, '0px')
		assert.notEqual((await style(page.locator('#legacy .whiteboard-activity'))).borderTopWidth, '0px')

		// Fixed-height scroller with headers that stay put.
		const timeline = await style(page.locator('#modern .whiteboard-activity__timeline'))
		assert.equal(timeline.overflowY, 'auto')
		assert.equal(timeline.maxHeight, '196px')
		assert.equal((await style(page.locator('#modern .whiteboard-activity__date-label').first())).position, 'sticky')
		const scrolled = await page.locator('#modern .whiteboard-activity__timeline').evaluate(node => {
			node.scrollTop = node.scrollHeight
			return { scrollTop: node.scrollTop, overflowing: node.scrollHeight > node.clientHeight }
		})
		assert.ok(scrolled.overflowing && scrolled.scrollTop > 0, 'the activity list must scroll inside its own box')

		// Access failures read as failures on one side and stay swallowed on the other.
		await page.locator('#modern-error .whiteboard-activity__error').waitFor()
		assert.match(await page.locator('#modern-error .whiteboard-activity__error > span').first().innerText(), /could not be loaded/)
		assert.equal(await page.locator('#legacy .whiteboard-activity__empty').innerText(), 'No whiteboard activity yet.')

		// The legacy board never reaches for the in-page overlay: its click went
		// to window.open, which this headless browser refuses.
		assert.equal(await page.evaluate(() => window.__overlay), undefined)
		await page.locator('#legacy .whiteboard-board__actions button').first().click()
		assert.equal(await page.evaluate(() => window.__overlay), undefined, 'the current interface must keep opening a separate window')

		// Focus mode uses the in-page viewer overlay and stands the inline board down.
		await page.locator('#modern .whiteboard-board__actions button').first().click()
		assert.equal(await page.locator('#modern .stub-whiteboard').count(), 0, 'the inline board must unmount while the overlay holds the file')
		assert.ok(await page.evaluate(() => !!window.__overlay))
		await page.locator('#modern .stub-whiteboard').waitFor()

		await page.screenshot({ path: '/check/desktop.png', fullPage: true })
		await page.setViewportSize({ width: 390, height: 844 })
		await page.waitForTimeout(300)
		assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= 390), 'the whiteboard tab must fit a mobile viewport')
		assert.equal((await style(page.locator('#modern .whiteboard-activity__timeline'))).maxHeight, '132px')
		await page.screenshot({ path: '/check/mobile.png', fullPage: true })

		assert.deepEqual(errors, [])
		console.log('PASS: live canvas in the modern tab, inert preview kept in the legacy one, themed header and scrolling activity, strict vs swallowed access failure, overlay stands the inline board down, mobile width.')
	} finally {
		await browser.close()
		server.close()
	}
})().catch(e => { console.error(e); process.exitCode = 1; server.close() })
