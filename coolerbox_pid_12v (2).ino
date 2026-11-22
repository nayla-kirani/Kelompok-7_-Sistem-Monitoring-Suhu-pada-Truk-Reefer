/*
- SMART COOLER BOX - Prototype 2 kipas DC 12V + es batu + PID (khusus perangkat keras asli)
- Board  : ESP32 DevKit (30/38 pin)
- Daya   : Adaptor 12V -> (+) kedua kipas dan masukan buck 12V->5V
-          Buck 5V -> pin VIN ESP32. Source MOSFET, GND buck, GND ESP32, GND adaptor = satu GND
- Sensor : DHT22 (suhu + kelembapan di dalam box)
- Aktuator:
-   KIPAS A "PENDINGIN" : meniup udara melewati wadah es batu -> udara dingin masuk ke box (ON/OFF, histeresis)
-   KIPAS B "STABILISASI": sirkulasi udara dalam box agar suhu merata & stabil (Prioritas PID)
- Indikator: LED hijau/kuning/merah + buzzer AKTIF GPIO 13 (kedip merah & "tit-tit-tit" cepat selaras)
- Cloud  : MQTT HiveMQ (kirim telemetri JSON & terima perintah kontrol)

# REFERENSI COMMAND MQTT - SMART COOLER BOX

- **Broker**          : `broker.hivemq.com` (Port: `1883`)
- **Topic Publish**   : `unsoed/project/coolerbox/data` (ESP32 -> Dashboard)
- **Topic Subscribe** : `unsoed/project/coolerbox/perintah` (Dashboard -> ESP32)
- **Format Pesan**    : Teks biasa, tidak *case-sensitive* (huruf besar/kecil otomatis terbaca)

## 1. Kontrol Sistem & Alarm Buzzer
- `COOLER_ON`         : Mengaktifkan sistem pendingin otomatis
- `COOLER_OFF`        : Mematikan sistem manual (kedua kipas OFF, status `RED`, buzzer ON)
- `MUTE`              : Membisukan buzzer sementara (otomatis reset saat kondisi alarm selesai)
- `UNMUTE`            : Mengaktifkan kembali suara buzzer

## 2. Pengaturan Target Suhu (Setpoint) & Kalibrasi
- `TEMP_SET:18.5`     : Mengatur setpoint ke suhu tertentu (`17.0` .. `20.0` C)
- `SET:18.5`          : Alias singkat dari `TEMP_SET` (`17.0` .. `20.0` C)
- `TEMP_UP`           : Menaikkan setpoint sebesar `+0.5` C (maksimal `20.0` C)
- `TEMP_DOWN`         : Menurunkan setpoint sebesar `-0.5` C (minimal `17.0` C)
- `CAL:-0.5`          : Koreksi kalibrasi offset sensor DHT22 (`-5.0` .. `+5.0` C)
- `TEMP_RESET`        : Mengembalikan setpoint ke default (`18.5` C) dan offset ke `0.0`

## 3. Kontrol & Tuning PID (Kipas B)
- `PID_ON`            : Mengaktifkan algoritma PID penuh (`P + I + D`)
- `PID_OFF`           : Menonaktifkan komponen `I` dan `D` (hanya kurva dasar `P`)
- `KP:1.5`            : Mengubah pengali Proporsional `Kp` (`0` .. `5`)
- `KI:0.25`           : Mengubah konstanta Integral `Ki` (`0` .. `5`)
- `KD:120.0`          : Mengubah konstanta Derivatif `Kd` (`0` .. `200`)

## 4. Mode Operasi & Kontrol Kipas Manual (dari dashboard)
- `MODE_AUTO`         : Mode otomatis (kedua kipas diatur ESP32 sesuai suhu & PID)
- `MODE_MANUAL`       : Mode manual (kipas A & B dikontrol dari dashboard), mulai dari kondisi kipas saat ini
- `FAN_COOL_ON/OFF`   : (hanya MANUAL) Kipas A pendingin es dinyalakan / dimatikan
- `FAN_STAB_ON/OFF`   : (hanya MANUAL) Kipas B penjaga suhu: ON = berputar sesuai PID, OFF = mati
  Catatan: saat MANUAL, kondisi "kedua kipas mati" tidak memicu alarm buzzer (alarm karena suhu tetap aktif).
  Saat sensor error, failsafe tetap menyalakan kedua kipas walau MANUAL.

## 5. Pengecekan Status (Telemetri)
- `STATUS`            : Meminta ESP32 segera mengirim data JSON terbaru ke topic `.../data`
- `PING`              : Alias dari `STATUS` untuk mengecek koneksi & status alat secara instan
*/

