package main

import (
	"encoding/json"
	"fmt"
	"log"
	"strings"
	"sync"
	"time"

	mqtt "github.com/eclipse/paho.mqtt.golang"
	"gorm.io/gorm"
)

type Hub struct {
	db  *gorm.DB
	cfg Config
	mq  mqtt.Client
	mu  sync.Mutex
}

// Struktur telemetry mengikuti JSON dari firmware ESP32.
type Telemetry struct {
	Temperature *float64 `json:"temp"`
	Humidity    *float64 `json:"hum"`

	Cooler   string  `json:"cooler"`
	Status   string  `json:"status"`
	Buzzer   string  `json:"buzzer"`
	Muted    string  `json:"muted"`
	Offset   float64 `json:"offset"`
	Setpoint *float64 `json:"setpoint"`
	SafeMin  *float64 `json:"safe_min"`
	SafeMax  *float64 `json:"safe_max"`
	WarnMax  *float64 `json:"warn_max"`

	// Kipas A (pendingin es): "ON" / "OFF".
	FanCool string `json:"fan_cool"`
	// Kipas B (penjaga suhu / stabilisasi): kecepatan dalam persen (0..100),
	// dihitung ESP32 dari duty PWM, sama dengan yang tampil di Serial Monitor.
	FanStab     int `json:"fan_stab"`
	FanStabDuty int `json:"fan_stab_duty"`

	Mode      string  `json:"mode"`
	PID       string  `json:"pid"`
	KP        float64 `json:"kp"`
	KI        float64 `json:"ki"`
	KD        float64 `json:"kd"`
	PIDOutput float64 `json:"pid_output"`
	Uptime    uint64  `json:"uptime"`
	BoxID     string  `json:"box_id"`
	ItemUID   string  `json:"item_uid"`
}

type Scan struct {
	ItemUID string `json:"item_uid"`
	BoxID   string `json:"box_id"`
}

var statusLabel = map[string]string{
	"normal":   "Normal",
	"warning":  "Warning",
	"critical": "Kritis",
}

func fan1Text(s string) string {
	if s == "on" {
		return "menyala"
	}
	return "mati"
}

func fan2Text(s string) string {
	if s == "on" {
		return "menyala"
	}
	return "mati"
}

func clean(s string, allowed ...string) string {
	for _, a := range allowed {
		if s == a {
			return s
		}
	}
	return allowed[0]
}

// Nilai awal zona suhu, sama dengan AMAN_MIN, AMAN_MAX, dan WARN_MAX di firmware.
// Setelah telemetry pertama masuk, nilai ini diganti dengan yang dikirim ESP32.
func (h *Hub) defaultThresholds() (float64, float64, float64) {
	return 17.0, 20.0, 23.0
}

// Status dari ESP32 (GREEN/YELLOW/RED/SENSOR_ERROR) diubah ke istilah dashboard,
// sehingga dashboard selalu sama dengan LED di perangkat.
func statusFromDevice(s string) string {
	switch strings.ToUpper(strings.TrimSpace(s)) {
	case "GREEN":
		return "normal"
	case "YELLOW":
		return "warning"
	case "RED", "SENSOR_ERROR":
		return "critical"
	}
	return ""
}

// Klasifikasi suhu berdasarkan ambang batas yang tersimpan.
func classify(
	prev string,
	t float64,
	safeMin float64,
	safeMax float64,
	warnMax float64,
	hysteresis float64,
) string {
	// Hysteresis untuk mengurangi perubahan status yang terlalu cepat.
	if hysteresis < 0 {
		hysteresis = 0
	}

	if prev == "critical" && t > warnMax-hysteresis {
		return "critical"
	}

	if prev == "warning" {
		if t < safeMin+hysteresis ||
			(t > safeMax-hysteresis && t <= warnMax) {
			return "warning"
		}
	}

	switch {
	case t < safeMin:
		return "warning"
	case t <= safeMax:
		return "normal"
	case t <= warnMax:
		return "warning"
	default:
		return "critical"
	}
}

