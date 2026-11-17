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
