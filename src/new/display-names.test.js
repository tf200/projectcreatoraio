import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const nl = JSON.parse(readFileSync(new URL('../../l10n/nl.json', import.meta.url), 'utf8'))

function registerDutch() {
	globalThis.window = globalThis
	globalThis.document = { documentElement: { lang: 'nl', dataset: { locale: 'nl_NL' } } }
	globalThis._oc_l10n_registry_translations = { projectcreatoraio: nl.translations }
	globalThis._oc_l10n_registry_plural_functions = { projectcreatoraio: (n) => (n != 1 ? 1 : 0) }
}
function unregister() {
	globalThis._oc_l10n_registry_translations = {}
	globalThis._oc_l10n_registry_plural_functions = {}
}

const { folderDisplayName } = await import('./folder-names.mjs')

test('the app\'s own folders are shown in Dutch, whatever name they got at creation', () => {
	registerDutch()
	assert.equal(folderDisplayName('Combi Nieuwbouw Zuidas - Shared Files'), 'Combi Nieuwbouw Zuidas - Gedeelde bestanden')
	assert.equal(folderDisplayName('Combi Nieuwbouw Zuidas - Gedeelde bestanden'), 'Combi Nieuwbouw Zuidas - Gedeelde bestanden')
	for (const stored of ['Public Notes', 'Openbare notities', 'Gedeelde notities']) {
		assert.equal(folderDisplayName(stored), 'Gedeelde notities')
	}
	unregister()
})

test('without a Dutch bundle the English names come back', () => {
	unregister()
	assert.equal(folderDisplayName('Zuidas - Gedeelde bestanden'), 'Zuidas - Shared Files')
	assert.equal(folderDisplayName('Openbare notities'), 'Public Notes')
})

test('folders users named themselves are left alone', () => {
	registerDutch()
	for (const own of ['Tekeningen', ' - Shared Files', 'Public Notes archive', '', 'Shared Files']) {
		assert.equal(folderDisplayName(own), own)
	}
	assert.equal(folderDisplayName(undefined), '')
	unregister()
})

const { stepDisplayName } = await import('./step-names.mjs')

test('standard Deck steps stored in English are shown in Dutch; own step names stay as typed', () => {
	registerDutch()
	assert.equal(stepDisplayName('Next priority'), 'Volgende prioriteit')
	assert.equal(stepDisplayName('Process Steps'), 'Processtappen')
	assert.equal(stepDisplayName('Approved'), 'Goedgekeurd')
	assert.equal(stepDisplayName('Done'), 'Afgerond')
	assert.equal(stepDisplayName('Approved/Done'), 'Goedgekeurd/afgerond')
	assert.equal(stepDisplayName('Volgende prioriteit'), 'Volgende prioriteit')
	assert.equal(stepDisplayName('Wachten op netbeheerder'), 'Wachten op netbeheerder')
	unregister()
	assert.equal(stepDisplayName('Next priority'), 'Next priority')
})

const { roleDisplayName } = await import('./members.js')

test('standard project roles stored in English are shown in Dutch; renamed roles stay as typed', () => {
	registerDutch()
	assert.equal(roleDisplayName('grid_operator', 'Grid operator (Elektra)'), 'Netbeheerder (elektra)')
	assert.equal(roleDisplayName('client_developer', 'Client/Developer'), 'Aanvrager/ontwikkelaar')
	assert.equal(roleDisplayName('grid_operator', 'Netbeheerder Stedin'), 'Netbeheerder Stedin')
	assert.equal(roleDisplayName('custom_1', 'Client/Developer'), 'Client/Developer')
	unregister()
})
