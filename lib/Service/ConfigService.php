<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Service;

use InvalidArgumentException;
use OCA\GDriveVolumes\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Manages global (admin) and personal (per-user) configuration, encrypted credentials,
 * and mounted volume definitions.
 */
class ConfigService {
    public const SCOPE_ADMIN = 'admin';
    public const SCOPE_PERSONAL = 'personal';

    public const AUTH_OAUTH = 'oauth';
    public const AUTH_SERVICE_ACCOUNT = 'service_account';

    public const EXPORT_OFFICE = 'office';
    public const EXPORT_PDF = 'pdf';
    public const EXPORT_NONE = 'none';

    private const KEY_ALLOW_PERSONAL = 'allow_personal_mounts';
    private const KEY_COMMON_LOCAL_DIR = 'default_common_local_dir';
    private const KEY_REMOTE_PREFIX = 'default_remote_prefix';
    private const KEY_CACHE_TTL = 'cache_ttl';
    private const KEY_ACCOUNT_ADMIN = 'admin_account';
    private const KEY_VOLUMES_ADMIN = 'admin_volumes';
    private const KEY_ACCOUNT_PERSONAL = 'personal_account';
    private const KEY_VOLUMES_PERSONAL = 'personal_volumes';
    private const KEY_HAS_PERSONAL_VOLUMES = 'has_personal_volumes';
    private const KEY_OAUTH_STATES = 'oauth_states';

    public function __construct(
        private readonly IConfig $config,
        private readonly ICrypto $crypto,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate and return scope string.
     */
    public function validateScope(string $scope): string {
        if ($scope !== self::SCOPE_ADMIN && $scope !== self::SCOPE_PERSONAL) {
            throw new InvalidArgumentException('Invalid configuration scope.');
        }
        return $scope;
    }

    public function isPersonalMountsAllowed(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_ALLOW_PERSONAL, '1') === '1';
    }

