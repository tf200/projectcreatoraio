import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { sharedZipUrl } from './documents.js'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')

test('the new interface no longer sends anyone to the old one', () => {
	const files = readdirSync(new URL('.', import.meta.url)).filter(name => /\.(vue|js)$/.test(name) && !name.endsWith('.test.js'))
	for (const name of files) {
		const source = read('./' + name)
		assert.doesNotMatch(source, /legacyUrl|legacy-url/, name)
		assert.doesNotMatch(source, /'(Back to|Open) current interface'/, name)
		assert.doesNotMatch(source, /'\/new\/create'|'\/new'\s*:/, name + ' links to the app\'s own addresses')
	}
})

test('the app\'s addresses serve the new interface; the old one is at /legacy for admins', () => {
	const routes = read('../../appinfo/routes.php')
	for (const [name, url] of [['page#create', '/create'], ['page#legacy', '/legacy'], ['page#legacyProject', '/legacy/{projectId}'], ['page#newIndex', '/new'], ['page#newProject', '/new/projects/{projectId}'], ['page#newProjectTab', '/new/projects/{projectId}/{tab}'], ['page#newCreate', '/new/create']]) {
		assert.ok(routes.includes(`'name' => '${name}', 'url' => '${url}'`), name)
	}
	const controller = read('../../lib/Controller/PageController.php')
	const method = name => controller.match(new RegExp(`public function ${name}\\([^)]*\\)[^{]*\\{([\\s\\S]*?)\\n\\t\\}`))[1]
	assert.match(method('index'), /return \$this->renderPage\('new'\);\s*$/)
	assert.match(method('project'), /renderPage\('new'\)/)
	assert.match(method('create'), /renderPage\('new'\)/)
	for (const name of ['legacy', 'legacyProject']) {
		assert.match(method(name), /if \(!\$this->isAdmin\(\)\) \{\s*return \$this->redirect/, name + ' sends non-admins to the new interface')
		assert.match(method(name), /renderPage\('index'\)/, name)
	}
	// Old links: the create form, the whiteboard pop-out, and every /new address.
	assert.match(method('index'), /getParam\('create', ''\) === '1'[\s\S]*?page\.create/)
	assert.match(method('index'), /'popout', ''\) === 'whiteboard'[\s\S]*?isAdmin\(\)[\s\S]*?renderPage\('index'\)[\s\S]*?redirectToProject\(\$this->request->getParam\('projectId'\), 'whiteboard'\)/)
	assert.match(method('newProjectTab'), /redirectToProject\(\$projectId, \$tab\)/)
	assert.match(controller, /preg_match\('\/\^\[1-9\]\\d\{0,17\}\$\/', \$id\)/, 'only a project id goes into a redirect')
	assert.match(controller, /preg_match\('\/\^\[A-Za-z\]\{1,32\}\$\/', \$tabName\)/, 'only a plain tab name goes into a redirect')
	// The app menu, notifications and emails link to page.index, which is now the new interface.
	assert.match(read('../../appinfo/info.xml'), /<route>projectcreatoraio\.page\.index<\/route>/)
})

test('the header\'s city opens the location menu with the map', () => {
	const header = read('./ProjectHeader.vue')
	assert.match(header, /class="pc-location__btn" :aria-expanded="String\(locationOpen\)"[^>]*@click="toggleLocation"/)
	assert.match(header, /<div v-if="locationOpen" id="pc-location-pop"[^>]*><LocationMenu :street="project\.loc_street \|\| ''" :city="project\.loc_city \|\| ''" :zip="project\.loc_zip \|\| ''" :search-url="locationUrl" @edit="editLocation" \/>/)
	assert.match(header, /editLocation\(\) \{ this\.locationOpen = false; this\.\$refs\.actions\.openClient\(\) \}/)
	assert.match(header, /event\.key === 'Escape' && this\.locationOpen/)
	const menu = read('./LocationMenu.vue')
	assert.match(menu, /extends: ProjectLocationMap/, 'same lookup and OpenStreetMap embed as the old interface')
	assert.match(menu, /<iframe v-else-if="mapEmbedUrl"\s+class="pc-location-menu__frame"[\s\S]*?:src="mapEmbedUrl"/)
	assert.match(menu, /v-if="!street\.trim\(\)"/, 'no street: says so instead of the create form\'s hint')
})

test('"View project details" opens the Edit project details dialog', () => {
	assert.match(read('./NewOverview.vue'), /openDetails\(\) \{ this\.\$emit\('edit-details'\) \}/)
	assert.match(read('./NewApp.vue'), /@edit-details="\$refs\.header\.openDetails\(\)"/)
	assert.match(read('./ProjectHeader.vue'), /openDetails\(\) \{ this\.\$refs\.actions\.openDetails\(\) \}/)
})

