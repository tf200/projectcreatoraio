import test from 'node:test'
import assert from 'node:assert/strict'
import { buildRichArguments, fileSizeLabel, plainText, reactionList, splitFileShare } from './chatMessage.js'

const parts = { mention: 'Mention', file: 'File', object: 'Object' }

test('mentions, files and shared objects each get their own component', () => {
	const args = buildRichArguments({
		'mention-user1': { type: 'user', id: 'bob', name: 'Bob' },
		'mention-call1': { type: 'call', id: 'room', name: 'Project X' },
		file: { type: 'file', id: '7', name: 'plan.pdf' },
		object: { type: 'talk-poll', id: '3', name: 'Lunch?' },
		actor: 'not an object',
	}, parts, 'alice')

	assert.deepEqual(Object.keys(args), ['mention-user1', 'mention-call1', 'file', 'object'])
	assert.equal(args['mention-user1'].component, 'Mention')
	assert.equal(args['mention-user1'].props.currentUserId, 'alice')
	assert.equal(args['mention-call1'].component, 'Mention')
	assert.equal(args.file.component, 'File')
	assert.equal(args.object.component, 'Object')
})

test('a file share without caption shows only the file', () => {
	const file = { type: 'file', id: '7', name: 'plan.pdf' }
	assert.deepEqual(splitFileShare({ message: '{file}', messageParameters: { file } }), { file, caption: '' })
	assert.deepEqual(splitFileShare({ message: 'Latest plan', messageParameters: { file } }), { file, caption: 'Latest plan' })
	assert.deepEqual(splitFileShare({ message: 'Hello', messageParameters: [] }), { file: null, caption: 'Hello' })
})

test('reply quotes read as plain text', () => {
	assert.equal(plainText({
		message: 'See {file} @ {mention-user1} {unknown}',
		messageParameters: {
			file: { type: 'file', name: 'plan.pdf' },
			'mention-user1': { type: 'user', name: 'Bob' },
		},
	}), 'See plan.pdf @ @Bob {unknown}')
})

test('reactions and sizes', () => {
	assert.deepEqual(reactionList([]), [])
	assert.deepEqual(reactionList({ '👍': 2 }), [{ emoji: '👍', count: 2 }])
	assert.equal(fileSizeLabel(512), '512 B')
	assert.equal(fileSizeLabel(1536), '1.5 KB')
	assert.equal(fileSizeLabel(0), '')
})

test('voice messages and recordings play from the viewer copy', async () => {
	const { mediaKind, davFileUrl } = await import('./chatMessage.js')
	assert.equal(mediaKind({ messageType: 'voice-message' }), 'audio')
	assert.equal(mediaKind({ messageType: 'record-video' }), 'video')
	assert.equal(mediaKind({ messageType: 'comment' }), null)
	assert.equal(davFileUrl('/remote.php/dav/files/alice', { path: 'Talk/Voice #1.ogg' }), '/remote.php/dav/files/alice/Talk/Voice%20%231.ogg')
	assert.equal(davFileUrl('/remote.php/dav/files/alice', { path: '' }), '')
})
