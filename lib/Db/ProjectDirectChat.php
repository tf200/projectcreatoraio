<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int|null getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string|null getUser1Id()
 * @method void setUser1Id(string $user1Id)
 * @method string|null getUser2Id()
 * @method void setUser2Id(string $user2Id)
 * @method string|null getTalkConversationToken()
 * @method void setTalkConversationToken(string $talkConversationToken)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class ProjectDirectChat extends Entity implements JsonSerializable {
	public $id;
	protected ?int $projectId = null;
	protected ?string $user1Id = null;
	protected ?string $user2Id = null;
	protected ?string $talkConversationToken = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('projectId', Types::BIGINT);
		$this->addType('user1Id', Types::STRING);
		$this->addType('user2Id', Types::STRING);
		$this->addType('talkConversationToken', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	/**
	 * Compute the canonical ordering of two user IDs to guarantee identical pair matching.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function canonicalUserPair(string $userA, string $userB): array {
		$u1 = trim($userA);
		$u2 = trim($userB);

		return strcmp($u1, $u2) <= 0 ? [$u1, $u2] : [$u2, $u1];
	}

	/**
	 * Given one participant's user ID, return the other participant's user ID.
	 */
	public function getOtherUserId(string $userId): ?string {
		if ($this->user1Id === $userId) {
			return $this->user2Id;
		}
		if ($this->user2Id === $userId) {
			return $this->user1Id;
		}
		return null;
	}

	/**
	 * Check whether a user is one of the participants.
	 */
	public function hasParticipant(string $userId): bool {
		return $this->user1Id === $userId || $this->user2Id === $userId;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'projectId' => $this->projectId,
			'user1Id' => $this->user1Id,
			'user2Id' => $this->user2Id,
			'talkConversationToken' => $this->talkConversationToken,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
