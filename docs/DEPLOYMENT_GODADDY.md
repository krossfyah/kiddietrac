# Kiddietrac — GoDaddy Deployment Playbook

This guide walks you from "I just bought GoDaddy hosting" to "Kiddietrac is live and accepting users." Read it once end-to-end before starting anything.

---

## 0. Decision: VPS or shared hosting?

| | Shared hosting | **VPS (recommended)** |
|---|---|---|
| Cost | $7–15/mo | $25–40/mo |
| PHP version control | No (locked) | Yes |
| SSH access | Limited | Full root |
| Composer / artisan | Awkward | Native |
| Queue workers | Cron-based only | systemd / supervisor |
| Scaling | Hard ceiling | Resize anytime |
| Verdict | Fine for MVP only | **Pick this** |

**Recommendation:** Buy a GoDaddy VPS with at least **2 CPU / 4 GB RAM / 80 GB SSD** running **Ubuntu 22.04 LTS**. Add the cPanel/WHM license — it makes admin much easier even though we'll mostly use the command line.

If your budget is truly tight, **Deluxe Linux Web Hosting** can run the backend as a Laravel app — but you'll hit walls on queue workers and SSH that will slow you down.

---

## 1. Buy the things you need

Order in this sequence so DNS propagates while you set up:

1. **Domain** — `kiddietrac.ca` (you may already have this)
2. **GoDaddy VPS** — Ubuntu 22.04, 2 CPU, 4 GB RAM, 80 GB SSD
3. **SSL certificate** — Either:
   - GoDaddy's Managed SSL (~$80/yr per domain), OR
   - Free **Let's Encrypt** via certbot (we'll set this up below)
