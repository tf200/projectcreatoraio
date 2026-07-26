<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Db;

use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class BoardPolicyMembershipMapperTest extends TestCase {
	public function testFindByRolesUsesSupportedArrayParameter(): void {
		$mapper = new BoardPolicyMembershipMapper(\OCP\Server::get(IDBConnection::class));

		self::assertSame([], $mapper->findByRoles([PHP_INT_MAX]));
	}
}
