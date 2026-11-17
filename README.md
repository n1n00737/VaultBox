# VaultBox

VaultBox is a self-hosted file manager served by a single PHP file. It provides an authenticated browser interface for uploading, browsing, previewing, organizing, and downloading files from a server-side storage directory.

## Features

- Password-protected access with session expiration, CSRF protection, and per-IP sign-in throttling.
- Grid and list views, filtering, sorting, multi-selection, and keyboard shortcuts.
- File and folder uploads, including folder trees, drag-and-drop, and resumable chunked transfers.
- Create folders, rename, delete, and move files and folders.
- In-browser previews for images, video, audio, PDF, and text files where the browser supports them.
- Image thumbnails and optional JPEG, PNG, and WebP compression using PHP GD.
- Optional public links for sharing individual files without requiring recipients to sign in.
- Responsive layout with light and dark color schemes.

## Requirements

- PHP 7.0 or later; PHP 8.x is recommended.
- A web server configured to run PHP, or PHP's built-in web server for local testing.
- Write access for the PHP process to the storage directory.
- The GD extension is optional. Without it, file management still works, but image thumbnails and compression are unavailable.
- WebP encoding requires a GD build with WebP support. Lossless WebP additionally requires PHP 8.1 or later and the corresponding GD support.
- EXIF support is optional and is used to correct JPEG orientation.
- A modern browser with JavaScript enabled.

## Installation

1. Copy `index.php` to a directory served by your PHP-enabled web server.
2. Choose a storage directory. The default is `storage/` next to `index.php`; the application creates it if it does not exist.
3. Ensure the PHP process can create and modify files in the storage directory.
4. Configure a password as described below before exposing the application to other users.
5. Open the URL for `index.php` in a browser and sign in.

For local testing, run the PHP development server from the directory containing `index.php`:

```sh
php -S 127.0.0.1:8000
```

Then open <http://127.0.0.1:8000/>. PHP's built-in server is for development and testing, not production deployment.

## Initial password setup

The `$CONFIG` array is at the top of `index.php`. Set `password_hash` to an empty string:

```php
'password_hash' => '',
```

Reload the page and submit a password of at least eight characters. The setup page displays a password hash. Copy that value into `password_hash` in `index.php`, replacing the empty value, and reload the page to sign in.

Do not leave `password_hash` empty on a publicly reachable server. The hash currently present in a downloaded copy of the script may not be a password you control; replace it with a hash you generated yourself.

## Configuration

Edit the `$CONFIG` array near the top of `index.php`. Paths may be absolute or relative to the script directory as shown in the default configuration.

| Setting | Default | Description |
| --- | --- | --- |
| `root` | `__DIR__ . '/storage'` | Directory containing managed files. Keep this outside the web root where possible. |
| `password_hash` | Set in the script | Password hash used for sign-in. Generate one through the initial setup flow. |
| `title` | `My Storage` | Name shown in the browser and application header. |
| `max_file_mb` | `0` | Maximum size per file in MiB. `0` disables the application-level limit; PHP and server limits still apply. |
| `public_links` | `true` | Enables or disables public file links. |
| `share_secret` | `''` | Secret used to sign fallback public links. Set a long, random value to keep those links valid when the password changes. If empty, the key is derived from `password_hash`. |
| `public_base_url` | `''` | Optional base path or URL for direct public file links, such as `/storage` or `https://cdn.example.com/storage`. Leave empty to detect a web-accessible storage directory automatically or use signed links when it is outside the web root. |
| `clean_names` | `true` | Converts names on upload to URL-friendly names: accents are transliterated, runs of unsupported characters become hyphens, and extensions are lowercased. |
| `session_hours` | `12` | Sign-in session lifetime in hours. |
| `max_attempts` | `5` | Failed password attempts from an IP address before a temporary lockout. |
| `lock_minutes` | `10` | Temporary sign-in lockout duration. |
| `thumb_dir` | System temporary directory | Directory used for thumbnail and statistics caches. |
| `allowed_ext` | List in the script | Upload extension allowlist. An empty list allows any extension not in `blocked_ext`. |
| `blocked_ext` | List in the script | Extensions that are always rejected, including common executable and server-side script formats. |