func (h *Hub) loadState(id string) (st DeviceState, existed bool) {
	result := h.db.Where("device_id = ?", id).
		Limit(1).
		Find(&st)

	existed = result.RowsAffected > 0

	if !existed {
		st.DeviceID = id
		st.SafeMin, st.SafeMax, st.WarnMax = h.defaultThresholds()
	} else {
		// Menangani data lama yang belum memiliki nilai ambang batas.
		defaultMin, defaultMax, defaultWarn := h.defaultThresholds()

		if st.SafeMin == 0 && st.SafeMax == 0 && st.WarnMax == 0 {
			st.SafeMin = defaultMin
			st.SafeMax = defaultMax
			st.WarnMax = defaultWarn
		}
	}

	return
}

func (h *Hub) onTelemetry(id string, b []byte) {
	var in Telemetry

	if err := json.Unmarshal(b, &in); err != nil {
		log.Printf("telemetry %s tidak valid: %s", id, b)
		return
	}

	// Saat sensor error, ESP32 mengirim "temp": null dan status "SENSOR_ERROR".
	// Data seperti ini tetap diproses agar dashboard tahu sensornya bermasalah.
	sensorError := in.Temperature == nil ||
		strings.EqualFold(strings.TrimSpace(in.Status), "SENSOR_ERROR")

	if in.Temperature == nil && !sensorError {
		log.Printf("telemetry %s tidak memiliki data suhu: %s", id, b)
		return
	}

	h.mu.Lock()
	defer h.mu.Unlock()

	st, _ := h.loadState(id)

	now := time.Now().UTC()

	// Saat sensor error, suhu terakhir yang valid dipertahankan (tidak masuk grafik).
	t := st.Temperature
	if in.Temperature != nil {
		t = *in.Temperature
	}

	// Zona suhu yang dipakai ESP32 ikut disimpan agar tampilan bar suhu sama.
	if in.SafeMin != nil && in.SafeMax != nil && in.WarnMax != nil &&
		*in.SafeMin < *in.SafeMax && *in.SafeMax < *in.WarnMax {
		st.SafeMin = *in.SafeMin
		st.SafeMax = *in.SafeMax
		st.WarnMax = *in.WarnMax
	}

	// Status memakai hasil ESP32. Kalau tidak ada, hitung sendiri sebagai cadangan.
	status := statusFromDevice(in.Status)
	if status == "" {
		status = classify(
			st.Status,
			t,
			st.SafeMin,
			st.SafeMax,
			st.WarnMax,
			h.cfg.Hysteresis,
		)
	}

	mode := strings.ToUpper(strings.TrimSpace(in.Mode))
	if mode == "" {
		mode = "UNKNOWN"
	}

	// Kipas 1 di dashboard = penjaga suhu = Kipas B (stabilisasi) di ESP32.
	// ESP32 mengirim kecepatannya dalam persen lewat field "fan_stab".
	fan1Percent := in.FanStab
	if fan1Percent < 0 {
		fan1Percent = 0
	}
	if fan1Percent > 100 {
		fan1Percent = 100
	}

	f1 := "off"
	if fan1Percent > 0 {
		f1 = "on"
	}

	// Kipas 2 di dashboard = pendingin es = Kipas A (pendingin) di ESP32.
	// Hanya ON/OFF lewat field "fan_cool".
	fan2Percent := 0
	f2 := "off"
	if strings.EqualFold(strings.TrimSpace(in.FanCool), "ON") {
		fan2Percent = 100
		f2 = "on"
	}

	var evs []Event

	add := func(typ, msg string) {
		evs = append(evs, Event{
			DeviceID:  id,
			Type:      typ,
			Message:   msg,
			CreatedAt: now,
		})
	}

	if st.Online == false && !st.LastSeen.IsZero() {
		add("info", "Perangkat kembali online")
	}

	if sensorError != st.SensorError && st.LastSeen.IsZero() == false {
		if sensorError {
			add("critical", "Sensor suhu error, alarm aktif")
		} else {
			add("info", "Sensor suhu kembali normal")
		}
	}

	if status != st.Status && st.Status != "" && !sensorError {
		add("info", fmt.Sprintf(
			"Kondisi %s, suhu %.1f°C",
			statusLabel[status], t,
		))
	}

	if f1 != st.Fan1State && st.Fan1State != "" {
		add("info", "Kipas 1 "+fan1Text(f1))
	}

	if f2 != st.Fan2State && st.Fan2State != "" {
		add("info", "Kipas 2 "+fan2Text(f2))
	}

	if mode != st.Mode && st.Mode != "" {
		add("info", "Mode berubah ke "+mode)
	}

	if st.LastSeen.IsZero() {
		add("info", "Perangkat pertama kali terhubung")
	}

	if in.ItemUID != "" && in.ItemUID != st.ItemUID {
		add("info", "Barang terpasang: "+h.itemLabel(in.ItemUID))
		st.ItemUID = in.ItemUID
	}

	if in.BoxID != "" {
		st.BoxID = in.BoxID
	}

	st.Temperature = t
	st.SensorError = sensorError
	st.Muted = strings.EqualFold(strings.TrimSpace(in.Muted), "YES")
	// Mute/unmute hanya berlaku untuk dua kondisi: Kritis dan sensor error.
	// Sensor error otomatis berstatus "critical", jadi cukup periksa status.
	st.Alarm = status == "critical" || sensorError
	st.Status = status
	st.Mode = mode
	st.Cooler = strings.ToUpper(strings.TrimSpace(in.Cooler))

	if in.Setpoint != nil {
		sp := *in.Setpoint
		st.Setpoint = &sp
	}

	st.Fan1State = f1
	st.Fan1RPM = nil
	st.Fan1Fault = false
	st.Fan1Percent = fan1Percent

	st.Fan2State = f2
	st.Fan2RPM = nil
	st.Fan2Fault = false
	st.Fan2Percent = fan2Percent

	st.Online = true
	st.LastSeen = now
	st.UpdatedAt = now

	if err := h.db.Save(&st).Error; err != nil {
		log.Println("Gagal menyimpan state:", err)
		return
	}

	if !sensorError {
		if err := h.db.Create(&Reading{
			DeviceID:    id,
			Temperature: t,
			Status:      status,
			CreatedAt:   now,
		}).Error; err != nil {
			log.Println("Gagal menyimpan reading:", err)
		}
	}

	if len(evs) > 0 {
		if err := h.db.Create(&evs).Error; err != nil {
			log.Println("Gagal menyimpan event:", err)
		}
	}

	log.Printf(
		"Telemetry diterima | suhu: %.1f°C | kelembapan: %v | mode: %s | kipas pendingin: %s | kipas penjaga suhu: %d%%",
		t,
		in.Humidity,
		mode,
		in.FanCool,
		fan1Percent,
	)
}

