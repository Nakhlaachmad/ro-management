# Pemindahan RO dari MariaDB ke PostgreSQL

Dokumen ini memindahkan data aktif RO dari MariaDB ke PostgreSQL. File dump lama hanya cadangan; sumber yang dipakai saat cutover adalah database MariaDB aktif setelah pengguna berhenti melakukan perubahan sementara.

## Yang dipindahkan

- `teams`, `users`, dan hash password pengguna;
- role, permission, dan seluruh relasinya;
- data FP beserta relasi Team dan pembuatnya.

Token API, token reset password, dan log pekerjaan gagal tidak ikut dipindahkan. Ini disengaja agar kredensial sementara tidak terbawa lintas lingkungan.

## Urutan aman

1. Buat backup MariaDB aktif dan pastikan dapat dipulihkan.
2. Aktifkan mode pemeliharaan pada RO agar tidak ada perubahan selama cutover.
3. Buat database PostgreSQL kosong dan isi variabel `DB_*` dengan koneksi PostgreSQL.
4. Jalankan skema aplikasi sekali:

   ```bash
   php artisan migrate --force
   ```

5. Isi `LEGACY_MYSQL_*` hanya untuk sesi pemindahan, lalu jalankan:

   ```bash
   php artisan ro:migrate-mariadb-to-postgres
   ```

6. Perintah memeriksa jumlah setiap tabel inti. Jika PostgreSQL tidak kosong, perintah berhenti. Pengosongan target hanya dapat dilakukan secara sadar dengan:

   ```bash
   php artisan ro:migrate-mariadb-to-postgres --replace --force
   ```

7. Uji login tiap role dan buat, ubah, serta nonaktifkan satu data FP pada staging. Setelah itu baru arahkan `DB_CONNECTION=pgsql` dan buka mode pemeliharaan.
8. Hapus seluruh nilai `LEGACY_MYSQL_*` setelah verifikasi selesai.

Jangan menjalankan `migrate:fresh`, jangan mengimpor dump ke produksi, dan jangan menjalankan `--replace` pada database yang belum memiliki backup terverifikasi.