#include <WiFi.h>
#include <PubSubClient.h>
#include "DHTesp.h"
#include "driver/gpio.h"

// ===================== KONFIGURASI JARINGAN =====================
const char* ssid          = "asa";
const char* password      = "12345789";
const char* mqttServer    = "broker.hivemq.com";
const int   mqttPort      = 1883;
const char* topicData     = "unsoed/project/coolerbox/data";
const char* topicPerintah = "unsoed/project/coolerbox/perintah";

// ===================== PIN =====================
#define PIN_DHT        15
#define PIN_LED_GREEN  25
#define PIN_LED_YELLOW 26
#define PIN_LED_RED    27
#define PIN_BUZZER     13   // Kaki (+) Buzzer Aktif -> GPIO 13 (D13), kaki (-) -> GND
#define PIN_FAN_COOL   18   // Kipas A -> gate MOSFET #1
#define PIN_FAN_STAB   19   // Kipas B -> gate MOSFET #2 (PWM)

// ===================== PWM (kompatibel core ESP32 v2 & v3) =====================
#define CH_FAN_STAB 0
#define CH_BUZZER   2
#define FAN_PWM_HZ  25000
#define PWM_RES     8
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  void pwmBegin(int pin, int ch, int hz) { ledcAttach(pin, hz, PWM_RES); }
  void pwmDuty(int pin, int ch, int d)   { ledcWrite(pin, d); }
  void pwmTone(int pin, int ch, int hz)  { ledcWriteTone(pin, hz); }
#else
  void pwmBegin(int pin, int ch, int hz) { ledcSetup(ch, hz, PWM_RES); ledcAttachPin(pin, ch); }
  void pwmDuty(int pin, int ch, int d)   { ledcWrite(ch, d); }
  void pwmTone(int pin, int ch, int hz)  { ledcWriteTone(ch, hz); }
#endif

// ===================== AMBANG BATAS SUHU (deg C) =====================
float setpoint = 18.5;                   // target suhu tengah rentang aman (17.0 - 20.0 C)
const float SP_DEFAULT = 18.5;
const float SP_MIN     = 17.0;
const float SP_MAX     = 20.0;

// Batas bawah Kipas B agar box tidak terlalu dingin (< 17.0 C)
const float STAB_OFF_REL = 1.6;          // Kipas B mati jika suhu <= SP - 1.6 (16.9 C)
const float STAB_ON_REL  = 1.3;          // Kipas B hidup lagi jika suhu >= SP - 1.3 (17.2 C)

// Status suhu: AMAN 17.0-20.0 C | WARNING 20.1-23.0 C (dan < 17.0 C) | KRITIS > 23.0 C (mulai 23.1 C)
const float AMAN_MIN = 17.0;             // batas bawah AMAN
const float AMAN_MAX = 20.0;             // batas atas AMAN (Kipas A OFF <= 19.7 C, ON > 20.0 C)
const float WARN_MAX = 23.0;             // batas atas WARNING; di atas ini (> 23.0 / mulai 23.1) = KRITIS

// ===================== PARAMETER PID & KIPAS B (PRIORITAS PID) =====================
const uint8_t DUTY_SAFE_BASE = 179;      // ~70% saat berada di zona aman (17.0 - 20.0 C)
const uint8_t DUTY_WARN_BASE = 217;      // ~85% saat mulai masuk zona warning (20.1 - 22.0 C)
const uint8_t DUTY_MIN       = 110;      // batas bawah PWM (~43%) agar kipas 12V tidak macet
const uint8_t DUTY_MAX       = 255;      // 100%
const float   FANB_FULL_C    = 23.1;     // suhu saat kipas B mencapai 100% (kritikal)

// Otoritas koreksi I dan D diperbesar agar PID dominan menjaga kestabilan suhu
const float   I_MAX          = 50.0;     // batas koreksi integral (+-50 duty = +-20%)
const float   D_MAX          = 50.0;     // batas koreksi turunan  (+-50 duty = +-20%)
const uint32_t KICK_MS       = 500;      // dorongan 100% saat kipas B mulai berputar dari diam

