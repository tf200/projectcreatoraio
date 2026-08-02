<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class BoardPermissionProfileMapper extends QBMapper {
	public const TABLE_NAME = 'pc_board_profiles';
	public function __construct(IDBConnection $db) { parent::__construct($db, self::TABLE_NAME, BoardPermissionProfile::class); }
	public function find(int $id): ?BoardPermissionProfile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_NAME)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try { return $this->findEntity($qb); } catch (DoesNotExistException) { return null; }
	}
	/** @return BoardPermissionProfile[] */
	public function findByOrganization(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE_NAME)->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))->orderBy('name');
		return $this->findEntities($qb);
	}
	public function createProfile(int $organizationId, string $uid, string $name, array $payload): BoardPermissionProfile {
		$profile = new BoardPermissionProfile(); $now = new DateTime();
		$profile->setOrganizationId($organizationId); $profile->setCreatorUid($uid); $profile->setName($name);
		$profile->setSchemaVersion(2); $profile->setPayloadJson(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		$profile->setCreatedAt($now); $profile->setUpdatedAt($now);
		return $this->insert($profile);
	}
}
