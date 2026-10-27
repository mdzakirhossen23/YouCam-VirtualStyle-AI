# YouCam VirtualStyle AI — cPanel deployment

This package is a drop-in replacement for `virtualstyle.baharsoftbd.com`. It keeps the five languages, five dark themes, sidebar, centered 1200×700 normal mode, and YouCam Clothes V4 flow while adding authenticated private storage and version history.

## Before deployment

1. Revoke the API key that appeared in the earlier uploaded configuration file and create a replacement in the YouCam console. Never reuse or publish the old key.
2. In cPanel, open **MultiPHP Manager** and select PHP 7.4 or newer (PHP 8.1+ recommended).
3. Ensure the PHP extensions `curl`, `json`, `session`, `fileinfo` or image functions, and preferably `zip` are enabled. The app can use HTTPS streams if cURL is unavailable; ZIP export falls back to a JSON manifest if ZipArchive is unavailable.
4. Back up the current domain folder before replacing it.

## Install in cPanel

1. Open File Manager and go to the document root for `virtualstyle.baharsoftbd.com`.
2. Upload the ZIP and extract it into the document root. The document root should directly contain `index.html`, `api.php`, `bootstrap.php`, `config.php`, `.htaccess`, `assets/`, and `storage/`.
3. Set permissions:
   - directories: `755` (use `700` for `storage` if the host permits it)
   - ordinary files: `644`
   - `config.php`: `600` or `640`
4. The app automatically reuses an existing `/home/ACCOUNT/youcam-config.php` key stored outside the web root. For a new/rotated key, prefer the `YOUCAM_API_KEY` environment variable in cPanel/CloudLinux. If neither is available, edit only the empty fallback in `config.php` on the server. Never place the key in HTML or JavaScript.
5. Visit `https://virtualstyle.baharsoftbd.com/api.php?action=health`. Confirm `ok`, `storage_writable`, and `youcam_configured` are `true`. Do not share a screenshot containing sensitive hosting details.
6. Visit the site, create a test account, upload one person photo and one garment photo, run one try-on, download the result, choose **Try again**, and confirm the new version appears in History.
7. In **cPanel → Cron Jobs**, schedule the following once per day, replacing the path with your account path: `php /home/CPANEL_USER/public_html/virtualstyle.baharsoftbd.com/cron_cleanup.php`. The script is CLI-only and cannot be run from the web.

## Updating an existing installation

- Preserve the existing `storage/` folder when deploying later code updates, or users will lose their private galleries.
- Preserve the server's `config.php`/environment secret. The supplied file intentionally contains no API key.
- To disable public registration after accounts are created, set `allow_registration` to `false` in `config.php`.

## Privacy and retention

- Every image is stored under a random per-user directory and is served only through an authenticated PHP endpoint.
- Apache rules block direct access to `storage/`, `config.php`, and `bootstrap.php`.
- Active assets are retained for 90 days by default. A user deletion hides the item immediately; deleted items are purged after 7 days. Cleanup runs on account access and through the recommended daily cPanel cron. Change these values in `config.php` if your privacy policy requires a different period.
- The export button downloads all active images plus a JSON manifest when ZipArchive is available.
- The app sends a person image and garment image to Perfect Corp/YouCam only when the signed-in user presses **Generate try-on**. YouCam usage may consume API credits.

## Smoke test performed

- PHP syntax: passed (`bootstrap.php`, `api.php`)
- JavaScript syntax: passed (`assets/app.js`)
- Local HTTP health check: passed
- Register/login/session isolation: passed
- Unauthenticated library access: blocked with HTTP 401
- Genuine PNG validation, private save, authenticated retrieval, and delete: passed
- 1200×700 layout, sidebar, icons, and English/Bangla switching: visually verified
- Live YouCam generation: not executed because the delivery intentionally contains no secret and a real request may consume paid credits

## Server compatibility notes

- Required: PHP sessions, JSON, `getimagesize`, HTTPS outbound requests, and a writable `storage/` directory.
- Recommended: PHP cURL and ZipArchive.
- The YouCam implementation follows the V4 sequence: create file slot → upload to signed URL → create `/s2s/v2.0/task/cloth-v4` task → poll → save the returned image locally.
