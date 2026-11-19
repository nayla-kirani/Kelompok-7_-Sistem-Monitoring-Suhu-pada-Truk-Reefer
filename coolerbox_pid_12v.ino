/*
 * SMART COOLER BOX - Prototype 2 kipas DC 12V + es batu + PID (khusus perangkat keras asli)
 * Board  : ESP32 DevKit (30/38 pin)
 * Daya   : Adaptor 12V -> (+) kedua kipas dan masukan buck 12V->5V
 *          Buck 5V -> pin VIN ESP32. Source MOSFET, GND buck, GND ESP32, GND adaptor = satu GND
 * Sensor : DHT22 (suhu + kelembapan di dalam box)
 * Aktuator:
 *   KIPAS A "PENDINGIN" : meniup udara melewati wadah es batu -> udara dingin masuk ke box (ON/OFF, histeresis)
 *   KIPAS B "STABILISASI": sirkulasi udara dalam box agar suhu merata & stabil (PWM proporsional)
 * Indikator: LED hijau/kuning/merah + buzzer (buzzer berbunyi saat status MERAH atau kedua kipas mati)
 * Cloud  : MQTT HiveMQ (format JSON kompatibel dengan dashboard lama)
 *
 * Kontrol kipas berjalan LOKAL di ESP32 (tidak tergantung WiFi/MQTT).
 *
 * PERINTAH MQTT (topic .../perintah)
 *  COOLER_ON | COOLER_OFF  : sistem pendingin aktif / dimatikan manual (kedua kipas mati)
 *  TEMP_SET:3.5 | SET:3.5  : ubah setpoint (SP_MIN..SP_MAX)
 *  TEMP_UP | TEMP_DOWN     : setpoint +/- 0.5 C
 *  CAL:-0.4                : koreksi kalibrasi sensor (offset, -5..+5 C)
 *  TEMP_RESET              : setpoint kembali default & offset = 0
 *  MUTE | UNMUTE           : senyapkan / aktifkan buzzer
 *  PID_ON | PID_OFF        : PID kipas B aktif / hanya P (perilaku lama)
 *  KP:x | KI:x | KD:x      : ubah gain PID (Kp 0..100, Ki 0..5, Kd 0..200)
 */

#include <WiFi.h>
#include <PubSubClient.h>
#include "DHTesp.h"
#include "driver/gpio.h"

// ===================== KONFIGURASI JARINGAN =====================
const char* ssid     = "asa";    // <-- ganti
const char* password = "12345789";     // <-- ganti
const char* mqttServer    = "broker.hivemq.com";
const int   mqttPort      = 1883;
const char* topicData     = "unsoed/project/coolerbox/data";
const char* topicPerintah = "unsoed/project/coolerbox/perintah";

// ===================== PIN =====================
#define PIN_DHT        15
#define PIN_LED_GREEN  25
#define PIN_LED_YELLOW 26
#define PIN_LED_RED    27
#define PIN_BUZZER     14
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
float setpoint = 16.5;                    // target suhu dalam box
const float SP_DEFAULT = 16.5;
const float SP_MIN     = 15.0;
const float SP_MAX     = 18.0;

const float H_ON  = 0.5;                 // Kipas A ON  jika suhu >= SP + 0.5
const float H_OFF = 0.5;                 // Kipas A OFF jika suhu <= SP - 0.5
const float STAB_OFF_REL = 1.0;          // Kipas B mati jika suhu <= SP - 1.0 (terlalu dingin)
const float STAB_ON_REL  = 0.7;          // Kipas B hidup lagi jika suhu >= SP - 0.7

const float SAFE_TOL     = 3.0;          // batas AMAN (hijau)  = SP + 1.0 (default 4 C)
const float BATAS_KRITIS = 20.0;          // > 8 C = KRITIS (merah)

// Kipas B (stabilisasi)
const uint8_t DUTY_HOLD = 130;           // ~51% (+-6V efektif): kipas 12V butuh duty lebih tinggi agar tidak macet
const uint8_t DUTY_MAX  = 255;
const float   I_MAX     = 100.0;         // batas integral PID (anti-windup), satuan duty
const uint32_t KICK_MS  = 500;           // dorongan 100% saat kipas B mulai berputar (kipas 12V lebih berat)

