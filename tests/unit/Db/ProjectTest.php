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

	public function testDisciplinesAreStoredOnceEachInTheFixedOrder(): void {
		$encoded = Project::encodeDisciplines(['water', 'electricity', 'water', 'fiber_optic']);

		$this->assertSame('["electricity","water","fiber_optic"]', $encoded);
		$this->assertSame(['electricity', 'water', 'fiber_optic'], Project::decodeDisciplines($encoded));
		$this->assertSame('[]', Project::encodeDisciplines([]));
	}

	public function testUnknownDisciplinesAreRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		Project::encodeDisciplines(['electricity', 'telepathy']);
	}

	public function testDisciplinesMustBeAList(): void {
		$this->expectException(\InvalidArgumentException::class);
		Project::encodeDisciplines('electricity');
	}

	public function testProjectsWithoutDisciplinesSerializeAnEmptyList(): void {
		$project = new Project();
		$this->assertSame([], $project->jsonSerialize()['disciplines']);
		$project->setDisciplines('["gas","sewerage"]');
		$this->assertSame(['gas', 'sewerage'], $project->jsonSerialize()['disciplines']);
		$project->setDisciplines('not json');
		$this->assertSame([], $project->jsonSerialize()['disciplines']);
	}
}
