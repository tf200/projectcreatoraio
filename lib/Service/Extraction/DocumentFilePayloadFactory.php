<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service\Extraction;

use OCP\AppFramework\OCS\OCSException;
use OCA\ProjectCreatorAIO\Service\DefaultLanguageL10n;
use OCP\Files\File;
use OCP\IL10N;

class DocumentFilePayloadFactory
{
    private IL10N $l;

    public function __construct(?IL10N $l = null)
    {
        $this->l = $l ?? DefaultLanguageL10n::userL10n();
    }

    public function fromFile(File $file): DocumentFilePayload
    {
        $content = $file->getContent();
        if (!is_string($content)) {
            throw new OCSException($this->l->t('Unable to read the file for OCR processing.'), 500);
        }

        return new DocumentFilePayload(
            (string) $file->getName(),
            strtolower(trim((string) $file->getMimeType())),
            $content,
        );
    }
}