// Proteksi kipas A
const uint32_t FAN_MIN_ON_MS  = 10000;   // kipas A minimal menyala 10 dtk
const uint32_t FAN_MIN_OFF_MS = 10000;   // kipas A minimal mati 10 dtk

// Buzzer (volume maksimal): nada di frekuensi resonansi, duty 50%, arus pin maksimal
const int      BUZZER_HZ   = 4000;       // frekuensi buzzer maksimal (4000 Hz)
const uint32_t BEEP_ON_MS  = 400;        // bunyi lebih lama daripada diam
const uint32_t BEEP_OFF_MS = 100;

// Tampilkan nilai P, I, D di Serial Monitor untuk tuning: 1 = tampil, 0 = sembunyi
#define DEBUG_PID 0

// Interval (ms)
const uint32_t SAMPLE_MS  = 2000;        // DHT22 maksimal 0.5 Hz
const uint32_t PUBLISH_MS = 3000;
const uint32_t PRINT_MS   = 10000;       // baris data di Serial Monitor tiap 10 detik

// ===================== VARIABEL GLOBAL =====================
enum Status { ST_GREEN, ST_YELLOW, ST_RED };

DHTesp dht;
WiFiClient wifiClient;
PubSubClient mqtt(wifiClient);

float tempSensor = NAN, temp = NAN, hum = NAN, tempOffset = 0;
bool  sensorFault = false, coolerOn = true, muted = false;
Status status = ST_GREEN;

bool     fanCoolOn = false;              // kipas A
uint8_t  fanStabDuty = 0;                // kipas B (0..255)
bool     stabActive = true;
uint32_t tFanA = 0, kickUntil = 0;

// PID kipas B (aktif saat mendinginkan). Kp = 38 sama dengan logika proporsional sebelumnya
bool  pidOn = true, pidActive = false;
float Kp = 38.0, Ki = 0.3, Kd = 40.0;    // satuan duty per C, per C.dtk, dtk.C
float pidI = 0, pidDterm = 0, pidDf = 0, pidPrevT = NAN;

uint32_t tSample = 0, tPublish = 0, tMqttTry = 0, tWifiTry = 0, tBeep = 0;
bool beepOn = false;

// ===================== FUNGSI BANTU =====================
// Teks status untuk Serial Monitor
const char* statusId() {
  if (sensorFault)         return "KRITIS (sensor error)";
  if (status == ST_GREEN)  return "AMAN";
  if (status == ST_YELLOW) return "WARNING";
  return "KRITIS";
}

