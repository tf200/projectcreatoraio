<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCA\ProjectCreatorAIO\Service\Extraction\DocumentExtractionResult;
use OCA\ProjectCreatorAIO\Service\Extraction\DocumentFilePayload;
use OCP\AppFramework\OCS\OCSException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Throwable;
use OCP\IL10N;

class PythonDocumentExtractionClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 120;

    private IL10N $l;

    public function __construct(
        private readonly IClientService $clientService,
        private readonly IConfig $config,
        ?IL10N $l = null,
    ) {
        $this->l = $l ?? DefaultLanguageL10n::userL10n();
    }

    /**
     * @param array<int, array<string, mixed>> $fieldDefinitions
     */
    public function extract(DocumentFilePayload $payload, array $fieldDefinitions): DocumentExtractionResult
    {
        $baseUrl = trim($this->config->getAppValue(Application::APP_ID, 'python_ocr_base_url', 'http://ocr-nextcloud-api:8085'));
        if ($baseUrl === '') {
            throw new OCSException($this->l->t('Python OCR service URL is not configured.'), 500);
        }

        $client = $this->clientService->newClient();

        try {
            $response = $client->post(rtrim($baseUrl, '/') . '/api/extract', [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'nextcloud' => [
                    'allow_local_address' => true,
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $payload->getContent(),
                        'filename' => $payload->getFileName(),
                    ],
                    [
                        'name' => 'fields',
                        'contents' => json_encode($this->buildRequestedFields($fieldDefinitions), JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
            ]);
        } catch (Throwable $e) {
            throw new OCSException($this->l->t('Python OCR service request failed: %s', [$e->getMessage()]), 502);
        }

        $statusCode = $response->getStatusCode();
        $body = $response->getBody();
        $decoded = json_decode($body, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
            if ($message === '') {
                $message = $this->l->t('Python OCR service returned an error.');
            }

            throw new OCSException($message, 502);
        }

        $fields = is_array($decoded) ? ($decoded['fields'] ?? null) : null;
        if (!is_array($fields)) {
            throw new OCSException($this->l->t('Python OCR service returned an invalid fields payload.'), 502);
        }

        return new DocumentExtractionResult($this->normalizeExtractedFields($fieldDefinitions, $fields));
    }

    /**
     * @param array<int, array<string, mixed>> $fieldDefinitions
     * @return string[]
     */
    private function buildRequestedFields(array $fieldDefinitions): array
    {
        $requested = [];

        foreach ($fieldDefinitions as $fieldDefinition) {
            $name = trim((string) ($fieldDefinition['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $requested[] = $name;
        }

        if ($requested === []) {
            throw new OCSException($this->l->t('At least one extraction field is required.'), 400);
        }

        return array_values(array_unique($requested));
    }

    /**
     * @param array<int, array<string, mixed>> $fieldDefinitions
     * @param array<string, mixed> $remoteFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeExtractedFields(array $fieldDefinitions, array $remoteFields): array
    {
        $normalized = [];

        foreach ($fieldDefinitions as $fieldDefinition) {
            $name = trim((string) ($fieldDefinition['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $value = $remoteFields[$name] ?? null;
            if (!is_scalar($value) && $value !== null) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            }

            $normalized[$name] = [
                'name' => $name,
                'value' => $value,
                'confidence' => $value === null || $value === '' ? 'low' : 'remote',
            ];
        }

        return $normalized;
    }
}
