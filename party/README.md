# Party Photo Gallery

A mobile-first party photo gallery. Guests scan a link, tap a big button, take a photo, and submit it. An admin reviews and approves photos before they appear in the public gallery. UD

---

## Quick-start checklist

- [ ] Upload the `party/` folder to your server (e.g. via cPanel File Manager or FTP)
- [ ] Set folder permissions (see below)
- [ ] Create the MySQL database and import `schema.sql`
- [ ] Edit `config.php` with your credentials
- [ ] Run `generate-password.php` and paste the hash into `config.php`
- [ ] Delete `generate-password.php`
- [ ] Visit `https://yourdomain.com/party/` to test the guest page
- [ ] Visit `https://yourdomain.com/party/admin/` to log in

---

## Folder permission requirements

Set these permissions via cPanel File Manager or `chmod`:

| Path | Permission | Notes |
|---|---|---|
| `party/` (and all subdirs) | `755` | Standard directory permission |
| All `.php` files | `644` | Standard file permission |
| `party/quarantine/` | `755` + **writable** | PHP writes uploaded files here |
| `party/gallery/` | `755` + **writable** | PHP moves approved files here |
| `party/gallery/thumbs/` | `755` + **writable** | PHP writes thumbnails here |
| `party/data/` | `755` + **writable** | Only needed if `USE_DATABASE = false` |

**cPanel shortcut:** In File Manager, right-click a folder → Change Permissions → tick "Recurse into subdirectories" for the `755` pass, then manually set `quarantine/`, `gallery/`, `gallery/thumbs/` and `data/` to writable.

---

## Database setup (MySQL / MariaDB)

1. In cPanel → MySQL Databases: create a database, a user, and grant the user **all privileges** on that database.
2. Import the schema:
   ```
   mysql -u your_user -p your_database < schema.sql
   ```
   Or in phpMyAdmin: select the database → Import tab → upload `schema.sql`.
3. Fill in `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` in `config.php`.

### Flat-file fallback (no MySQL)

Set `USE_DATABASE = false` in `config.php`. Photo metadata is stored as JSON in `party/data/`. This mode is suitable for low-traffic events but is slower under concurrent load.

---

## Setting QUARANTINE_DIR and GALLERY_DIR

### Option A — Inside public_html (default, simplest)

The defaults in `config.php` point to `party/quarantine/` and `party/gallery/` inside the webroot. The `.htaccess` files in those directories block direct HTTP access and PHP execution.

No changes required.

### Option B — Outside public_html (recommended for maximum security)

Move the directories outside the webroot so the web server cannot serve them at all. Only the PHP process needs access.

```php
// config.php
define('QUARANTINE_DIR', '/home/yourusername/party_quarantine');
define('GALLERY_DIR',    '/home/yourusername/party_gallery');
define('THUMBS_DIR',     '/home/yourusername/party_gallery/thumbs');
```

Then create the directories and set permissions:
```bash
mkdir -p ~/party_quarantine ~/party_gallery/thumbs
chmod 755 ~/party_quarantine ~/party_gallery ~/party_gallery/thumbs
```

> **Note:** If `GALLERY_DIR` is outside the webroot, `gallery.php` needs to serve images through PHP rather than relying on direct URL access. In that case, create a simple `serve-image.php` that reads the file with `readfile()` after validating the UUID, and update the paths returned by `gallery.php`. The default setup keeps `gallery/` inside the webroot for simplicity.

---

## Setting your admin password

1. Upload the full `party/` folder to your server.
2. Visit `https://yourdomain.com/party/generate-password.php` in a browser, or run:
   ```bash
   php generate-password.php
   ```
3. Copy the generated hash into `config.php`:
   ```php
   define('ADMIN_PASSWORD_HASH', '$2y$12$...');
   ```
4. **Delete `generate-password.php`** from your server. The script attempts to self-delete, but verify manually.

---

## Apache configuration

The included `.htaccess` files handle Apache automatically. Ensure `mod_rewrite` and `mod_headers` are enabled (they are on virtually all cPanel shared hosts).

If you get 500 errors from the `.htaccess` files, your host may use an older Apache syntax. Replace `Require all denied` with:

```apache
Order deny,allow
Deny from all
```

And `Require all granted` with:

```apache
Order allow,deny
Allow from all
```

---

## Nginx configuration

If you are on Nginx (VPS/dedicated), `.htaccess` files are ignored. Add the following blocks to your server block (adjust paths as needed):

```nginx
server {
    listen 443 ssl;
    server_name yourdomain.com;
    root /home/yourusername/public_html;
    index index.php;

    # Block direct access to quarantine
    location ^~ /party/quarantine/ {
        deny all;
    }

    # Block PHP execution in gallery
    location ~* /party/gallery/.*\.php$ {
        deny all;
    }

    # Block data directory
    location ^~ /party/data/ {
        deny all;
    }

    # Block includes directory
    location ^~ /party/includes/ {
        deny all;
    }

    # Block config and schema from direct access
    location ~* /party/(config|schema)\.php$ {
        deny all;
    }

    # PHP-FPM handler
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Gallery images — serve statically
    location ^~ /party/gallery/ {
        try_files $uri =404;
        # Allow only image extensions
        location ~* \.(jpg|jpeg|png|gif|webp|heic|avif)$ {
            expires 7d;
            add_header Cache-Control "public";
        }
        # Deny anything else in this directory
        location ~ . {
            deny all;
        }
    }
}
```

---

## HEIC / iPhone photos

iPhone cameras save photos as HEIC by default. The server validates and accepts these. During moderation approval:

- **With `php-imagick` installed:** HEIC is auto-converted to JPEG, EXIF is stripped, and the image is resized. This is the recommended path.
- **Without Imagick:** GD is used for JPEG/PNG/WebP. HEIC files are copied unchanged (GD cannot process them). Install `php-imagick` on your server for full HEIC support.

To check if Imagick is available on cPanel:
- cPanel → Software → Select PHP Version → Extensions → look for `imagick`

---

## Email notifications

Set `NOTIFY_EMAIL` in `config.php` to your address to receive an alert on every upload. Uses PHP's built-in `mail()` function. Make sure your host has outbound mail configured (most shared hosts do via cPanel → Email).

Set to an empty string `''` to disable.

---

## Rate limiting

Default: 20 uploads per IP per 24-hour rolling window. Adjust `RATE_LIMIT_UPLOADS` and `RATE_LIMIT_WINDOW_HOURS` in `config.php`.

Rate limit records are stored in the `upload_attempts` MySQL table (or `data/rate_limits.json` in flat-file mode). Old records are pruned automatically during checks but you can also purge them manually:

```sql
DELETE FROM upload_attempts WHERE attempted_at < NOW() - INTERVAL 48 HOUR;
```

---

## Security notes

| Feature | Implementation |
|---|---|
| File validation | Magic bytes (not MIME type or extension) |
| Filenames | Server-generated UUID — client name discarded |
| CSRF | Session token in every form and AJAX call |
| Admin auth | `password_verify()` against bcrypt hash (cost 12) |
| Session expiry | 2 hours inactivity (configurable) |
| EXIF stripping | Imagick `stripImage()` or GD re-encode |
| Directory listing | Disabled via `Options -Indexes` |
| Script execution in uploads | Blocked by `.htaccess` in quarantine/ and gallery/ |
| CSP | `Content-Security-Policy` header on every page |

---

## File structure

```
party/
  index.php              Guest upload + public gallery
  upload.php             POST handler (returns JSON)
  gallery.php            Approved photo JSON endpoint
  config.php             All configuration — edit before deploy
  generate-password.php  One-time password hash tool — delete after use
  schema.sql             MySQL table definitions
  .htaccess              Root rules
  includes/
    db.php               DB/JSON abstraction layer
    image.php            Magic bytes, EXIF strip, resize, thumbnail
    .htaccess            Block direct access
  admin/
    index.php            Login + dashboard + moderation queue
    moderate.php         AJAX approve / reject handler
    .htaccess            Restrict to index.php and moderate.php only
  quarantine/
    .htaccess            Deny all HTTP access
  gallery/
    .htaccess            Allow images, deny everything else
    thumbs/
      .htaccess          Same as gallery/
  assets/
    style.css            Mobile-first festive styles
    app.js               Camera flow, upload, gallery, lightbox
  data/
    .htaccess            Deny all (flat-file JSON storage)
README.md
```