// Proteksi kipas A
const uint32_t FAN_MIN_ON_MS  = 10000;   // kipas A minimal menyala 10 dtk
const uint32_t FAN_MIN_OFF_MS = 10000;   // kipas A minimal mati 10 dtk

// Indikator & Buzzer (selaras "tit-tit-tit" cepat & kedip merah)
#define BUZZER_AKTIF 1                   // 1 = buzzer AKTIF, 0 = buzzer PASIF
const int      BUZZER_HZ      = 4000;
const uint32_t ALARM_PULSE_MS = 75;      // durasi ON/OFF bunyi "tit" & kedip LED merah (ms)

// Tampilkan baris ke-2 nilai PID di Serial Monitor: 1 = tampil, 0 = sembunyi
#define DEBUG_PID 1

// Interval (ms)
const uint32_t SAMPLE_MS  = 2000;        // default DHT22 maksimal 0.5 Hz (tiap 2 detik)
const uint32_t PUBLISH_MS = 1000;        // kirim data MQTT tiap 1 detik
const uint32_t PRINT_MS   = 1000;        // cetak ke Serial Monitor tiap 1 detik

// ===================== VARIABEL GLOBAL =====================
enum Status { ST_GREEN, ST_YELLOW, ST_RED };

DHTesp dht;
WiFiClient wifiClient;
PubSubClient mqtt(wifiClient);

float tempSensor = NAN, temp = NAN, hum = NAN, tempOffset = 0;
bool  sensorFault = false, coolerOn = true, muted = false;
uint8_t dhtFailCount = 0;
Status status = ST_GREEN;
String lastCmd = "NONE";
bool publishNow = false;

bool     manualMode = false;             // false = AUTO, true = MANUAL (dari dashboard)
bool     manualCool = false;             // keinginan pengguna untuk kipas A saat MANUAL
bool     manualStab = false;             // keinginan pengguna untuk kipas B saat MANUAL
bool     fanCoolOn = false;              // kipas A
uint8_t  fanStabDuty = 0;                // kipas B (0..255)
bool     stabActive = true;
uint32_t tFanA = 0, kickUntil = 0;

// Variabel PID Kipas B
bool  pidOn = true, pidActive = false;
float Kp = 1.5, Ki = 0.25, Kd = 120.0;
float pidPterm = 0, pidI = 0, pidDterm = 0, pidDf = 0, pidPrevT = NAN;

uint32_t tSample = 0, tPublish = 0, tMqttTry = 0, tWifiTry = 0, tAlarm = 0;
bool beepOn = false, alarmPulse = false;

// ===================== FUNGSI BANTU =====================
const char* statusId() {
  if (sensorFault)         return "KRITIS (sensor error)";
  if (status == ST_GREEN)  return "AMAN";
  if (status == ST_YELLOW) return "WARNING";
  return "KRITIS";
}

const char* statusText() {
  if (sensorFault)         return "SENSOR_ERROR";
  if (status == ST_GREEN)  return "GREEN";
  if (status == ST_YELLOW) return "YELLOW";
  return "RED";
}

const char* modeText() {
  if (!coolerOn)    return "OFF";
  if (sensorFault)  return "FAILSAFE";
  if (fanCoolOn)    return "COOLING";
  if (fanStabDuty)  return "HOLD";
  return "IDLE";
}

bool bothFansOff() { return !fanCoolOn && fanStabDuty == 0; }

const char* fansOffReason() {
  if (!bothFansOff()) return "NONE";
  return (coolerOn && !manualMode) ? "TARGET" : "MANUAL";
}

void evaluateStatus() {
  if (sensorFault)                               status = ST_RED;     // sensor error = KRITIS
  else if (!coolerOn)                            status = ST_RED;     // dimatikan manual = KRITIS
  else if (temp > WARN_MAX)                      status = ST_RED;     // > 23.0 C (mulai 23.1 C) = KRITIS
  else if (temp >= AMAN_MIN && temp <= AMAN_MAX) status = ST_GREEN;   // 17.0 - 20.0 C = AMAN
  else                                           status = ST_YELLOW;  // 20.1 - 23.0 C atau < 17.0 C = WARNING
}

void buzz(bool on) {
  if (BUZZER_AKTIF) digitalWrite(PIN_BUZZER, on ? HIGH : LOW);
  else pwmTone(PIN_BUZZER, CH_BUZZER, on ? BUZZER_HZ : 0);
}