test('Download all shared files gives the whole Shared Files folder as a ZIP', () => {
	const dav = 'https://cloud.test/remote.php/dav'
	assert.equal(sharedZipUrl(dav, [{ path: '/alice/files/Firma de Testerij  - Shared Files' }]), dav + '/files/alice/Firma%20de%20Testerij%20%20-%20Shared%20Files?accept=zip')
	assert.equal(sharedZipUrl(dav + '/', [{ path: '/alice/files/Projects/P#1' }]), dav + '/files/alice/Projects/P%231?accept=zip')
	for (const roots of [[], null, [{}], [{ path: 'relative/files/x' }], [{ path: '/alice/other/x' }], [{ path: '/alice/files/a' }, { path: '/alice/files/b' }]]) {
		assert.equal(sharedZipUrl(dav, roots), '', JSON.stringify(roots))
	}
	const module = read('./ProjectModule.vue')
	assert.match(module, /<a v-if="sharedZipUrl"\s+class="pc-documents-zip"\s+:href="sharedZipUrl"\s+download>/)
	assert.match(module, /sharedZipUrl\(\) \{ return this\.tab === 'documents' \? sharedZipUrl\(generateRemoteUrl\('dav'\), this\.files\.shared\) : '' \}/)
})

test('the project shelf starts folded while a project is open', () => {
	const shelf = read('./ProjectShelf.vue')
	assert.match(shelf, /data\(\) \{ return \{ collapsed: true \} \}/)
	assert.match(shelf, /folded\(\) \{ return this\.collapsed && !!this\.selectedId \}/, 'with no project open the shelf stays visible')
	assert.match(shelf, /<div v-else v-show="!folded" class="pc-shelf-content">/)
	assert.match(shelf, /<button v-if="selectedId" class="pc-secondary pc-shelf-toggle" :aria-expanded="String\(!folded\)"/)
	assert.match(shelf, /folded\(value\) \{ if \(!value\) this\.revealSelection\(\) \}/, 'unfolding scrolls to the open project')
})

test('the Intake form is hidden: no tab, and its old links open the Overview', async () => {
	const { readRoute, interfaceUrl } = await import('./navigation.js')
	const base = '/apps/projectcreatoraio'
	for (const tab of ['intake', 'cardVisibility']) {
		assert.deepEqual(readRoute(base + '/21', '?tab=' + tab), { projectId: 21, tab: 'overview' }, tab)
		assert.equal(interfaceUrl(base, { projectId: 21, tab }), base + '/21', tab)
	}
	assert.doesNotMatch(read('./ProjectHeader.vue'), /id: 'intake'/)
	assert.match(read('./create.js'), /t\('Starts with the 20 Combi process tasks\.'\)/)
	const nl = JSON.parse(read('../../l10n/nl.json')).translations
	assert.equal(nl['Starts with the 20 Combi process tasks.'], 'Dit project begint met de 20 Combi-procestaken.')
	assert.equal(nl['Starts with the 20 Combi process tasks and the intake form.'], undefined)
	assert.match(read('../../l10n/nl.js'), /"Starts with the 20 Combi process tasks\.": "Dit project begint met de 20 Combi-procestaken\."/)
})

