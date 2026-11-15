# Google Drive Volumes (`gdrive_volumes`)

Mount specific Google Drive folders as independent Nextcloud volumes (for example, `NextCloud/Volume_1` -> `/Gdrive/Volume_1`, `NextCloud/Volume_2` -> `/Gdrive/Volume_2`) with zero local storage overhead, metadata caching, virtual disk quotas, and Google Workspace document export.

## Features

- **Per-folder volume mounting**: Mount any Google Drive folder (by remote path such as `NextCloud/Volume_1` or by Google Drive folder ID) at any path inside Nextcloud's user files (default parent directory: `Gdrive`).
- **Admin & Personal scopes**:
  - **Admin volumes**: Configured globally in *Administration settings > Google Drive Volumes* and assigned to all users or specific users/groups.
  - **Personal volumes**: Configured per user in *Personal settings > Google Drive Volumes* (when enabled by the administrator). Personal volumes take precedence if they target the same local mount path as an admin volume.
- **Zero external Composer dependencies**: Pure PHP implementation (`>= 8.2`, tested up to `8.5`) using Nextcloud's built-in HTTP client, database, caching, and filesystem APIs.
- **Authentication options**:
  - **OAuth 2.0 (Client ID + Client Secret)** with automatic refresh token renewal.
  - **Service Account (JSON)** using RS256 JWT signing (`openssl`) and optional Google Workspace domain-wide delegation (impersonation email).
- **Google Docs / Sheets / Slides export**:
  - `office`: Exports to `.docx`, `.xlsx`, `.pptx` (and `.svg` for Drawings).
  - `pdf`: Exports Google Workspace files as `.pdf`.
  - `none`: Skips native Google Workspace documents.
- **Performance & safety**:
  - Configurable metadata cache TTL (`5` to `86400` seconds, default `60`).
  - Per-volume **read-only** mode and **virtual quota** (`K`, `M`, `G`, `T` suffixes supported).
  - Configurable delete behavior (**Move to Google Drive Trash** vs. permanent delete).
  - Automatic `appinfo/info.xml` metadata sync (`filesystem` app type and `installed_version`) on boot so WebDAV (`remote.php/dav`) and `git` updates work seamlessly.
- **Full `occ` CLI support**: List, mount, remove, and warm/sync volume caches from the command line.

---

## Requirements

- **Nextcloud**: `30` – `35`
- **PHP**: `8.2` – `8.5`
- **PHP extensions**: `openssl` (required for Service Account RS256 JWT signing) and `curl` / `json` (standard in Nextcloud)
- **Google Cloud project** with the **Google Drive API** enabled

---

## Installation

1. Clone the repository into your Nextcloud `custom_apps` directory:

   ```bash
   cd /var/www/nextcloud/custom_apps
   git clone https://github.com/krofinski/nc-gdrive-volumes.git gdrive_volumes
   ```

2. Ensure the web server user owns the directory:

   ```bash
   chown -R www-data:www-data /var/www/nextcloud/custom_apps/gdrive_volumes
   ```

3. Enable the app in Nextcloud:

   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable gdrive_volumes
   ```

> **Note for Docker / containerized Nextcloud (e.g. LinuxServer.io):**  
> Run `occ` commands using your container's `occ` wrapper (for example `docker exec -it nextcloud occ app:enable gdrive_volumes`) and place the app in `/config/www/nextcloud/custom_apps/gdrive_volumes` (or your mapped `custom_apps` path).

---

## Updating via Git

When pulling updates from GitHub:

```bash
cd /var/www/nextcloud/custom_apps/gdrive_volumes
git fetch origin
git reset --hard origin/main
sudo -u www-data php /var/www/nextcloud/occ gdrive-volumes:sync-cache
```

`gdrive_volumes` automatically synchronizes its `types` (`filesystem`) and `installed_version` entries in `oc_appconfig` on boot, so updating via `git` does not trigger Nextcloud's upgrade-blocked `503` state and ensures WebDAV endpoints always load the storage backend.

---

## Google Cloud Setup

You can connect using **OAuth 2.0** (recommended for personal Google accounts) or a **Service Account** (recommended for Google Workspace / Shared Drives).

### Option A: OAuth 2.0 (Google Auth Platform)

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and create or select a project.
2. Go to **APIs & Services > Library**, search for **Google Drive API**, and click **Enable**.
3. Open **Google Auth platform** (formerly *OAuth consent screen*):
   - **Overview / Branding**: Enter your **App name**, **User support email**, and **Developer contact information**. If your Nextcloud uses a custom domain, add it under **Authorized domains** if prompted.
   - **Audience**: Choose **Internal** (Google Workspace only) or **External**.
     - If you choose **External**, the app starts in **Testing** mode:
       - Either add your Google account(s) under **Audience > Test users** *(note: refresh tokens issued in Testing mode expire after ~7 days)*, **or**
       - Click **Publish App** under **Audience** once all required Branding/Contact fields are filled so tokens do not expire weekly.
   - **Data Access (Scopes)**: Click **Add or remove scopes** and add:
     ```text
     https://www.googleapis.com/auth/drive
     ```
     *(If it does not appear in the table, paste it into the "Manually add scopes" box and click **Add to table**, then **Save**.)*
4. Go to **Clients > Create client** (or **APIs & Services > Credentials > Create credentials > OAuth client ID**):
   - Application type: **Web application**.
   - Under **Authorized redirect URIs**, add the exact **Redirect URI** shown in Nextcloud under **Settings > Google Drive Volumes** (for example: `https://your-nextcloud.tld/index.php/apps/gdrive_volumes/oauth/callback`, or without `/index.php` if pretty URLs are configured).
   - Click **Create** and copy the **Client ID** and **Client Secret**.
