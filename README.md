# WP Nextcloud Bridge

A WordPress plugin that connects your WordPress site directly to Nextcloud via WebDAV. Browse, upload, download, share, and sync files — all without leaving WordPress.

## Features

- **File Browser** — Browse your Nextcloud files from the WordPress admin or any page/post via shortcode `[nextcloud]`
- **Upload from Browser** — Drag & drop or click to upload. Large files are automatically chunked (3-step WebDAV chunked upload protocol)
- **Download Proxy** — Download files through WordPress without exposing Nextcloud credentials
- **Public Share Links** — Generate Nextcloud public share links with one click, auto-copied to clipboard
- **Folder Management** — Create and delete folders directly from the browser UI
- **Directory Sync** — Sync specific WordPress directories to Nextcloud on demand (WP → NC, additive only)
- **Shortcode** — Embed the file browser anywhere with `[nextcloud]`
- **Document List** — Show a folder's files as a linked `<ul>`/`<ol>` with `[nextcloud-list]`
- **Diagnostics** — Built-in diagnostic page for troubleshooting connection issues

## Requirements

- WordPress 5.8+
- PHP 7.4+
- cURL extension enabled
- Nextcloud server with WebDAV access

## Installation

1. Download the [latest release](../../releases/latest) ZIP file
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate
4. Go to **Settings → Nextcloud Bridge** to configure

## Configuration

### Connection Settings

