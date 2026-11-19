# Monitoring suhu truk reefer

ESP32 ──MQTT (HiveMQ)──> **Backend Go** (MySQL) ──REST API──> **Laravel** (dashboard)

```
reefer-monitoring/
├── go-backend/           Go (Fiber v3 + GORM + paho MQTT): subscribe MQTT, simpan ke MySQL, REST API
└── laravel-dashboard/    File Laravel (controller, route, view, config) untuk ditaruh di project Laravel
```

Kode ini belum dijalankan atau dikompilasi di lingkungan pembuatnya, jadi kemungkinan ada penyesuaian kecil saat pertama dijalankan.

## 1. Jalankan backend Go

Butuh Go 1.24 atau lebih baru (Fiber v3 mungkin minta lebih baru, ikuti pesan dari `go mod tidy`) dan MySQL.

```bash
cd go-backend
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS reefer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"      # buat database "reefer"
cp .env.example .env               # lalu isi DB, MQTT, dan API_TOKEN
go mod tidy                        # unduh dependensi dan buat go.sum
go run .
```

Tabel dibuat otomatis oleh GORM (AutoMigrate). Cek `http://localhost:8080/health`.

## 2. Pasang dashboard Laravel

```bash
composer create-project laravel/laravel reefer-dashboard
```

Salin dari `laravel-dashboard/` ke project itu:

- `app/Http/Controllers/DashboardController.php`
- `resources/views/dashboard.blade.php`
- isi `routes/web.php` (gabungkan, atau gantikan route `/` bawaan)
- blok `'reefer'` dari `config/services.php` ke `config/services.php` milik project

Tambahkan ke `.env` Laravel:

```
REEFER_API_URL=http://localhost:8080
REEFER_API_TOKEN=ganti-dengan-token-rahasia   # sama dengan API_TOKEN di Go
REEFER_DEVICE_ID=reefer-01
```

Lalu `php artisan serve` dan buka `http://localhost:8000`. Dashboard hanya butuh PHP, tidak butuh npm.

## 3. Topic dan payload MQTT (untuk ESP32)

Prefix default `reefer`, ganti dengan yang unik kalau memakai broker publik.

| Topic | Arah | Isi |
|---|---|---|
| `reefer/{device_id}/telemetry` | ESP32 ke backend | JSON, kirim tiap beberapa detik |
| `reefer/{device_id}/status` | ESP32 ke backend | `online` atau `offline` (retained). Atur Last Will ke `offline`, dan kirim `online` saat connect |
| `reefer/{device_id}/scan` | ESP32 ke backend | JSON hasil scan |
| `reefer/{device_id}/cmd` | backend ke ESP32 | JSON perintah dari dashboard |

**telemetry**
```json
{
  "temperature": 1.4,
  "mode": "auto",
  "fan1": { "state": "normal", "rpm": 2400, "fault": false },
  "fan2": { "state": "off" },
  "box_id": "BOX-001",
  "item_uid": "04A1B2C3"
}
```
`fan1.state`: `off` | `normal` | `high`. `fan2.state`: `off` | `on`. `rpm`, `fault`, `box_id`, dan `item_uid` opsional.

**scan**
```json
{ "item_uid": "04A1B2C3", "box_id": "BOX-001" }
```

**cmd** (yang dikirim backend, hanya field yang diubah)
```json
{ "mode": "manual", "fan1": "high", "fan2": "on" }
```
ESP32 harus subscribe ke `reefer/{device_id}/cmd`, menjalankan perintahnya, lalu melaporkan kondisi baru lewat telemetry. Dashboard hanya berubah setelah telemetry baru masuk.

## 4. Uji tanpa hardware

Dengan `mosquitto_pub` (paket `mosquitto-clients`), broker publik, device `reefer-01`:

```bash
B=broker.hivemq.com

# perangkat online
mosquitto_pub -h $B -t reefer/reefer-01/status -r -m online

# suhu normal, kipas 1 normal
mosquitto_pub -h $B -t reefer/reefer-01/telemetry -m '{"temperature":1.2,"mode":"auto","fan1":{"state":"normal"},"fan2":{"state":"off"},"box_id":"BOX-001","item_uid":"04A1B2C3"}'

# warning: kipas 1 tinggi
mosquitto_pub -h $B -t reefer/reefer-01/telemetry -m '{"temperature":4.5,"mode":"auto","fan1":{"state":"high"},"fan2":{"state":"off"}}'

# kritis: dua kipas nyala
mosquitto_pub -h $B -t reefer/reefer-01/telemetry -m '{"temperature":9.1,"mode":"auto","fan1":{"state":"high"},"fan2":{"state":"on"}}'

# lihat perintah dari dashboard
mosquitto_sub -h $B -t reefer/reefer-01/cmd -v
```

Daftarkan nama barang (opsional):
```bash
curl -X POST http://localhost:8080/api/items -H "Authorization: Bearer <API_TOKEN>" \
  -H "Content-Type: application/json" -d '{"uid":"04A1B2C3","name":"Ikan tongkol"}'
```

## 5. Catatan

- **Status suhu dihitung di Go** (`classify` di `hub.go`): normal di bawah 2°C, warning 2 sampai 8°C, kritis di atas 8°C. Ada histeresis 0,2°C yang diatur lewat `.env`. Logika kipas tetap dijalankan ESP32.
- **Perangkat dianggap offline** kalau tidak ada data lebih dari 30 detik (`DEVICE_STALE_SECONDS`), meski broker belum mengirim `offline`.
- **Sebelum telemetry pertama masuk**, dashboard menampilkan banner "tidak bisa terhubung" karena API membalas 404.
- **Data riwayat terus bertambah** (satu baris per telemetry). Untuk produksi, tambahkan penghapusan data lama atau naikkan interval kirim.
- **Broker publik HiveMQ** bisa dibaca siapa saja. Untuk produksi pakai HiveMQ Cloud dengan username dan password (`tls://...:8883`).
