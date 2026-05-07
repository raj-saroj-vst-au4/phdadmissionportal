# SJMSOM PhD Admissions Portal — Installation

End-to-end setup for a fresh box. All paths assume the project lives at
`/srv/www/phdportal`. Replace as needed.

## 1. System packages

Apache + PHP for the web app, MySQL for persistence, Python for the Excel
extractors and the OMR scanner, plus a few binaries the scanner shells out to.

```bash
sudo apt-get update
sudo apt-get install -y \
    apache2 \
    php php-cli php-mysql php-mbstring php-xml php-curl php-gd php-zip \
    mysql-server \
    python3 python3-venv python3-pip \
    poppler-utils \
    libgl1 \
    composer
```

- `poppler-utils` provides `pdftoppm`, used by the OMR scanner to rasterize PDFs.
- `libgl1` provides `libGL.so.1`, which `opencv-python-headless` dlopens at
  runtime even on headless servers.

Enable Apache's PHP module if not already on:

```bash
sudo a2enmod php8.3 rewrite
sudo systemctl restart apache2
```

## 2. Database

Create the DB and user, then load the schema and apply migrations in order.

```bash
sudo mysql <<'SQL'
CREATE DATABASE phdadmissions CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'phdadmin'@'localhost' IDENTIFIED BY 'phdadmin@2026';
GRANT ALL PRIVILEGES ON phdadmissions.* TO 'phdadmin'@'localhost';
FLUSH PRIVILEGES;
SQL

cd /srv/www/phdportal
mysql -u phdadmin -p'phdadmin@2026' phdadmissions < schema.sql

# Apply migrations in chronological order
for m in scripts/migration_rename_shortlist_status.sql \
         scripts/migration_add_user_email.sql \
         scripts/migration_add_nationality.sql \
         scripts/migration_ra_to_ta.sql \
         scripts/migration_add_requires_scribe.sql \
         scripts/migration_repanel_2026.sql \
         scripts/migration_add_omr.sql \
         scripts/migration_omr_confidence.sql; do
    echo ">> $m"
    mysql -u phdadmin -p'phdadmin@2026' phdadmissions < "$m"
done
```

## 3. Application config

Copy the example config and fill in real credentials:

```bash
cp src/config-example.php src/config.php
$EDITOR src/config.php   # set DB creds, SMTP creds, APP_BASE
```

Key values to verify:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `APP_BASE` (URL prefix, e.g. `/phdportal` or `''` for domain root)
- `SMTP_*` (used by the email-communication module)
- `PYTHON_BIN` — system Python, used by `extract_excel.py` and `extract_marks.py`
- `OMR_PYTHON_BIN` — auto-resolves to `.venv-omr/bin/python` if it exists,
  otherwise falls back to `PYTHON_BIN`. Don't edit this; bootstrap the venv
  in step 5 instead.

## 4. PHP dependencies + admin/panel seed users

```bash
composer install --no-dev
php scripts/install.php
```

`install.php` prints the seeded credentials. The default admin is
`phdcoord / Phd@2026` — change the password from the UI immediately.

## 5. OMR scanner environment

The OMR scanner needs `opencv-python-headless`, `numpy`, and `pdf2image`,
which aren't in the system Python. Don't `pip install --user` these — Apache
runs as `www-data` and won't be able to traverse a developer's home dir.
Use the bootstrap script which creates a project-local venv readable by
`www-data`:

```bash
bash scripts/install_omr.sh
```

What that script does (also documented inline):

1. Installs missing apt packages (`poppler-utils`, `libgl1`, `python3-venv`).
2. Creates `.venv-omr/` if absent and installs the scanner deps into it.
3. `chmod -R o+rX .venv-omr` so Apache can exec the Python binary and load
   the .so files.
4. Smoke-tests the venv with `import cv2, numpy`.

To verify from PHP that the wiring is correct:

```bash
php -r "require '/srv/www/phdportal/src/config.php';
echo OMR_PYTHON_BIN.PHP_EOL;
echo trim(shell_exec(escapeshellcmd(OMR_PYTHON_BIN).' -c \"import cv2; print(cv2.__version__)\"'));"
```

Should print the venv path followed by the cv2 version.

## 6. Filesystem permissions

The web user needs to write to `public/uploads/` (Excel sheets, photos,
candidate-application PDFs, scanned OMR PDFs).

```bash
sudo chown -R www-data:www-data public/uploads
sudo chmod -R u+rwX,g+rwX public/uploads
```

The `.venv-omr/` directory does NOT need to be writable by `www-data` — the
bootstrap script already widened it to `o+rX` (read-only execute), which is
what the scanner needs.

## 7. Apache vhost

A minimal vhost — adjust `ServerName`, `DocumentRoot`, and TLS as needed:

```apache
<VirtualHost *:80>
    ServerName admissions.example.iitb.ac.in

    Alias /phdportal /srv/www/phdportal/public
    <Directory /srv/www/phdportal/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # Block direct access to the venv, scripts, and src directories
    <Directory /srv/www/phdportal/.venv-omr>
        Require all denied
    </Directory>
    <Directory /srv/www/phdportal/scripts>
        Require all denied
    </Directory>
    <Directory /srv/www/phdportal/src>
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/phdportal_error.log
    CustomLog ${APACHE_LOG_DIR}/phdportal_access.log combined
</VirtualHost>
```

PHP's default `upload_max_filesize=2M` is fine — the chunked uploaders for
applications/photos/OMR PDFs already split files into 1.5 MB chunks. If you
need to override anywhere:

```bash
sudo $EDITOR /etc/php/8.3/apache2/php.ini
# upload_max_filesize = 2M
# post_max_size = 8M
```

```bash
sudo systemctl reload apache2
```

## 8. Sanity checks

```bash
# 1. Page reachability (302 to login expected when unauthenticated)
curl -sS -o /dev/null -w "%{http_code} %{redirect_url}\n" \
    http://localhost/phdportal/admin/omr.php

# 2. OMR scanner regression (synthesizes + scans a test OMR end-to-end)
.venv-omr/bin/python /tmp/test_omr_e2e.py 2>/dev/null || \
    echo "(test_omr_e2e.py is dev-only; ignore if missing)"
```

Login at `http://<host>/phdportal/login.php` with the seeded admin credentials.

---

## Common upgrade paths

### After `git pull`
```bash
composer install --no-dev
# Apply any new migrations under scripts/
# Refresh OMR venv (no-op if nothing changed)
bash scripts/install_omr.sh
```

### Adding new Python deps to the OMR scanner
Edit the `pip install` line in [scripts/install_omr.sh](scripts/install_omr.sh)
and re-run it. Don't manually `pip install` outside the venv.

### Resetting the OMR venv
```bash
rm -rf /srv/www/phdportal/.venv-omr
bash /srv/www/phdportal/scripts/install_omr.sh
```

## Troubleshooting

**"Scanner produced no result. Output: ... ModuleNotFoundError: No module named 'cv2'"**
The web server is invoking the system Python instead of the venv. Check:
```bash
php -r "require '/srv/www/phdportal/src/config.php'; echo OMR_PYTHON_BIN;"
```
If it prints `/usr/bin/python3`, the venv doesn't exist or isn't executable.
Re-run `bash scripts/install_omr.sh`.

**"fiducials not found" on every page**
Either the scanned PDF was generated outside this portal (different layout),
or the scan resolution is too low. The scanner expects the OMR sheets
generated by `public/admin/omr.php` (Download Blank OMR / Per-Candidate OMRs)
and ≥150 DPI scans.

**QR not detected on a few pages but the rest work**
Those rows will show the amber `REVIEW` highlight in the results table.
Open the linked scanned-sheet image to spot-check, then either re-scan the
physical page at higher quality or correct the answers manually via
`admin/marks.php` after pushing.
