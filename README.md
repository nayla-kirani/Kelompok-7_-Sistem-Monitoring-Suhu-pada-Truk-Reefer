# 🚛 Sistem Monitoring Suhu pada Truk Reefer Berbasis ESP32 dan IoT

### Kelompok 7

## 📌 Identitas Kelompok

| Keterangan | Informasi |
|---|---|
| Nama Proyek | Sistem Monitoring Suhu pada Truk Reefer |
| Kelompok | 7 |
| Mata Kuliah | Internet of Things |
| Program Studi | Teknik Komputer |


## 👥 Anggota Kelompok

| No. | Nama Anggota | NIM | Pembagian Tugas |
|---|---|---|---|
| 1 | Windi Sulaiman Ismansa | H1H024005 | Perancangan dan Perakitan Hardware |
| 2 | Nayla Zazki Kirani | H1H04007 | Pemrograman Sensor dan Monitoring Suhu |
| 3 | Maharani Tri Wahyuningrum | H1H024012 | Pengembangan Sistem IoT dan Dashboard |
| 4 | Diva Syahita Mawarni | H1H024015 | Pengujian Sistem dan Dokumentasi |

## 📖 Deskripsi Proyek

Proyek ini merupakan pengembangan prototipe sistem monitoring dan pengendalian suhu pada truk berpendingin (*reefer*) berbasis Internet of Things (IoT).

Sistem menggunakan ESP32 sebagai mikrokontroler utama dan sensor DHT22 untuk membaca suhu serta kelembapan dalam ruang pendingin.

Pengendalian suhu dilakukan menggunakan dua kipas DC dengan fungsi berbeda, yaitu kipas pendingin dan kipas stabilisasi. Data monitoring dikirim melalui protokol MQTT dan ditampilkan pada dashboard web, sehingga kondisi suhu dapat dipantau dan pengaturan sistem dapat dilakukan melalui antarmuka pengguna.

## 🎯 Tujuan Proyek

1. Mengembangkan prototipe monitoring suhu dan kelembapan pada ruang pendingin.
2. Mengimplementasikan pengendalian kipas otomatis berdasarkan suhu yang terdeteksi.
3. Menampilkan informasi monitoring melalui dashboard berbasis web.
4. Mengintegrasikan ESP32 dengan protokol MQTT untuk komunikasi data.
5. Menerapkan indikator LED dan buzzer sebagai sistem peringatan kondisi suhu.

## 🔧 Komponen Utama

- ESP32 DevKit
- Sensor DHT22
- 2 Mini Kipas DC
- Driver MOSFET
- LED indikator (hijau, kuning, merah)
- Buzzer
- Catu daya
- Box pendingin dan wadah es
- Breadboard dan kabel jumper

## ⚙️ Mekanisme Kerja Sistem

1. Sensor DHT22 membaca suhu dan kelembapan di dalam box pendingin.
2. ESP32 menerima dan memproses data hasil pembacaan sensor.
3. Sistem membandingkan suhu aktual dengan nilai setpoint.
4. ESP32 mengendalikan kipas pendingin dan kipas stabilisasi secara otomatis.
5. LED dan buzzer memberikan informasi serta peringatan berdasarkan kondisi sistem.
6. Data monitoring dikirim melalui MQTT dan ditampilkan pada dashboard web.
7. Pengguna dapat memantau kondisi dan mengirimkan perintah pengendalian melalui dashboard.

## 🛠️ Teknologi yang Digunakan

- **Hardware:** ESP32, DHT22, kipas DC, MOSFET
- **Pemrograman:** C/C++ (Arduino IDE)
- **Komunikasi IoT:** Wi-Fi dan MQTT
- **MQTT Broker:** HiveMQ
- **Monitoring:** Dashboard berbasis web

## 📂 Dokumentasi

Repository ini digunakan untuk menyimpan source code, dokumentasi perancangan, diagram sistem, serta hasil pengembangan dan pengujian prototipe.

## 📝 Status Proyek

🚧 Dalam tahap pengembangan dan pengujian.

---

**Kelompok 7 – Sistem Monitoring Suhu pada Truk Reefer**

*Proyek pengembangan sistem monitoring dan pengendalian suhu berbasis ESP32 dan Internet of Things (IoT).*
