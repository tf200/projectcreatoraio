<?php
namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use JsonSerializable;

class PrivateFolderLink extends Entity implements JsonSerializable {
    protected ?int     $projectId = null;
    protected ?string  $userId = null;
    protected ?int     $folderId = null;
    protected ?string  $folderPath = null;

    public function __construct() {
        $this->addType('projectId',  Types::INTEGER);
        $this->addType('userId',     Types::STRING);
        $this->addType('folderId',   Types::INTEGER);
        $this->addType('folderPath', Types::STRING);
    }

    public function jsonSerialize(): array {
        return [
            'projectId'  => $this->projectId,
            'userId'     => $this->userId,
            'folderId'   => $this->folderId,
            'folderPath' => $this->folderPath
        ];
    }
}