| Field | Description |
|-------|-------------|
| **Nextcloud URL** | Full URL without trailing slash, e.g. `https://cloud.example.com` |
| **Username** | Your Nextcloud username |
| **Password** | An [App Password](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html#managing-devices) is recommended |

### Paths & Sync

| Field | Description |
|-------|-------------|
| **Nextcloud Root Folder** | Base folder on Nextcloud (default: `/WordPress`). Created automatically if missing |
| **Max Sync File Size** | Files larger than this are skipped during sync (default: 2048 MB) |
| **Directories to Sync** | Pairs of local WordPress paths and remote Nextcloud paths |

### Trusted Domain

If your WordPress server accesses Nextcloud via an internal IP (e.g. `172.x.x.x`), you must add it to Nextcloud's `trusted_domains` in `config/config.php`:

```php
'trusted_domains' => [
    0 => 'cloud.example.com',
    1 => '172.21.0.1:5050',  // your internal address
],
```

## Usage

### Shortcode

Embed the file browser on any page or post:

```
[nextcloud]
[nextcloud path="Documents"]
[nextcloud path="Photos" upload="true" delete="true"]
[nextcloud upload="false" create_folder="false"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `path` | `""` (root) | Initial folder to display |
| `upload` | `true` | Allow file uploads |
| `create_folder` | `true` | Allow creating new folders |
| `delete` | `false` | Allow deleting files/folders |

> **Note:** Administrators (`manage_options`) get the full browser. Everyone else, including logged-out visitors, gets a **read-only** browser (browse + download only) restricted to the shortcode's `path`. A shortcode without `path` is shown to administrators only, so the whole Nextcloud account is never exposed publicly.

### Document List Shortcode

Render the files of a folder as a plain HTML list (`<ul>` or `<ol>`) with download links — no JavaScript required:

```
[nextcloud-list path="Documents"]
[nextcloud-list path="Documents" type="ol"]
[nextcloud-list path="Documents" extensions="pdf,docx" show_size="true"]
[nextcloud-list path="Reports" orderby="modified" order="desc" limit="5"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `path` | `""` (root, admins only) | Folder whose files are listed (sub-folders are skipped) |
| `type` | `ul` | `ul` (unordered) or `ol` (ordered) |
| `orderby` | `name` | `name`, `modified` or `size` |
| `order` | `asc` | `asc` or `desc` |
| `limit` | `0` | Maximum number of files (`0` = all) |
| `extensions` | `""` | Comma-separated whitelist, e.g. `pdf,docx` |
| `show_size` | `false` | Append the file size to each entry |
| `class` | `""` | Extra CSS classes for the list element |
| `start` | `""` | `ol` only: starting number |
| `reversed` | `false` | `ol` only: count down |

Links use the download proxy with tokens scoped to `path` that expire after one hour — exclude pages using this shortcode from full-page caching. Rendering lives in `NC_List` (abstract) with `NC_Unordered_List` / `NC_Ordered_List` subclasses; further list types can be added via the `wpnc_list_types` filter:

```php
add_filter( 'wpnc_list_types', function ( $types ) {
    $types['dl'] = My_Definition_List::class; // extends WPNC\NC_List
    return $types;
} );
```

### Admin Pages

- **Settings → Nextcloud Bridge** — Connection settings, test connection, file browser, manual sync
- **Settings → NC Diagnostics** — Detailed HTTP request/response debugging

### File Browser

- Click folder names to navigate into them
- Use the breadcrumb bar to jump to any parent folder
- **⬆️ Upload** button or drag & drop files onto the browser area
- **⬇️** Download files through the proxy (credentials stay server-side)
- **🔗** Generate a public share link (auto-copied to clipboard)
- **🗑️** Delete files or folders (when `delete="true"`)
- **📁+** Create new folders

### Large File Upload

Files larger than the PHP chunk size are automatically uploaded using Nextcloud's chunked upload protocol:

1. `MKCOL` — Create a temporary directory under `/uploads/{user}/{uuid}/`
2. `PUT` — Upload each 10 MB chunk sequentially with progress tracking
3. `MOVE` — Assemble `.file` into the final destination path

The browser shows a per-file progress bar with percentage and chunk counter.

### Directory Sync

Configure directory pairs in the settings page to sync local WordPress directories to Nextcloud:

| Local Path | Remote Path | Result |
|------------|-------------|--------|
| `wp-content/uploads` | `uploads` | Syncs to `{root}/uploads/` on NC |
| `wp-content/themes` | `themes` | Syncs to `{root}/themes/` on NC |

Sync is **additive only** — it uploads new or modified files but never deletes remote files. Comparison uses file existence → size → modification time.

## Architecture

```
wp-nextcloud-bridge/
├── wp-nextcloud-bridge.php           # Entry point + PSR-4 autoloader
├── inc/
│   ├── class-nextcloud-client.php    # WebDAV/OCS client (pure I/O, no side-effects)
│   ├── class-nc-settings.php         # WordPress Settings API page
│   ├── class-nc-ajax.php             # AJAX endpoint router (browse/upload/sync/share)
│   ├── class-nc-sync.php             # WP→NC incremental sync engine
│   ├── class-nc-shortcode.php        # [nextcloud] shortcode
│   ├── class-nc-list-shortcode.php   # [nextcloud-list] shortcode
│   ├── class-nc-list.php             # Abstract list renderer
│   ├── class-nc-unordered-list.php   # <ul> renderer
│   ├── class-nc-ordered-list.php     # <ol> renderer
│   └── class-nc-diagnostics.php      # Diagnostic test page
├── assets/
│   ├── js/
│   │   ├── admin.js                  # Admin settings page JS
│   │   └── front.js                  # Frontend file browser (vanilla JS, no jQuery)
│   └── css/
│       ├── admin.css                 # Admin styles
│       └── front.css                 # Frontend styles (DM Sans + JetBrains Mono)
└── README.md
```

### Design Principles

- **Single responsibility** — Each class does one thing. The WebDAV client is pure I/O with no WordPress side-effects.
- **WordPress-native HTTP** — Uses `wp_remote_request()` with an `http_api_curl` filter workaround for WebDAV methods (PROPFIND, MKCOL, etc.) where WordPress's `WP_Http_Curl` transport silently drops the request body.
- **No external dependencies** — No Composer, no SDK, no jQuery on the frontend. Just PHP + vanilla JS.
- **Namespace isolation** — All PHP classes live under the `WPNC\` namespace with a simple autoloader.
- **Security by default** — Every AJAX handler verifies nonce + capability. Download proxy supports HMAC-signed tokens for time-limited public access.

## WebDAV Methods Used

| Operation | HTTP Method | Endpoint |
|-----------|-------------|----------|
| List folder | `PROPFIND Depth:1` | `/remote.php/dav/files/{user}/` |
| File/folder info | `PROPFIND Depth:0` | `/remote.php/dav/files/{user}/{path}` |
| Create folder | `MKCOL` | `/remote.php/dav/files/{user}/{path}` |
| Upload file | `PUT` | `/remote.php/dav/files/{user}/{path}` |
| Download file | `GET` | `/remote.php/dav/files/{user}/{path}` |
| Delete | `DELETE` | `/remote.php/dav/files/{user}/{path}` |
| Move/rename | `MOVE` | `/remote.php/dav/files/{user}/{path}` |
| Chunked upload (init) | `MKCOL` | `/remote.php/dav/uploads/{user}/{uuid}/` |
| Chunked upload (chunk) | `PUT` | `/remote.php/dav/uploads/{user}/{uuid}/{range}` |
| Chunked upload (assemble) | `MOVE` | `.file → /dav/files/{user}/{dest}` |
| Get shares | `GET` | `/ocs/v2.php/apps/files_sharing/api/v1/shares` |
| Create share | `POST` | `/ocs/v2.php/apps/files_sharing/api/v1/shares` |
| Test connection | `PROPFIND Depth:0` | `/remote.php/dav/files/{user}/` |

## Troubleshooting

### HTTP 400 on all requests

Most likely a **Trusted Domain** error. Check **Settings → NC Diagnostics → Test 3** — if it shows `"Trusted domain error"`, add your WordPress server's address to Nextcloud's `trusted_domains` config.

### HTTP 401 / 403

Authentication failed. Verify username and password. Using an **App Password** (Nextcloud → Settings → Security → Devices & sessions) is recommended.

### HTTP 404

The WebDAV endpoint was not found. Check that the Nextcloud URL is correct and does not include a trailing slash or path suffix.

### Uploads fail for large files

1. Check PHP's `upload_max_filesize` and `post_max_size` in `php.ini` — these limit individual chunk size
2. Check `max_execution_time` — chunked uploads may need more time
3. Check the **Max Sync File Size** setting in the plugin

### Diagnostics page

Go to **Settings → NC Diagnostics** for detailed request/response output including:
- `wp_remote_request()` test with `http_api_curl` filter status
- Direct cURL test (bypasses WordPress HTTP API)
- Nextcloud `status.php` connectivity check
- PHP version, cURL version, SSL info, WordPress HTTP transport type

## License

GPL-2.0-or-later
