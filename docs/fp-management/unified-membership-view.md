# F3.7E — Unified FP Membership View

14 Oktober 2026. **PASS untuk implementasi dan pengujian yang dijalankan. VISUAL ACCEPTED menunggu operator.**

## Implementasi

Seluruh record yang dibuka melalui `/fp-dev/fp-memberships/{record}` menggunakan satu custom View: judul Detail Financial Planner, breadcrumb kembali existing, badge status, foto profil di atas, informasi FP/keanggotaan/riwayat di tengah, KTP dan ijazah di bawah. Tidak ada approval/rejection/verifikasi atau fixed approval bar pada membership View. Header action Dokumen existing tetap dipertahankan dengan izin yang sama.

Semua TextEntry original dipertahankan, termasuk NIP, nama, email, telepon, tempat/tanggal lahir, alamat, pendidikan, NIK/rekening sesuai izin, Team, Jalur/sumber, tanggal referensi/persetujuan, exit/durasi/jenis/alasan dan riwayat identitas. Foto original dipindahkan dari ImageEntry ke card atas. Status membership ditambahkan sebagai badge. Tidak ada cabang/field baru yang dikarang apabila tidak tersedia di schema existing.

Komponen/styling F3.7D digunakan bersama: kartu `submission-document`, CSS `victory-review-*`, dan markup `private-document-dialog` yang diekstrak byte-identical dari submission review. Existing `resources/js/fp/viewer.js` tidak diubah. Tidak ada dependency baru atau styling ulang halaman list. List/actions/query/auth portion FpMembershipResource byte-identical terhadap source sebelum fase ini.

Dokumen dipilih hanya dari relasi membership yang sedang dibuka, dengan pemeriksaan membership_id, READY dan deletion NONE. Tidak fallback ke dokumen membership lain pada identitas yang sama. Legacy tanpa submission/dokumen tetap memakai placeholder. NIP/status NULL tidak diisi atau dipetakan menjadi ACTIVE. Tidak diperlukan source-type khusus untuk CSV: layout membaca record canonical yang sama, tidak membuat importer atau mapping data.

Endpoint: `fp.document` / `/fp-private/documents/{uuid}`. Viewer inline authorized tetap pada tab yang sama; private_path tidak dikirim ke markup. Controller/storage/authorization/no-store guards tidak diubah. Header viewer lama tetap tersedia sesuai permission existing.

Shared card menerima pesan placeholder opsional “Belum tersedia”; default submission tetap sama. Error image ditangani sebelum/sesudah Alpine initialization agar tidak menampilkan broken image. Ini fallback presentasi, tidak mengubah pipeline/storage dokumen.

## Files

1. `C:/laragon/www/RO_management/app/Filament/Resources/FpMembershipResource/Pages/ViewFpMembership.php`
2. `C:/laragon/www/RO_management/app/Filament/Resources/FpMembershipResource.php` — infolist only.
3. `C:/laragon/www/RO_management/resources/views/filament/fp/membership-view.blade.php` — baru.
4. `C:/laragon/www/RO_management/resources/views/filament/fp/private-document-dialog.blade.php` — shared extraction baru.
5. `C:/laragon/www/RO_management/resources/views/filament/fp/submission-review.blade.php` — include shared dialog; markup/handler existing identik.
6. `C:/laragon/www/RO_management/resources/views/filament/fp/submission-document.blade.php` — reusable placeholder/error fallback.
7. `C:/laragon/www/RO_management/tests/Feature/MembershipViewLayoutTest.php` — baru.
8. `C:/laragon/www/RO_management/docs/fp-management/unified-membership-view.md` — laporan.

CSS existing tidak diubah pada F3.7E. Helper/evidence ada di workspace `work/f37e` / `outputs/F3.7E`, bukan dependency runtime wajib.

## Pengujian aktual

| Area | Hasil |
| --- | --- |
| Offline unit/regression | **25 tests / 236 assertions PASS**; role matrix, documents owner/READY/deletion, guest no-preview, legacy NULL status/documents, NIP lama tidak berubah, BM mutation denial, shared review action regression, design system/directories/reports |
| PHP lint / Pint test | PASS, 3 PHP files terkait |
| Original fields/list/query/auth preservation | PASS; semua nama TextEntry original ada; source sebelum infolist identik |
| Shared viewer extraction | PASS byte-identical |
| Real Chrome complete FP | **6 viewport/theme cases PASS**: desktop1440, tablet834, mobile390 × light/dark; urutan, no approval controls/bar, no horizontal overflow, no visible broken image, KTP/ijazah benar-benar loaded dalam viewer pada tab yang sama |
| Image-error fallback | **PASS 3 cards**, real Chrome dengan HTTP 503 dokumen yang dimock; bukan bukti fixture database tanpa dokumen |
| Real incomplete FP | **NOT RUN**, tidak ditemukan record utama dengan kurang dari 3 dokumen READY/not-deleted; tidak dibuat fixture utama |
| Legacy missing-document fixture | **PASS offline**, record canonical LEGACY tanpa submission/dokumen; NIP1001 dan status NULL tetap sama |
| CSV-imported record | **NOT RUN**, fixture dengan provenance CSV tidak teridentifikasi; tidak mengarang hasil importer |
| Authorization | Empat role dan guest/ownership guards PASS offline; real login/document viewer diuji menggunakan existing HRD saja. Controller/policy tidak berubah |

Screenshot lengkap: `outputs/F3.7E/complete-{light,dark}-{desktop,tablet,mobile}.png`. Biodata, user-menu dan gambar dokumen dimask untuk privacy; blok abu-abu bukan placeholder aplikasi pada record lengkap.

## Integritas dan provenance data

14 protected hashes dan accepted .env cocok dengan manifest original. Semua operasi SQL fase ini READ ONLY; tidak ada migration/schema/SQL mutation/grants, account/role changes, NIP rewrite, backfill, dummy record utama, SSO/JWT/Nextcloud changes, deployment atau commit/stage.

Perbandingan dengan baseline fase F3.7D lama menemukan `fp_audit_events` 32→34 dan fingerprint fp_submissions berubah (jumlah rows tetap 6). **Baseline lama tidak diganti atau dianggap cocok.** Dua event tambahan adalah BIODATA_VERIFIED pada 14 Oktober 17:17:18 WIB dan DOCUMENTS_VERIFIED pada 17:17:26 WIB, sebelum source backup/start F3.7E pukul 17:48:23 WIB. Latest submission update juga 17:17:26 WIB. Query provenance setelah browser menunjukkan **0 audit events dan 0 submission updates sejak awal F3.7E**. Fingerprint tabel lain cocok dengan baseline fase sebelumnya. Siapa yang melakukan verifikasi tersebut tidak disimpulkan.

Evidence: `offline.txt`, `browser.json`, `image-fallback.json`, `previous-phase-differences.json`, `data-provenance.json`. Main server existing tidak dihentikan atau diganti.

## Batas hasil

Implementasi berlaku untuk semua record melalui View page yang sama. Missing/legacy handling terbukti offline; visual missing-document/CSV pada database nyata masih NOT RUN karena fixture tidak tersedia. Browser acceptance empat role di main tidak diklaim. Proposal simplifikasi approval F3.7D tetap terpisah dan belum dieksekusi pada fase F3.7E.
