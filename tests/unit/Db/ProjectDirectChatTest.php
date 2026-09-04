<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Db;

use DateTime;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChat;
use PHPUnit\Framework\TestCase;

final class ProjectDirectChatTest extends TestCase {
	public function testCanonicalUserPairSorting(): void {
		$pair1 = ProjectDirectChat::canonicalUserPair('alice', 'bob');
		$this->assertSame(['alice', 'bob'], $pair1);

		$pair2 = ProjectDirectChat::canonicalUserPair('bob', 'alice');
		$this->assertSame(['alice', 'bob'], $pair2);

		$pair3 = ProjectDirectChat::canonicalUserPair(' userB ', 'userA');
		$this->assertSame(['userA', 'userB'], $pair3);

		$pairSame = ProjectDirectChat::canonicalUserPair('charlie', 'charlie');
		$this->assertSame(['charlie', 'charlie'], $pairSame);
	}

	public function testGetOtherUserId(): void {
		$chat = new ProjectDirectChat();
		$chat->setUser1Id('alice');
		$chat->setUser2Id('bob');

		$this->assertSame('bob', $chat->getOtherUserId('alice'));
		$this->assertSame('alice', $chat->getOtherUserId('bob'));
		$this->assertNull($chat->getOtherUserId('charlie'));
	}

	public function testHasParticipant(): void {
		$chat = new ProjectDirectChat();
		$chat->setUser1Id('alice');
		$chat->setUser2Id('bob');

		$this->assertTrue($chat->hasParticipant('alice'));
		$this->assertTrue($chat->hasParticipant('bob'));
		$this->assertFalse($chat->hasParticipant('charlie'));
	}

	public function testJsonSerialization(): void {
		$now = new DateTime('2026-09-04T12:00:00+00:00');

		$chat = new ProjectDirectChat();
		$chat->setId(10);
		$chat->setProjectId(42);
		$chat->setUser1Id('alice');
		$chat->setUser2Id('bob');
		$chat->setTalkConversationToken('token-abc-123');
		$chat->setCreatedAt($now);
		$chat->setUpdatedAt($now);

		$json = $chat->jsonSerialize();

		$this->assertSame(10, $json['id']);
		$this->assertSame(42, $json['projectId']);
		$this->assertSame('alice', $json['user1Id']);
		$this->assertSame('bob', $json['user2Id']);
		$this->assertSame('token-abc-123', $json['talkConversationToken']);
		$this->assertNotEmpty($json['createdAt']);
		$this->assertNotEmpty($json['updatedAt']);
	}
}