bool alarmActive() { return status == ST_RED || (bothFansOff() && !manualMode); }

void updateIndicators() {
  static bool alarmPrev = false;
  bool aktif = alarmActive();
  if (alarmPrev && !aktif) muted = false;
  alarmPrev = aktif;

  if (aktif) {
    if (!alarmPulse && tAlarm == 0) { alarmPulse = true; tAlarm = millis(); }
    else if (millis() - tAlarm >= ALARM_PULSE_MS) { tAlarm = millis(); alarmPulse = !alarmPulse; }
  } else {
    alarmPulse = false;
    tAlarm = 0;
  }

  beepOn = aktif && !muted && alarmPulse;
  buzz(beepOn);

  digitalWrite(PIN_LED_GREEN,  status == ST_GREEN);
  digitalWrite(PIN_LED_YELLOW, status == ST_YELLOW);
  digitalWrite(PIN_LED_RED,    (status == ST_RED) && alarmPulse);
}

// ===================== KONTROL KIPAS =====================
void applyFanStab(uint8_t d) {
  if (d == 0) { fanStabDuty = 0; kickUntil = 0; pwmDuty(PIN_FAN_STAB, CH_FAN_STAB, 0); return; }
  if (fanStabDuty == 0) kickUntil = millis() + KICK_MS;
  fanStabDuty = d;
  pwmDuty(PIN_FAN_STAB, CH_FAN_STAB, (millis() < kickUntil) ? DUTY_MAX : d);
}

// ===================== PID KIPAS B =====================
float computeBaseP(float t) {
  if (t >= FANB_FULL_C) {
    return DUTY_MAX; // 100% di 23.1 C ke atas
  }
  if (t > AMAN_MAX) {
    // Zona Warning (20.1 .. 23.1 C): naik linear dari 85% (217) ke 100% (255)
    float warnStart = AMAN_MAX + 0.1f;
    float span = FANB_FULL_C - warnStart;
    float ratio = constrain((t - warnStart) / span, 0.0f, 1.0f);
    return DUTY_WARN_BASE + ratio * (DUTY_MAX - DUTY_WARN_BASE);
  }
  // Zona Aman (17.0 .. 20.0 C): pusat 70% (179) di setpoint + respons proporsional Kp
  return DUTY_SAFE_BASE + (Kp * 4.0f * (t - setpoint));
}

void resetPid() {
  pidI = 0;
  pidDterm = 0;
  pidDf = 0;
  pidPrevT = NAN;
  pidActive = false;
  pidPterm = isnan(temp) ? 0.0f : computeBaseP(temp);
}

void updatePid(float dt) {
  if (sensorFault || isnan(temp) || !coolerOn || !stabActive) {
    pidPterm = 0; pidI = 0; pidDterm = 0; pidDf = 0; pidPrevT = NAN; pidActive = false;
    return;
  }

  pidPterm = computeBaseP(temp);                            // komponen P selalu aktif sebagai kurva dasar

  // Jika PID_OFF: komponen I dan D dinonaktifkan (0.0)
  if (!pidOn) {
    pidI = 0;
    pidDterm = 0;
    pidDf = 0;
    pidPrevT = temp;
    pidActive = false;
    return;
  }

  float e = temp - setpoint;                                // selisih suhu terhadap setpoint (error)

  if (!isnan(pidPrevT)) {
    float raw = (temp - pidPrevT) / dt;                     // laju perubahan suhu (C/detik)
    pidDf += 0.35f * (raw - pidDf);                         // filter low-pass meredam noise DHT22
    pidDterm = constrain(Kd * pidDf, -D_MAX, D_MAX);        // komponen D: meredam lonjakan/penurunan cepat
  } else {
    pidDterm = 0;
  }

  float u = pidPterm + pidI + pidDterm;
  bool jenuh = (u >= DUTY_MAX && e > 0) || (u <= DUTY_MIN && e < 0);
  if (!jenuh) {
    pidI = constrain(pidI + Ki * e * dt, -I_MAX, I_MAX);    // komponen I: menghapus error sisa jangka panjang
  }

  pidPrevT = temp;
  pidActive = true;
}

