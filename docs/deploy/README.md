# Deploy ke VPS Hostinger

Satu VPS (Ubuntu 24.04, 2 vCPU / 8 GB) menjalankan seluruh stack: Nginx, PHP 8.4-FPM,
PostgreSQL 17, Redis, Reverb, dan Horizon.

## 1. Sekali saja di server

```bash
# paket dasar
sudo apt install nginx php8.4-fpm php8.4-{cli,pgsql,redis,gd,intl,mbstring,xml,zip,curl,bcmath} \
  postgresql-17 redis-server supervisor fail2ban ufw git unzip
# firewall: hanya 22, 80, 443
sudo ufw allow 22 && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
# SSH: login dengan key saja (PasswordAuthentication no di /etc/ssh/sshd_config)
# PostgreSQL & Redis hanya localhost: listen_addresses='localhost' / bind 127.0.0.1
sudo -u postgres createuser pos --pwprompt && sudo -u postgres createdb -O pos pos
sudo -u postgres createdb -O pos pos_staging
```

- Letakkan `.env` di `/var/www/pos/shared/.env` (di luar web root; `APP_DEBUG=false`,
  `APP_ENV=production`, `BROADCAST_CONNECTION=reverb`, `QUEUE_CONNECTION=redis`,
  `BACKUP_DISK=r2` + variabel `R2_*`).
- Sertifikat HTTPS: `sudo certbot --nginx -d pos.example.com` (HSTS sudah ada di contoh Nginx).
- Cron owner `deploy`: `* * * * * cd /var/www/pos/current && php artisan schedule:run >> /dev/null 2>&1`
- QZ Tray: jalankan `php artisan pos:qz-keys` sekali di server, lalu import sertifikat
  `storage/app/qz/digital-certificate.txt` ke QZ Tray di PC/tablet kasir.

## 2. Nginx

Lihat [nginx.conf](nginx.conf): reverse proxy + proxy WebSocket `/app` ke Reverb (`127.0.0.1:8080`).

## 3. Supervisor

Lihat [supervisor.conf](supervisor.conf): Horizon (queue) dan Reverb (WebSocket).

## 3b. Checklist realtime (jika layar tampil "Offline")

1. `.env` produksi wajib berisi `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
   (isi bebas, tapi harus ada), `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`.
2. `php artisan config:cache`, lalu supervisor `pos-reverb` harus RUNNING.
3. Nginx meneruskan `/app` (WebSocket) ke `127.0.0.1:8080` (lihat nginx.conf).
4. Jalankan `php artisan pos:realtime-check` — memeriksa konfigurasi, koneksi ke Reverb, dan kirim event uji.
5. Browser mengambil host/port WebSocket saat runtime dari halaman (tidak perlu build ulang). Override opsional:
   `REVERB_PUBLIC_HOST`, `REVERB_PUBLIC_PORT`, `REVERB_PUBLIC_SCHEME`.

Tanpa WebSocket aplikasi tetap jalan lewat polling 10 detik (notifikasi pesanan QR tetap berbunyi, hanya lebih lambat).

Catatan: layar Dapur (KDS) kini opsional dan default MATI. Aktifkan di Pengaturan > Dapur bila dibutuhkan.

## 4. Alur deploy

1. Push ke `main` memicu GitHub Actions (`.github/workflows/tests.yml`): Pint, Larastan level 8,
   ESLint, `tsc`, Pest dengan PostgreSQL 17 + Redis.
2. Jika lulus, aset frontend dibangun di CI lalu `vendor/bin/dep deploy production` membuat rilis baru via SSH.
3. Deployer menjalankan `migrate --force`, cache config/route/view, lalu mengganti symlink `current`
   (zero-downtime), lalu `horizon:terminate` dan `reverb:restart` secara graceful.
4. 5 rilis terakhir disimpan. Rollback cepat: `vendor/bin/dep rollback production`.
5. Migrasi yang mengubah data uang wajib punya rencana rollback tertulis di PR.

Secrets GitHub yang dibutuhkan: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`.

## 5. Backup & monitoring

- `backup:run --only-db` tiap 6 jam (scheduler), dikirim ke Cloudflare R2 (`BACKUP_DISK=r2`), retensi 30 hari.
- Aktifkan snapshot VPS mingguan dari panel Hostinger sebagai lapisan kedua.
- UptimeRobot memantau `/up` tiap 1 menit.
- Sentry (error tracking) dan Laravel Pulse (metrik) **belum dipasang** — lihat README bagian "Belum selesai".
- Uji restore minimal sebulan sekali; catat hasilnya. Langkah restore:

```bash
# unduh backup terbaru dari R2, lalu
unzip backup.zip && gunzip -c db-dumps/pgsql-pos.sql.gz | psql -U pos -d pos_restore
```