The default allowlist includes common image, video, audio, document, and archive formats. Review and adjust both extension lists to match your needs. The application validates file extensions; it is not an antivirus or content-inspection system.

### Upload size limits

Chunked uploads are capped at 8 MiB per request, but the effective chunk size is also determined by PHP's `upload_max_filesize` and `post_max_size`. For large files:

- Set `max_file_mb` to the desired per-file application limit, or leave it at `0`.
- Set PHP's `upload_max_filesize` and `post_max_size` high enough for the upload chunks and request overhead.
- Check any reverse proxy or web-server request-body limits.
- Confirm that the storage filesystem has sufficient free space.

An application limit of `0` does not bypass PHP, proxy, disk, or filesystem limits.

## Using the application

- Click **Upload** to select files or **Folder** to select a directory tree.
- Drop files or folders onto the page to upload them into the open directory.
- Click a folder to open it. On desktop, click to select and double-click to open; on touch devices, tap to open.
- Use the search field to filter the current directory. Sorting and grid/list preferences are saved in the browser.
- Select files or folders to download, rename, delete, move by dragging to a destination folder, or copy public links for files.
- Open supported files in the viewer. Use the arrow keys or on-screen controls to move between previewable items.
- Compress supported images from the selection toolbar or viewer. Compression creates a new `-min` file next to the original and does not replace the original.

Keyboard shortcuts:

| Shortcut | Action |
| --- | --- |
| `/` | Focus the filter field |
| `Ctrl+A` / `Cmd+A` | Select visible items |
| `Enter` | Open the selected item |
| `F2` | Rename the selected item |
| `Delete` | Delete the selected item |
| `Escape` | Clear the selection or close the viewer |
| `Shift` + click | Select a range |
| `Ctrl` / `Cmd` + click | Add or remove an item from the selection |

## Public links

Public links are disabled when `public_links` is false or no signing key is available. If `root` is reachable from the web, VaultBox may create a direct URL to the file. Setting `public_base_url` also forces direct-link generation, for example when files are served from a CDN. Otherwise, it creates a signed URL served by `index.php`.

Anyone with a public link can access that file without signing in. Direct URLs are ordinary web-server URLs and do not use VaultBox's sign-in or signature checks. For signed links, access is based on a signature rather than an expiration time; changing `share_secret` invalidates existing signed links. Keep `root` outside the web root if files should not be directly accessible, and set `public_base_url` only when direct public access is intended.

## Security and operations

- Serve the application over HTTPS, especially when it is reachable outside a trusted local network.
- Use a unique password and a long random `share_secret`; do not commit private deployment configuration or credentials.
- Keep the storage directory outside the web root unless direct web access to its contents is intended.
- The app hides dot-prefixed items in listings and rejects dot-prefixed names through its file-management operations.
- Protect backups of both the storage directory and `index.php`. The application does not provide backups, quotas, antivirus scanning, or user accounts.
- Run PHP with only the filesystem permissions needed for the application. Review web-server and PHP logs when uploads fail.
- Configure PHP and your web server to reject request sizes and execution times appropriate to your deployment.

## Troubleshooting

- **Cannot create or modify files:** Verify that the PHP process has write access to `root` and that the filesystem is not full.
- **Uploads fail or cannot resume:** Check `upload_max_filesize`, `post_max_size`, proxy body limits, and available disk space. A stale partial upload may be removed from its target directory after 24 hours.
- **Images have no thumbnails or compression controls:** Install or enable PHP GD. WebP options also require GD WebP support.
- **Public links are unavailable:** Enable `public_links` and ensure either `password_hash` or `share_secret` is configured.
- **Sign-in is temporarily blocked:** Wait for `lock_minutes` to elapse, then try again.
- **The setup page appears again:** Check that the generated hash was saved in the active copy of `index.php` and that PHP can read that file.

## License

No license file is included in this repository. Contact the repository owner for licensing terms before redistributing or using VaultBox in a context that requires explicit permission.