void controlFans() {
  uint32_t now = millis();
  bool wantA = fanCoolOn;
  uint8_t dutyB = 0;
  bool force = false;

  if (!coolerOn) {
    wantA = false; dutyB = 0; force = true;
  } else if (sensorFault || isnan(temp)) {
    wantA = true; dutyB = DUTY_MAX; force = true;
  } else {
    // Kipas A: OFF di zona aman (17.0-20.0 C), ON saat masuk Warning/Kritikal (> 20.0 C)
    if (temp > AMAN_MAX)              wantA = true;
    else if (temp <= AMAN_MAX - 0.3f) wantA = false;

    // Kipas B: Prioritas PID penuh
    if (temp <= setpoint - STAB_OFF_REL)     stabActive = false;
    else if (temp >= setpoint - STAB_ON_REL) stabActive = true;

    if (stabActive) {
      if (temp >= FANB_FULL_C) {
        dutyB = DUTY_MAX; // Kritikal (>= 23.1 C) -> 100% penuh
      } else {
        float d = (pidOn && pidActive) ? (pidPterm + pidI + pidDterm) : computeBaseP(temp);
        dutyB = (uint8_t)constrain(d, (float)DUTY_MIN, (float)DUTY_MAX);
      }
    } else {
      dutyB = 0;
    }

    // Mode MANUAL: pengguna menentukan kipas A (ON/OFF) dan kipas B (ON=sesuai PID / OFF)
    if (manualMode) {
      wantA = manualCool;
      if (!manualStab) dutyB = 0;
      force = true;      // abaikan batas minimal ON/OFF kipas A agar perintah langsung dijalankan
    }
  }

  if (wantA != fanCoolOn) {
    uint32_t minTime = fanCoolOn ? FAN_MIN_ON_MS : FAN_MIN_OFF_MS;
    if (force || now - tFanA >= minTime) { fanCoolOn = wantA; tFanA = now; }
  }
  digitalWrite(PIN_FAN_COOL, fanCoolOn);
  applyFanStab(dutyB);
}

// ===================== SENSOR =====================
void readSensor() {
  TempAndHumidity d = dht.getTempAndHumidity();
  if (dht.getStatus() != DHTesp::ERROR_NONE || isnan(d.temperature)) {
    if (dhtFailCount < 3) dhtFailCount++;
    if (dhtFailCount >= 3) {
      sensorFault = true;
      tempSensor = NAN; temp = NAN; hum = NAN;
    }
  } else {
    dhtFailCount = 0;
    sensorFault = false;
    tempSensor = d.temperature;
    hum = d.humidity;
    temp = tempSensor + tempOffset;
  }
}

// ===================== MQTT (KIRIM & TERIMA) =====================
void publishTelemetry() {
  if (!mqtt.connected()) return;
  char tBuf[12], hBuf[12];
  if (sensorFault) { strcpy(tBuf, "null"); strcpy(hBuf, "null"); }
  else { snprintf(tBuf, sizeof(tBuf), "%.1f", temp); snprintf(hBuf, sizeof(hBuf), "%.1f", hum); }

  char json[800];
  snprintf(json, sizeof(json),
    "{\"temp\":%s,\"hum\":%s,\"cooler\":\"%s\",\"status\":\"%s\","
    "\"buzzer\":\"%s\",\"muted\":\"%s\",\"offset\":%.1f,"
    "\"setpoint\":%.1f,\"safe_min\":%.1f,\"safe_max\":%.1f,\"warn_max\":%.1f,"
    "\"fan_cool\":\"%s\",\"fan_stab\":%d,\"fan_stab_duty\":%d,"
    "\"op_mode\":\"%s\",\"mode\":\"%s\",\"fans_off\":\"%s\",\"fans_off_reason\":\"%s\","
    "\"pid\":\"%s\",\"pid_p\":%.1f,\"pid_i\":%.1f,\"pid_d\":%.1f,"
    "\"kp\":%.1f,\"ki\":%.2f,\"kd\":%.1f,\"last_cmd\":\"%s\",\"uptime\":%lu}",
    tBuf, hBuf,
    coolerOn ? "ON" : "OFF", statusText(),
    beepOn ? "ON" : "OFF", muted ? "YES" : "NO", tempOffset,
    setpoint, AMAN_MIN, AMAN_MAX, WARN_MAX,
    fanCoolOn ? "ON" : "OFF", (int)(fanStabDuty * 100 / 255), (int)fanStabDuty,
    manualMode ? "MANUAL" : "AUTO", modeText(), bothFansOff() ? "YES" : "NO", fansOffReason(),
    pidOn ? "ON" : "OFF", pidPterm, pidI, pidDterm,
    Kp, Ki, Kd, lastCmd.c_str(), millis() / 1000UL);

  mqtt.publish(topicData, json);
}

