#!/bin/bash
# Install SuiteCRM 8.10.x inside Ubuntu (WSL2) on Windows 11.
# Invoked by Install-SuiteCRM-Windows11.ps1 as root. Not for native Windows PHP.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
export APT_LISTCHANGES_FRONTEND=none

LOG=/var/log/suitecrm-install.log
mkdir -p /var/log
exec > >(tee -a "$LOG") 2>&1

echo "=== SuiteCRM setup started $(date -Is) ==="

ENV_FILE=${ENV_FILE:-/root/suitecrm-setup.env}
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

VERSION=${VERSION:-8.10.2}
SITE_URL=${SITE_URL:-http://localhost/}
ADMIN_USER=${ADMIN_USER:-admin}
DB_NAME=${DB_NAME:-suitecrm}
DB_USER=${DB_USER:-suitecrm}
HTTP_PORT=${HTTP_PORT:-80}
INSTALL_DIR=${INSTALL_DIR:-/var/www/suitecrm}
LINUX_USER=${LINUX_USER:-crmadmin}
DOWNLOAD_URL=${DOWNLOAD_URL:-https://github.com/SuiteCRM/SuiteCRM-Core/releases/download/v${VERSION}/SuiteCRM-${VERSION}.zip}

SITE_URL="${SITE_URL%/}/"

rand() {
  # head closes the pipe early; do not let SIGPIPE abort the script.
  openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c "$1" || true
}

# SuiteCRM's default policy expects upper, lower, and a digit.
strong_pw() {
  local body
  body=$(rand 16)
  printf 'Aa9%s' "$body"
}

CREDS=/root/suitecrm-credentials.txt
PRESERVE_CREDS=0
if [[ -f "$INSTALL_DIR/public/legacy/config.php" && -f "$CREDS" ]]; then
  PRESERVE_CREDS=1
  set -a
  # shellcheck disable=SC1090
  source "$CREDS"
  set +a
  echo "Existing installation found. Keeping passwords already stored in ${CREDS}."
fi

if [[ "$PRESERVE_CREDS" -eq 0 ]]; then
  if ! command -v openssl >/dev/null 2>&1; then
    apt-get update -y
    apt-get install -y openssl ca-certificates
  fi
  [[ -n "${ADMIN_PASSWORD:-}" ]] || ADMIN_PASSWORD=$(strong_pw)
  [[ -n "${DB_PASSWORD:-}" ]] || DB_PASSWORD=$(strong_pw)
  [[ -n "${LINUX_PASSWORD:-}" ]] || LINUX_PASSWORD=$(strong_pw)
fi
DEMO_USER_PASSWORD=${DEMO_USER_PASSWORD:-ArgDemo-ChangeMe-810}

if ! id "$LINUX_USER" >/dev/null 2>&1; then
  useradd -m -s /bin/bash -G sudo "$LINUX_USER"
fi
echo "${LINUX_USER}:${LINUX_PASSWORD}" | chpasswd

mkdir -p /etc
cat > /etc/wsl.conf <<EOF
[boot]
systemd=true

[user]
default=${LINUX_USER}

[network]
generateHosts=true
generateResolvConf=true
EOF

avail_kb=$(df -Pk / | awk 'NR==2 {print $4}')
if [[ "${avail_kb}" -lt 3000000 ]]; then
  echo "ERROR: Need at least 3 GB free inside the Linux filesystem (have ${avail_kb} KB)."
  exit 1
fi

echo "Updating apt and installing Apache, MariaDB, and PHP..."
apt-get update -y
if ! apt-cache show php8.3 >/dev/null 2>&1; then
  apt-get install -y software-properties-common ca-certificates
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi

APT_OPTS=(-y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
apt-get install "${APT_OPTS[@]}" \
  apache2 mariadb-server unzip curl ca-certificates openssl \
  php8.3 php8.3-cli php8.3-curl php8.3-intl php8.3-gd php8.3-mbstring \
  php8.3-mysql php8.3-xml php8.3-zip php8.3-soap php8.3-bcmath \
  libapache2-mod-php8.3

# imap and ldap are optional in the SuiteCRM 8 matrix.
apt-get install "${APT_OPTS[@]}" php8.3-imap php8.3-ldap || echo "Optional PHP imap/ldap packages were skipped."

PHP_INI_SET() {
  local ini="$1" key="$2" value="$3"
  if grep -qE "^[;[:space:]]*${key}[[:space:]]*=" "$ini"; then
    sed -i -E "s|^[;[:space:]]*${key}[[:space:]]*=.*|${key} = ${value}|" "$ini"
  else
    echo "${key} = ${value}" >> "$ini"
  fi
}

for ini in /etc/php/8.3/apache2/php.ini /etc/php/8.3/cli/php.ini; do
  [[ -f "$ini" ]] || continue
  PHP_INI_SET "$ini" memory_limit 512M
  PHP_INI_SET "$ini" upload_max_filesize 64M
  PHP_INI_SET "$ini" post_max_size 64M
  PHP_INI_SET "$ini" max_execution_time 300
  PHP_INI_SET "$ini" max_input_time 300
  PHP_INI_SET "$ini" max_input_vars 5000
  PHP_INI_SET "$ini" date.timezone America/New_York
  PHP_INI_SET "$ini" display_errors Off
  PHP_INI_SET "$ini" log_errors On
  # SuiteCRM treats notices as failures during install if they are included here.
  sed -i -E 's|^error_reporting = .*|error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT \& ~E_NOTICE \& ~E_WARNING|' "$ini"
done

a2enmod rewrite >/dev/null
a2enmod php8.3 >/dev/null || true
echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null || true

if [[ "$HTTP_PORT" != "80" ]]; then
  grep -qE "^Listen ${HTTP_PORT}$" /etc/apache2/ports.conf || echo "Listen ${HTTP_PORT}" >> /etc/apache2/ports.conf
fi

start_one() {
  local name
  for name in "$@"; do
    if service "$name" start; then
      return 0
    fi
    if systemctl start "$name"; then
      return 0
    fi
  done
  return 1
}

echo "Starting MariaDB..."
start_one mariadb mysql
systemctl enable mariadb >/dev/null 2>&1 || systemctl enable mysql >/dev/null 2>&1 || true

# Wait until the server accepts connections.
for _ in $(seq 1 30); do
  if mysqladmin --protocol=socket ping --silent; then
    break
  fi
  sleep 1
done
mysqladmin --protocol=socket ping --silent

mysql --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Keep MariaDB on the Linux loopback interface only.
MYSQLD_CNF=/etc/mysql/mariadb.conf.d/99-suitecrm-bind.cnf
mkdir -p /etc/mysql/mariadb.conf.d
cat > "$MYSQLD_CNF" <<'EOF'
[mysqld]
bind-address = 127.0.0.1
skip-networking = 0
EOF
start_one mariadb mysql || true

install_app() {
  echo "Downloading ${DOWNLOAD_URL}"
  curl -fL --retry 5 --retry-delay 2 -A 'ARG-SuiteCRM-Setup' -o /tmp/SuiteCRM.zip "$DOWNLOAD_URL"
  rm -rf /tmp/suitecrm-extract
  mkdir -p /tmp/suitecrm-extract
  unzip -q /tmp/SuiteCRM.zip -d /tmp/suitecrm-extract

  local src=""
  if [[ -d /tmp/suitecrm-extract/public && -f /tmp/suitecrm-extract/bin/console ]]; then
    src=/tmp/suitecrm-extract
  else
    local pub
    pub=$(find /tmp/suitecrm-extract -mindepth 2 -maxdepth 3 -type d -name public | head -n 1 || true)
    if [[ -n "$pub" ]]; then
      src=$(dirname "$pub")
    fi
  fi
  if [[ -z "$src" || ! -f "${src}/bin/console" ]]; then
    echo "ERROR: Downloaded archive does not look like the SuiteCRM install package."
    find /tmp/suitecrm-extract -maxdepth 3 -type d | head -n 50
    exit 1
  fi

  rm -rf "$INSTALL_DIR"
  mkdir -p "$(dirname "$INSTALL_DIR")"
  mv "$src" "$INSTALL_DIR"
  rm -rf /tmp/suitecrm-extract /tmp/SuiteCRM.zip

  chown -R www-data:www-data "$INSTALL_DIR"
  find "$INSTALL_DIR" -type d -exec chmod 2775 {} \;
  find "$INSTALL_DIR" -type f -exec chmod 0664 {} \;
  chmod 0755 "$INSTALL_DIR/bin/console"

  local -a extra=()
  local help
  help=$(runuser -u www-data -- php "$INSTALL_DIR/bin/console" suitecrm:app:install --help || true)
  if grep -q -- '--sys-check-option' <<<"$help"; then
    extra+=(--sys-check-option true)
  fi

  echo "Running the SuiteCRM CLI installer (this can take several minutes)..."
  (
    cd "$INSTALL_DIR"
    runuser -u www-data -- php bin/console suitecrm:app:install \
      -u "$ADMIN_USER" \
      -p "$ADMIN_PASSWORD" \
      -U "$DB_USER" \
      -P "$DB_PASSWORD" \
      -H "127.0.0.1" \
      -N "$DB_NAME" \
      --db-port "3306" \
      -S "$SITE_URL" \
      -d "no" \
      "${extra[@]}"
  )

  if [[ -f "$INSTALL_DIR/public/legacy/.htaccess" ]]; then
    sed -i -E 's|^[[:space:]]*RewriteBase[[:space:]]+.*|    RewriteBase /legacy|' "$INSTALL_DIR/public/legacy/.htaccess" || true
  fi

  cat > "$INSTALL_DIR/public/legacy/config_override.php" <<'PHP'
<?php
$sugar_config['system_name'] = 'ARG';
$sugar_config['default_currency_iso4217'] = 'USD';
$sugar_config['default_currency_name'] = 'US Dollars';
$sugar_config['default_currency_symbol'] = '$';
$sugar_config['default_date_format'] = 'm/d/Y';
$sugar_config['default_time_format'] = 'h:ia';
$sugar_config['default_locale_name_format'] = 's f l';
$sugar_config['default_export_charset'] = 'UTF-8';
PHP
  chown www-data:www-data "$INSTALL_DIR/public/legacy/config_override.php"
  chmod 0664 "$INSTALL_DIR/public/legacy/config_override.php"

  # Installer creates new files; keep them writable by Apache.
  chown -R www-data:www-data "$INSTALL_DIR"
  find "$INSTALL_DIR" -type d -exec chmod 2775 {} \;
  find "$INSTALL_DIR" -type f -exec chmod 0664 {} \;
  chmod 0755 "$INSTALL_DIR/bin/console"
  echo "$VERSION" > "$INSTALL_DIR/.install-complete"
}

if [[ -f "$INSTALL_DIR/public/legacy/config.php" && -f "$INSTALL_DIR/bin/console" ]]; then
  echo "SuiteCRM is already installed at ${INSTALL_DIR}. Skipping download and installer."
  echo "Delete ${INSTALL_DIR} and the ${DB_NAME} database first if you want a clean reinstall."
else
  install_app
fi

cat > /etc/apache2/sites-available/suitecrm.conf <<EOF
<VirtualHost *:${HTTP_PORT}>
    ServerName localhost
    DocumentRoot ${INSTALL_DIR}/public

    <Directory ${INSTALL_DIR}/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/suitecrm-error.log
    CustomLog \${APACHE_LOG_DIR}/suitecrm-access.log combined
</VirtualHost>
EOF

a2dissite 000-default >/dev/null 2>&1 || true
a2ensite suitecrm >/dev/null
systemctl enable apache2 >/dev/null 2>&1 || true
systemctl enable cron >/dev/null 2>&1 || true

# Simple cron fallback. Windows Task Scheduler is also registered by the PowerShell script,
# because that is what SuiteCRM documents for Windows and it does not depend on systemd.
cat > /etc/cron.d/suitecrm <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www-data php ${INSTALL_DIR}/bin/console schedulers:run >> /var/log/suitecrm-cron.log 2>&1
* * * * * www-data php ${INSTALL_DIR}/bin/console messenger:consume internal-async --time-limit=55 --memory-limit=256M >> /var/log/suitecrm-messenger.log 2>&1
EOF
chmod 0644 /etc/cron.d/suitecrm
touch /var/log/suitecrm-cron.log /var/log/suitecrm-messenger.log
chown www-data:www-data /var/log/suitecrm-cron.log /var/log/suitecrm-messenger.log || true

start_one apache2
start_one cron || true

CREDS=/root/suitecrm-credentials.txt
if [[ "$PRESERVE_CREDS" -eq 0 ]]; then
  cat > "$CREDS" <<EOF
SUITECRM_VERSION=${VERSION}
SITE_URL=${SITE_URL}
HTTP_PORT=${HTTP_PORT}
ADMIN_USER=${ADMIN_USER}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
DEMO_USER_PASSWORD=${DEMO_USER_PASSWORD}
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
INSTALL_DIR=${INSTALL_DIR}
LINUX_USER=${LINUX_USER}
LINUX_PASSWORD=${LINUX_PASSWORD}
DISTRO=${DISTRO:-Ubuntu-24.04}
EOF
  chmod 600 "$CREDS"
else
  chmod 600 "$CREDS" || true
fi

echo "Probing http://127.0.0.1:${HTTP_PORT}/ ..."
code="000"
for _ in $(seq 1 20); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT}/" || true)
  case "$code" in
    200|301|302|303) break ;;
  esac
  sleep 1
done
echo "HTTP probe status: ${code}"
if [[ "$code" != "200" && "$code" != "301" && "$code" != "302" && "$code" != "303" ]]; then
  echo "ERROR: SuiteCRM did not answer on port ${HTTP_PORT}."
  echo "----- Apache error log (tail) -----"
  tail -n 80 /var/log/apache2/suitecrm-error.log 2>/dev/null || true
  echo "----- SuiteCRM log (tail) -----"
  tail -n 80 "$INSTALL_DIR/public/legacy/suitecrm.log" 2>/dev/null || true
  tail -n 40 "$INSTALL_DIR/logs/prod.log" 2>/dev/null || true
  exit 1
fi

echo "=== SuiteCRM setup finished $(date -Is) ==="
echo "Site: ${SITE_URL}"
echo "Credentials: ${CREDS}"