4. **Optional but recommended** Add-ons:
   - **Pusher** account (https://pusher.com) — free tier OK to start, $49/mo at scale (real-time messaging, live timeline updates)
   - **Firebase project** (https://console.firebase.google.com) — free (Android push notifications)
   - **Postmark** or **SendGrid** account — for transactional email (GoDaddy's SMTP is unreliable at scale)
   - **Stripe** account (Canadian entity) — for parent payment processing
   - **Anthropic API key** (https://console.anthropic.com) — for AI digests (~$30/mo for ~100 children)

---

## 2. Initial VPS setup

SSH in as root (GoDaddy emails you credentials):

```bash
ssh root@your-vps-ip
```

### 2.1 Create a non-root user

```bash
adduser kt
usermod -aG sudo kt
rsync --archive --chown=kt:kt ~/.ssh /home/kt
exit
ssh kt@your-vps-ip
```

### 2.2 Install the LEMP stack

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 + extensions Laravel needs
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
    php8.3-xml php8.3-zip php8.3-curl php8.3-gd php8.3-bcmath php8.3-intl \
    php8.3-imagick php8.3-redis

# MySQL 8
sudo apt install -y mysql-server
sudo mysql_secure_installation
# Answer: VALIDATE PASSWORD = no (you'll set strong passwords yourself)
#         root password = set a strong one — save it in your password manager
#         remove anonymous = yes
#         disallow root remote = yes
#         remove test db = yes
#         reload privilege tables = yes

# Nginx
sudo apt install -y nginx

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js (for asset building if needed)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Git
sudo apt install -y git unzip
```

### 2.3 Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

---

## 3. Database setup

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE kiddietrac CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kt_app'@'localhost' IDENTIFIED BY 'GENERATE_A_STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON kiddietrac.* TO 'kt_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Import the schema:

```bash
# Upload schema.sql first via scp or paste it on the server
mysql -u kt_app -p kiddietrac < ~/kiddietrac/database/schema.sql
```

Verify:

```bash
mysql -u kt_app -p kiddietrac -e "SHOW TABLES;"
```

You should see all the tables listed (`agencies`, `centres`, `rooms`, `users`, etc.).

---

## 4. Deploy the Laravel backend

### 4.1 Clone or upload

```bash
cd /var/www
sudo mkdir api.kiddietrac.ca && sudo chown kt:kt api.kiddietrac.ca
cd api.kiddietrac.ca

# Option A: from git (recommended)
git clone https://github.com/YOUR-ORG/kiddietrac-backend.git .

# Option B: upload via scp from your machine
# scp -r ./backend kt@your-vps-ip:/var/www/api.kiddietrac.ca/
```

### 4.2 Configure

```bash
cp .env.example .env
nano .env
```

Set at minimum:

```
APP_NAME=Kiddietrac
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.kiddietrac.ca

DB_DATABASE=kiddietrac
DB_USERNAME=kt_app
DB_PASSWORD=the_password_you_just_set

ANTHROPIC_API_KEY=sk-ant-...
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...

MAIL_HOST=smtp.postmarkapp.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=hello@kiddietrac.ca
```

### 4.3 Install dependencies and migrate

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Permissions
sudo chown -R kt:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 storage bootstrap/cache
```

---

## 5. Nginx configuration

```bash
sudo nano /etc/nginx/sites-available/api.kiddietrac.ca
```

```nginx
server {
    listen 80;
    server_name api.kiddietrac.ca;
    root /var/www/api.kiddietrac.ca/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    index index.php;
    charset utf-8;

    # Increase upload size for photo uploads
    client_max_body_size 25M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

For the parent portal (`app.kiddietrac.ca`):

```bash
sudo nano /etc/nginx/sites-available/app.kiddietrac.ca
```

```nginx
server {
    listen 80;
    server_name app.kiddietrac.ca;
    root /var/www/app.kiddietrac.ca;

    index index.html;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.html;
    }

    # Cache static assets aggressively
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

Enable both:

```bash
sudo ln -s /etc/nginx/sites-available/api.kiddietrac.ca /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/app.kiddietrac.ca /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

---

## 6. Point DNS at the VPS

In GoDaddy's DNS Manager:

| Type | Name | Value | TTL |
|------|------|-------|-----|
| A | `@` | your-vps-ip | 1 hour |
| A | `api` | your-vps-ip | 1 hour |
| A | `app` | your-vps-ip | 1 hour |
| CNAME | `www` | `@` | 1 hour |

Wait 10–60 minutes for propagation. Test with `dig api.kiddietrac.ca` — it should resolve to your VPS IP.

---

## 7. SSL with Let's Encrypt

```bash
sudo snap install --classic certbot
sudo ln -s /snap/bin/certbot /usr/bin/certbot

sudo certbot --nginx \
  -d kiddietrac.ca \
  -d www.kiddietrac.ca \
  -d api.kiddietrac.ca \
  -d app.kiddietrac.ca \
  --agree-tos --redirect --email you@kiddietrac.ca
```

Certbot auto-renews every 90 days via cron — verify:

```bash
sudo systemctl status certbot.timer
```

---

## 8. Cron + queue worker

### 8.1 Laravel scheduler (runs daily digest generation, invoice creation, etc.)

```bash
crontab -e
```

Add:

```
* * * * * cd /var/www/api.kiddietrac.ca && php artisan schedule:run >> /dev/null 2>&1
```

### 8.2 Queue worker (background jobs for photos, emails, notifications)

Install supervisor:

```bash
sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/kiddietrac-worker.conf
```

```ini
[program:kiddietrac-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/api.kiddietrac.ca/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=kt
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/api.kiddietrac.ca/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start kiddietrac-worker:*
```

---

## 9. Deploy the parent portal

```bash
sudo mkdir -p /var/www/app.kiddietrac.ca
sudo chown -R kt:www-data /var/www/app.kiddietrac.ca
# Upload parent-portal/* into /var/www/app.kiddietrac.ca
scp -r ./parent-portal/* kt@your-vps-ip:/var/www/app.kiddietrac.ca/
```

Update the API base URL at the top of `index.html` and `js/app.js` to:

```js
const API_BASE = 'https://api.kiddietrac.ca/api/v1';
```

---

## 10. Smoke test

```bash
# Health check
curl https://api.kiddietrac.ca/api/v1/health
# Expect: {"status":"ok","timestamp":"..."}

# Visit app.kiddietrac.ca in your browser. Login page should render.
```

Create your first admin user via tinker:

```bash
cd /var/www/api.kiddietrac.ca
php artisan tinker
```

```php
$user = \App\Models\User::create([
    'email' => 'you@kiddietrac.ca',
    'password' => 'change-me-immediately',
    'first_name' => 'Your',
    'last_name' => 'Name',
    'status' => 'active',
    'email_verified_at' => now(),
]);

\App\Models\RoleAssignment::create([
    'user_id' => $user->id,
    'role' => 'agency_admin',
    'agency_id' => 1,
    'active' => true,
]);
```

You can now log in at `https://app.kiddietrac.ca`.

---

## 11. Backups (do this on day one, not day 100)

### 11.1 Database backup

```bash
sudo nano /usr/local/bin/kt-backup.sh
```

```bash
#!/bin/bash
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR=/var/backups/kiddietrac
mkdir -p $BACKUP_DIR

mysqldump -u kt_app -p'YOUR_DB_PASSWORD' kiddietrac \
  --single-transaction --quick --lock-tables=false \
  | gzip > $BACKUP_DIR/db_$TIMESTAMP.sql.gz

# Keep last 30 days
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +30 -delete
```

```bash
sudo chmod +x /usr/local/bin/kt-backup.sh
sudo crontab -e
```

Add: `0 3 * * * /usr/local/bin/kt-backup.sh` (3 AM daily)

### 11.2 Off-site backup

Add a second cron that syncs `/var/backups/kiddietrac` to **Backblaze B2** ($6/TB/month) or AWS S3 — never trust backups that live on the same machine.

---

## 12. What GoDaddy can't do — and the workaround

| Need | GoDaddy can't | Use instead |
|------|---------------|-------------|
| WebSocket real-time | No persistent connections | **Pusher Channels** ($49/mo) — Laravel has first-class support |
| Push notifications | N/A | **Firebase Cloud Messaging** (free) — server SDK in Laravel |
| Photo storage at scale (>50 GB) | Disk pricey | **Backblaze B2** ($6/TB) or **Cloudflare R2** |
| Video calls | Way out of scope | **Daily.co** or **Whereby Embedded** |
| Transactional email at volume | SMTP unreliable | **Postmark** ($15/mo) or **SendGrid** |
| Search-as-you-type | No Elasticsearch | **Algolia** (free tier) or MySQL `FULLTEXT` |

The Laravel config (`config/broadcasting.php`, `config/filesystems.php`) already abstracts these — flip the driver in `.env` and you're done.

---

## 13. Cost summary (year 1)

| Item | Monthly | Annual |
|------|---------|--------|
| GoDaddy VPS (4 GB) | $30 | $360 |
| Domain | — | $20 |
| Postmark email | $15 | $180 |
| Pusher (when needed) | $49 | $588 |
| Anthropic AI digests (~100 kids) | $30 | $360 |
| Stripe (3% of revenue) | varies | varies |
| Backblaze B2 backups | $5 | $60 |
| **Total infrastructure** | **~$130** | **~$1,570** |

Compared to AWS-equivalent (~$400+/mo for the same redundancy), this is a defensible early-stage stack.

---

## 14. Things to set up in week 2

- **Sentry** for error monitoring (`composer require sentry/sentry-laravel`)
- **Cloudflare** in front of the domain for DDoS protection + CDN (free)
- **UptimeRobot** to ping `/api/v1/health` every 5 minutes (free)
- **Fail2ban** to block brute-force SSH:
  ```bash
  sudo apt install fail2ban
  ```
- **Automated security updates**:
  ```bash
  sudo apt install unattended-upgrades
  sudo dpkg-reconfigure -plow unattended-upgrades
  ```

---

## 15. When to leave GoDaddy

You should plan to migrate when **any** of these happen:

- You exceed 500 active children (CPU starts to matter)
- You add a second product surface (e.g., agency admin app needs websockets)
- You sign your first multi-centre agency customer (you'll want geographic redundancy)
- You hit ~$8K MRR (the cost of leaving is recovered in 3 months)

Good migration targets: **AWS Canada (ca-central-1)** for strict data-residency clients, **DigitalOcean App Platform** for simpler ops, or **Fly.io** for global edge.

The Laravel + MySQL stack you've built is portable — moving is a weekend, not a quarter.
