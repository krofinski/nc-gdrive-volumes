<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
script('gdrive_volumes', 'settings');
style('gdrive_volumes', 'settings');

$scope = (string)($_['scope'] ?? 'personal');
$redirectUri = (string)($_['redirectUri'] ?? '');
$apiBaseUrl = (string)($_['apiBaseUrl'] ?? '');
$personalAllowed = (bool)($_['personalMountsAllowed'] ?? true);
?>
<div id="gdrive-volumes-app"
     class="section gdrive-volumes-section"
     data-scope="<?php p($scope); ?>"
     data-redirect-uri="<?php p($redirectUri); ?>"
     data-api-base="<?php p($apiBaseUrl); ?>"
     data-personal-allowed="<?php p($personalAllowed ? '1' : '0'); ?>">

    <h2><?php p($l->t('Google Drive Volumes')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Link multiple Google Drive subdirectories (e.g. NextCloud/Volume_1, NextCloud/Volume_2) as independent external disks inside Nextcloud under a common local directory (e.g. Gdrive/Volume_1, Gdrive/Volume_2).')); ?>
    </p>

    <div id="gdrive-volumes-banner" class="gdrive-banner hidden" role="status" aria-live="polite"></div>

    <?php if ($scope === 'personal' && !$personalAllowed): ?>
        <div class="gdrive-card gdrive-disabled-notice">
            <p><?php p($l->t('Personal Google Drive volume mounts are currently disabled by the Nextcloud administrator.')); ?></p>
        </div>
    <?php else: ?>
        <!-- Card 1: Google Drive Account Connection -->
        <div class="gdrive-card">
            <div class="gdrive-card-header">
                <h3><?php p($l->t('1. Google Drive Account')); ?></h3>
                <span id="gdrive-connection-badge" class="gdrive-badge gdrive-badge-muted">
                    <?php p($l->t('Checking status...')); ?>
                </span>
            </div>

            <div class="gdrive-auth-tabs" role="tablist">
                <button type="button" class="gdrive-tab active" data-auth-tab="oauth">
                    <?php p($l->t('OAuth 2.0 (User / Workspace)')); ?>
                </button>
                <button type="button" class="gdrive-tab" data-auth-tab="service_account">
                    <?php p($l->t('Service Account (JSON)')); ?>
                </button>
            </div>

            <div id="gdrive-panel-oauth" class="gdrive-auth-panel">
                <div class="gdrive-field">
                    <label for="gdrive-redirect-uri"><?php p($l->t('Authorized Redirect URI (add this in Google Cloud Console)')); ?></label>
                    <div class="gdrive-inline-group">
                        <input id="gdrive-redirect-uri" type="text" readonly value="<?php p($redirectUri); ?>">
                        <button type="button" id="gdrive-copy-redirect" class="button">
                            <?php p($l->t('Copy')); ?>
                        </button>
                    </div>
                </div>

                <div class="gdrive-grid-2">
                    <div class="gdrive-field">
                        <label for="gdrive-client-id"><?php p($l->t('Google OAuth Client ID')); ?></label>
                        <input id="gdrive-client-id" type="text" autocomplete="off" placeholder="1234567890-xxxx.apps.googleusercontent.com">
                    </div>
                    <div class="gdrive-field">
                        <label for="gdrive-client-secret"><?php p($l->t('Google OAuth Client Secret')); ?></label>
                        <input id="gdrive-client-secret" type="password" autocomplete="new-password" placeholder="<?php p($l->t('Enter Client Secret')); ?>">
                    </div>
                </div>

                <div class="gdrive-actions">
                    <button type="button" id="gdrive-save-oauth" class="button primary">
                        <?php p($l->t('Save Credentials')); ?>
                    </button>
                    <button type="button" id="gdrive-connect-oauth" class="button">
                        <?php p($l->t('Connect with Google Drive')); ?>
                    </button>
                    <button type="button" id="gdrive-test-account" class="button">
                        <?php p($l->t('Test Connection')); ?>
                    </button>
                    <button type="button" id="gdrive-disconnect-account" class="button gdrive-btn-danger">
                        <?php p($l->t('Disconnect')); ?>
                    </button>
                </div>
            </div>

            <div id="gdrive-panel-service-account" class="gdrive-auth-panel hidden">
                <div class="gdrive-field">
                    <label for="gdrive-sa-file"><?php p($l->t('Upload Service Account JSON file or paste contents below')); ?></label>
                    <input id="gdrive-sa-file" type="file" accept=".json,application/json">
                </div>
                <div class="gdrive-field">
                    <label for="gdrive-sa-json"><?php p($l->t('Service Account JSON')); ?></label>
                    <textarea id="gdrive-sa-json" rows="4" placeholder='{"type": "service_account", "client_email": "...", "private_key": "..."}'></textarea>
                    <small id="gdrive-sa-status" class="gdrive-hint"></small>
                </div>
                <div class="gdrive-field">
                    <label for="gdrive-sa-impersonate"><?php p($l->t('Google Workspace Impersonate Email (optional, for domain-wide delegation)')); ?></label>
                    <input id="gdrive-sa-impersonate" type="email" placeholder="user@yourdomain.com">
                </div>

                <div class="gdrive-actions">
                    <button type="button" id="gdrive-save-sa" class="button primary">
                        <?php p($l->t('Save Service Account')); ?>
                    </button>
                    <button type="button" id="gdrive-test-sa" class="button">
                        <?php p($l->t('Test Connection')); ?>
                    </button>
                    <button type="button" id="gdrive-disconnect-sa" class="button gdrive-btn-danger">
                        <?php p($l->t('Disconnect')); ?>
                    </button>
                </div>
            </div>

            <div id="gdrive-account-feedback" class="gdrive-inline-feedback hidden" role="status" aria-live="polite"></div>
        </div>

        <!-- Card 2: Common Directory Defaults & Cache -->
        <div class="gdrive-card">
            <div class="gdrive-card-header">
                <h3><?php p($l->t('2. Common Directory & Cache Defaults')); ?></h3>
            </div>

            <div class="gdrive-grid-3">
                <div class="gdrive-field">
                    <label for="gdrive-default-local-dir"><?php p($l->t('Default Common Local Directory')); ?></label>
                    <input id="gdrive-default-local-dir" type="text" placeholder="Gdrive">
                    <small class="gdrive-hint"><?php p($l->t('Example: Gdrive (volumes become Gdrive/Volume_1, Gdrive/Volume_2)')); ?></small>
                </div>
                <div class="gdrive-field">
                    <label for="gdrive-default-remote-prefix"><?php p($l->t('Default Google Drive Base Folder')); ?></label>
                    <input id="gdrive-default-remote-prefix" type="text" placeholder="NextCloud">
                    <small class="gdrive-hint"><?php p($l->t('Example: NextCloud (pre-fills NextCloud/...)')); ?></small>
                </div>
                <div class="gdrive-field">
                    <label for="gdrive-cache-ttl"><?php p($l->t('Metadata Cache TTL (seconds)')); ?></label>
                    <input id="gdrive-cache-ttl" type="number" min="5" max="86400" value="60">
                    <small class="gdrive-hint"><?php p($l->t('Caches folder listings and path-to-ID mappings (5–86400s).')); ?></small>
                </div>
            </div>

            <?php if ($scope === 'admin'): ?>
                <div class="gdrive-checkbox-row">
                    <input id="gdrive-allow-personal" type="checkbox" class="checkbox">
                    <label for="gdrive-allow-personal">
                        <?php p($l->t('Allow users to configure their own personal Google Drive volumes in Personal Settings')); ?>
                    </label>
                </div>
            <?php endif; ?>

            <div class="gdrive-actions">
                <button type="button" id="gdrive-save-general" class="button primary">
                    <?php p($l->t('Save Defaults')); ?>
                </button>
                <button type="button" id="gdrive-clear-cache" class="button">
                    <?php p($l->t('Clear Metadata Cache')); ?>
                </button>
            </div>

            <div id="gdrive-general-feedback" class="gdrive-inline-feedback hidden" role="status" aria-live="polite"></div>
        </div>

        <!-- Card 3: Create / Edit Volume Form -->
        <div class="gdrive-card">
            <div class="gdrive-card-header">
                <h3 id="gdrive-volume-form-title"><?php p($l->t('3. Add Google Drive Volume')); ?></h3>
            </div>

            <form id="gdrive-volume-form">
                <input id="gdrive-vol-id" type="hidden" value="">

                <div class="gdrive-grid-2">
                    <div class="gdrive-field">
                        <label for="gdrive-vol-name"><?php p($l->t('Disk / Volume Name')); ?></label>
                        <input id="gdrive-vol-name" type="text" placeholder="Volume 1">
                    </div>
                    <div class="gdrive-field gdrive-checkbox-inline-wrap">
                        <label class="gdrive-checkbox-label">
                            <input id="gdrive-vol-enabled" type="checkbox" class="checkbox" checked>
                            <span><?php p($l->t('Volume enabled (mounted in Nextcloud)')); ?></span>
                        </label>
                    </div>
                </div>

                <div class="gdrive-subsection">
                    <h4><?php p($l->t('Google Drive Source Subdirectory')); ?></h4>
                    <div class="gdrive-grid-2">
                        <div class="gdrive-field">
                            <label for="gdrive-vol-remote-path"><?php p($l->t('Remote Path in Google Drive')); ?></label>
                            <div class="gdrive-inline-group">
                                <input id="gdrive-vol-remote-path" type="text" placeholder="NextCloud/Volume_1">
                                <button type="button" id="gdrive-browse-drive" class="button">
                                    <?php p($l->t('Browse Drive')); ?>
                                </button>
                            </div>
                            <small class="gdrive-hint"><?php p($l->t('Subdirectory path inside Google Drive (e.g. NextCloud/Volume_1).')); ?></small>
                        </div>

                        <div class="gdrive-field">
                            <label for="gdrive-vol-remote-id"><?php p($l->t('Google Drive Folder ID (optional override)')); ?></label>
                            <input id="gdrive-vol-remote-id" type="text" placeholder="<?php p($l->t('Auto-resolved from Remote Path')); ?>">
                            <small class="gdrive-hint"><?php p($l->t('Leave blank to auto-resolve from Remote Path, or paste an explicit Drive Folder ID.')); ?></small>
                        </div>
                    </div>

                    <div class="gdrive-checkbox-row">
                        <input id="gdrive-vol-auto-create" type="checkbox" class="checkbox" checked>
                        <label for="gdrive-vol-auto-create">
                            <?php p($l->t('Automatically create missing subdirectories in Google Drive (e.g. NextCloud/Volume_1)')); ?>
                        </label>
                    </div>
                </div>

                <div class="gdrive-subsection">
                    <h4><?php p($l->t('Nextcloud Local Mount Path (Common Directory + Subdirectory)')); ?></h4>
                    <div class="gdrive-path-builder">
                        <div class="gdrive-field">
                            <label for="gdrive-vol-local-parent"><?php p($l->t('Common Directory')); ?></label>
                            <input id="gdrive-vol-local-parent" type="text" placeholder="Gdrive">
                        </div>
                        <div class="gdrive-path-sep" aria-hidden="true">/</div>
                        <div class="gdrive-field">
                            <label for="gdrive-vol-local-sub"><?php p($l->t('Volume Subdirectory')); ?></label>
                            <input id="gdrive-vol-local-sub" type="text" required placeholder="Volume_1">
                        </div>
                    </div>
                    <div class="gdrive-mount-preview">
                        <span><?php p($l->t('Resulting Nextcloud disk path:')); ?></span>
                        <code id="gdrive-mount-preview-code">/Gdrive/Volume_1</code>
                    </div>
                </div>

                <div class="gdrive-subsection">
                    <h4><?php p($l->t('Disk Behavior & Quota')); ?></h4>
                    <div class="gdrive-grid-2">
                        <div class="gdrive-field">
                            <label for="gdrive-vol-quota"><?php p($l->t('Virtual Disk Quota (optional)')); ?></label>
                            <input id="gdrive-vol-quota" type="text" placeholder="<?php p($l->t('e.g. 50G, 200G, or blank for Drive account quota')); ?>">
                        </div>
                        <div class="gdrive-field">
                            <label for="gdrive-vol-export"><?php p($l->t('Google Docs / Sheets / Slides Export')); ?></label>
                            <select id="gdrive-vol-export">
                                <option value="office"><?php p($l->t('Export as Office (.docx / .xlsx / .pptx)')); ?></option>
                                <option value="pdf"><?php p($l->t('Export as PDF (.pdf)')); ?></option>
                                <option value="none"><?php p($l->t('Hide native Google Workspace documents')); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="gdrive-checkbox-row">
                        <input id="gdrive-vol-readonly" type="checkbox" class="checkbox">
                        <label for="gdrive-vol-readonly"><?php p($l->t('Read-only disk (block uploads, edits, and deletions)')); ?></label>
                    </div>
                    <div class="gdrive-checkbox-row">
                        <input id="gdrive-vol-trash" type="checkbox" class="checkbox" checked>
                        <label for="gdrive-vol-trash"><?php p($l->t('Move deleted files to Google Drive Trash instead of permanent deletion')); ?></label>
                    </div>
                </div>

                <?php if ($scope === 'admin'): ?>
                    <div class="gdrive-subsection">
                        <h4><?php p($l->t('Visibility (leave blank for all Nextcloud users)')); ?></h4>
                        <div class="gdrive-grid-2">
                            <div class="gdrive-field">
                                <label for="gdrive-vol-groups"><?php p($l->t('Allowed Groups (comma-separated)')); ?></label>
                                <input id="gdrive-vol-groups" type="text" list="gdrive-groups-datalist" placeholder="admin, family">
                                <datalist id="gdrive-groups-datalist"></datalist>
                            </div>
                            <div class="gdrive-field">
                                <label for="gdrive-vol-users"><?php p($l->t('Allowed Users (comma-separated)')); ?></label>
                                <input id="gdrive-vol-users" type="text" list="gdrive-users-datalist" placeholder="roberto">
                                <datalist id="gdrive-users-datalist"></datalist>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="gdrive-actions">
                    <button type="submit" id="gdrive-vol-submit" class="button primary">
                        <?php p($l->t('Add Volume')); ?>
                    </button>
                    <button type="button" id="gdrive-vol-cancel" class="button hidden">
                        <?php p($l->t('Cancel Edit')); ?>
                    </button>
                </div>

                <div id="gdrive-volume-feedback" class="gdrive-inline-feedback hidden" role="status" aria-live="polite"></div>
            </form>
        </div>

        <!-- Card 4: Configured Volumes List -->
        <div class="gdrive-card">
            <div class="gdrive-card-header">
                <h3><?php p($l->t('4. Mounted Volumes')); ?></h3>
                <button type="button" id="gdrive-refresh-volumes" class="button">
                    <?php p($l->t('Refresh')); ?>
                </button>
            </div>
            <div id="gdrive-volumes-list" class="gdrive-volumes-list">
                <p class="gdrive-empty"><?php p($l->t('Loading configured volumes...')); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Folder Browser Modal -->
    <div id="gdrive-browser-modal" class="gdrive-modal hidden" role="dialog" aria-modal="true" aria-labelledby="gdrive-browser-title">
        <div class="gdrive-modal-backdrop"></div>
        <div class="gdrive-modal-dialog">
            <div class="gdrive-modal-header">
                <h4 id="gdrive-browser-title"><?php p($l->t('Browse Google Drive Folders')); ?></h4>
                <button type="button" id="gdrive-browser-close" class="button">&times;</button>
            </div>
            <div id="gdrive-browser-breadcrumbs" class="gdrive-breadcrumbs"></div>
            <div id="gdrive-browser-body" class="gdrive-modal-body"></div>
            <div class="gdrive-modal-footer">
                <button type="button" id="gdrive-browser-select-current" class="button primary">
                    <?php p($l->t('Select Current Folder')); ?>
                </button>
                <button type="button" id="gdrive-browser-cancel" class="button">
                    <?php p($l->t('Cancel')); ?>
                </button>
            </div>
        </div>
    </div>
</div>
