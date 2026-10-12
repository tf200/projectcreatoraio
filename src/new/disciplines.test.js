import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { DISCIPLINES, disciplinesOf } from './disciplines.js'

test('the disciplines are the server’s, in the same order', () => {
	const php = readFileSync(new URL('../../lib/Db/Project.php', import.meta.url), 'utf8')
	const server = php.match(/const DISCIPLINES = \[([^\]]*)\]/)[1].match(/'([^']+)'/g).map(s => s.slice(1, -1))
	assert.deepEqual(DISCIPLINES.map(d => d.value), server)
	assert.deepEqual(DISCIPLINES.map(d => d.label), ['Electricity', 'Water', 'Gas', 'Coax', 'Fiber optic', 'Sewerage'])
})

test('a choice is kept once each, in order, without unknown keys', () => {
	assert.deepEqual(disciplinesOf(['water', 'gas', 'water', 'telepathy', 'electricity']), ['electricity', 'water', 'gas'])
	assert.deepEqual(disciplinesOf(null), [])
	assert.deepEqual(disciplinesOf('water'), [])
})
