<?php
namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use JsonSerializable;

class ProjectNote extends Entity implements JsonSerializable {
    public const NOTE_TYPE_GENERAL = 'general';
    public const NOTE_TYPE_CUSTOMER = 'customer';
    public const NOTE_TYPE_INTERNAL = 'internal';
    public const NOTE_TYPE_DECISION = 'decision';
    public const NOTE_TYPE_RISK_BLOCKER = 'risk_blocker';
    public const NOTE_TYPE_ACTION_POINT = 'action_point';
    public const NOTE_TYPE_TECHNICAL = 'technical';
    public const NOTE_TYPE_AUDIT = 'audit';

    public const NOTE_TYPES = [
        self::NOTE_TYPE_GENERAL,
        self::NOTE_TYPE_CUSTOMER,
        self::NOTE_TYPE_INTERNAL,
        self::NOTE_TYPE_DECISION,
        self::NOTE_TYPE_RISK_BLOCKER,
        self::NOTE_TYPE_ACTION_POINT,
        self::NOTE_TYPE_TECHNICAL,
        self::NOTE_TYPE_AUDIT,
    ];

    public const NOTE_TYPE_LABELS = [
        self::NOTE_TYPE_GENERAL => 'General note',
        self::NOTE_TYPE_CUSTOMER => 'Customer note',
        self::NOTE_TYPE_INTERNAL => 'Internal note',
        self::NOTE_TYPE_DECISION => 'Decision',
        self::NOTE_TYPE_RISK_BLOCKER => 'Risk / blocker',
        self::NOTE_TYPE_ACTION_POINT => 'Action point',
        self::NOTE_TYPE_TECHNICAL => 'Technical note',
        self::NOTE_TYPE_AUDIT => 'Audit note',
    ];

    public $id;

    protected ?int $projectId = null;
    protected ?string $userId = null;
    protected ?string $title = null;
    protected ?string $content = null;
    protected ?string $visibility = null; // 'public' or 'private'
    protected ?string $noteType = null;
    protected ?DateTime $createdAt = null;
    protected ?DateTime $updatedAt = null;

    public function __construct() {
        $this->addType('projectId', Types::INTEGER);
        $this->addType('userId', Types::STRING);
        $this->addType('title', Types::STRING);
        $this->addType('content', Types::TEXT);
        $this->addType('visibility', Types::STRING);
        $this->addType('noteType', Types::STRING);
        $this->addType('createdAt', Types::DATETIME);
        $this->addType('updatedAt', Types::DATETIME);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'projectId' => $this->projectId,
            'userId' => $this->userId,
            'title' => $this->title,
            'content' => $this->content,
            'visibility' => $this->visibility,
            'noteType' => self::normalizeNoteType($this->noteType),
            'createdAt' => $this->createdAt ? $this->createdAt->format('Y-m-d H:i:s') : null,
            'updatedAt' => $this->updatedAt ? $this->updatedAt->format('Y-m-d H:i:s') : null,
        ];
    }

    public static function isValidNoteType(mixed $noteType): bool {
        return is_string($noteType) && in_array($noteType, self::NOTE_TYPES, true);
    }

    public static function normalizeNoteType(mixed $noteType): string {
        return self::isValidNoteType($noteType) ? $noteType : self::NOTE_TYPE_GENERAL;
    }

    public static function noteTypeLabel(mixed $noteType): string {
        return self::NOTE_TYPE_LABELS[self::normalizeNoteType($noteType)];
    }
}
