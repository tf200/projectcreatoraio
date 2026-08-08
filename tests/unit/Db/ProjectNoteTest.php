<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Db;

use OCA\ProjectCreatorAIO\Db\ProjectNote;
use PHPUnit\Framework\TestCase;

final class ProjectNoteTest extends TestCase {
	public function testJsonSerializeIncludesNoteType(): void {
		$note = new ProjectNote();
		$note->setNoteType(ProjectNote::NOTE_TYPE_DECISION);

		$this->assertSame(ProjectNote::NOTE_TYPE_DECISION, $note->jsonSerialize()['noteType']);
	}

	public function testInvalidNoteTypeFallsBackToGeneral(): void {
		$this->assertSame(ProjectNote::NOTE_TYPE_GENERAL, ProjectNote::normalizeNoteType('unknown'));
		$this->assertSame('General note', ProjectNote::noteTypeLabel('unknown'));
	}
}