void onMessage(char* topic, byte* payload, unsigned int len) {
  char buf[48];
  if (len > sizeof(buf) - 1) len = sizeof(buf) - 1;
  memcpy(buf, payload, len);
  buf[len] = 0;

  String cmd(buf);
  cmd.trim();
  cmd.replace("\"", "'");
  cmd.toUpperCase();
  String ack = "OK: " + cmd;
  bool needPidReset = false;

  if      (cmd == "COOLER_ON")  coolerOn = true;
  else if (cmd == "COOLER_OFF") coolerOn = false;
  else if (cmd == "MODE_AUTO")   manualMode = false;
  else if (cmd == "MODE_MANUAL") {
    if (!manualMode) { manualMode = true; manualCool = fanCoolOn; manualStab = (fanStabDuty > 0); }
  }
  else if (cmd == "FAN_COOL_ON" || cmd == "FAN_COOL_OFF") {
    if (manualMode) manualCool = (cmd == "FAN_COOL_ON"); else ack = "ERR: ubah ke mode MANUAL dulu";
  }
  else if (cmd == "FAN_STAB_ON" || cmd == "FAN_STAB_OFF") {
    if (manualMode) manualStab = (cmd == "FAN_STAB_ON"); else ack = "ERR: ubah ke mode MANUAL dulu";
  }
  else if (cmd == "MUTE")       muted = true;
  else if (cmd == "UNMUTE")     muted = false;
  else if (cmd == "TEMP_UP")    { setpoint = min(SP_MAX, setpoint + 0.5f); needPidReset = true; }
  else if (cmd == "TEMP_DOWN")  { setpoint = max(SP_MIN, setpoint - 0.5f); needPidReset = true; }
  else if (cmd == "TEMP_RESET") { setpoint = SP_DEFAULT; tempOffset = 0;   needPidReset = true; }
  else if (cmd == "STATUS" || cmd == "PING") { /* langsung balas telemetri terbaru */ }
  else if (cmd.startsWith("TEMP_SET:") || cmd.startsWith("SET:")) {
    float v = cmd.substring(cmd.indexOf(':') + 1).toFloat();
    if (v >= SP_MIN && v <= SP_MAX) { setpoint = v; needPidReset = true; }
    else ack = "ERR: setpoint harus " + String(SP_MIN, 1) + ".." + String(SP_MAX, 1);
  }
  else if (cmd.startsWith("CAL:")) {
    float v = cmd.substring(4).toFloat();
    if (v >= -5 && v <= 5) { tempOffset = v; needPidReset = true; }
    else ack = "ERR: CAL harus -5..5";
  }
  else if (cmd == "PID_ON"  || cmd == "PID ON"  || cmd == "PID:ON")  { pidOn = true;  needPidReset = true; }
  else if (cmd == "PID_OFF" || cmd == "PID OFF" || cmd == "PID:OFF") { pidOn = false; needPidReset = true; }
  else if (cmd.startsWith("KP:")) { float v = cmd.substring(3).toFloat(); if (v >= 0 && v <= 5)   { Kp = v; needPidReset = true; } else ack = "ERR: KP 0..5"; }
  else if (cmd.startsWith("KI:")) { float v = cmd.substring(3).toFloat(); if (v >= 0 && v <= 5)   { Ki = v; needPidReset = true; } else ack = "ERR: KI 0..5"; }
  else if (cmd.startsWith("KD:")) { float v = cmd.substring(3).toFloat(); if (v >= 0 && v <= 200) { Kd = v; needPidReset = true; } else ack = "ERR: KD 0..200"; }
  else ack = "ERR: perintah tidak dikenal";

  lastCmd = ack;
  Serial.println("[MQTT RX] " + ack);

  if (!sensorFault && !isnan(tempSensor)) temp = tempSensor + tempOffset;
  if (needPidReset) resetPid();
  updatePid(SAMPLE_MS / 1000.0f);
  evaluateStatus();
  controlFans();
  updateIndicators();
  publishNow = true; // Jadwalkan balasan telemetri segera di loop()
}

