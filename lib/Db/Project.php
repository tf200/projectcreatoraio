<?php
namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use JsonSerializable;

class Project extends Entity implements JsonSerializable {
    public $id;

    // 1. Project Details
    protected string|null $name        = null;
    protected string|null $label       = null;
    protected string|null $number      = null;
    protected int|null    $type        = null;
    protected string|null $description = null;

    // 2. Client Info
    protected string|null $clientName    = null;
    protected string|null $clientRole    = null;
    protected string|null $clientPhone   = null;
    protected string|null $clientEmail   = null;
    protected string|null $clientAddress = null; // Client Specific Address

    // 3. Location Info
    protected string|null $locStreet   = null;
    protected string|null $locCity     = null;
    protected string|null $locZip      = null;
    protected string|null $externalRef = null;

    // System Fields
    protected string|null $ownerId     = null;
    protected string|null $boardId     = null;
    protected string|null $projectGroupGid = null;
    protected string|null $talkConversationToken = null;
    protected int|null    $folderId    = null;
    protected int|null    $groupFolderId = null;
    public    string|null $folderPath  = null;
    protected int|null    $status      = null;
    protected int|null    $organizationId = null;
    protected string|null    $whiteBoardId = null;
    protected DateTime|null $lastDeckMoveAt = null;
    protected DateTime|null $staleNotifiedAt = null;
    protected DateTime|null $archivedAt = null;
    protected int|null $requiredPreparationWeeks = null;
    protected int|null $cvObjectOwnership = null;
    protected int|null $cvTraceOwnership = null;
    protected int|null $cvBuildingType = null;
    protected int|null $cvAvpLocation = null;
    protected DateTime|null $createdAt = null;
    protected DateTime|null $updatedAt = null;

    public function __construct() {
        $this->addType('name',        Types::STRING);
        $this->addType('label',       Types::STRING);
        $this->addType('number',      Types::STRING);
        $this->addType('type',        Types::INTEGER);
        $this->addType('description', Types::STRING);
        
        // Client
        $this->addType('clientName',    Types::STRING);
        $this->addType('clientRole',    Types::STRING);
        $this->addType('clientPhone',   Types::STRING);
        $this->addType('clientEmail',   Types::STRING);
        $this->addType('clientAddress', Types::STRING);

        // Location
        $this->addType('locStreet',   Types::STRING);
        $this->addType('locCity',     Types::STRING);
        $this->addType('locZip',      Types::STRING);
        $this->addType('externalRef', Types::STRING);

        // System
        $this->addType('ownerId',     Types::STRING);
        $this->addType('boardId',     Types::STRING);
        $this->addType('projectGroupGid', Types::STRING);
        $this->addType('talkConversationToken', Types::STRING);
        $this->addType('folderId',    Types::INTEGER);
        $this->addType('groupFolderId', Types::INTEGER);
        $this->addType('folderPath',  Types::STRING);
        $this->addType('status',      Types::INTEGER);
        $this->addType('organizationId', Types::INTEGER);
        $this->addType('whiteBoardId', Types::STRING);
        $this->addType('lastDeckMoveAt', Types::DATETIME);
        $this->addType('staleNotifiedAt', Types::DATETIME);
        $this->addType('archivedAt', Types::DATETIME);
        $this->addType('requiredPreparationWeeks', Types::INTEGER);
        $this->addType('cvObjectOwnership', Types::SMALLINT);
        $this->addType('cvTraceOwnership', Types::SMALLINT);
        $this->addType('cvBuildingType', Types::SMALLINT);
        $this->addType('cvAvpLocation', Types::SMALLINT);
        $this->addType('createdAt',   Types::DATETIME);
        $this->addType('updatedAt',   Types::DATETIME);
    }

    public static function encodeClientRoles(array|string|null $roles): ?string {
        if ($roles === null) {
            return null;
        }

        if (is_string($roles)) {
            return $roles;
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($role): string => is_string($role) ? trim($role) : '',
            $roles,
        ))));

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    public static function decodeClientRoles(?string $roles): array {
        if ($roles === null || trim($roles) === '') {
            return [];
        }

        try {
            $decoded = json_decode($roles, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [$roles];
        }

        if (!is_array($decoded)) {
            return [$roles];
        }

        return array_values(array_filter($decoded, static fn ($role): bool => is_string($role) && trim($role) !== ''));
    }

    public function jsonSerialize(): array {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'label'       => $this->name,
            'number'      => $this->number,
            'type'        => $this->type,
            'description' => $this->description,
            
            // Client
            'client_name'    => $this->clientName,
            'client_role'    => self::decodeClientRoles($this->clientRole),
            'client_phone'   => $this->clientPhone,
            'client_email'   => $this->clientEmail,
            'client_address' => $this->clientAddress,

            // Location
            'loc_street'   => $this->locStreet,
            'loc_city'     => $this->locCity,
            'loc_zip'      => $this->locZip,
            'external_ref' => $this->externalRef,

            'ownerId'    => $this->ownerId,
            'boardId'    => $this->boardId,
            'project_group_gid' => $this->projectGroupGid,
            'talk_conversation_token' => $this->talkConversationToken,
            'folderId'   => $this->folderId,
            'groupFolderId' => $this->groupFolderId,
            'folderPath' => $this->folderPath,
            'status'     => $this->status,
            'organization_id' => $this->organizationId,
            'white_board_id' => $this->whiteBoardId,
            'archived_at' => $this->archivedAt,
            'required_preparation_weeks' => $this->requiredPreparationWeeks,
            'cv_object_ownership' => $this->cvObjectOwnership,
            'cv_trace_ownership' => $this->cvTraceOwnership,
            'cv_building_type' => $this->cvBuildingType,
            'cv_avp_location' => $this->cvAvpLocation,
            'createdAt'  => $this->createdAt,
            'updatedAt'  => $this->updatedAt
        ];
    }
}
