<?php
namespace OCA\ProjectCreatorAIO\Service;

use OCP\Files\Folder;
use OCP\Files\Node;

class FileTreeService {
    /**
     * Takes a starting Node and recursively builds an array representation of it,
     * including its children if it's a folder.
     */
    public function buildTree(Node $node): array {
        $info = [
            'id'       => $node->getId(),
            'name'     => $node->getName(),
            'type'     => ($node instanceof Folder) ? 'folder' : 'file',
            'mimetype' => $node->getMimeType(),
            'size'     => $node->getSize(),
            'mtime'    => $node->getMTime(),
            'path'     => $node->getPath(),
        ];
        
        if ($node instanceof Folder) {
            $children = $node->getDirectoryListing();
            $info['children'] = array_map([$this, 'buildTree'], $children);
        }

        return $info;
    }
}