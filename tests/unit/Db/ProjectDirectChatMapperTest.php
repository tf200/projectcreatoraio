<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Db;

use OCA\ProjectCreatorAIO\Db\ProjectDirectChat;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChatMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ProjectDirectChatMapperTest extends TestCase {
	public function testCreateChatAppliesCanonicalPairAndPersists(): void {
		$db = $this->createMock(IDBConnection::class);
		$mapper = $this->getMockBuilder(ProjectDirectChatMapper::class)
			->setConstructorArgs([$db])
			->onlyMethods(['insert'])
			->getMock();

		$mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (ProjectDirectChat $chat) {
				$this->assertSame(42, $chat->getProjectId());
				// Canonical order: 'bob' and 'alice' -> user1='alice', user2='bob'
				$this->assertSame('alice', $chat->getUser1Id());
				$this->assertSame('bob', $chat->getUser2Id());
				$this->assertSame('room-token-xyz', $chat->getTalkConversationToken());
				$this->assertNotNull($chat->getCreatedAt());
				$this->assertNotNull($chat->getUpdatedAt());

				$chat->setId(7);
				return $chat;
			});

		$created = $mapper->createChat(42, 'bob', 'alice', 'room-token-xyz');

		$this->assertSame(7, $created->getId());
		$this->assertSame('alice', $created->getUser1Id());
		$this->assertSame('bob', $created->getUser2Id());
		$this->assertSame('room-token-xyz', $created->getTalkConversationToken());
	}

	public function testFindByTalkConversationTokenReturnsNullOnEmpty(): void {
		$db = $this->createMock(IDBConnection::class);
		$mapper = new ProjectDirectChatMapper($db);

		$this->assertNull($mapper->findByTalkConversationToken(''));
		$this->assertNull($mapper->findByTalkConversationToken('   '));
	}

	public function testDeleteByTokenReturnsEarlyOnEmpty(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$mapper = new ProjectDirectChatMapper($db);
		$mapper->deleteByToken('');
		$mapper->deleteByToken('   ');
	}
}
