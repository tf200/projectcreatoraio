<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class CardPolicy extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $cardId = null;
	protected ?int $boardId = null;

	public function __construct() {
		$this->addType('cardId', Types::BIGINT);
		$this->addType('boardId', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'cardId' => $this->cardId,
			'boardId' => $this->boardId,
		];
	}
}
