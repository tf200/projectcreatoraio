<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Db;

use OCA\ProjectCreatorAIO\Db\ProjectNote;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ProjectNoteMapperTest extends TestCase {
	public function testProjectStatusLabelsAndValidation(): void {
		$this->assertSame('Active', ProjectStatus::getLabel(ProjectStatus::ACTIVE));
		$this->assertSame('Waiting on Customer', ProjectStatus::getLabel(ProjectStatus::WAITING_ON_CUSTOMER));
		$this->assertSame('On Hold', ProjectStatus::getLabel(ProjectStatus::ON_HOLD));
		$this->assertSame('Done', ProjectStatus::getLabel(ProjectStatus::DONE));
		$this->assertSame('Archived', ProjectStatus::getLabel(ProjectStatus::ARCHIVED));
		$this->assertSame('Unknown', ProjectStatus::getLabel(999));

		$this->assertTrue(ProjectStatus::isValid(ProjectStatus::ACTIVE));
		$this->assertTrue(ProjectStatus::isValid(ProjectStatus::WAITING_ON_CUSTOMER));
		$this->assertTrue(ProjectStatus::isValid(ProjectStatus::ON_HOLD));
		$this->assertTrue(ProjectStatus::isValid(ProjectStatus::DONE));
		$this->assertTrue(ProjectStatus::isValid(ProjectStatus::ARCHIVED));
		$this->assertFalse(ProjectStatus::isValid(999));
	}

	public function testCreateStatusChangeNoteCreatesAuditNoteWithFormattedContent(): void {
		$db = $this->createMock(IDBConnection::class);
		$mapper = $this->getMockBuilder(ProjectNoteMapper::class)
			->setConstructorArgs([$db])
			->onlyMethods(['insert'])
			->getMock();

		$mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (ProjectNote $note) {
				$this->assertSame(42, $note->getProjectId());
				$this->assertSame('alice', $note->getUserId());
				$this->assertSame('Project status has been updated', $note->getTitle());
				$this->assertSame("Project status updated from Active to Waiting on Customer.\n\nReason: Deck inactivity (no card activity for 90+ days)", $note->getContent());
				$this->assertSame('public', $note->getVisibility());
				$this->assertSame(ProjectNote::NOTE_TYPE_AUDIT, $note->getNoteType());
				$this->assertNotNull($note->getCreatedAt());
				$this->assertNotNull($note->getUpdatedAt());

				$note->setId(101);
				return $note;
			});

		$createdNote = $mapper->createStatusChangeNote(
			42,
			'alice',
			ProjectStatus::ACTIVE,
			ProjectStatus::WAITING_ON_CUSTOMER,
			'Deck inactivity (no card activity for 90+ days)',
		);

		$this->assertSame(101, $createdNote->getId());
		$this->assertSame('Project status has been updated', $createdNote->getTitle());
	}
}
