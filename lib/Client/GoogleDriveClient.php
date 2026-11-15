<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Client;

use OCA\GDriveVolumes\Service\ConfigService;
use OCA\GDriveVolumes\Service\MetadataCacheService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lightweight Google Drive API v3 client built on Nextcloud's IClientService.
 * Supports OAuth 2.0 (with automatic refresh), Service Account JWT (RS256),
 * Shared Drives, recursive path resolution/creation, resumable uploads, and
 * native Google Docs export.
 */
class GoogleDriveClient {
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_API_BASE = 'https://www.googleapis.com/upload/drive/v3';
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/drive';

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const SHORTCUT_MIME = 'application/vnd.google-apps.shortcut';

    private const RESUMABLE_THRESHOLD_BYTES = 4 * 1024 * 1024; // 4 MiB
    private const CHUNK_SIZE_BYTES = 5 * 1024 * 1024; // 5 MiB (multiple of 256 KiB)

    private const ITEM_FIELDS = 'id,name,mimeType,size,modifiedTime,createdTime,md5Checksum,version,parents,trashed,capabilities(canEdit,canDelete,canRename),shortcutDetails(targetId,targetMimeType)';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly ConfigService $configService,
        private readonly MetadataCacheService $cacheService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build the Google OAuth 2.0 consent URL.
     */
    public function buildAuthorizationUrl(string $clientId, string $redirectUri, string $state): string {
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::OAUTH_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];
        return self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange an OAuth 2.0 authorization code for access and refresh tokens.
     *
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(string $scope, ?string $uid, string $code, string $redirectUri): array {
        $account = $this->configService->getAccount($scope, $uid);
        if ($account['client_id'] === '' || $account['client_secret'] === '') {
            throw new RuntimeException('Client ID and Client Secret must be configured before connecting.');
        }

        $tokenData = $this->postFormUrlEncoded(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $account['client_id'],
            'client_secret' => $account['client_secret'],
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ], 'OAuth token exchange');
        if (empty($tokenData['access_token'])) {
            throw new RuntimeException('Google OAuth response did not include an access_token.');
        }

        $expiresIn = (int)($tokenData['expires_in'] ?? 3600);
        $updated = $this->configService->saveAccount($scope, $uid, [
            'auth_type' => ConfigService::AUTH_OAUTH,
            'access_token' => (string)$tokenData['access_token'],
            'refresh_token' => !empty($tokenData['refresh_token'])
                ? (string)$tokenData['refresh_token']
                : (string)$account['refresh_token'],
            'token_expires_at' => time() + $expiresIn,
        ]);

        // Fetch connected user email/name
        $about = $this->getAboutInfo($scope, $uid);
        if (!empty($about['user'])) {
            $updated = $this->configService->saveAccount($scope, $uid, [
                'connected_email' => (string)($about['user']['emailAddress'] ?? ''),
                'connected_name' => (string)($about['user']['displayName'] ?? ''),
            ]);
        }