void mqttMaintain() {
  if (WiFi.status() != WL_CONNECTED) {
    if (millis() - tWifiTry > 10000) { tWifiTry = millis(); Serial.println("Menghubungkan WiFi ulang....."); WiFi.reconnect(); }
    return;
  }
  if (!mqtt.connected()) {
    if (millis() - tMqttTry < 5000) return;
    tMqttTry = millis();
    String cid = "esp32-coolerbox-" + String((uint32_t)ESP.getEfuseMac(), HEX);
    Serial.print("Menghubungkan MQTT......");
    if (mqtt.connect(cid.c_str())) {
      Serial.println(" terhubung");
      mqtt.subscribe(topicPerintah);
      publishNow = true;
    } else {
      Serial.printf(" gagal (rc=%d), coba lagi 5 detik\n", mqtt.state());
    }
  }
  mqtt.loop();
}

// ===================== SETUP & LOOP =====================
void setup() {
  Serial.begin(115200);

  pinMode(PIN_LED_GREEN, OUTPUT);
  pinMode(PIN_LED_YELLOW, OUTPUT);
  pinMode(PIN_LED_RED, OUTPUT);
  pinMode(PIN_FAN_COOL, OUTPUT);
  digitalWrite(PIN_FAN_COOL, LOW);

  pwmBegin(PIN_FAN_STAB, CH_FAN_STAB, FAN_PWM_HZ);
  pwmDuty(PIN_FAN_STAB, CH_FAN_STAB, 0);
  pinMode(PIN_BUZZER, OUTPUT);
  digitalWrite(PIN_BUZZER, LOW);
  if (!BUZZER_AKTIF) pwmBegin(PIN_BUZZER, CH_BUZZER, BUZZER_HZ);
  gpio_set_drive_capability((gpio_num_t)PIN_BUZZER, GPIO_DRIVE_CAP_3);
  buzz(false);

  dht.setup(PIN_DHT, DHTesp::DHT22);

  Serial.print("Menghubungkan WiFi");
  WiFi.begin(ssid, password);
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 10000) { delay(250); Serial.print("."); }
  Serial.println(WiFi.status() == WL_CONNECTED ? " terhubung" : " gagal (mode offline, kontrol lokal tetap jalan)");

  mqtt.setServer(mqttServer, mqttPort);
  mqtt.setBufferSize(1024);
  mqtt.setCallback(onMessage);

  readSensor();
  updatePid(SAMPLE_MS / 1000.0f);
  evaluateStatus();
  controlFans();
  updateIndicators();
  tMqttTry = millis() - 5000;
  mqttMaintain();
}

void loop() {
  uint32_t now = millis();

  if (now - tSample >= SAMPLE_MS) {
    tSample = now;
    readSensor();
    updatePid(SAMPLE_MS / 1000.0f);
    evaluateStatus();
  }

  static uint32_t tPrint = 0;
  static bool sudahCetak = false;
  if (!sudahCetak || now - tPrint >= PRINT_MS) {
    sudahCetak = true;
    tPrint = now;
    char sTemp[14], sB[12];
    if (sensorFault) strcpy(sTemp, "ERROR"); else snprintf(sTemp, sizeof(sTemp), "%.1f C", temp);
    if (fanStabDuty == 0) strcpy(sB, "OFF"); else snprintf(sB, sizeof(sB), "ON %d%%", (int)(fanStabDuty * 100 / 255));

    // Baris 1 (Utama): persis seperti format referensi
    Serial.printf("Mode: %s | Suhu: %s | Status: %s | Kipas A: %s | Kipas B: %s | Kedua kipas mati: %s\n",
                  manualMode ? "MANUAL" : "AUTO", sTemp, statusId(), fanCoolOn ? "ON" : "OFF", sB, bothFansOff() ? "YA" : "TIDAK");
#if DEBUG_PID
    // Baris 2: nilai PID di garis baru tepat di bawahnya
    Serial.printf("PID: %s | P=%.1f | I=%.1f | D=%.1f | Kp=%.1f Ki=%.2f Kd=%.1f\n",
                  pidOn ? "ON" : "OFF", pidPterm, pidI, pidDterm, Kp, Ki, Kd);
#endif
  }

  controlFans();
  updateIndicators();
  mqttMaintain();

  if (publishNow || now - tPublish >= PUBLISH_MS) {
    publishNow = false;
    tPublish = now;
    publishTelemetry();
  }
}