5. In Nextcloud, open **Settings > Google Drive Volumes**:
   - Select **OAuth 2.0** as the authentication method.
   - Paste the **Client ID** and **Client Secret** and click **Save Credentials**.
   - Click **Connect with Google Drive**, approve access in Google, and verify the connection status turns green.

### Option B: Service Account (JSON)

1. In [Google Cloud Console](https://console.cloud.google.com/), enable **Google Drive API**.
2. Go to **IAM & Admin > Service Accounts > Create Service Account**.
3. Open the created service account, go to **Keys > Add key > Create new key > JSON**, and download the `.json` key file.
4. **Share your Google Drive folder(s)** (or Shared Drive) with the service account's `client_email` (found inside the JSON key), granting **Editor** (or **Viewer** for read-only volumes).
   - *Optional (Google Workspace)*: Enable **Domain-wide Delegation** on the service account and enter the **Impersonation email** in Nextcloud if you want API calls to act on behalf of a Workspace user.
5. In Nextcloud (**Settings > Google Drive Volumes**), choose **Service Account (JSON)**, paste the JSON key contents, and click **Save Credentials**.

---

## Usage & Configuration

1. Open **Administration settings > Google Drive Volumes** (or **Personal settings > Google Drive Volumes** if enabled for users).
2. Configure global settings as needed:
   - **Default local directory**: Parent folder inside Nextcloud Files (default: `Gdrive`).
   - **Default remote prefix**: Base folder in Google Drive (default: `NextCloud`).
   - **Metadata cache TTL**: Seconds to cache folder listings and file metadata (`5`–`86400`, default: `60`).
   - **Delete behavior**: Move deleted files to Google Drive Trash (enabled by default) or delete permanently.
   - **Allow personal volumes**: Allow non-admin users to connect their own Google Drive account and mount personal volumes.
3. Add a volume (example):
   - **Name**: `Volume 1`
   - **Remote path**: `NextCloud/Volume_1` *(or a specific Google Drive Folder ID)*
   - **Local subfolder**: `Volume_1` *(mounted at `/Gdrive/Volume_1`)*
   - **Auto-create remote folder**: Automatically creates `NextCloud/Volume_1` in Google Drive if it does not exist.
   - **Export format**: `office`, `pdf`, or `none`.
   - **Read-only** / **Quota**: Optional per-volume restrictions (e.g. `50G`).
   - **Assign to users / groups**: Leave empty for all users, or restrict to specific Nextcloud users or groups.
4. Click **Save Volume** (or **Sync & Warm Cache**).

---

## OCC Commands

All commands are run as the Nextcloud web user (`www-data`, or via your container's `occ` wrapper):

### List configured volumes

```bash
php occ gdrive-volumes:list
php occ gdrive-volumes:list --scope=admin
php occ gdrive-volumes:list --scope=personal --user=alice
```

### Mount a new volume

```bash
php occ gdrive-volumes:mount \
  --name="Volume 1" \
  --remote-path="NextCloud/Volume_1" \
  --local-sub="Volume_1" \
  --local-parent="Gdrive" \
  --auto-create \
  --export="office"
```

Useful options:
- `--scope=admin|personal` (default: `admin`)
- `--owner=<user>` (required when `--scope=personal`)
- `--remote-id=<folderId>` (alternative to `--remote-path`)
- `--read-only`
- `--quota=50G`
- `--users=alice,bob` / `--groups=team`

### Sync & warm volume cache

Invalidates metadata caches, verifies/resolves the remote Google Drive folder, ensures the local parent folder exists, reconciles `oc_mounts`, and warms the root directory listing:

```bash
php occ gdrive-volumes:sync-cache
php occ gdrive-volumes:sync-cache --volume=<volume-id>
```

### Remove a volume

```bash
php occ gdrive-volumes:remove <volume-id>
```

---

## Troubleshooting

- **Do not run recursive `occ files:scan` on large Google Drive mounts**:  
  Running `occ files:scan --path="/<user>/files/Gdrive"` without `--shallow` crawls every nested subfolder via the Google Drive API and can appear to hang on large trees. Use `--shallow` instead, or run `gdrive-volumes:sync-cache`:
  ```bash
  php occ gdrive-volumes:sync-cache
  php occ files:scan --shallow -v --path="/<user>/files/Gdrive"
  ```
- **`CSRF check failed` during OAuth callback**:  
  Make sure your Nextcloud URL in the browser matches `overwrite.cli.url` / `trusted_domains` in `config/config.php` and the **Authorized redirect URI** in Google Cloud Console (including `https://` and whether `/index.php` is present).
- **`Failed resolving root folder ... Invalid JSON response`**:  
  Check that **Google Drive API** is enabled in your Google Cloud project, your OAuth token / Service Account has not expired or been revoked, and the target folder exists (or **Auto-create remote folder** is enabled).
- **Volumes visible in Settings but empty in Nextcloud Files**:  
  Run `php occ gdrive-volumes:sync-cache` to verify the mount registration (`[OK] ... mounted at /Gdrive/... (N items in root)`), ensure the app has `<types><filesystem/></types>` (handled automatically in v1.0.5+), and check **Administration settings > Logging** for `gdrive_volumes` messages.

---

## License

AGPL-3.0-or-later