        return $updated;
    }

    /**
     * Obtain a valid bearer access token for the given scope/user, refreshing or minting JWT if expired.
     */
    public function getAccessToken(string $scope, ?string $uid): string {
        $account = $this->configService->getAccount($scope, $uid);
        $now = time();

        if (
            $account['access_token'] !== ''
            && (int)$account['token_expires_at'] > ($now + 90)
        ) {
            return (string)$account['access_token'];
        }

        if ($account['auth_type'] === ConfigService::AUTH_SERVICE_ACCOUNT) {
            return $this->refreshServiceAccountToken($scope, $uid, $account);
        }

        return $this->refreshOAuthToken($scope, $uid, $account);
    }

    /**
     * Get Google Drive account user & storage quota information.
     *
     * @return array<string, mixed>
     */
    public function getAboutInfo(string $scope, ?string $uid, bool $useCache = false): array {
        $cacheNs = MetadataCacheService::scopeNamespace($scope, $uid);
        if ($useCache) {
            $cached = $this->cacheService->get($cacheNs, 'about_info');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/about', [
            'query' => [
                'fields' => 'user(displayName,emailAddress),storageQuota(limit,usage,usageInDrive,usageInDriveTrash)',
            ],
        ]);

        $ttl = $this->configService->getCacheTtl($scope, $uid);
        $this->cacheService->set($cacheNs, 'about_info', $data, max(60, $ttl));
        return $data;
    }

    /**
     * Resolve a folder path (e.g. "NextCloud/Volume_1") from root, optionally auto-creating missing segments.
     *
     * @return array<string, mixed> Folder metadata including 'id' and 'name'
     */
    public function resolveOrCreateFolderPath(
        string $scope,
        ?string $uid,
        string $remotePath,
        bool $autoCreate = false
    ): array {
        $normalized = trim(str_replace('\\', '/', $remotePath), '/');
        if ($normalized === '' || $normalized === 'root') {
            return $this->getItemMetadata($scope, $uid, 'root');
        }

        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
        $currentId = 'root';
        $currentItem = null;

        foreach ($segments as $segment) {
            $found = $this->findChildFolderByName($scope, $uid, $currentId, $segment);
            if ($found === null) {
                if (!$autoCreate) {
                    throw new RuntimeException(sprintf(
                        'Google Drive folder segment "%s" in path "%s" does not exist.',
                        $segment,
                        $normalized
                    ));
                }
                $found = $this->createFolder($scope, $uid, $currentId, $segment);
            }
            $currentId = (string)$found['id'];
            $currentItem = $found;
        }

        return $currentItem ?? $this->getItemMetadata($scope, $uid, 'root');
    }

    /**
     * Resolve a volume's root folder ID, verifying explicit ID or resolving/creating remote_path.
     *
     * @param array<string, mixed> $volume
     */
    public function resolveVolumeRootFolder(array $volume): array {
        $scope = (string)$volume['scope'];
        $uid = $volume['owner_uid'] !== '' ? (string)$volume['owner_uid'] : null;
        $folderId = trim((string)($volume['remote_folder_id'] ?? ''));

        if ($folderId !== '') {
            try {
                $item = $this->getItemMetadata($scope, $uid, $folderId);
                if (empty($item['trashed']) && ($item['mimeType'] ?? '') === self::FOLDER_MIME) {
                    return $item;
                }
            } catch (\Throwable $e) {
                if (empty($volume['remote_path'])) {
                    throw new RuntimeException('Configured Google Drive Folder ID is invalid or inaccessible: ' . $e->getMessage(), 0, $e);
                }
            }
        }

        $remotePath = (string)($volume['remote_path'] ?? '');
        $item = $this->resolveOrCreateFolderPath(
            $scope,
            $uid,
            $remotePath,
            (bool)($volume['auto_create_remote'] ?? false)
        );

        if (!empty($item['id']) && $item['id'] !== $folderId && !empty($volume['id'])) {
            $this->configService->updateResolvedRemoteFolderId($scope, $uid, (string)$volume['id'], (string)$item['id']);
        }

        return $item;
    }

    /**
     * List subfolders under a folder ID (for the UI Google Drive folder picker).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSubfolders(string $scope, ?string $uid, string $parentId = 'root'): array {
        $escapedParent = $this->escapeQueryValue($parentId);
        $query = sprintf(
            "'%s' in parents and mimeType = '%s' and trashed = false",
            $escapedParent,
            self::FOLDER_MIME
        );

        $folders = [];
        $pageToken = null;
        do {
            $params = [
                'q' => $query,
                'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,parents)',
                'orderBy' => 'name',
                'pageSize' => 200,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }
            $res = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/files', [
                'query' => $params,
            ]);
            foreach (($res['files'] ?? []) as $file) {
                if (is_array($file)) {
                    $folders[] = $file;
                }
            }
            $pageToken = $res['nextPageToken'] ?? null;
        } while (!empty($pageToken) && count($folders) < 1000);

        return $folders;
    }

    /**
     * List shared drives available to the account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSharedDrives(string $scope, ?string $uid): array {
        try {
            $res = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/drives', [
                'query' => [
                    'pageSize' => 100,
                    'fields' => 'drives(id,name)',
                ],
            ]);
            return is_array($res['drives'] ?? null) ? $res['drives'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * List all non-trashed children inside a Google Drive folder.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listChildren(string $scope, ?string $uid, string $folderId): array {
        $escapedParent = $this->escapeQueryValue($folderId);
        $query = sprintf("'%s' in parents and trashed = false", $escapedParent);

        $items = [];
        $pageToken = null;

        do {
            $params = [
                'q' => $query,
                'fields' => 'nextPageToken,files(' . self::ITEM_FIELDS . ')',
                'orderBy' => 'folder,name',
                'pageSize' => 500,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $res = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/files', [
                'query' => $params,
            ]);

            foreach (($res['files'] ?? []) as $file) {
                if (is_array($file)) {
                    $items[] = $this->normalizeItem($file);
                }
            }
            $pageToken = $res['nextPageToken'] ?? null;
        } while (!empty($pageToken));

        return $items;
    }

    /**
     * Fetch a single child item by exact name inside a parent folder.
     *
     * @return array<string, mixed>|null
     */
    public function getChildByName(string $scope, ?string $uid, string $parentId, string $name): ?array {
        $query = sprintf(
            "'%s' in parents and name = '%s' and trashed = false",
            $this->escapeQueryValue($parentId),
            $this->escapeQueryValue($name)
        );

        $res = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/files', [
            'query' => [
                'q' => $query,
                'fields' => 'files(' . self::ITEM_FIELDS . ')',
                'orderBy' => 'folder,modifiedTime desc',
                'pageSize' => 10,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ],
        ]);

        $files = $res['files'] ?? [];
        if (!is_array($files) || $files === []) {
            return null;
        }

        return $this->normalizeItem($files[0]);
    }

    /**
     * Find a child folder by exact name inside a parent folder.
     *
     * @return array<string, mixed>|null
     */
    public function findChildFolderByName(string $scope, ?string $uid, string $parentId, string $name): ?array {
        $query = sprintf(
            "'%s' in parents and name = '%s' and mimeType = '%s' and trashed = false",
            $this->escapeQueryValue($parentId),
            $this->escapeQueryValue($name),
            self::FOLDER_MIME
        );

        $res = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/files', [
            'query' => [
                'q' => $query,
                'fields' => 'files(' . self::ITEM_FIELDS . ')',
                'orderBy' => 'modifiedTime desc',
                'pageSize' => 5,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ],
        ]);

        $files = $res['files'] ?? [];
        if (!is_array($files) || $files === []) {
            return null;
        }

        return $this->normalizeItem($files[0]);
    }

    /**
     * Retrieve item metadata by Google Drive file/folder ID.
     *
     * @return array<string, mixed>
     */
    public function getItemMetadata(string $scope, ?string $uid, string $itemId): array {
        $data = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/files/' . rawurlencode($itemId), [
            'query' => [
                'fields' => self::ITEM_FIELDS,
                'supportsAllDrives' => 'true',
            ],
        ]);
        return $this->normalizeItem($data);
    }

    /**
     * Create a subfolder inside $parentId.
     *
     * @return array<string, mixed>
     */
    public function createFolder(string $scope, ?string $uid, string $parentId, string $name): array {
        $data = $this->apiJsonRequest($scope, $uid, 'POST', self::DRIVE_API_BASE . '/files', [
            'query' => [
                'fields' => self::ITEM_FIELDS,
                'supportsAllDrives' => 'true',
            ],
            'json' => [
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
            ],
        ]);
        return $this->normalizeItem($data);
    }

    /**
     * Rename and/or move an item to another parent folder.
     *
     * @return array<string, mixed>
     */
    public function moveAndRename(
        string $scope,
        ?string $uid,
        string $itemId,
        string $oldParentId,
        string $newParentId,
        string $newName
    ): array {
        $query = [
            'fields' => self::ITEM_FIELDS,
            'supportsAllDrives' => 'true',
        ];
        if ($oldParentId !== $newParentId) {
            $query['addParents'] = $newParentId;
            $query['removeParents'] = $oldParentId;
        }

        $data = $this->apiJsonRequest($scope, $uid, 'PATCH', self::DRIVE_API_BASE . '/files/' . rawurlencode($itemId), [
            'query' => $query,
            'json' => [
                'name' => $newName,
            ],
        ]);
        return $this->normalizeItem($data);
    }

    /**
     * Update file metadata fields (such as modifiedTime).
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function updateMetadata(string $scope, ?string $uid, string $itemId, array $metadata): array {
        $data = $this->apiJsonRequest($scope, $uid, 'PATCH', self::DRIVE_API_BASE . '/files/' . rawurlencode($itemId), [
            'query' => [
                'fields' => self::ITEM_FIELDS,
                'supportsAllDrives' => 'true',
            ],
            'json' => $metadata,
        ]);
        return $this->normalizeItem($data);
    }

    /**
     * Copy a file server-side in Google Drive.
     *
     * @return array<string, mixed>
     */
    public function copyFile(string $scope, ?string $uid, string $sourceId, string $targetParentId, string $targetName): array {
        $data = $this->apiJsonRequest($scope, $uid, 'POST', self::DRIVE_API_BASE . '/files/' . rawurlencode($sourceId) . '/copy', [
            'query' => [
                'fields' => self::ITEM_FIELDS,
                'supportsAllDrives' => 'true',
            ],
            'json' => [
                'name' => $targetName,
                'parents' => [$targetParentId],
            ],
        ]);
        return $this->normalizeItem($data);
    }

    /**
     * Trash or permanently delete a file/folder.
     */
    public function deleteItem(string $scope, ?string $uid, string $itemId, bool $moveToTrash = true): void {
        if ($moveToTrash) {
            $this->updateMetadata($scope, $uid, $itemId, ['trashed' => true]);
            return;
        }

        $this->apiRequest($scope, $uid, 'DELETE', self::DRIVE_API_BASE . '/files/' . rawurlencode($itemId), [
            'query' => [
                'supportsAllDrives' => 'true',
            ],
        ]);
    }

    /**
     * Download a binary file (or export a Google Workspace document) directly into a seekable stream.
     *
     * @param resource $targetStream
     */
    public function downloadToStream(
        string $scope,
        ?string $uid,
        string $itemId,
        $targetStream,
        ?string $exportMimeType = null
    ): int {
        $url = $exportMimeType !== null
            ? self::DRIVE_API_BASE . '/files/' . rawurlencode($itemId) . '/export'
            : self::DRIVE_API_BASE . '/files/' . rawurlencode($itemId);

        $query = $exportMimeType !== null
            ? ['mimeType' => $exportMimeType]
            : ['alt' => 'media', 'supportsAllDrives' => 'true'];

        $response = $this->apiRequest($scope, $uid, 'GET', $url, [
            'query' => $query,
            'save_to' => $targetStream,
            'timeout' => 300,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Google Drive download failed with HTTP ' . $response->getStatusCode());
        }

        $stat = fstat($targetStream);
        $writtenBytes = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
        rewind($targetStream);
        return $writtenBytes;
    }

    /**
     * Upload content from a seekable PHP stream, creating a new file or updating an existing file.
     * Uses multipart upload for small files and chunked resumable upload for large files.
     *
     * @param resource $sourceStream
     * @return array<string, mixed>
     */
    public function uploadFromStream(
        string $scope,
        ?string $uid,
        $sourceStream,
        string $parentId,
        string $name,
        string $mimeType = 'application/octet-stream',
        ?string $existingFileId = null
    ): array {
        $stat = fstat($sourceStream);
        $totalSize = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
        rewind($sourceStream);

        if ($totalSize <= self::RESUMABLE_THRESHOLD_BYTES) {
            return $this->uploadMultipart($scope, $uid, $sourceStream, $totalSize, $parentId, $name, $mimeType, $existingFileId);
        }

        return $this->uploadResumable($scope, $uid, $sourceStream, $totalSize, $parentId, $name, $mimeType, $existingFileId);
    }

    /**
     * Calculate total bytes used inside a folder subtree (with safety pagination cap).
     */
    public function calculateFolderUsageBytes(string $scope, ?string $uid, string $rootFolderId, int $maxFolders = 500): int {
        $totalBytes = 0;
        $queue = [$rootFolderId];
        $visited = 0;

        while ($queue !== [] && $visited < $maxFolders) {
            $currentId = array_shift($queue);
            $visited++;

            $query = sprintf("'%s' in parents and trashed = false", $this->escapeQueryValue($currentId));
            $pageToken = null;

            do {
                $params = [
                    'q' => $query,
                    'fields' => 'nextPageToken,files(id,mimeType,size)',
                    'pageSize' => 1000,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ];
                if ($pageToken !== null) {
                    $params['pageToken'] = $pageToken;
                }

                $res = $this->apiJsonRequest($scope, $uid, 'GET', self::DRIVE_API_BASE . '/files', [
                    'query' => $params,
                ]);

                foreach (($res['files'] ?? []) as $file) {
                    if (!is_array($file)) {
                        continue;
                    }
                    if (($file['mimeType'] ?? '') === self::FOLDER_MIME) {
                        $queue[] = (string)$file['id'];
                    } elseif (isset($file['size'])) {
                        $totalBytes += (int)$file['size'];
                    }
                }
                $pageToken = $res['nextPageToken'] ?? null;
            } while (!empty($pageToken));
        }

        return $totalBytes;
    }

    /**
     * Map native Google Workspace mimeTypes to export mimeType + virtual file extension.
     *
     * @return array{mime: string, ext: string}|null
     */
    public static function getGoogleExportTarget(string $googleMimeType, string $exportMode): ?array {
        if ($exportMode === ConfigService::EXPORT_NONE) {
            return null;
        }

        if ($exportMode === ConfigService::EXPORT_PDF) {
            return match ($googleMimeType) {
                'application/vnd.google-apps.document',
                'application/vnd.google-apps.spreadsheet',
                'application/vnd.google-apps.presentation',
                'application/vnd.google-apps.drawing' => ['mime' => 'application/pdf', 'ext' => 'pdf'],
                default => null,
            };
        }

        return match ($googleMimeType) {
            'application/vnd.google-apps.document' => [
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'ext' => 'docx',
            ],
            'application/vnd.google-apps.spreadsheet' => [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ext' => 'xlsx',
            ],
            'application/vnd.google-apps.presentation' => [
                'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'ext' => 'pptx',
            ],
            'application/vnd.google-apps.drawing' => [
                'mime' => 'image/svg+xml',
                'ext' => 'svg',
            ],
            default => null,
        };
    }

    public static function isGoogleWorkspaceFile(string $mimeType): bool {
        return str_starts_with($mimeType, 'application/vnd.google-apps.')
            && $mimeType !== self::FOLDER_MIME
            && $mimeType !== self::SHORTCUT_MIME;
    }

    /**
     * @param resource $sourceStream
     * @return array<string, mixed>
     */
    private function uploadMultipart(
        string $scope,
        ?string $uid,
        $sourceStream,
        int $totalSize,
        string $parentId,
        string $name,
        string $mimeType,
        ?string $existingFileId
    ): array {
        $content = $totalSize > 0 ? (string)stream_get_contents($sourceStream) : '';
        $boundary = 'ncgdrive_' . bin2hex(random_bytes(12));

        $metadata = $existingFileId !== null
            ? ['name' => $name, 'mimeType' => $mimeType]
            : ['name' => $name, 'parents' => [$parentId], 'mimeType' => $mimeType];

        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata, JSON_THROW_ON_ERROR) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mimeType}\r\n\r\n"
            . $content . "\r\n"
            . "--{$boundary}--";

        $method = $existingFileId !== null ? 'PATCH' : 'POST';
        $url = $existingFileId !== null
            ? self::UPLOAD_API_BASE . '/files/' . rawurlencode($existingFileId)
            : self::UPLOAD_API_BASE . '/files';

        $data = $this->apiJsonRequest($scope, $uid, $method, $url, [
            'query' => [
                'uploadType' => 'multipart',
                'fields' => self::ITEM_FIELDS,
                'supportsAllDrives' => 'true',
            ],
            'headers' => [
                'Content-Type' => 'multipart/related; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 120,
        ]);

        return $this->normalizeItem($data);
    }

    /**
     * @param resource $sourceStream
     * @return array<string, mixed>
     */
    private function uploadResumable(
        string $scope,
        ?string $uid,
        $sourceStream,
        int $totalSize,
        string $parentId,
        string $name,
        string $mimeType,
        ?string $existingFileId
    ): array {
        $method = $existingFileId !== null ? 'PATCH' : 'POST';
        $url = $existingFileId !== null
            ? self::UPLOAD_API_BASE . '/files/' . rawurlencode($existingFileId)
            : self::UPLOAD_API_BASE . '/files';

        $metadata = $existingFileId !== null
            ? ['name' => $name, 'mimeType' => $mimeType]
            : ['name' => $name, 'parents' => [$parentId], 'mimeType' => $mimeType];

        $initResp = $this->apiRequest($scope, $uid, $method, $url, [
            'query' => [
                'uploadType' => 'resumable',
                'fields' => self::ITEM_FIELDS,
                'supportsAllDrives' => 'true',
            ],
            'headers' => [
                'X-Upload-Content-Type' => $mimeType,
                'X-Upload-Content-Length' => (string)$totalSize,
            ],
            'json' => $metadata,
            'timeout' => 60,
        ]);

        $sessionUri = $initResp->getHeader('Location');
        if ($sessionUri === '') {
            throw new RuntimeException('Google Drive resumable upload did not return a Location header.');
        }

        $client = $this->clientService->newClient();
        $offset = 0;

        while ($offset < $totalSize) {
            $chunkLen = min(self::CHUNK_SIZE_BYTES, $totalSize - $offset);
            fseek($sourceStream, $offset);
            $chunk = fread($sourceStream, $chunkLen);
            if ($chunk === false) {
                throw new RuntimeException('Failed reading upload stream chunk.');
            }
            $end = $offset + strlen($chunk) - 1;

            $resp = $client->put($sessionUri, [
                'headers' => [
                    'Content-Type' => $mimeType,
                    'Content-Length' => (string)strlen($chunk),
                    'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $end, $totalSize),
                ],
                'body' => $chunk,
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => 180,
            ]);

            $status = $resp->getStatusCode();
            if ($status === 200 || $status === 201) {
                return $this->normalizeItem($this->decodeJsonResponse($resp->getBody(), 'Resumable upload finalization'));
            }
            if ($status === 308) {
                $rangeHeader = $resp->getHeader('Range');
                if (preg_match('/bytes=0-(\d+)/', $rangeHeader, $matches)) {
                    $offset = ((int)$matches[1]) + 1;
                } else {
                    $offset += strlen($chunk);
                }
                continue;
            }

            throw new RuntimeException(sprintf(
                'Resumable upload chunk failed with HTTP %d: %s',
                $status,
                $resp->getBody()
            ));
        }

        throw new RuntimeException('Resumable upload completed stream without finalizing metadata.');
    }

    /**
     * @param array<string, mixed> $account
     */
    private function refreshOAuthToken(string $scope, ?string $uid, array $account): string {
        if ($account['refresh_token'] === '' || $account['client_id'] === '' || $account['client_secret'] === '') {
            throw new RuntimeException('Google Drive OAuth account is not connected or missing refresh token.');
        }

        $tokenData = $this->postFormUrlEncoded(self::TOKEN_URL, [
            'client_id' => $account['client_id'],
            'client_secret' => $account['client_secret'],
            'refresh_token' => $account['refresh_token'],
            'grant_type' => 'refresh_token',
        ], 'OAuth token refresh');
        if (empty($tokenData['access_token'])) {
            throw new RuntimeException('Failed to refresh Google Drive OAuth access token.');
        }

        $expiresIn = (int)($tokenData['expires_in'] ?? 3600);
        $this->configService->saveAccount($scope, $uid, [
            'access_token' => (string)$tokenData['access_token'],
            'token_expires_at' => time() + $expiresIn,
        ]);

        return (string)$tokenData['access_token'];
    }

    /**
     * @param array<string, mixed> $account
     */
    private function refreshServiceAccountToken(string $scope, ?string $uid, array $account): string {
        $sa = json_decode((string)$account['service_account_json'], true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('Google Service Account JSON is not configured.');
        }

        $tokenUri = (string)($sa['token_uri'] ?? self::TOKEN_URL);
        $now = time();
        $header = $this->base64UrlEncode((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));

        $claimsPayload = [
            'iss' => (string)$sa['client_email'],
            'scope' => self::OAUTH_SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        if (!empty($account['impersonate_email'])) {
            $claimsPayload['sub'] = (string)$account['impersonate_email'];
        }

        $claims = $this->base64UrlEncode((string)json_encode($claimsPayload, JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $claims;

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, (string)$sa['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('Failed to sign Google Service Account JWT with OpenSSL.');
        }

        $assertion = $signingInput . '.' . $this->base64UrlEncode($signature);

        $tokenData = $this->postFormUrlEncoded($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ], 'Service account token request');
        if (empty($tokenData['access_token'])) {
            throw new RuntimeException('Google Service Account token response did not include an access_token.');
        }

        $expiresIn = (int)($tokenData['expires_in'] ?? 3600);
        $this->configService->saveAccount($scope, $uid, [
            'access_token' => (string)$tokenData['access_token'],
            'token_expires_at' => time() + $expiresIn,
            'connected_email' => (string)($account['impersonate_email'] ?: $sa['client_email']),
            'connected_name' => 'Service Account',
        ]);

        return (string)$tokenData['access_token'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function apiJsonRequest(string $scope, ?string $uid, string $method, string $url, array $options = []): array {
        $resp = $this->apiRequest($scope, $uid, $method, $url, $options);
        if ($resp->getBody() === '') {
            return [];
        }
        return $this->decodeJsonResponse($resp->getBody(), $method . ' ' . $url);
    }

    /**
     * Execute an authenticated request against Google APIs, retrying once on HTTP 401.
     *
     * @param array<string, mixed> $options
     */
    private function apiRequest(string $scope, ?string $uid, string $method, string $url, array $options = []): \OCP\Http\Client\IResponse {
        $client = $this->clientService->newClient();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt === 1) {
                // Force token refresh on retry
                $this->configService->saveAccount($scope, $uid, ['token_expires_at' => 0]);
            }

            $token = $this->getAccessToken($scope, $uid);
            $reqOptions = $options;
            $reqOptions['headers'] = array_merge($options['headers'] ?? [], [
                'Authorization' => 'Bearer ' . $token,
            ]);
            $reqOptions['timeout'] = $options['timeout'] ?? 45;
            $reqOptions['http_errors'] = false;

            $resp = match (strtoupper($method)) {
                'GET' => $client->get($url, $reqOptions),
                'POST' => $client->post($url, $reqOptions),
                'PUT' => $client->put($url, $reqOptions),
                'PATCH' => $client->patch($url, $reqOptions),
                'DELETE' => $client->delete($url, $reqOptions),
                default => throw new RuntimeException('Unsupported HTTP method: ' . $method),
            };

            $status = $resp->getStatusCode();
            if ($status === 401 && $attempt === 0) {
                if (isset($options['save_to']) && is_resource($options['save_to'])) {
                    ftruncate($options['save_to'], 0);
                    rewind($options['save_to']);
                }
                continue;
            }

            if ($status >= 400) {
                $body = $resp->getBody();
                $this->logger->error('Google Drive API error {status} on {method} {url}: {body}', [
                    'status' => $status,
                    'method' => $method,
                    'url' => $url,
                    'body' => $body,
                ]);
                $decoded = json_decode($body, true);
                $message = is_array($decoded) && !empty($decoded['error']['message'])
                    ? (string)$decoded['error']['message']
                    : ('HTTP ' . $status);
                throw new RuntimeException('Google Drive API error: ' . $message);
            }

            return $resp;
        }

        throw new RuntimeException('Google Drive API authentication failed.');
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeItem(array $raw): array {
        $mimeType = (string)($raw['mimeType'] ?? 'application/octet-stream');
        $isDir = $mimeType === self::FOLDER_MIME;

        $mtime = isset($raw['modifiedTime']) ? (strtotime((string)$raw['modifiedTime']) ?: time()) : time();
        $ctime = isset($raw['createdTime']) ? (strtotime((string)$raw['createdTime']) ?: $mtime) : $mtime;

        return [
            'id' => (string)($raw['id'] ?? ''),
            'name' => (string)($raw['name'] ?? ''),
            'mimeType' => $mimeType,
            'isDir' => $isDir,
            'size' => isset($raw['size']) ? (int)$raw['size'] : 0,
            'hasExplicitSize' => isset($raw['size']),
            'mtime' => $mtime,
            'ctime' => $ctime,
            'md5Checksum' => (string)($raw['md5Checksum'] ?? ''),
            'version' => (string)($raw['version'] ?? ''),
            'parents' => is_array($raw['parents'] ?? null) ? $raw['parents'] : [],
            'trashed' => (bool)($raw['trashed'] ?? false),
        ];
    }

    /**
     * Post a form-urlencoded request and decode the JSON response.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function postFormUrlEncoded(string $url, array $params, string $context): array {
        $client = $this->clientService->newClient();
        $response = $client->post($url, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'http_errors' => false,
            'timeout' => 30,
        ]);

        return $this->decodeJsonResponse($response->getBody(), $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(string $body, string $context): array {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid JSON response during %s.', $context));
        }
        if (isset($decoded['error'])) {
            $errMsg = is_array($decoded['error'])
                ? (string)($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string)($decoded['error_description'] ?? $decoded['error']);
            throw new RuntimeException(sprintf('%s failed: %s', $context, $errMsg));
        }
        return $decoded;
    }

    private function escapeQueryValue(string $value): string {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