test('the overview timeline uses the Planning tab’s numbered key-date rows', () => {
	const overview = read('./NewOverview.vue')
	const steps = [...overview.matchAll(/<span class="pc-timeline-step"[^>]*>(\d)<\/span>/g)].map(m => m[1])
	assert.deepEqual(steps, ['1', '2', '3', '4', '5', '6', '7', '8'])
	for (const label of ["t('Request date')", "t('Process steps')", "t('PROCESS COMPLETION DATE')", "t('Handover')", "t('REQUIRED PREPARATION TIME')", "t('MINIMUM START DATE')", "t('START DATES')", "t('DESIRED')", "t('ACTUAL')", "t('End date')", "t('Weeks on site')"]) assert.ok(overview.includes(label), label)
	assert.doesNotMatch(overview, /<h3 id="pc-timeline-\w+"><i/, 'no phase dot; Planning has none')
	assert.match(overview, /<span class="pc-sr-only">\{\{ stageText\(0\) \}\}<\/span>/, 'screen readers still hear the current phase')
	assert.doesNotMatch(overview, /<input|@click="set|Hand over/, 'read-only')
	const css = read('./new-ui.css')
	assert.doesNotMatch(css, /pc-timeline-group h3 i\{|pc-timeline-group dl\{/)
	assert.match(css, /\.pc-new \.pc-timeline-steps>li\{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var\(--iz-border\)\}/)
})
test('the overview timeline shows the plain line Process steps uses, without ≈', () => {
	const overview = read('./NewOverview.vue')
	assert.doesNotMatch(overview, /≈|pc-timeline-approx|pc-timeline-meter--estimate/)
	assert.match(overview, /<div class="pc-timeline-overall"><p><strong>\{\{ timeline\.overall\.percent === null \? '—' : timeline\.overall\.percent \+ '%' \}\}<\/strong>/)
	assert.match(overview, /class="iz-pill iz-pill--muted pc-timeline-estimate">\{\{ t\('Estimate'\) \}\}/, 'the Estimate label stays')
	const css = read('./new-ui.css')
	assert.doesNotMatch(css, /pc-timeline-overall \.pc-timeline-meter\{height|meter--estimate|repeating-linear-gradient\(-45deg/)
})

test('the Planning zoom reads as a percentage, 100% where the planning opens', () => {
	const gantt = read('../components/ProjectTimeline/GanttChart.vue')
	assert.match(gantt, /<span class="zoom-indicator" :title="t\('Zoom level'\)">\{\{ t\('\{n\}%', \{ n: zoomPercent \}\) \}\}<\/span>/)
	assert.doesNotMatch(gantt, /px\/d/)
	assert.match(gantt, /const DEFAULT_DAY_WIDTH = 4\n/)
	assert.match(gantt, /zoomDayWidth: DEFAULT_DAY_WIDTH,/)
	// Click through every step both ways with the component's own code.
	const chart = ganttModel(gantt)
	const seen = [chart.zoomPercent]
	for (let i = 0; i < 4; i++) { chart.zoomIn(); seen.push(chart.zoomPercent) }
	for (let i = 0; i < 7; i++) { chart.zoomOut(); seen.push(chart.zoomPercent) }
	for (let i = 0; i < 2; i++) { chart.zoomIn(); seen.push(chart.zoomPercent) }
	assert.deepEqual(seen, [100, 150, 200, 300, 400, 300, 200, 150, 100, 75, 50, 50, 75, 100], 'one step at a time, both ways, stopping at 50% and 400%')
	const nl = JSON.parse(read('../../l10n/nl.json')).translations
	assert.equal(nl['{n}%'], '{n}%')
	assert.equal(nl['Zoom level'], 'Zoomniveau')
	assert.equal(nl['{n}px/d'], undefined)
})

// GanttChart's zoom, built from the component's own computed properties and methods.
function ganttModel(gantt, { availableWidth = 0, totalDays = 0 } = {}) {
	const levels = JSON.parse(gantt.match(/const ZOOM_LEVELS = (\[[^\]]*\])/)[1])
	const body = name => gantt.match(new RegExp('\\n\\t\\t' + name + '\\(\\) \\{([\\s\\S]*?)\\n\\t\\t\\}'))[1]
	const make = name => new Function('ZOOM_LEVELS', 'DEFAULT_DAY_WIDTH', 'return function () {' + body(name) + '}')(levels, 4)
	const chart = { zoomDayWidth: 4, availableWidth, totalDays, setZoom(value) { this.zoomDayWidth = value } }
	for (const name of ['fitDayWidth', 'dayWidth', 'zoomPercent', 'canZoomIn', 'canZoomOut']) Object.defineProperty(chart, name, { get: make(name) })
	for (const name of ['zoomIn', 'zoomOut']) chart[name] = make(name)
	return chart
}

test('a short plan fills the width; a long plan keeps its zoom and scrolls', () => {
	const gantt = read('../components/ProjectTimeline/GanttChart.vue')
	// Discipline check on a 1920 px screen: 180 days, 1246 px for the dates.
	const short = ganttModel(gantt, { availableWidth: 1246, totalDays: 180 })
	assert.equal(short.dayWidth, 6.92)
	assert.ok(short.dayWidth * short.totalDays <= 1246, 'never wider than the space, so no scrollbar')
	assert.equal(short.zoomPercent, 173)
	assert.equal(short.canZoomOut, false, 'zoom out stops at the fitted zoom')
	assert.equal(short.canZoomIn, true)
	short.zoomIn(); assert.equal(short.zoomPercent, 200)
	short.zoomOut(); assert.equal(short.zoomPercent, 173, 'back to the fitted zoom, not below')
	short.availableWidth = 766; assert.equal(short.zoomPercent, 106, 'after zooming back out, a narrower window fits again')
	short.availableWidth = 2000; assert.equal(short.zoomPercent, 278, 'a wider window fits again')
	// Just below 100%: zooming out goes to the fitted zoom, not past it.
	const near = ganttModel(gantt, { availableWidth: 630, totalDays: 180 })
	assert.equal(near.zoomPercent, 100)
	near.zoomOut(); assert.equal(near.zoomPercent, 88)
	assert.equal(near.canZoomOut, false)
	// Firma de Testerij: 761 days do not fit, so 100% and scrolling, as before.
	const long = ganttModel(gantt, { availableWidth: 1246, totalDays: 761 })
	assert.equal(long.zoomPercent, 100)
	assert.equal(long.canZoomOut, true)
	// Before the chart is measured nothing changes.
	assert.equal(ganttModel(gantt).zoomPercent, 100)
	// Measured after loading, when the plan changes, and on every resize.
	assert.match(gantt, /loading\(\) \{ this\.\$nextTick\(this\.fitTimeline\) \},\n\t\ttotalDays\(\) \{ this\.\$nextTick\(this\.fitTimeline\) \},/)
	assert.match(gantt, /new ResizeObserver\(\(\) => this\.fitTimeline\(\)\)/)
	assert.match(gantt, /beforeDestroy\(\) \{\n\t\tthis\.resizeObserver\?\.disconnect\(\)/)
	assert.match(gantt, /:disabled="!canZoomOut" @click="zoomOut"/)
	assert.match(gantt, /:disabled="!canZoomIn" @click="zoomIn"/)
	assert.doesNotMatch(gantt, /this\.dayWidth = /, 'only the chosen zoom is ever set')
})
