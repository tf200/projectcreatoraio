import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

test('both Deck embeds load the bundle through the versioned URL', () => {
	for (const file of ['../components/ProjectDeck/DeckBoard.vue', '../components/ProjectDeck/DeckAnalytics.vue']) {
		const source = readFileSync(new URL(file, import.meta.url), 'utf8')
		assert.match(source, /const src = deckEmbedScriptUrl\(\)/, file)
		assert.doesNotMatch(source, /deck-embedded-tasks\.js/, file + ' has no unversioned copy of the URL')
	}
	const helper = readFileSync(new URL('./deckEmbedScript.js', import.meta.url), 'utf8')
	assert.match(helper, /'\?v=' \+ encodeURIComponent\(version\)/)
})
