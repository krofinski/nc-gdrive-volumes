(function () {
    'use strict';

    const APP_ID = 'gdrive_volumes';

    function getRequestToken() {
        if (document.head && document.head.dataset && document.head.dataset.requesttoken) {
            return document.head.dataset.requesttoken;
        }
        const headEl = document.getElementsByTagName('head')[0];
        if (headEl) {
            const attrToken = headEl.getAttribute('data-requesttoken');
            if (attrToken) {
                return attrToken;
            }
        }
        if (window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        return '';
    }

    function initApp() {
        const root = document.getElementById('gdrive-volumes-app');
        if (!root) {
            return false;
        }
        if (root.dataset.gdriveInitialized === '1') {
            return true;
        }
        root.dataset.gdriveInitialized = '1';

        const scope = root.dataset.scope || 'personal';
        const personalAllowed = root.dataset.personalAllowed === '1';
        const dataApiBase = (root.getAttribute('data-api-base') || '').replace(/\/+$/, '');

        const els = {
            banner: document.getElementById('gdrive-volumes-banner'),
            connBadge: document.getElementById('gdrive-connection-badge'),
            tabs: root.querySelectorAll('.gdrive-tab'),
            panelOAuth: document.getElementById('gdrive-panel-oauth'),
            panelSA: document.getElementById('gdrive-panel-service-account'),
            redirectUri: document.getElementById('gdrive-redirect-uri'),
            copyRedirect: document.getElementById('gdrive-copy-redirect'),
            clientId: document.getElementById('gdrive-client-id'),
            clientSecret: document.getElementById('gdrive-client-secret'),
            saveOAuth: document.getElementById('gdrive-save-oauth'),
            connectOAuth: document.getElementById('gdrive-connect-oauth'),
            testAccount: document.getElementById('gdrive-test-account'),
            disconnectAccount: document.getElementById('gdrive-disconnect-account'),
            saFile: document.getElementById('gdrive-sa-file'),
            saJson: document.getElementById('gdrive-sa-json'),
            saStatus: document.getElementById('gdrive-sa-status'),
            saImpersonate: document.getElementById('gdrive-sa-impersonate'),
            saveSA: document.getElementById('gdrive-save-sa'),
            testSA: document.getElementById('gdrive-test-sa'),
            disconnectSA: document.getElementById('gdrive-disconnect-sa'),
            accountFeedback: document.getElementById('gdrive-account-feedback'),
            defaultLocalDir: document.getElementById('gdrive-default-local-dir'),
            defaultRemotePrefix: document.getElementById('gdrive-default-remote-prefix'),
            cacheTtl: document.getElementById('gdrive-cache-ttl'),
            allowPersonal: document.getElementById('gdrive-allow-personal'),
            saveGeneral: document.getElementById('gdrive-save-general'),
            clearCache: document.getElementById('gdrive-clear-cache'),
            generalFeedback: document.getElementById('gdrive-general-feedback'),
            volFormTitle: document.getElementById('gdrive-volume-form-title'),
            volForm: document.getElementById('gdrive-volume-form'),
            volId: document.getElementById('gdrive-vol-id'),
            volName: document.getElementById('gdrive-vol-name'),
            volEnabled: document.getElementById('gdrive-vol-enabled'),
            volRemotePath: document.getElementById('gdrive-vol-remote-path'),
            volRemoteId: document.getElementById('gdrive-vol-remote-id'),
            volAutoCreate: document.getElementById('gdrive-vol-auto-create'),
            volLocalParent: document.getElementById('gdrive-vol-local-parent'),
            volLocalSub: document.getElementById('gdrive-vol-local-sub'),
            mountPreviewCode: document.getElementById('gdrive-mount-preview-code'),
            volQuota: document.getElementById('gdrive-vol-quota'),
            volExport: document.getElementById('gdrive-vol-export'),
            volReadOnly: document.getElementById('gdrive-vol-readonly'),
            volTrash: document.getElementById('gdrive-vol-trash'),
            volGroups: document.getElementById('gdrive-vol-groups'),
            volUsers: document.getElementById('gdrive-vol-users'),
            groupsDatalist: document.getElementById('gdrive-groups-datalist'),
            usersDatalist: document.getElementById('gdrive-users-datalist'),
            volSubmit: document.getElementById('gdrive-vol-submit'),
            volCancel: document.getElementById('gdrive-vol-cancel'),
            volumeFeedback: document.getElementById('gdrive-volume-feedback'),
            volumesList: document.getElementById('gdrive-volumes-list'),
            refreshVolumes: document.getElementById('gdrive-refresh-volumes'),
            browseDriveBtn: document.getElementById('gdrive-browse-drive'),
            modal: document.getElementById('gdrive-browser-modal'),
            modalClose: document.getElementById('gdrive-browser-close'),
            modalCancel: document.getElementById('gdrive-browser-cancel'),
            modalBreadcrumbs: document.getElementById('gdrive-browser-breadcrumbs'),
            modalBody: document.getElementById('gdrive-browser-body'),
            modalSelectCurrent: document.getElementById('gdrive-browser-select-current'),
        };

        const state = {
            authorizeUrl: '',
            general: {
                default_common_local_dir: 'Gdrive',
                default_remote_prefix: 'NextCloud',
                cache_ttl: 60,
                allow_personal_mounts: true,
            },
            volumes: [],
            browserStack: [{ id: 'root', name: 'My Drive', isDriveRoot: true }],
        };

        function buildApiUrl(path) {
            if (dataApiBase) {
                return dataApiBase + path;
            }
            if (window.OC && typeof window.OC.generateUrl === 'function') {
                return window.OC.generateUrl('/apps/' + APP_ID + path);
            }
            const webroot = (typeof window._oc_webroot === 'string' ? window._oc_webroot : '').replace(/\/+$/, '');
            return webroot + '/index.php/apps/' + APP_ID + path;
        }

        async function requestJson(method, path, body) {
            const headers = {
                'Accept': 'application/json',
            };
            const token = getRequestToken();
            if (token) {
                headers.requesttoken = token;
            }

            const options = {
                method: method,
                credentials: 'same-origin',
                headers: headers,
            };
            if (body !== undefined) {
                headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }

            const resp = await fetch(buildApiUrl(path), options);
            const rawText = await resp.text();
            let data = {};
            try {
                data = rawText ? JSON.parse(rawText) : {};
            } catch {
                data = {};
            }
            if (!resp.ok) {
                throw new Error(data.message || ('Request failed with HTTP ' + resp.status));
            }
            return data;
        }

        function showBanner(message, type) {
            if (!els.banner) {
                return;
            }
            els.banner.textContent = message;
            els.banner.className = 'gdrive-banner is-' + (type || 'success');
        }

        function showInlineFeedback(containerEl, type, content) {
            if (!containerEl) {
                return;
            }
            const cssType = type === 'success' || type === 'warning' || type === 'error' ? type : 'info';
            containerEl.className = 'gdrive-inline-feedback feedback-' + cssType;
            containerEl.innerHTML = '';

            if (typeof content === 'string') {
                const p = document.createElement('p');
                p.textContent = content;
                containerEl.appendChild(p);
            } else if (content instanceof Node) {
                containerEl.appendChild(content);
            }
        }

        function setButtonLoading(btn, isLoading, loadingLabel) {
            if (!btn) {
                return;
            }
            if (isLoading) {
                if (!btn.dataset.originalText) {
                    btn.dataset.originalText = btn.textContent || '';
                }
                btn.disabled = true;
                if (loadingLabel) {
                    btn.textContent = loadingLabel;
                }
            } else {
                btn.disabled = false;
                if (btn.dataset.originalText !== undefined) {
                    btn.textContent = btn.dataset.originalText;
                    delete btn.dataset.originalText;
                }
            }
        }

        function checkOAuthCallbackQuery() {
            const params = new URLSearchParams(window.location.search);
            const oauthStatus = params.get('gdrive_oauth');
            const oauthMsg = params.get('gdrive_msg');
            if (oauthStatus && oauthMsg) {
                const msgType = oauthStatus === 'success' ? 'success' : 'error';
                showBanner(oauthMsg, msgType);
                showInlineFeedback(els.accountFeedback, msgType, oauthMsg);
                params.delete('gdrive_oauth');
                params.delete('gdrive_msg');
                const newQuery = params.toString();
                const cleanUrl = window.location.pathname + (newQuery ? '?' + newQuery : '') + window.location.hash;
                window.history.replaceState({}, '', cleanUrl);
            }
        }

        function setAuthTab(authType) {
            const active = authType === 'service_account' ? 'service_account' : 'oauth';
            els.tabs.forEach((tab) => {
                tab.classList.toggle('active', tab.dataset.authTab === active);
            });
            if (els.panelOAuth && els.panelSA) {
                els.panelOAuth.classList.toggle('hidden', active !== 'oauth');
                els.panelSA.classList.toggle('hidden', active !== 'service_account');
            }
        }

        function updateMountPreview() {
            if (!els.mountPreviewCode || !els.volLocalParent || !els.volLocalSub) {
                return;
            }
            const parent = (els.volLocalParent.value || '').replace(/^\/+|\/+$/g, '');
            const sub = (els.volLocalSub.value || '').replace(/^\/+|\/+$/g, '') || 'Volume_1';
            els.mountPreviewCode.textContent = '/' + (parent ? parent + '/' + sub : sub);
        }

        function formatBytes(bytes, unlimitedLabel) {
            const numeric = Number(bytes);
            if (!Number.isFinite(numeric) || numeric <= 0) {
                return unlimitedLabel || 'Account quota';
            }
            const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
            let val = numeric;
            let idx = 0;
            while (val >= 1024 && idx < units.length - 1) {
                val /= 1024;
                idx += 1;
            }
            return (Math.round(val * 100) / 100) + ' ' + units[idx];
        }

        function quotaToInputString(bytes) {
            if (!bytes || bytes <= 0) {
                return '';
            }
            const gib = bytes / (1024 ** 3);
            if (Number.isInteger(gib)) {
                return gib + 'G';
            }
            const mib = bytes / (1024 ** 2);
            if (Number.isInteger(mib)) {
                return mib + 'M';
            }
            return String(bytes);
        }

        function prefillVolumeFromDriveFolder(folder) {
            if (!els.volForm) {
                return;
            }
            if (els.volId && els.volId.value) {
                resetVolumeForm();
            }
            if (els.volRemotePath) {
                els.volRemotePath.value = folder.name || '';
            }
            if (els.volRemoteId) {
                els.volRemoteId.value = folder.id || '';
            }
            if (els.volLocalSub) {
                els.volLocalSub.value = folder.name || '';
            }
            if (els.volName && !els.volName.value.trim()) {
                els.volName.value = folder.name || '';
            }
            if (els.volLocalParent && !els.volLocalParent.value.trim()) {
                els.volLocalParent.value = state.general.default_common_local_dir || 'Gdrive';
            }
            updateMountPreview();
            showInlineFeedback(
                els.volumeFeedback,
                'info',
                'Prefilled folder "' + (folder.name || '') + '". Review options below and click "Add Volume" to mount it.'
            );
            els.volForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (els.volLocalSub) {
                els.volLocalSub.focus();
            }
        }

        function renderAccountTestSuccess(data) {
            const frag = document.createDocumentFragment();

            const user = data.user || {};
            const quota = data.storageQuota || {};
            const email = user.emailAddress || 'unknown';
            const displayName = user.displayName ? (' (' + user.displayName + ')') : '';

            const summaryP = document.createElement('p');
            const strong = document.createElement('strong');
            strong.textContent = 'Google Drive connection OK: ';
            summaryP.appendChild(strong);
            summaryP.appendChild(document.createTextNode(email + displayName));
            frag.appendChild(summaryP);

            const usedStr = quota.usage !== undefined ? formatBytes(quota.usage, '0 B') : 'N/A';
            const limitStr = quota.limit ? formatBytes(quota.limit) : 'Unlimited / Shared';
            const quotaP = document.createElement('p');
            quotaP.textContent = 'Drive storage used: ' + usedStr + ' / ' + limitStr;
            frag.appendChild(quotaP);

            const folders = Array.isArray(data.rootFolders) ? data.rootFolders : [];
            if (folders.length > 0) {
                const foldersLabel = document.createElement('p');
                foldersLabel.textContent = 'Top-level Google Drive folders detected (' + folders.length + '):';
                frag.appendChild(foldersLabel);

                const previewWrap = document.createElement('div');
                previewWrap.className = 'gdrive-root-folders-preview';

                folders.forEach((folder) => {
                    const chip = document.createElement('div');
                    chip.className = 'gdrive-folder-chip';

                    const nameSpan = document.createElement('span');
                    nameSpan.textContent = folder.name || folder.id;
                    chip.appendChild(nameSpan);

                    const pickBtn = document.createElement('button');
                    pickBtn.type = 'button';
                    pickBtn.className = 'button gdrive-folder-chip-btn';
                    pickBtn.textContent = 'Use as volume';
                    pickBtn.addEventListener('click', () => prefillVolumeFromDriveFolder(folder));
                    chip.appendChild(pickBtn);

                    previewWrap.appendChild(chip);
                });

                frag.appendChild(previewWrap);
            } else {
                const noFoldersP = document.createElement('p');
                noFoldersP.textContent = 'No top-level folders found in My Drive root yet. You can enter a Remote Path (e.g. NextCloud/Volume_1) in Section 3 with auto-create enabled.';
                frag.appendChild(noFoldersP);
            }

            const volumeCount = typeof data.volumeCount === 'number' ? data.volumeCount : state.volumes.length;
            if (volumeCount === 0) {
                const callout = document.createElement('div');
                callout.className = 'gdrive-feedback-callout';
                callout.textContent = 'Your Google Drive account is connected, and 0 volumes are mounted yet. To see directories inside Nextcloud Files, add a volume in Section 3 below (or click "Use as volume" on one of the folders above).';
                frag.appendChild(callout);
            }

            showInlineFeedback(els.accountFeedback, 'success', frag);
        }

        function renderAccount(account) {
            setAuthTab(account.auth_type || 'oauth');
            if (els.clientId) {
                els.clientId.value = account.client_id || '';
            }
            if (els.clientSecret) {
                els.clientSecret.value = '';
                els.clientSecret.placeholder = account.has_client_secret
                    ? '•••••••••••••••• (saved, enter to replace)'
                    : 'Enter Client Secret';
            }
            if (els.saImpersonate) {
                els.saImpersonate.value = account.impersonate_email || '';
            }
            if (els.saStatus) {
                els.saStatus.textContent = account.has_service_account
                    ? ('Configured service account: ' + (account.service_account_email || 'saved'))
                    : 'No Service Account JSON stored yet.';
            }

            if (els.connBadge) {
                if (account.connected) {
                    const who = account.connected_email || account.service_account_email || 'Connected';
                    els.connBadge.textContent = 'Connected (' + who + ')';
                    els.connBadge.className = 'gdrive-badge gdrive-badge-success';
                } else {
                    els.connBadge.textContent = 'Not connected';
                    els.connBadge.className = 'gdrive-badge gdrive-badge-warning';
                }
            }
        }

        function renderGeneral(general) {
            state.general = general;
            if (els.defaultLocalDir) {
                els.defaultLocalDir.value = general.default_common_local_dir ?? 'Gdrive';
            }
            if (els.defaultRemotePrefix) {
                els.defaultRemotePrefix.value = general.default_remote_prefix ?? 'NextCloud';
            }
            if (els.cacheTtl) {
                els.cacheTtl.value = String(general.cache_ttl ?? 60);
            }
            if (els.allowPersonal) {
                els.allowPersonal.checked = Boolean(general.allow_personal_mounts);
            }

            if (els.volId && !els.volId.value) {
                applyDefaultPathsToForm();
            }
        }

        function applyDefaultPathsToForm() {
            const localCommon = state.general.default_common_local_dir || 'Gdrive';
            const remotePrefix = state.general.default_remote_prefix || '';
            if (els.volLocalParent) {
                els.volLocalParent.value = localCommon;
            }
            if (els.volRemotePath && !els.volRemotePath.value) {
                els.volRemotePath.placeholder = remotePrefix ? (remotePrefix + '/Volume_1') : 'NextCloud/Volume_1';
            }
            updateMountPreview();
        }

        function renderDatalists(groups, users) {
            if (els.groupsDatalist && Array.isArray(groups)) {
                els.groupsDatalist.innerHTML = '';
                groups.forEach((g) => {
                    const opt = document.createElement('option');
                    opt.value = g.gid;
                    opt.textContent = g.displayName || g.gid;
                    els.groupsDatalist.appendChild(opt);
                });
            }
            if (els.usersDatalist && Array.isArray(users)) {
                els.usersDatalist.innerHTML = '';
                users.forEach((u) => {
                    const opt = document.createElement('option');
                    opt.value = u.uid;
                    opt.textContent = u.displayName ? (u.uid + ' (' + u.displayName + ')') : u.uid;
                    els.usersDatalist.appendChild(opt);
                });
            }
        }

        function resetVolumeForm() {
            if (!els.volForm) {
                return;
            }
            els.volId.value = '';
            els.volName.value = '';
            els.volEnabled.checked = true;
            els.volRemotePath.value = '';
            els.volRemoteId.value = '';
            els.volAutoCreate.checked = true;
            els.volLocalSub.value = '';
            els.volQuota.value = '';
            els.volExport.value = 'office';
            els.volReadOnly.checked = false;
            els.volTrash.checked = true;
            if (els.volGroups) {
                els.volGroups.value = '';
            }
            if (els.volUsers) {
                els.volUsers.value = '';
            }
            els.volFormTitle.textContent = '3. Add Google Drive Volume';
            els.volSubmit.textContent = 'Add Volume';
            els.volCancel.classList.add('hidden');
            applyDefaultPathsToForm();
        }

        function loadVolumeIntoForm(vol) {
            els.volId.value = vol.id;
            els.volName.value = vol.name || '';
            els.volEnabled.checked = Boolean(vol.enabled);
            els.volRemotePath.value = vol.remote_path || '';
            els.volRemoteId.value = vol.remote_folder_id || '';
            els.volAutoCreate.checked = Boolean(vol.auto_create_remote);
            els.volLocalParent.value = vol.local_parent_dir || '';
            els.volLocalSub.value = vol.local_sub_dir || '';
            els.volQuota.value = quotaToInputString(vol.quota_bytes);
            els.volExport.value = vol.export_google_docs || 'office';
            els.volReadOnly.checked = Boolean(vol.read_only);
            els.volTrash.checked = Boolean(vol.trash_on_delete);
            if (els.volGroups) {
                els.volGroups.value = (vol.allowed_groups || []).join(', ');
            }
            if (els.volUsers) {
                els.volUsers.value = (vol.allowed_users || []).join(', ');
            }
            els.volFormTitle.textContent = '3. Edit Volume: ' + (vol.name || vol.local_sub_dir);
            els.volSubmit.textContent = 'Save Changes';
            els.volCancel.classList.remove('hidden');
            updateMountPreview();
            els.volForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function createBadge(text, cls) {
            const span = document.createElement('span');
            span.className = 'gdrive-badge ' + cls;
            span.textContent = text;
            return span;
        }

        function renderVolumes(volumes) {
            state.volumes = Array.isArray(volumes) ? volumes : [];
            if (!els.volumesList) {
                return;
            }
            els.volumesList.innerHTML = '';

            if (state.volumes.length === 0) {
                const p = document.createElement('p');
                p.className = 'gdrive-empty';
                p.textContent = 'No Google Drive volumes configured yet. Add one above (e.g. NextCloud/Volume_1 -> Gdrive/Volume_1).';
                els.volumesList.appendChild(p);
                return;
            }

            state.volumes.forEach((vol) => {
                const item = document.createElement('div');
                item.className = 'gdrive-volume-item';

                const info = document.createElement('div');
                info.className = 'gdrive-volume-info';

                const titleRow = document.createElement('div');
                titleRow.className = 'gdrive-volume-title-row';

                const strong = document.createElement('strong');
                strong.textContent = vol.name || vol.local_sub_dir;
                titleRow.appendChild(strong);

                titleRow.appendChild(createBadge(
                    vol.enabled ? 'Mounted' : 'Disabled',
                    vol.enabled ? 'gdrive-badge-success' : 'gdrive-badge-muted'
                ));
                titleRow.appendChild(createBadge(
                    vol.read_only ? 'Read-Only' : 'Read/Write',
                    vol.read_only ? 'gdrive-badge-warning' : 'gdrive-badge-muted'
                ));

                const meta1 = document.createElement('div');
                meta1.className = 'gdrive-volume-meta';
                meta1.append('Local path: ');
                const localCode = document.createElement('code');
                localCode.textContent = '/' + vol.local_mount_path;
                meta1.appendChild(localCode);
                meta1.append('  ←  Google Drive: ');
                const remoteCode = document.createElement('code');
                remoteCode.textContent = vol.remote_path || ('ID: ' + vol.remote_folder_id);
                meta1.appendChild(remoteCode);

                const meta2 = document.createElement('div');
                meta2.className = 'gdrive-volume-meta';
                const details = [
                    'Folder ID: ' + (vol.remote_folder_id || 'auto-resolve'),
                    'Quota: ' + formatBytes(vol.quota_bytes),
                    'Docs: ' + (vol.export_google_docs || 'office'),
                ];
                if (scope === 'admin') {
                    const groupsStr = (vol.allowed_groups || []).length ? vol.allowed_groups.join(', ') : 'all';
                    const usersStr = (vol.allowed_users || []).length ? vol.allowed_users.join(', ') : 'all';
                    if (groupsStr !== 'all' || usersStr !== 'all') {
                        details.push('Groups: ' + groupsStr + ' | Users: ' + usersStr);
                    } else {
                        details.push('Visible to: all users');
                    }
                }
                meta2.textContent = details.join(' · ');

                info.appendChild(titleRow);
                info.appendChild(meta1);
                info.appendChild(meta2);

                const actions = document.createElement('div');
                actions.className = 'gdrive-volume-actions';

                const testBtn = document.createElement('button');
                testBtn.type = 'button';
                testBtn.className = 'button';
                testBtn.textContent = 'Test';
                testBtn.addEventListener('click', () => handleTestVolume(vol, testBtn));

                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'button';
                editBtn.textContent = 'Edit';
                editBtn.addEventListener('click', () => loadVolumeIntoForm(vol));

                const toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'button';
                toggleBtn.textContent = vol.enabled ? 'Disable' : 'Enable';
                toggleBtn.addEventListener('click', () => handleToggleVolume(vol, toggleBtn));

                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'button gdrive-btn-danger';
                delBtn.textContent = 'Delete';
                delBtn.addEventListener('click', () => handleDeleteVolume(vol, delBtn));

                actions.appendChild(testBtn);
                actions.appendChild(editBtn);
                actions.appendChild(toggleBtn);
                actions.appendChild(delBtn);

                item.appendChild(info);
                item.appendChild(actions);
                els.volumesList.appendChild(item);
            });
        }

        async function loadState() {
            const data = await requestJson('GET', '/api/settings/' + scope);
            state.authorizeUrl = data.authorize_url || '';
            renderAccount(data.account || {});
            renderGeneral(data.general || {});
            renderDatalists(data.groups || [], data.users || []);
            renderVolumes(data.volumes || []);
        }

        async function handleTestVolume(vol, btn) {
            setButtonLoading(btn, true, 'Testing...');
            try {
                showBanner('Testing volume "' + vol.name + '"...', 'warning');
                showInlineFeedback(els.volumeFeedback, 'info', 'Testing volume "' + vol.name + '"...');
                const data = await requestJson('POST', '/api/volumes/' + scope + '/' + encodeURIComponent(vol.id) + '/test');
                const msg = 'Volume "' + vol.name + '" OK — Remote folder "' + data.folder.name + '" (' + data.folder.id + '), ' + data.children_count + ' item(s) at root.';
                showBanner(msg, 'success');
                showInlineFeedback(els.volumeFeedback, 'success', msg);
                await loadState();
            } catch (err) {
                showBanner(err.message, 'error');
                showInlineFeedback(els.volumeFeedback, 'error', err.message);
            } finally {
                setButtonLoading(btn, false);
            }
        }

        async function handleToggleVolume(vol, btn) {
            setButtonLoading(btn, true, 'Saving...');
            try {
                await requestJson('PUT', '/api/volumes/' + scope + '/' + encodeURIComponent(vol.id), {
                    enabled: !vol.enabled,
                });
                const msg = 'Volume "' + vol.name + '" ' + (!vol.enabled ? 'enabled' : 'disabled') + '.';
                showBanner(msg, 'success');
                showInlineFeedback(els.volumeFeedback, 'success', msg);
                await loadState();
            } catch (err) {
                showBanner(err.message, 'error');
                showInlineFeedback(els.volumeFeedback, 'error', err.message);
            } finally {
                setButtonLoading(btn, false);
            }
        }

        async function handleDeleteVolume(vol, btn) {
            if (!window.confirm('Delete volume "' + vol.name + '" mounted at /' + vol.local_mount_path + '? (Files inside Google Drive will NOT be deleted.)')) {
                return;
            }
            setButtonLoading(btn, true, 'Deleting...');
            try {
                await requestJson('DELETE', '/api/volumes/' + scope + '/' + encodeURIComponent(vol.id));
                if (els.volId.value === vol.id) {
                    resetVolumeForm();
                }
                const msg = 'Volume "' + vol.name + '" removed.';
                showBanner(msg, 'success');
                showInlineFeedback(els.volumeFeedback, 'success', msg);
                await loadState();
            } catch (err) {
                showBanner(err.message, 'error');
                showInlineFeedback(els.volumeFeedback, 'error', err.message);
            } finally {
                setButtonLoading(btn, false);
            }
        }

        function openBrowserModal() {
            state.browserStack = [{ id: 'root', name: 'My Drive', isDriveRoot: true }];
            els.modal.classList.remove('hidden');
            loadDriveFolderLevel('root');
        }

        function closeBrowserModal() {
            els.modal.classList.add('hidden');
        }

        function buildStackRemotePath() {
            return state.browserStack
                .filter((entry) => !entry.isDriveRoot && entry.id !== 'root')
                .map((entry) => entry.name)
                .join('/');
        }

        function renderBrowserBreadcrumbs() {
            els.modalBreadcrumbs.innerHTML = '';
            state.browserStack.forEach((crumb, index) => {
                if (index > 0) {
                    const sep = document.createElement('span');
                    sep.textContent = '/';
                    els.modalBreadcrumbs.appendChild(sep);
                }
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'gdrive-breadcrumb-btn';
                btn.textContent = crumb.name;
                btn.addEventListener('click', () => {
                    state.browserStack = state.browserStack.slice(0, index + 1);
                    loadDriveFolderLevel(crumb.id);
                });
                els.modalBreadcrumbs.appendChild(btn);
            });
        }

        async function loadDriveFolderLevel(parentId) {
            renderBrowserBreadcrumbs();
            els.modalBody.innerHTML = '<p class="gdrive-empty">Loading Google Drive folders...</p>';
            try {
                const data = await requestJson('GET', '/api/drive/' + scope + '/folders?parentId=' + encodeURIComponent(parentId));
                els.modalBody.innerHTML = '';

                if (parentId === 'root' && Array.isArray(data.sharedDrives) && data.sharedDrives.length > 0) {
                    data.sharedDrives.forEach((drive) => {
                        const row = createBrowserRow('[Shared Drive] ' + drive.name, drive.id, true);
                        els.modalBody.appendChild(row);
                    });
                }

                if (Array.isArray(data.folders) && data.folders.length > 0) {
                    data.folders.forEach((folder) => {
                        const row = createBrowserRow(folder.name, folder.id, false);
                        els.modalBody.appendChild(row);
                    });
                } else if (els.modalBody.children.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'gdrive-empty';
                    empty.textContent = 'No subfolders inside this directory.';
                    els.modalBody.appendChild(empty);
                }
            } catch (err) {
                els.modalBody.innerHTML = '';
                const p = document.createElement('p');
                p.className = 'gdrive-btn-danger';
                p.textContent = err.message;
                els.modalBody.appendChild(p);
            }
        }

        function createBrowserRow(label, folderId, isDriveRoot) {
            const row = document.createElement('div');
            row.className = 'gdrive-folder-row';

            const openBtn = document.createElement('button');
            openBtn.type = 'button';
            openBtn.className = 'gdrive-folder-open';
            openBtn.textContent = label;
            openBtn.addEventListener('click', () => {
                state.browserStack.push({ id: folderId, name: label.replace(/^\[Shared Drive\] /, ''), isDriveRoot: isDriveRoot });
                loadDriveFolderLevel(folderId);
            });

            const pickBtn = document.createElement('button');
            pickBtn.type = 'button';
            pickBtn.className = 'button';
            pickBtn.textContent = 'Select';
            pickBtn.addEventListener('click', () => {
                const cleanName = label.replace(/^\[Shared Drive\] /, '');
                const stackPath = buildStackRemotePath();
                const fullPath = isDriveRoot ? '' : (stackPath ? stackPath + '/' + cleanName : cleanName);
                applySelectedDriveFolder(folderId, fullPath, cleanName);
            });

            row.appendChild(openBtn);
            row.appendChild(pickBtn);
            return row;
        }

        function applySelectedDriveFolder(folderId, fullPath, folderName) {
            els.volRemoteId.value = folderId;
            if (fullPath) {
                els.volRemotePath.value = fullPath;
            }
            if (!els.volLocalSub.value && folderName && folderId !== 'root') {
                els.volLocalSub.value = folderName;
            }
            if (!els.volName.value && folderName && folderId !== 'root') {
                els.volName.value = folderName;
            }
            updateMountPreview();
            closeBrowserModal();
        }

        function bindEvents() {
            els.tabs.forEach((tab) => {
                tab.addEventListener('click', () => setAuthTab(tab.dataset.authTab));
            });

            if (els.copyRedirect) {
                els.copyRedirect.addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(els.redirectUri.value);
                        showBanner('Redirect URI copied to clipboard.', 'success');
                        showInlineFeedback(els.accountFeedback, 'info', 'Redirect URI copied to clipboard.');
                    } catch {
                        els.redirectUri.select();
                    }
                });
            }

            if (els.saveOAuth) {
                els.saveOAuth.addEventListener('click', async () => {
                    setButtonLoading(els.saveOAuth, true, 'Saving...');
                    try {
                        const data = await requestJson('POST', '/api/settings/' + scope + '/account', {
                            auth_type: 'oauth',
                            client_id: els.clientId.value.trim(),
                            client_secret: els.clientSecret.value.trim(),
                        });
                        renderAccount(data.account || {});
                        const msg = 'OAuth credentials saved. Click "Connect with Google Drive" to authorize.';
                        showBanner(msg, 'success');
                        showInlineFeedback(els.accountFeedback, 'success', msg);
                    } catch (err) {
                        showBanner(err.message, 'error');
                        showInlineFeedback(els.accountFeedback, 'error', err.message);
                    } finally {
                        setButtonLoading(els.saveOAuth, false);
                    }
                });
            }

            if (els.connectOAuth) {
                els.connectOAuth.addEventListener('click', async () => {
                    setButtonLoading(els.connectOAuth, true, 'Redirecting...');
                    try {
                        if (els.clientId.value.trim() && els.clientSecret.value.trim()) {
                            await requestJson('POST', '/api/settings/' + scope + '/account', {
                                auth_type: 'oauth',
                                client_id: els.clientId.value.trim(),
                                client_secret: els.clientSecret.value.trim(),
                            });
                        }
                        window.location.href = state.authorizeUrl || buildApiUrl('/oauth/' + scope + '/authorize');
                    } catch (err) {
                        setButtonLoading(els.connectOAuth, false);
                        showBanner(err.message, 'error');
                        showInlineFeedback(els.accountFeedback, 'error', err.message);
                    }
                });
            }

            if (els.saFile) {
                els.saFile.addEventListener('change', (evt) => {
                    const file = evt.target.files && evt.target.files[0];
                    if (!file) {
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = () => {
                        els.saJson.value = String(reader.result || '');
                    };
                    reader.readAsText(file);
                });
            }

            if (els.saveSA) {
                els.saveSA.addEventListener('click', async () => {
                    setButtonLoading(els.saveSA, true, 'Saving...');
                    try {
                        const data = await requestJson('POST', '/api/settings/' + scope + '/account', {
                            auth_type: 'service_account',
                            service_account_json: els.saJson.value.trim(),
                            impersonate_email: els.saImpersonate.value.trim(),
                        });
                        els.saJson.value = '';
                        renderAccount(data.account || {});
                        const msg = 'Service Account configuration saved.';
                        showBanner(msg, 'success');
                        showInlineFeedback(els.accountFeedback, 'success', msg);
                    } catch (err) {
                        showBanner(err.message, 'error');
                        showInlineFeedback(els.accountFeedback, 'error', err.message);
                    } finally {
                        setButtonLoading(els.saveSA, false);
                    }
                });
            }

            const handleTestAccount = async (btn) => {
                setButtonLoading(btn, true, 'Testing...');
                try {
                    showBanner('Testing Google Drive connection...', 'warning');
                    showInlineFeedback(els.accountFeedback, 'info', 'Testing Google Drive connection...');
                    const data = await requestJson('POST', '/api/settings/' + scope + '/test');
                    renderAccount(data.account || {});
                    const userEmail = (data.user && data.user.emailAddress) ? data.user.emailAddress : 'account';
                    showBanner('Connection verified for ' + userEmail + '.', 'success');
                    renderAccountTestSuccess(data);
                } catch (err) {
                    showBanner(err.message, 'error');
                    showInlineFeedback(els.accountFeedback, 'error', err.message);
                } finally {
                    setButtonLoading(btn, false);
                }
            };

            const handleDisconnectAccount = async (btn) => {
                if (!window.confirm('Disconnect the configured Google Drive credentials for this scope?')) {
                    return;
                }
                setButtonLoading(btn, true, 'Disconnecting...');
                try {
                    const data = await requestJson('DELETE', '/api/settings/' + scope + '/account');
                    renderAccount(data.account || {});
                    const msg = 'Google Drive account disconnected.';
                    showBanner(msg, 'success');
                    showInlineFeedback(els.accountFeedback, 'info', msg);
                } catch (err) {
                    showBanner(err.message, 'error');
                    showInlineFeedback(els.accountFeedback, 'error', err.message);
                } finally {
                    setButtonLoading(btn, false);
                }
            };

            if (els.testAccount) {
                els.testAccount.addEventListener('click', () => handleTestAccount(els.testAccount));
            }
            if (els.testSA) {
                els.testSA.addEventListener('click', () => handleTestAccount(els.testSA));
            }
            if (els.disconnectAccount) {
                els.disconnectAccount.addEventListener('click', () => handleDisconnectAccount(els.disconnectAccount));
            }
            if (els.disconnectSA) {
                els.disconnectSA.addEventListener('click', () => handleDisconnectAccount(els.disconnectSA));
            }

            if (els.saveGeneral) {
                els.saveGeneral.addEventListener('click', async () => {
                    setButtonLoading(els.saveGeneral, true, 'Saving...');
                    try {
                        const payload = {
                            default_common_local_dir: els.defaultLocalDir.value.trim(),
                            default_remote_prefix: els.defaultRemotePrefix.value.trim(),
                            cache_ttl: Number(els.cacheTtl.value) || 60,
                        };
                        if (els.allowPersonal) {
                            payload.allow_personal_mounts = els.allowPersonal.checked;
                        }
                        const data = await requestJson('POST', '/api/settings/' + scope + '/general', payload);
                        renderGeneral(data.general || {});
                        const msg = 'Default settings saved.';
                        showBanner(msg, 'success');
                        showInlineFeedback(els.generalFeedback, 'success', msg);
                    } catch (err) {
                        showBanner(err.message, 'error');
                        showInlineFeedback(els.generalFeedback, 'error', err.message);
                    } finally {
                        setButtonLoading(els.saveGeneral, false);
                    }
                });
            }

            if (els.clearCache) {
                els.clearCache.addEventListener('click', async () => {
                    setButtonLoading(els.clearCache, true, 'Clearing...');
                    try {
                        await requestJson('POST', '/api/cache/' + scope + '/clear');
                        const msg = 'Google Drive metadata cache cleared.';
                        showBanner(msg, 'success');
                        showInlineFeedback(els.generalFeedback, 'success', msg);
                    } catch (err) {
                        showBanner(err.message, 'error');
                        showInlineFeedback(els.generalFeedback, 'error', err.message);
                    } finally {
                        setButtonLoading(els.clearCache, false);
                    }
                });
            }

            if (els.volLocalParent) {
                els.volLocalParent.addEventListener('input', updateMountPreview);
            }
            if (els.volLocalSub) {
                els.volLocalSub.addEventListener('input', updateMountPreview);
            }
            if (els.volRemotePath) {
                els.volRemotePath.addEventListener('input', () => {
                    if (els.volRemoteId) {
                        els.volRemoteId.value = '';
                    }
                });
            }
            if (els.volCancel) {
                els.volCancel.addEventListener('click', resetVolumeForm);
            }
            if (els.refreshVolumes) {
                els.refreshVolumes.addEventListener('click', async () => {
                    setButtonLoading(els.refreshVolumes, true, 'Refreshing...');
                    try {
                        await loadState();
                    } catch (err) {
                        showBanner(err.message, 'error');
                    } finally {
                        setButtonLoading(els.refreshVolumes, false);
                    }
                });
            }

            if (els.volForm) {
                els.volForm.addEventListener('submit', async (evt) => {
                    evt.preventDefault();
                    setButtonLoading(els.volSubmit, true, 'Mounting...');
                    try {
                        const editingId = els.volId.value.trim();
                        const payload = {
                            name: els.volName.value.trim(),
                            enabled: els.volEnabled.checked,
                            remote_path: els.volRemotePath.value.trim(),
                            remote_folder_id: els.volRemoteId.value.trim(),
                            auto_create_remote: els.volAutoCreate.checked,
                            local_parent_dir: els.volLocalParent.value.trim(),
                            local_sub_dir: els.volLocalSub.value.trim(),
                            quota_bytes: els.volQuota.value.trim(),
                            export_google_docs: els.volExport.value,
                            read_only: els.volReadOnly.checked,
                            trash_on_delete: els.volTrash.checked,
                        };
                        if (els.volGroups) {
                            payload.allowed_groups = els.volGroups.value;
                        }
                        if (els.volUsers) {
                            payload.allowed_users = els.volUsers.value;
                        }

                        const data = editingId
                            ? await requestJson('PUT', '/api/volumes/' + scope + '/' + encodeURIComponent(editingId), payload)
                            : await requestJson('POST', '/api/volumes/' + scope, payload);

                        resetVolumeForm();
                        if (data.warning) {
                            const warnMsg = 'Volume saved, but remote check reported: ' + data.warning;
                            showBanner(warnMsg, 'warning');
                            showInlineFeedback(els.volumeFeedback, 'warning', warnMsg);
                        } else {
                            const okMsg = 'Volume "' + data.volume.name + '" mounted at /' + data.volume.local_mount_path + '. Open Nextcloud Files to access it.';
                            showBanner(okMsg, 'success');
                            showInlineFeedback(els.volumeFeedback, 'success', okMsg);
                        }
                        await loadState();
                    } catch (err) {
                        showBanner(err.message, 'error');
                        showInlineFeedback(els.volumeFeedback, 'error', err.message);
                    } finally {
                        setButtonLoading(els.volSubmit, false);
                    }
                });
            }

            if (els.browseDriveBtn) {
                els.browseDriveBtn.addEventListener('click', openBrowserModal);
            }
            if (els.modalClose) {
                els.modalClose.addEventListener('click', closeBrowserModal);
            }
            if (els.modalCancel) {
                els.modalCancel.addEventListener('click', closeBrowserModal);
            }
            if (els.modalSelectCurrent) {
                els.modalSelectCurrent.addEventListener('click', () => {
                    const current = state.browserStack[state.browserStack.length - 1];
                    const fullPath = buildStackRemotePath();
                    applySelectedDriveFolder(current.id, fullPath, current.name);
                });
            }
        }

        if (scope === 'personal' && !personalAllowed) {
            return true;
        }

        checkOAuthCallbackQuery();
        bindEvents();
        loadState().catch((err) => {
            showBanner(err.message, 'error');
            showInlineFeedback(els.accountFeedback, 'error', err.message);
        });

        return true;
    }

    function bootWithRetry(attempt) {
        if (initApp()) {
            return;
        }
        if (attempt < 30) {
            window.setTimeout(() => bootWithRetry(attempt + 1), 100);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootWithRetry(0));
    } else {
        bootWithRetry(0);
    }
})();