func (h *Hub) onStatus(id, payload string) {
	online := strings.TrimSpace(payload) == "online"

	h.mu.Lock()
	defer h.mu.Unlock()

	st, existed := h.loadState(id)
	if !existed {
		return
	}

	if st.Online != online {
		msg := "Perangkat offline"
		if online {
			msg = "Perangkat online"
		}

		h.db.Create(&Event{
			DeviceID:  id,
			Type:      "info",
			Message:   msg,
			CreatedAt: time.Now().UTC(),
		})
	}

	st.Online = online

	if online {
		st.LastSeen = time.Now().UTC()
	}

	h.db.Save(&st)
}

func (h *Hub) onScan(id string, b []byte) {
	var in Scan

	if err := json.Unmarshal(b, &in); err != nil || in.ItemUID == "" {
		log.Printf("scan %s tidak valid: %s", id, b)
		return
	}

	h.mu.Lock()
	defer h.mu.Unlock()

	st, existed := h.loadState(id)
	if !existed {
		return
	}

	st.ItemUID = in.ItemUID

	if in.BoxID != "" {
		st.BoxID = in.BoxID
	}

	h.db.Save(&st)

	h.db.Create(&Event{
		DeviceID:  id,
		Type:      "info",
		Message:   "Barang discan: " + h.itemLabel(in.ItemUID),
		CreatedAt: time.Now().UTC(),
	})
}

func (h *Hub) itemLabel(uid string) string {
	var it Item

	if h.db.Where("uid = ?", uid).Limit(1).Find(&it).RowsAffected > 0 {
		return fmt.Sprintf("%s (%s)", it.Name, uid)
	}

	return uid
}
