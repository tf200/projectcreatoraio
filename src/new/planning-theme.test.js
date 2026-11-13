import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const theme = read('./planning-theme.css')

// Every selector, with comments and @container wrappers stripped.
function selectorsOf(css) {
	const body = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/@container[^{]*\{/g, '')
	return [...body.matchAll(/([^{}]+)\{[^{}]*\}/g)].flatMap(match => match[1].split(',')).map(s => s.trim()).filter(Boolean)
}

test('the Planning theme only reaches the new layout\'s Planning tab', () => {
	const selectors = selectorsOf(theme)
	assert.ok(selectors.length > 100)
	assert.deepEqual(selectors.filter(s => !s.startsWith('.pc-new .pc-planning-theme')), [])
	assert.match(read('./ProjectModule.vue'), /'pc-planning-theme': tab === 'planning'/)
	assert.match(read('../new-main.js'), /import '\.\/new\/planning-theme\.css'/)
})

test('one title, and the delay warning still shows', () => {
	assert.match(theme, /\.pc-new \.pc-planning-theme \.timeline-v2__title,\n\.pc-new \.pc-planning-theme \.timeline-v2__subtitle \{ display: none; \}/)
	assert.doesNotMatch(theme, /delay-warning-badge[^{]*\{[^}]*display: none/)
	assert.doesNotMatch(theme, /title-with-badge[^{]*\{[^}]*display: none/)
})

test('date warnings and refusals keep their colour', () => {
	assert.match(theme, /\.kpi-card__subtext--warning \{ color: var\(--iz-warning-text\); white-space: normal; \}/)
	assert.match(theme, /\.kpi-card__subtext--error \{ color: var\(--iz-danger-text\); white-space: normal; \}/)
})

test('the key dates read as three columns and stack when narrow', () => {
	assert.match(theme, /\.kpi-group \{ flex-direction: column; gap: 0; \}/)
	assert.match(theme, /@container kpi-bar \(max-width: 719px\) \{[\s\S]*?flex-basis: 100%/)
})
