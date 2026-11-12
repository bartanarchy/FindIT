# Panduan Backup & Restore FindIT

Backup memakai `pg_dump` (format custom) dan disimpan **di luar folder website**, lalu disalin ke lokasi kedua
(remote). Semua proses berjalan lewat command line, tidak bergantung pada browser atau halaman PHP.

## 1. Cara kerja singkat

1. `pg_dump` membuat file `.dump` di `BACKUP_DIR\daily` (default `C:\FindIT_Backups`).
2. File dicek: header, `pg_restore --list`, 7 tabel wajib ada, checksum SHA-256 disimpan di `.sha256`.
3. File disalin ke remote, lalu dicek ada, ukuran sama, dan checksum sama.
4. Status: `SUCCESS` (lokal **dan** remote OK), `PARTIAL_SUCCESS` (lokal OK, remote belum), `FAILED`, `RUNNING`.
5. Retensi dijalankan **hanya setelah** ada backup baru yang valid: 7 daily dan 4 weekly. Backup valid terbaru tidak pernah dihapus; salinan lokal tidak dihapus bila remote gagal.
6. Weekly = salinan terverifikasi dari daily. Lokal dan remote punya retensi sendiri.

Folder: `BACKUP_DIR\daily`, `weekly`, `logs` (backup.log, restore.log, `runs\*.json`), `config`.
Data kolom sensitif sudah terenkripsi di dalam database, jadi file dump pun berisi `enc:v1:...`.
**Tanpa `APP_ENC_KEY` isi dump tidak bisa dibaca. Simpan salinan key di tempat terpisah.**

## 2. Konfigurasi (`.env`)

Salin dari `.env.example`. Yang penting:

| Variabel | Fungsi |
| --- | --- |
| `BACKUP_DIR` | Folder backup, wajib di luar folder website. Kosong = `C:\FindIT_Backups` |
| `PG_BIN_DIR` | Folder `pg_dump.exe`, mis. `C:\Program Files\PostgreSQL\16\bin` (dicari otomatis bila kosong) |
| `REMOTE_DRIVER` | `none`, `folder` atau `sftp` |
| `REMOTE_FOLDER_PATH` | Untuk `folder`: drive lain / USB / NAS / folder sinkron cloud. Tidak boleh sama dengan `BACKUP_DIR` |
| `REMOTE_SFTP_*` | Untuk `sftp`: host, port, user, folder, `REMOTE_SFTP_KEY` (login pakai key, tanpa password) |
| `BACKUP_DB_USER/PASS`, `RESTORE_DB_USER/PASS` | Akun database khusus (opsional, lihat `database/hardening/least-privilege.sql`) |
| `RESTORE_TEST_DB` | Database uji restore, namanya harus mengandung `test` |
| `RESTORE_WEB_ENABLED` | `true` untuk mengaktifkan tombol Restore di web (default `false`) |

Tanpa remote (`REMOTE_DRIVER=none`) hasil backup selalu `PARTIAL_SUCCESS`. Itu disengaja: satu lokasi saja bukan backup yang aman.

Cek semuanya: `php scripts\backup-check.php`

## 3. Backup manual

* Web: login admin → **Backup Management** → **Backup Now**.
* Command line: `php scripts\backup.php --type=manual`
* Hanya kirim ulang ke remote: `php scripts\backup.php --retry-remote`

Exit code: 0 ok/dilewati, 1 gagal, 2 lokal ok tapi remote belum, 3 ada proses lain yang sedang berjalan.

## 4. Backup otomatis (Windows Task Scheduler)

```powershell
powershell -ExecutionPolicy Bypass -File scripts\windows\install-scheduler.ps1
powershell -ExecutionPolicy Bypass -File scripts\windows\install-scheduler.ps1 -DailyAt 21:30
powershell -ExecutionPolicy Bypass -File scripts\windows\install-scheduler.ps1 -Remove
```

Membuat dua task: **FindIT Backup** (tiap hari pada jam `-DailyAt` dan saat Anda login; bila komputer mati pada
jamnya, dijalankan segera setelah menyala) dan **FindIT Restore Test** (tiap Minggu). Backup tidak diulang bila sudah
ada backup baik dalam `BACKUP_MIN_INTERVAL_HOURS` (12 jam). Dua proses tidak berjalan bersamaan (file lock).
Bila PostgreSQL belum siap, dicoba ulang 5 kali per 60 detik. Coba sekarang: `Start-ScheduledTask -TaskName "FindIT Backup"`.