const char* statusText() {
  if (sensorFault) return "SENSOR_ERROR";
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

// PARAMETER: kedua kipas mati
bool bothFansOff() { return !fanCoolOn && fanStabDuty == 0; }

// Alasan kedua kipas mati:
//  MANUAL = pendingin dimatikan lewat perintah COOLER_OFF
//  TARGET = suhu sudah terlalu dingin / di bawah target (normal)
//  NONE   = setidaknya satu kipas sedang menyala
const char* fansOffReason() {
  if (!bothFansOff()) return "NONE";
  return coolerOn ? "TARGET" : "MANUAL";
}

void evaluateStatus() {
  if (sensorFault)                    status = ST_RED;      // sensor putus = bahaya
  else if (!coolerOn)                 status = ST_RED;      // pendingin dimatikan manual
  else if (temp > BATAS_KRITIS)       status = ST_RED;
  else if (temp <= setpoint + SAFE_TOL) status = ST_GREEN;
  else                                status = ST_YELLOW;   // di atas batas aman
}

void updateLeds() {
  digitalWrite(PIN_LED_GREEN,  status == ST_GREEN);
  digitalWrite(PIN_LED_YELLOW, status == ST_YELLOW);
  digitalWrite(PIN_LED_RED,    status == ST_RED);
}

// Alarm aktif jika status MERAH atau KEDUA KIPAS MATI
bool alarmActive() { return status == ST_RED || bothFansOff(); }

void handleBuzzer() {
  static bool alarmPrev = false;
  bool aktif = alarmActive();
  if (alarmPrev && !aktif) muted = false;                    // reset mute setelah semua kondisi alarm selesai
  alarmPrev = aktif;

  bool alarm = aktif && !muted;
  if (alarm) {
    if (millis() - tBeep >= (beepOn ? BEEP_ON_MS : BEEP_OFF_MS)) {
      tBeep = millis();
      beepOn = !beepOn;
      pwmTone(PIN_BUZZER, CH_BUZZER, beepOn ? BUZZER_HZ : 0);
    }
  } else if (beepOn || tBeep != 0) {
    beepOn = false;
    tBeep = 0;
    pwmTone(PIN_BUZZER, CH_BUZZER, 0);
  }
}

// ===================== KONTROL KIPAS =====================
void applyFanStab(uint8_t d) {
  if (d == 0) { fanStabDuty = 0; kickUntil = 0; pwmDuty(PIN_FAN_STAB, CH_FAN_STAB, 0); return; }
  if (fanStabDuty == 0) kickUntil = millis() + KICK_MS;     // start dari diam -> beri dorongan
  fanStabDuty = d;
  pwmDuty(PIN_FAN_STAB, CH_FAN_STAB, (millis() < kickUntil) ? DUTY_MAX : d);
}

// ===================== PID KIPAS B =====================
// Dihitung tiap SAMPLE_MS. Suku P dihitung langsung di controlFans(); di sini hanya I dan D.
void resetPid() { pidI = 0; pidDterm = 0; pidDf = 0; pidPrevT = NAN; pidActive = false; }

void updatePid(float dt) {
  if (sensorFault || isnan(temp) || !coolerOn || !fanCoolOn) { resetPid(); return; }   // hanya saat mendinginkan
  if (!pidOn) { resetPid(); return; }                                                 // mode P saja
  float e = temp - setpoint;                                // positif = terlalu hangat
  if (!isnan(pidPrevT)) {
    float raw = (temp - pidPrevT) / dt;                     // laju perubahan suhu (turunan)
    pidDf += 0.3f * (raw - pidDf);                          // low-pass karena DHT22 berisik
    pidDterm = Kd * pidDf;
  }
  float u = DUTY_HOLD + Kp * e + pidI + pidDterm;
  bool jenuh = (u >= DUTY_MAX && e > 0) || (u <= DUTY_HOLD && e < 0);
  if (!jenuh) pidI = constrain(pidI + Ki * e * dt, -I_MAX, I_MAX);   // anti-windup
  pidPrevT = temp;
  pidActive = true;
}

void controlFans() {
  uint32_t now = millis();
  bool wantA = fanCoolOn;
  uint8_t dutyB = 0;
  bool force = false;                                        // abaikan waktu minimum jika true

  if (!coolerOn) {                                           // dimatikan manual
    wantA = false; dutyB = 0; force = true;
  } else if (sensorFault || isnan(temp)) {                   // FAIL-SAFE: sensor rusak -> dinginkan maksimal
    wantA = true; dutyB = DUTY_MAX; force = true;
  } else {
    // Kipas A: ON/OFF dengan histeresis (deadband +/-0.5 C)
    if (temp >= setpoint + H_ON)       wantA = true;
    else if (temp <= setpoint - H_OFF) wantA = false;

    // Kipas B: proporsional saat mendinginkan, sirkulasi pelan saat hold
    if (wantA) {
      float d = DUTY_HOLD + (temp - setpoint) * Kp;           // suku P
      if (pidOn && pidActive) d += pidI + pidDterm;           // + suku I dan D
      dutyB = (uint8_t)constrain(d, (float)DUTY_HOLD, (float)DUTY_MAX);
    } else {
      if (temp <= setpoint - STAB_OFF_REL)      stabActive = false;   // terlalu dingin -> stop
      else if (temp >= setpoint - STAB_ON_REL)  stabActive = true;
      dutyB = stabActive ? DUTY_HOLD : 0;
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
    sensorFault = true;
    tempSensor = NAN; temp = NAN; hum = NAN;
  } else {
    sensorFault = false;
    tempSensor = d.temperature;
    hum = d.humidity;
    temp = tempSensor + tempOffset;
  }
}

// ===================== MQTT =====================
void publishTelemetry() {
  if (!mqtt.connected()) return;
  char tBuf[12], hBuf[12];
  if (sensorFault) { strcpy(tBuf, "null"); strcpy(hBuf, "null"); }
  else { snprintf(tBuf, sizeof(tBuf), "%.1f", temp); snprintf(hBuf, sizeof(hBuf), "%.1f", hum); }

  char json[448];
  snprintf(json, sizeof(json),
    "{\"temp\":%s,\"hum\":%s,\"cooler\":\"%s\",\"status\":\"%s\","
    "\"buzzer\":\"%s\",\"muted\":\"%s\",\"offset\":%.1f,"
    "\"setpoint\":%.1f,\"safe_max\":%.1f,\"fan_cool\":\"%s\",\"fan_stab\":%d,"
    "\"mode\":\"%s\",\"fans_off\":\"%s\",\"fans_off_reason\":\"%s\",\"pid\":\"%s\",\"kp\":%.1f,\"ki\":%.2f,\"kd\":%.1f,\"uptime\":%lu}",
    tBuf, hBuf,
    coolerOn ? "ON" : "OFF", statusText(),
    beepOn ? "ON" : "OFF", muted ? "YES" : "NO", tempOffset,
    setpoint, setpoint + SAFE_TOL, fanCoolOn ? "ON" : "OFF", (int)(fanStabDuty * 100 / 255),
    modeText(), bothFansOff() ? "YES" : "NO", fansOffReason(), pidOn ? "ON" : "OFF", Kp, Ki, Kd, millis() / 1000UL);
  mqtt.publish(topicData, json);
}

void onMessage(char* topic, byte* payload, unsigned int len) {
  char buf[32];
  if (len > sizeof(buf) - 1) len = sizeof(buf) - 1;
  memcpy(buf, payload, len);
  buf[len] = 0;

  String cmd(buf);
  cmd.trim();
  cmd.toUpperCase();
  String ack = "OK: " + cmd;

  if      (cmd == "COOLER_ON")  coolerOn = true;
  else if (cmd == "COOLER_OFF") coolerOn = false;
  else if (cmd == "MUTE")       muted = true;
  else if (cmd == "UNMUTE")     muted = false;
  else if (cmd == "TEMP_UP")    setpoint = min(SP_MAX, setpoint + 0.5f);
  else if (cmd == "TEMP_DOWN")  setpoint = max(SP_MIN, setpoint - 0.5f);
  else if (cmd == "TEMP_RESET") { setpoint = SP_DEFAULT; tempOffset = 0; }
  else if (cmd.startsWith("TEMP_SET:") || cmd.startsWith("SET:")) {
    float v = cmd.substring(cmd.indexOf(':') + 1).toFloat();
    if (v >= SP_MIN && v <= SP_MAX) setpoint = v;
    else ack = "ERR: setpoint harus " + String(SP_MIN, 1) + ".." + String(SP_MAX, 1);
  }
  else if (cmd.startsWith("CAL:")) {
    float v = cmd.substring(4).toFloat();
    if (v >= -5 && v <= 5) tempOffset = v;
    else ack = "ERR: CAL harus -5..5";
  }
  else if (cmd == "PID_ON")     pidOn = true;
  else if (cmd == "PID_OFF")    pidOn = false;
  else if (cmd.startsWith("KP:")) { float v = cmd.substring(3).toFloat(); if (v >= 0 && v <= 100) Kp = v; else ack = "ERR: KP 0..100"; }
  else if (cmd.startsWith("KI:")) { float v = cmd.substring(3).toFloat(); if (v >= 0 && v <= 5)   Ki = v; else ack = "ERR: KI 0..5"; }
  else if (cmd.startsWith("KD:")) { float v = cmd.substring(3).toFloat(); if (v >= 0 && v <= 200) Kd = v; else ack = "ERR: KD 0..200"; }
  else ack = "ERR: perintah tidak dikenal";

  Serial.println(ack);
  resetPid();                                               // setpoint/gain berubah -> mulai ulang integral
  if (!sensorFault) temp = tempSensor + tempOffset;
  evaluateStatus();
  updateLeds();
  controlFans();
  publishTelemetry();
}

void mqttMaintain() {
  if (WiFi.status() != WL_CONNECTED) {
    if (millis() - tWifiTry > 10000) { tWifiTry = millis(); Serial.println("Menghubungkan WiFi ulang....."); WiFi.reconnect(); }
    return;
  }
  if (!mqtt.connected()) {
    if (millis() - tMqttTry < 5000) return;                  // non-blocking: kontrol lokal tetap jalan
    tMqttTry = millis();
    String cid = "esp32-coolerbox-" + String((uint32_t)ESP.getEfuseMac(), HEX);
    Serial.print("Menghubungkan MQTT......");
    if (mqtt.connect(cid.c_str())) {
      Serial.println(" terhubung");
      mqtt.subscribe(topicPerintah);
      publishTelemetry();
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
  pwmBegin(PIN_BUZZER, CH_BUZZER, BUZZER_HZ);
  gpio_set_drive_capability((gpio_num_t)PIN_BUZZER, GPIO_DRIVE_CAP_3);   // arus pin maksimal (~40 mA)
  pwmTone(PIN_BUZZER, CH_BUZZER, 0);

  dht.setup(PIN_DHT, DHTesp::DHT22);

  Serial.print("Menghubungkan WiFi");
  WiFi.begin(ssid, password);
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 10000) { delay(250); Serial.print("."); }
  Serial.println(WiFi.status() == WL_CONNECTED ? " terhubung" : " gagal (mode offline, kontrol lokal tetap jalan)");

  mqtt.setServer(mqttServer, mqttPort);
  mqtt.setBufferSize(512);
  mqtt.setCallback(onMessage);

  readSensor();
  evaluateStatus();
  updateLeds();
  controlFans();
  tMqttTry = millis() - 5000;                               // coba MQTT sekarang juga
  mqttMaintain();
}

void loop() {
  uint32_t now = millis();

  if (now - tSample >= SAMPLE_MS) {
    tSample = now;
    readSensor();
    updatePid(SAMPLE_MS / 1000.0f);
    evaluateStatus();
    updateLeds();
  }

  // Cetak ke Serial Monitor tiap PRINT_MS (pembacaan dan kontrol tetap tiap SAMPLE_MS)
  static uint32_t tPrint = 0;
  static bool sudahCetak = false;
  if (!sudahCetak || now - tPrint >= PRINT_MS) {
    sudahCetak = true;
    tPrint = now;
    char sTemp[14], sB[10];
    if (sensorFault) strcpy(sTemp, "ERROR"); else snprintf(sTemp, sizeof(sTemp), "%.1f C", temp);
    if (fanStabDuty == 0) strcpy(sB, "OFF"); else snprintf(sB, sizeof(sB), "ON %d%%", (int)(fanStabDuty * 100 / 255));
    Serial.printf("Suhu: %s | Status: %s | Kipas A: %s | Kipas B: %s | Kedua kipas mati: %s\n",
                  sTemp, statusId(), fanCoolOn ? "ON" : "OFF", sB, bothFansOff() ? "YA" : "TIDAK");
#if DEBUG_PID
    Serial.printf("   PID %s | P=%.0f I=%.0f D=%.0f | Kp=%.1f Ki=%.2f Kd=%.1f\n",
                  pidOn ? "ON" : "OFF", isnan(temp) ? 0.0f : Kp * (temp - setpoint), pidI, pidDterm, Kp, Ki, Kd);
#endif
  }

  controlFans();          // dipanggil tiap loop (histeresis & waktu minimum menjaga kestabilan)
  handleBuzzer();
  mqttMaintain();

  if (now - tPublish >= PUBLISH_MS) {
    tPublish = now;
    publishTelemetry();
  }
}
