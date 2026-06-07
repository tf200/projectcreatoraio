<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class ProjectSigningRequest extends Entity implements JsonSerializable {
	protected ?int $projectId = null;
	protected ?int $fileId = null;
	protected ?string $filePath = null;
	protected ?string $fileName = null;
	protected ?string $status = null;
	protected ?string $signatureFlow = null;
	protected ?string $signersJson = null;
	protected ?string $libresignFileId = null;
	protected ?string $createdBy = null;
	protected ?string $lastError = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;
	protected ?DateTime $completedAt = null;

	public function __construct() {
		$this->addType('projectId', Types::INTEGER);
		$this->addType('fileId', Types::INTEGER);
		$this->addType('filePath', Types::STRING);
		$this->addType('fileName', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('signatureFlow', Types::STRING);
		$this->addType('signersJson', Types::TEXT);
		$this->addType('libresignFileId', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('lastError', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
		$this->addType('completedAt', Types::DATETIME);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'project_id' => $this->projectId,
			'file_id' => $this->fileId,
			'file_path' => $this->filePath,
			'file_name' => $this->fileName,
			'status' => $this->status,
			'signature_flow' => $this->signatureFlow,
			'signers' => $this->decodeSigners(),
			'libresign_file_id' => $this->libresignFileId,
			'created_by' => $this->createdBy,
			'last_error' => $this->lastError,
			'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
			'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
			'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
		];
	}

	private function decodeSigners(): array {
		$decoded = json_decode((string) $this->signersJson, true);
		return is_array($decoded) ? $decoded : [];
	}
}
