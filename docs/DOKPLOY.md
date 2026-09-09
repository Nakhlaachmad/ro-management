# Deploy RO Management di Dokploy

Gunakan satu service aplikasi dari repository RO dan satu service PostgreSQL terpisah. Dokploy menangani domain serta HTTPS di reverse proxy; container aplikasi hanya menerima HTTP pada port `80`.

## Konfigurasi aplikasi

1. Buat service **Docker Compose** baru dari repository GitHub RO.
2. Gunakan path `./docker-compose.dokploy.yml`; domain Dokploy diarahkan ke service `web` port `80`.
3. Masukkan nilai dari `.env.dokploy.example` sebagai environment variables. `APP_KEY` dibuat baru di lingkungan Dokploy.
4. Health check `/up` sudah didefinisikan di Compose.
5. Deploy aplikasi tanpa menjalankan migrasi otomatis di startup.

## Sinkronisasi identitas IWACS

RO tidak berbagi database dengan IWACS. Setelah kedua service memiliki URL HTTPS,
buat client Keycloak rahasia bernama `ro-service` dan masukkan secret-nya hanya ke
Dokploy. Client itu memerlukan role API `registrations.write`; client yang dipakai
IWACS untuk mengirim event ke RO memerlukan `users.sync` dan audience `ro-service`.

- Isi semua variabel `IWACS_RO_*` dan `API_JWT_*` pada `.env.dokploy.example`.
- Di IWACS isi `RO_SYNC_*`, lalu aktifkan `API_RO_SYNC_DISPATCH_ENABLED=true`.
- Jalankan worker IWACS agar event registrasi RO yang telah diantrikan terkirim.
- Jangan memakai credential KPI sebagai pengganti `ro-service` di staging/produksi.

Hanya profil akun yang disinkronkan: nama, email, role, status, dan credential
hash untuk akun baru dari registrasi. Tabel FP, Team, dan data bisnis RO tidak
pernah dikirim ke IWACS.

## SSO browser dari IWACS

Menu **RO Management** di IWACS mengirim JWT RS256 yang berlaku singkat ke
`/sso/jwt-login` RO. RO menerima token hanya satu kali, memeriksa issuer,
audience `ro`, masa berlaku, signature, dan kecocokan `sso_subject` dengan
akun RO lokal sebelum membuat session panel.

- Salin **public key** pasangan JWT IWACS ke
  `storage/app/keys/iwacs-public.pem` pada service RO. Private key tetap hanya
  berada di IWACS dan tidak boleh dipindahkan.
- Isi `PORTAL_APP_URL`, `SSO_EXPECTED_ISSUER`, `SSO_EXPECTED_AUDIENCE=ro`,
  serta `SSO_PUBLIC_KEY_PATH` sesuai `.env.dokploy.example`.
- Uji dengan akun yang sudah ada di RO dan telah tersinkron (`sso_subject`
  tersedia). Jika belum ada, RO menolak masuk alih-alih membuat akun baru.
  Panel RO saat ini memakai path dasar (`/`), bukan `/admin`.

## Database dan cutover

1. Buat service PostgreSQL terpisah dan database khusus RO.
2. Jalankan `php artisan migrate --force` satu kali melalui terminal service `web` Dokploy.
3. Ikuti `docs/POSTGRESQL_MIGRATION.md` untuk memindahkan data MariaDB aktif.
4. Setelah verifikasi, hapus `LEGACY_MYSQL_*` dari Dokploy dan deploy ulang.

## Catatan operasional

- Jangan menyimpan `.env`, dump SQL, password, atau private key di GitHub.
- Volume bernama `ro_storage` menyimpan berkas aplikasi yang harus bertahan saat redeploy.
- Aktifkan backup harian PostgreSQL dan `ro_storage` ke tujuan S3 melalui menu Backup Dokploy, lalu jalankan tombol **Test** sebelum cutover.
- Jangan mengaktifkan lebih dari satu replika selama `SESSION_DRIVER=file` masih digunakan.
- Saat pekerjaan antrean mulai dipakai, buat worker Dokploy terpisah dengan command `php artisan queue:work --tries=3 --timeout=90`; tidak perlu menjalankannya sekarang karena RO masih memakai antrean sinkron.
- Scheduler, jika nanti dipakai, dijalankan sebagai service/cron terpisah dengan `php artisan schedule:work`.
