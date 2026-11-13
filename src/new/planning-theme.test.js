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
	// The What-If card editor is a dialog outside the tab; it is reached only while the tab is shown.
	// A theme attribute on body may come first, for the dark and light colour schemes.
	const scoped = s => /^(body\[data-theme-[a-z-]+\] )?\.pc-new \.pc-planning-theme/.test(s) || /^body(\[data-theme-[a-z-]+\])?:has\(\.pc-new \.pc-planning-theme\) \.whatif-editor /.test(s)
	assert.deepEqual(selectors.filter(s => !scoped(s)), [])
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

test('the weeks fields are one field with room for three digits and the arrows', () => {
	const rule = theme.match(/\.prep-input-wrap \.prep-number-field \{([^}]*)\}/)[1]
	for (const part of ['width: calc(3ch + 30px) !important', 'height: 30px !important', 'margin: 0 !important', 'border: 0 !important', 'box-shadow: none !important', 'appearance: auto !important']) assert.ok(rule.includes(part), part)
	assert.match(theme, /\.prep-number-field::-webkit-inner-spin-button \{ margin-inline-start: 4px; \}/)
	assert.match(theme, /\.prep-input-row \{ flex-wrap: wrap; gap: 8px; \}/)
	assert.match(theme, /\.prep-save-btn \{ --default-clickable-area: 32px; flex-shrink: 0;/)
	assert.match(theme, /\.prep-input-wrap \{ height: 32px;/)
	assert.match(theme, /\.prep-input-wrap:focus-within \{ border-color: var\(--iz-accent\);/)
})

test('What-If fields lose Nextcloud\'s heavy default, in the panel and in the card editor', () => {
	assert.match(theme, /\.pc-new \.pc-planning-theme \.planning-try__fields input,\nbody:has\(\.pc-new \.pc-planning-theme\) \.whatif-editor \.field-row input \{ height: 34px !important;[^}]*border: 1px solid var\(--iz-border-strong\) !important;/)
})

test('the browser\'s own field parts follow the dark and light themes', () => {
	assert.match(theme, /body\[data-theme-dark\] \.pc-new \.pc-planning-theme input,[\s\S]*?\{ color-scheme: dark; \}/)
	assert.match(theme, /body\[data-theme-light\] \.pc-new \.pc-planning-theme input,[\s\S]*?\{ color-scheme: light; \}/)
})
