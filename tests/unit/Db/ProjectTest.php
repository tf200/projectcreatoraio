<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Db;

use OCA\ProjectCreatorAIO\Db\Project;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase {
	public function testClientRolesRoundTripThroughStringStorage(): void {
		$encoded = Project::encodeClientRoles([
			'project_sponsor',
			'project_owner',
			'project_sponsor',
		]);

		$this->assertSame('["project_sponsor","project_owner"]', $encoded);
		$this->assertSame(['project_sponsor', 'project_owner'], Project::decodeClientRoles($encoded));
	}

	public function testEmptyClientRoleSelectionIsStoredAsJsonArray(): void {
		$this->assertSame('[]', Project::encodeClientRoles([]));
		$this->assertSame([], Project::decodeClientRoles('[]'));
	}

	public function testLegacyClientRoleIsPreservedAsSingleSelection(): void {
		$this->assertSame('Existing custom role', Project::encodeClientRoles('Existing custom role'));
		$this->assertSame(['Existing custom role'], Project::decodeClientRoles('Existing custom role'));
	}

	public function testJsonSerializationExposesClientRolesAsArray(): void {
		$project = new Project();
		$project->setClientRole('["project_sponsor","business_contact"]');

		$this->assertSame(
			['project_sponsor', 'business_contact'],
			$project->jsonSerialize()['client_role'],
		);
	}
}
