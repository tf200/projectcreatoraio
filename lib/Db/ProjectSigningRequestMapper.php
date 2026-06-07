<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ProjectSigningRequestMapper extends QBMapper {
	public const TABLE_NAME = 'project_signing_requests';
	private const SELECT_COLUMNS = [
		'id', 'project_id', 'file_id', 'file_path', 'file_name', 'status', 'signature_flow',
		'signers_json', 'libresign_file_id', 'created_by', 'last_error', 'created_at', 'updated_at', 'completed_at',
	];

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, ProjectSigningRequest::class);
	}

	public function find(int $id): ?ProjectSigningRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::SELECT_COLUMNS)->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return ProjectSigningRequest[] */
	public function findByProject(int $projectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::SELECT_COLUMNS)->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function findLatestByProjectAndFile(int $projectId, int $fileId): ?ProjectSigningRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::SELECT_COLUMNS)->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function createRecord(int $projectId, int $fileId, string $filePath, string $fileName, string $flow, array $signers, string $createdBy): ProjectSigningRequest {
		$now = new DateTime();
		$record = new ProjectSigningRequest();
		$record->setProjectId($projectId);
		$record->setFileId($fileId);
		$record->setFilePath($filePath);
		$record->setFileName($fileName);
		$record->setStatus('pending');
		$record->setSignatureFlow($flow);
		$record->setSignersJson(json_encode($signers, JSON_THROW_ON_ERROR));
		$record->setCreatedBy($createdBy);
		$record->setCreatedAt($now);
		$record->setUpdatedAt($now);
		return $this->insert($record);
	}

	public function saveRecord(ProjectSigningRequest $record): ProjectSigningRequest {
		$record->setUpdatedAt(new DateTime());
		return $this->update($record);
	}
}