    public function setPersonalMountsAllowed(bool $allowed): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_ALLOW_PERSONAL, $allowed ? '1' : '0');
    }

    public function getDefaultCommonLocalDir(string $scope = self::SCOPE_ADMIN, ?string $uid = null): string {
        if ($scope === self::SCOPE_PERSONAL && $uid !== null && $uid !== '') {
            $value = $this->config->getUserValue($uid, Application::APP_ID, self::KEY_COMMON_LOCAL_DIR, '');
            if ($value !== '') {
                return $value;
            }
        }
        return $this->config->getAppValue(Application::APP_ID, self::KEY_COMMON_LOCAL_DIR, 'Gdrive');
    }

    public function getDefaultRemotePrefix(string $scope = self::SCOPE_ADMIN, ?string $uid = null): string {
        if ($scope === self::SCOPE_PERSONAL && $uid !== null && $uid !== '') {
            $value = $this->config->getUserValue($uid, Application::APP_ID, self::KEY_REMOTE_PREFIX, '');
            if ($value !== '') {
                return $value;
            }
        }
        return $this->config->getAppValue(Application::APP_ID, self::KEY_REMOTE_PREFIX, 'NextCloud');
    }

    public function getCacheTtl(string $scope = self::SCOPE_ADMIN, ?string $uid = null): int {
        if ($scope === self::SCOPE_PERSONAL && $uid !== null && $uid !== '') {
            $raw = $this->config->getUserValue($uid, Application::APP_ID, self::KEY_CACHE_TTL, '');
            if ($raw !== '' && is_numeric($raw)) {
                return max(5, min(86400, (int)$raw));
            }
        }
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY_CACHE_TTL, '60');
        return max(5, min(86400, (int)$raw));
    }

    /**
     * Save general settings for admin or personal scope.
     *
     * @param array<string, mixed> $data
     */
    public function saveGeneralSettings(string $scope, ?string $uid, array $data): void {
        $scope = $this->validateScope($scope);
        $commonLocalDir = $this->normalizeRelativePath((string)($data['default_common_local_dir'] ?? 'Gdrive'), true);
        $remotePrefix = $this->normalizeRelativePath((string)($data['default_remote_prefix'] ?? 'NextCloud'), true);
        $cacheTtl = max(5, min(86400, (int)($data['cache_ttl'] ?? 60)));

        if ($scope === self::SCOPE_ADMIN) {
            $this->config->setAppValue(Application::APP_ID, self::KEY_COMMON_LOCAL_DIR, $commonLocalDir);
            $this->config->setAppValue(Application::APP_ID, self::KEY_REMOTE_PREFIX, $remotePrefix);
            $this->config->setAppValue(Application::APP_ID, self::KEY_CACHE_TTL, (string)$cacheTtl);
            if (array_key_exists('allow_personal_mounts', $data)) {
                $this->setPersonalMountsAllowed((bool)$data['allow_personal_mounts']);
            }
            return;
        }

        if ($uid === null || $uid === '') {
            throw new InvalidArgumentException('User ID is required for personal scope.');
        }

        $this->config->setUserValue($uid, Application::APP_ID, self::KEY_COMMON_LOCAL_DIR, $commonLocalDir);
        $this->config->setUserValue($uid, Application::APP_ID, self::KEY_REMOTE_PREFIX, $remotePrefix);
        $this->config->setUserValue($uid, Application::APP_ID, self::KEY_CACHE_TTL, (string)$cacheTtl);
    }

    /**
     * Get decrypted Google Drive account configuration for the given scope.
     *
     * @return array<string, mixed>
     */
    public function getAccount(string $scope, ?string $uid = null): array {
        $scope = $this->validateScope($scope);
        $raw = $scope === self::SCOPE_ADMIN
            ? $this->config->getAppValue(Application::APP_ID, self::KEY_ACCOUNT_ADMIN, '')
            : $this->config->getUserValue((string)$uid, Application::APP_ID, self::KEY_ACCOUNT_PERSONAL, '');

        $decoded = [];
        if ($raw !== '') {
            try {
                $decrypted = $this->crypto->decrypt($raw);
                $parsed = json_decode($decrypted, true);
                if (is_array($parsed)) {
                    $decoded = $parsed;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to decrypt Google Drive account config: ' . $e->getMessage(), [
                    'app' => Application::APP_ID,
                    'scope' => $scope,
                ]);
            }
        }

        return array_merge([
            'auth_type' => self::AUTH_OAUTH,
            'client_id' => '',
            'client_secret' => '',
            'access_token' => '',
            'refresh_token' => '',
            'token_expires_at' => 0,
            'connected_email' => '',
            'connected_name' => '',
            'service_account_json' => '',
            'service_account_email' => '',
            'impersonate_email' => '',
        ], $decoded);
    }

    /**
     * Persist encrypted Google Drive account configuration.
     *
     * @param array<string, mixed> $account
     */
    public function saveAccount(string $scope, ?string $uid, array $account): array {
        $scope = $this->validateScope($scope);
        $current = $this->getAccount($scope, $uid);

        $authType = ($account['auth_type'] ?? $current['auth_type']) === self::AUTH_SERVICE_ACCOUNT
            ? self::AUTH_SERVICE_ACCOUNT
            : self::AUTH_OAUTH;

        $merged = [
            'auth_type' => $authType,
            'client_id' => trim((string)($account['client_id'] ?? $current['client_id'])),
            'client_secret' => array_key_exists('client_secret', $account) && trim((string)$account['client_secret']) !== ''
                ? trim((string)$account['client_secret'])
                : (string)$current['client_secret'],
            'access_token' => (string)($account['access_token'] ?? $current['access_token']),
            'refresh_token' => (string)($account['refresh_token'] ?? $current['refresh_token']),
            'token_expires_at' => (int)($account['token_expires_at'] ?? $current['token_expires_at']),
            'connected_email' => (string)($account['connected_email'] ?? $current['connected_email']),
            'connected_name' => (string)($account['connected_name'] ?? $current['connected_name']),
            'service_account_json' => $current['service_account_json'],
            'service_account_email' => $current['service_account_email'],
            'impersonate_email' => trim((string)($account['impersonate_email'] ?? $current['impersonate_email'])),
        ];

        if (array_key_exists('service_account_json', $account) && trim((string)$account['service_account_json']) !== '') {
            $saRaw = trim((string)$account['service_account_json']);
            $saParsed = json_decode($saRaw, true);
            if (!is_array($saParsed) || empty($saParsed['client_email']) || empty($saParsed['private_key'])) {
                throw new InvalidArgumentException('Invalid Google Service Account JSON: client_email and private_key are required.');
            }
            $merged['service_account_json'] = $saRaw;
            $merged['service_account_email'] = (string)$saParsed['client_email'];
            // Reset cached token when credentials change
            $merged['access_token'] = '';
            $merged['token_expires_at'] = 0;
        }

        // If OAuth client ID changed manually, clear stale tokens
        if (
            $authType === self::AUTH_OAUTH
            && $current['client_id'] !== ''
            && $merged['client_id'] !== $current['client_id']
        ) {
            $merged['access_token'] = '';
            $merged['refresh_token'] = '';
            $merged['token_expires_at'] = 0;
            $merged['connected_email'] = '';
            $merged['connected_name'] = '';
        }

        $encrypted = $this->crypto->encrypt((string)json_encode($merged, JSON_THROW_ON_ERROR));

        if ($scope === self::SCOPE_ADMIN) {
            $this->config->setAppValue(Application::APP_ID, self::KEY_ACCOUNT_ADMIN, $encrypted);
        } else {
            if ($uid === null || $uid === '') {
                throw new InvalidArgumentException('User ID is required for personal scope.');
            }
            $this->config->setUserValue($uid, Application::APP_ID, self::KEY_ACCOUNT_PERSONAL, $encrypted);
        }

        return $merged;
    }

    /**
     * Clear tokens/credentials for a scope.
     */
    public function disconnectAccount(string $scope, ?string $uid = null): void {
        $scope = $this->validateScope($scope);
        $current = $this->getAccount($scope, $uid);
        $cleared = array_merge($current, [
            'access_token' => '',
            'refresh_token' => '',
            'token_expires_at' => 0,
            'connected_email' => '',
            'connected_name' => '',
            'service_account_json' => '',
            'service_account_email' => '',
        ]);

        $encrypted = $this->crypto->encrypt((string)json_encode($cleared, JSON_THROW_ON_ERROR));
        if ($scope === self::SCOPE_ADMIN) {
            $this->config->setAppValue(Application::APP_ID, self::KEY_ACCOUNT_ADMIN, $encrypted);
        } else {
            if ($uid === null || $uid === '') {
                throw new InvalidArgumentException('User ID is required for personal scope.');
            }
            $this->config->setUserValue($uid, Application::APP_ID, self::KEY_ACCOUNT_PERSONAL, $encrypted);
        }
    }

    /**
     * Persist a one-time OAuth state nonce in app config so callbacks work even without Redis/APCu.
     *
     * @param array<string, mixed> $payload
     */
    public function saveOAuthState(string $nonce, array $payload): void {
        if ($nonce === '') {
            return;
        }
        $cutoff = time() - 600;
        $states = $this->readPrunedOAuthStates($cutoff);
        if (!isset($payload['created'])) {
            $payload['created'] = time();
        }
        $states[$nonce] = $payload;
        $this->config->setAppValue(
            Application::APP_ID,
            self::KEY_OAUTH_STATES,
            (string)json_encode($states, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Retrieve and remove a one-time OAuth state nonce from app config if still valid.
     *
     * @return array<string, mixed>|null
     */
    public function consumeOAuthState(string $nonce): ?array {
        if ($nonce === '') {
            return null;
        }
        $states = $this->readPrunedOAuthStates(time() - 600);
        if ($states === []) {
            return null;
        }

        $matched = $states[$nonce] ?? null;
        unset($states[$nonce]);

        $this->config->setAppValue(
            Application::APP_ID,
            self::KEY_OAUTH_STATES,
            (string)json_encode($states, JSON_THROW_ON_ERROR)
        );

        return $matched;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readPrunedOAuthStates(int $cutoff): array {
        $raw = $this->config->getAppValue(Application::APP_ID, self::KEY_OAUTH_STATES, '{}');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $pruned = [];
        foreach ($decoded as $key => $entry) {
            if (is_string($key) && is_array($entry) && (int)($entry['created'] ?? 0) >= $cutoff) {
                $pruned[$key] = $entry;
            }
        }
        return $pruned;
    }

    /**
     * Return safe account summary (never exposing secrets or raw tokens) for UI/API responses.
     *
     * @return array<string, mixed>
     */
    public function getPublicAccountSummary(string $scope, ?string $uid = null): array {
        $account = $this->getAccount($scope, $uid);
        $authType = $account['auth_type'];
        $connected = $authType === self::AUTH_SERVICE_ACCOUNT
            ? ($account['service_account_json'] !== '' && $account['service_account_email'] !== '')
            : ($account['refresh_token'] !== '' || $account['access_token'] !== '');

        return [
            'auth_type' => $authType,
            'client_id' => $account['client_id'],
            'has_client_secret' => $account['client_secret'] !== '',
            'has_refresh_token' => $account['refresh_token'] !== '',
            'has_service_account' => $account['service_account_json'] !== '',
            'service_account_email' => $account['service_account_email'],
            'impersonate_email' => $account['impersonate_email'],
            'connected' => $connected,
            'connected_email' => $account['connected_email'],
            'connected_name' => $account['connected_name'],
        ];
    }

    /**
     * Get all configured volumes for the given scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVolumes(string $scope, ?string $uid = null): array {
        $scope = $this->validateScope($scope);
        $raw = $scope === self::SCOPE_ADMIN
            ? $this->config->getAppValue(Application::APP_ID, self::KEY_VOLUMES_ADMIN, '[]')
            : $this->config->getUserValue((string)$uid, Application::APP_ID, self::KEY_VOLUMES_PERSONAL, '[]');

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $volumes = [];
        foreach ($decoded as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $volumes[] = $this->normalizeStoredVolume($item, $scope, (string)$uid);
            }
        }
        return $volumes;
    }

    /**
     * Find a specific volume by ID within a scope, or across all scopes when $scope is null.
     *
     * @return array<string, mixed>|null
     */
    public function getVolumeById(string $volumeId, ?string $scope = null, ?string $uid = null): ?array {
        if ($scope !== null) {
            foreach ($this->getVolumes($scope, $uid) as $volume) {
                if ($volume['id'] === $volumeId) {
                    return $volume;
                }
            }
            return null;
        }

        foreach ($this->getVolumes(self::SCOPE_ADMIN) as $volume) {
            if ($volume['id'] === $volumeId) {
                return $volume;
            }
        }

        foreach ($this->getUsersWithPersonalVolumes() as $userUid) {
            foreach ($this->getVolumes(self::SCOPE_PERSONAL, $userUid) as $volume) {
                if ($volume['id'] === $volumeId) {
                    return $volume;
                }
            }
        }

        return null;
    }

    /**
     * Return all volumes across admin and every user (used by OCC commands).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllVolumes(): array {
        $all = $this->getVolumes(self::SCOPE_ADMIN);
        foreach ($this->getUsersWithPersonalVolumes() as $userUid) {
            foreach ($this->getVolumes(self::SCOPE_PERSONAL, $userUid) as $volume) {
                $all[] = $volume;
            }
        }
        return $all;
    }

    /**
     * Get all active volumes that apply to a specific Nextcloud user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveVolumesForUser(IUser $user): array {
        $uid = $user->getUID();
        $result = [];
        $claimedLocalPaths = [];

        // Personal volumes take precedence for their own user if personal mounts are enabled
        if ($this->isPersonalMountsAllowed()) {
            foreach ($this->getVolumes(self::SCOPE_PERSONAL, $uid) as $volume) {
                if (!$volume['enabled']) {
                    continue;
                }
                $localKey = strtolower($volume['local_mount_path']);
                $claimedLocalPaths[$localKey] = true;
                $result[] = $volume;
            }
        }

        foreach ($this->getVolumes(self::SCOPE_ADMIN) as $volume) {
            if (!$volume['enabled']) {
                continue;
            }
            if (!$this->isAdminVolumeVisibleToUser($volume, $user)) {
                continue;
            }
            $localKey = strtolower($volume['local_mount_path']);
            if (isset($claimedLocalPaths[$localKey])) {
                continue;
            }
            $claimedLocalPaths[$localKey] = true;
            $result[] = $volume;
        }

        return $result;
    }

    /**
     * Create or update a volume inside the given scope.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveVolume(string $scope, ?string $uid, array $input, ?string $existingId = null): array {
        $scope = $this->validateScope($scope);
        if ($scope === self::SCOPE_PERSONAL && ($uid === null || $uid === '')) {
            throw new InvalidArgumentException('User ID is required for personal scope.');
        }

        $volumes = $this->getVolumes($scope, $uid);
        $now = time();
        $existing = null;
        $existingIndex = null;

        if ($existingId !== null && $existingId !== '') {
            foreach ($volumes as $idx => $vol) {
                if ($vol['id'] === $existingId) {
                    $existing = $vol;
                    $existingIndex = $idx;
                    break;
                }
            }
            if ($existing === null) {
                throw new InvalidArgumentException('Volume not found.');
            }
        }

        $localParent = $this->normalizeRelativePath(
            (string)($input['local_parent_dir'] ?? ($existing['local_parent_dir'] ?? $this->getDefaultCommonLocalDir($scope, $uid))),
            true
        );
        $localSub = $this->normalizeRelativePath(
            (string)($input['local_sub_dir'] ?? ($existing['local_sub_dir'] ?? '')),
            false
        );
        $localMountPath = $this->buildLocalMountPath($localParent, $localSub);

        $remotePath = $this->normalizeRelativePath(
            (string)($input['remote_path'] ?? ($existing['remote_path'] ?? '')),
            true
        );
        $remoteFolderId = trim((string)($input['remote_folder_id'] ?? ($existing['remote_folder_id'] ?? '')));

        if ($remotePath === '' && $remoteFolderId === '') {
            throw new InvalidArgumentException('Provide either a Google Drive subdirectory path (e.g. NextCloud/Volume_1) or a Google Drive Folder ID.');
        }

        $name = trim((string)($input['name'] ?? ($existing['name'] ?? '')));
        if ($name === '') {
            $name = $localSub;
        }

        $exportMode = (string)($input['export_google_docs'] ?? ($existing['export_google_docs'] ?? self::EXPORT_OFFICE));
        if (!in_array($exportMode, [self::EXPORT_OFFICE, self::EXPORT_PDF, self::EXPORT_NONE], true)) {
            $exportMode = self::EXPORT_OFFICE;
        }

        $quotaBytes = $this->parseQuotaBytes($input['quota_bytes'] ?? ($existing['quota_bytes'] ?? null));
        $enabled = array_key_exists('enabled', $input) ? (bool)$input['enabled'] : ($existing['enabled'] ?? true);

        $allowedUsers = $scope === self::SCOPE_ADMIN
            ? $this->normalizeStringList($input['allowed_users'] ?? ($existing['allowed_users'] ?? []))
            : [$uid];
        $allowedGroups = $scope === self::SCOPE_ADMIN
            ? $this->normalizeStringList($input['allowed_groups'] ?? ($existing['allowed_groups'] ?? []))
            : [];

        $volumeId = $existing['id'] ?? bin2hex(random_bytes(8));

        // Ensure no duplicate local mount paths among enabled volumes in this scope
        if ($enabled) {
            foreach ($volumes as $vol) {
                if ($vol['id'] !== $volumeId && $vol['enabled'] && strcasecmp($vol['local_mount_path'], $localMountPath) === 0) {
                    throw new InvalidArgumentException(sprintf(
                        'Another enabled volume ("%s") is already mounted at "%s".',
                        $vol['name'],
                        $localMountPath
                    ));
                }
            }
        }

        $record = [
            'id' => $volumeId,
            'name' => $name,
            'scope' => $scope,
            'owner_uid' => $scope === self::SCOPE_PERSONAL ? (string)$uid : '',
            'remote_path' => $remotePath,
            'remote_folder_id' => $remoteFolderId,
            'auto_create_remote' => array_key_exists('auto_create_remote', $input)
                ? (bool)$input['auto_create_remote']
                : (bool)($existing['auto_create_remote'] ?? true),
            'local_parent_dir' => $localParent,
            'local_sub_dir' => $localSub,
            'local_mount_path' => $localMountPath,
            'read_only' => array_key_exists('read_only', $input)
                ? (bool)$input['read_only']
                : (bool)($existing['read_only'] ?? false),
            'quota_bytes' => $quotaBytes,
            'export_google_docs' => $exportMode,
            'trash_on_delete' => array_key_exists('trash_on_delete', $input)
                ? (bool)$input['trash_on_delete']
                : (bool)($existing['trash_on_delete'] ?? true),
            'enabled' => $enabled,
            'allowed_users' => $allowedUsers,
            'allowed_groups' => $allowedGroups,
            'created_at' => (int)($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];

        if ($existingIndex !== null) {
            $volumes[$existingIndex] = $record;
        } else {
            $volumes[] = $record;
        }

        $this->persistVolumes($scope, $uid, $volumes);
        return $record;
    }

    /**
     * Delete a volume definition from the given scope.
     */
    public function deleteVolume(string $scope, ?string $uid, string $volumeId): bool {
        $scope = $this->validateScope($scope);
        $volumes = $this->getVolumes($scope, $uid);
        $filtered = array_values(array_filter(
            $volumes,
            static fn (array $vol): bool => $vol['id'] !== $volumeId
        ));

        if (count($filtered) === count($volumes)) {
            return false;
        }

        $this->persistVolumes($scope, $uid, $filtered);
        return true;
    }

    /**
     * Update resolved remote_folder_id on an existing volume without changing timestamps needlessly.
     */
    public function updateResolvedRemoteFolderId(string $scope, ?string $uid, string $volumeId, string $folderId): void {
        $volumes = $this->getVolumes($scope, $uid);
        foreach ($volumes as $idx => $vol) {
            if ($vol['id'] === $volumeId) {
                if ($vol['remote_folder_id'] === $folderId) {
                    return;
                }
                $volumes[$idx]['remote_folder_id'] = $folderId;
                $volumes[$idx]['updated_at'] = time();
                $this->persistVolumes($scope, $uid, $volumes);
                return;
            }
        }
    }

    /**
     * Normalize a relative path string, rejecting path traversal or control characters.
     */
    public function normalizeRelativePath(string $path, bool $allowEmpty = false): string {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path cannot contain null bytes.');
        }

        $normalized = str_replace('\\', '/', trim($path));
        $normalized = trim($normalized, '/');
        if ($normalized === '') {
            if ($allowEmpty) {
                return '';
            }
            throw new InvalidArgumentException('Subdirectory name cannot be empty.');
        }

        $parts = array_values(array_filter(explode('/', $normalized), static fn (string $p): bool => $p !== ''));
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                throw new InvalidArgumentException('Path segments "." and ".." are not allowed.');
            }
        }

        return implode('/', $parts);
    }

    public function buildLocalMountPath(string $localParentDir, string $localSubDir): string {
        $parent = $this->normalizeRelativePath($localParentDir, true);
        $sub = $this->normalizeRelativePath($localSubDir, false);
        return $parent !== '' ? $parent . '/' . $sub : $sub;
    }

    /**
     * @return array<int, string>
     */
    public function getUsersWithPersonalVolumes(): array {
        return $this->config->getUsersForUserValue(Application::APP_ID, self::KEY_HAS_PERSONAL_VOLUMES, '1');
    }

    private function isAdminVolumeVisibleToUser(array $volume, IUser $user): bool {
        $allowedUsers = $volume['allowed_users'] ?? [];
        $allowedGroups = $volume['allowed_groups'] ?? [];

        if ($allowedUsers === [] && $allowedGroups === []) {
            return true;
        }

        $uid = $user->getUID();
        if (in_array($uid, $allowedUsers, true)) {
            return true;
        }

        foreach ($allowedGroups as $groupId) {
            if ($this->groupManager->isInGroup($uid, $groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $volumes
     */
    private function persistVolumes(string $scope, ?string $uid, array $volumes): void {
        $encoded = (string)json_encode(array_values($volumes), JSON_THROW_ON_ERROR);
        if ($scope === self::SCOPE_ADMIN) {
            $this->config->setAppValue(Application::APP_ID, self::KEY_VOLUMES_ADMIN, $encoded);
            return;
        }

        if ($uid === null || $uid === '') {
            throw new InvalidArgumentException('User ID is required for personal scope.');
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::KEY_VOLUMES_PERSONAL, $encoded);
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::KEY_HAS_PERSONAL_VOLUMES,
            $volumes === [] ? '0' : '1'
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeStoredVolume(array $raw, string $scope, string $uid): array {
        $localParent = trim((string)($raw['local_parent_dir'] ?? ''), '/');
        $localSub = trim((string)($raw['local_sub_dir'] ?? ''), '/');
        $localMount = $localParent !== '' ? $localParent . '/' . $localSub : $localSub;

        return [
            'id' => (string)$raw['id'],
            'name' => (string)($raw['name'] ?? $localSub),
            'scope' => $scope,
            'owner_uid' => $scope === self::SCOPE_PERSONAL ? $uid : '',
            'remote_path' => trim((string)($raw['remote_path'] ?? ''), '/'),
            'remote_folder_id' => trim((string)($raw['remote_folder_id'] ?? '')),
            'auto_create_remote' => (bool)($raw['auto_create_remote'] ?? true),
            'local_parent_dir' => $localParent,
            'local_sub_dir' => $localSub,
            'local_mount_path' => $localMount,
            'read_only' => (bool)($raw['read_only'] ?? false),
            'quota_bytes' => $this->parseQuotaBytes($raw['quota_bytes'] ?? null),
            'export_google_docs' => (string)($raw['export_google_docs'] ?? self::EXPORT_OFFICE),
            'trash_on_delete' => (bool)($raw['trash_on_delete'] ?? true),
            'enabled' => (bool)($raw['enabled'] ?? true),
            'allowed_users' => $this->normalizeStringList($raw['allowed_users'] ?? []),
            'allowed_groups' => $this->normalizeStringList($raw['allowed_groups'] ?? []),
            'created_at' => (int)($raw['created_at'] ?? 0),
            'updated_at' => (int)($raw['updated_at'] ?? 0),
        ];
    }

    private function parseQuotaBytes(mixed $value): ?int {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_numeric($value)) {
            $intVal = (int)$value;
            return $intVal > 0 ? $intVal : null;
        }
        if (is_string($value) && preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMGTP]?B?)?\s*$/i', $value, $matches)) {
            $num = (float)$matches[1];
            $unit = strtoupper($matches[2] ?? '');
            $multiplier = match ($unit) {
                'K', 'KB' => 1024,
                'M', 'MB' => 1024 ** 2,
                'G', 'GB' => 1024 ** 3,
                'T', 'TB' => 1024 ** 4,
                default => 1,
            };
            $bytes = (int)round($num * $multiplier);
            return $bytes > 0 ? $bytes : null;
        }
        return null;
    }

    /**
     * @param mixed $input
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $input): array {
        if (is_string($input)) {
            $input = explode(',', $input);
        }
        if (!is_array($input)) {
            return [];
        }
        $clean = [];
        foreach ($input as $entry) {
            $trimmed = trim((string)$entry);
            if ($trimmed !== '' && !in_array($trimmed, $clean, true)) {
                $clean[] = $trimmed;
            }
        }
        return $clean;
    }
}