Manual lewat GUI: Task Scheduler → Create Task → Action `php.exe`, argument `scripts\backup.php --retries=5 --retry-delay=60`,
Start in = folder project, centang "Run task as soon as possible after a scheduled start is missed".

## 5. Restore

Cek isi dulu: `php scripts\restore.php --status` dan `--list`.

```
php scripts\restore.php                      # interaktif
php scripts\restore.php --file=daily/findit_2026-11-12_020000.dump --confirm=FindIT
php scripts\restore.php --file=... --confirm=FindIT --remote    # ambil dari salinan remote
```

Cara aman yang dipakai: backup dimuat ke database sementara, divalidasi (tabel, jumlah baris, relasi), baru ditukar nama.
Database lama **tidak dihapus**, namanya menjadi `<db>_old_<waktu>`. File backup sumber tidak pernah dihapus.
Restore tetap bisa jalan bila database sudah hilang (tidak butuh website). Restore dari web: **Restore Data**
(harus `RESTORE_WEB_ENABLED=true`, mengetik nama database, hanya file dari daftar, tidak menerima path bebas).

Uji tanpa risiko (hanya menyentuh database test): `php scripts\restore-test.php`.
Latihan bencana lengkap: `php scripts\restore-drill.php`.

## 6. Bila gagal

| Pesan / gejala | Tindakan |
| --- | --- |
| `pg_dump was not found` | Isi `PG_BIN_DIR` di `.env` |
| `SERVER_DOWN` / DB tidak terjangkau | Nyalakan service PostgreSQL, ulangi |
| `AUTH_FAILED` | Periksa `DB_USER/DB_PASS` atau `BACKUP_DB_*` / `RESTORE_DB_*` |
| `PARTIAL_SUCCESS` | Remote bermasalah: perbaiki lalu `php scripts\backup.php --retry-remote` |
| `BUSY` | Ada backup/restore lain yang berjalan, tunggu |
| Restore gagal | Database lama tidak diganti. Lihat `BACKUP_DIR\logs\restore.log` |

Log berisi run id, waktu, file, tipe, ukuran, status lokal/remote/validasi/restore, dan identitas admin; tanpa password.

## 7. Keamanan

* `BACKUP_DIR` ditolak bila berada di dalam folder website; `scripts/` dan `scripts/windows/` ditutup `.htaccess`.
* Perintah dijalankan tanpa shell (array argumen), password lewat environment, bukan di command line.
* Gunakan akun terbatas: `findit_app` (web), `findit_backup` (hanya baca), `findit_restore`.
* Jangan commit `.env`. Untuk SFTP pakai key, dan batasi akses file key.
* Remote `folder`/`sftp` sebaiknya terenkripsi di tujuan (BitLocker, SFTP = terenkripsi saat transfer).

## 8. Yang belum teruji

Diuji di Linux dengan PostgreSQL 16: backup, remote folder, retry, restore, restore ke DB hilang, akun least-privilege.
**Belum diuji:** Windows Task Scheduler/PowerShell, Laragon, PostgreSQL versi Anda, dan remote SFTP ke server sungguhan.
Jalankan `backup-check.php`, satu backup manual, dan `restore-drill.php` di komputer Anda sebelum mengandalkannya.

## 9. Daftar file

Baru: `app/backup/*` (BackupConfig, BackupManager, RestoreManager, BackupStore, OperationLock, ProcessRunner,
RemoteStorage, FolderRemote, SftpRemote, bootstrap), `app/helpers/backup_ui.php`,
`admin/users.php`, `admin/backups.php`, `admin/backup-history.php`, `admin/restore.php`,
`scripts/backup.php`, `backup-check.php`, `restore.php`, `restore-test.php`, `restore-drill.php`,
`scripts/windows/install-scheduler.ps1`, `docs/BACKUP_GUIDE.md`.
Diubah: `app/helpers/ui.php` (menu admin), `admin/index.php` (kartu), `database/hardening/least-privilege.sql`, `.env.example`, `README.md`.
Dihapus: sistem lama (journal + snapshot `.fbk`, `backup-snapshot.php`, `verify-backup.php`, `inspect-backup.php`).
