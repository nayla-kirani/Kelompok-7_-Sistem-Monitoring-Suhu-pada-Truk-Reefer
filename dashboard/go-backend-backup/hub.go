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
	Setpoint float64 `json:"setpoint"`
	SafeMax  float64 `json:"safe_max"`

	FanCool string `json:"fan_cool"`
	FanStab int    `json:"fan_stab"`

	Mode          string `json:"mode"`
	FansOff       string `json:"fans_off"`
	FansOffReason string `json:"fans_off_reason"`

	PID    string  `json:"pid"`
	KP     float64 `json:"kp"`
	KI     float64 `json:"ki"`
	KD     float64 `json:"kd"`
	Uptime uint64  `json:"uptime"`

	BoxID   string `json:"box_id"`
	ItemUID string `json:"item_uid"`
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
	switch s {
	case "normal":
		return "menyala"
	case "high":
		return "menyala, kecepatan tinggi"
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

func classify(prev string, t float64, cfg Config) string {
	switch {
	case t > cfg.CritAt:
		return "critical"
	case t >= cfg.WarnAt:
		if prev == "critical" && t > cfg.CritAt-cfg.Hysteresis {
			return "critical"
		}
		return "warning"
	default:
		if (prev == "warning" || prev == "critical") &&
			t >= cfg.WarnAt-cfg.Hysteresis {
			return "warning"
		}
		return "normal"
	}
}

func (h *Hub) loadState(id string) (st DeviceState, existed bool) {
	existed = h.db.Where("device_id = ?", id).
		Limit(1).
		Find(&st).RowsAffected > 0

	if !existed {
		st.DeviceID = id
	}

	return
}

func (h *Hub) onTelemetry(id string, b []byte) {
	var in Telemetry

	if err := json.Unmarshal(b, &in); err != nil {
		log.Printf("telemetry %s tidak valid: %s", id, b)
		return
	}

	if in.Temperature == nil {
		log.Printf("telemetry %s tidak memiliki data suhu: %s", id, b)
		return
	}

	h.mu.Lock()
	defer h.mu.Unlock()

	st, existed := h.loadState(id)

	now := time.Now().UTC()
	t := *in.Temperature

	status := classify(st.Status, t, h.cfg)

	// Mode dari ESP32: OFF, FAILSAFE, COOLING, HOLD, atau IDLE.
	mode := strings.ToUpper(strings.TrimSpace(in.Mode))
	if mode == "" {
		mode = "UNKNOWN"
	}

	// Fan cooling ESP32: ON/OFF.
	f1 := "off"
	if strings.EqualFold(in.FanCool, "ON") {
		f1 = "normal"
	}

	// Fan stabilisasi ESP32: nilai PWM 0-100.
	f2 := "off"
	if in.FanStab > 0 {
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

	if existed {
		if !st.Online {
			add("info", "Perangkat kembali online")
		}

		if status != st.Status {
			add("info", fmt.Sprintf(
				"Kondisi %s, suhu %.1f°C",
				statusLabel[status], t,
			))
		}

		if f1 != st.Fan1State {
			add("info", "Kipas 1 "+fan1Text(f1))
		}

		if f2 != st.Fan2State {
			add("info", "Kipas 2 "+fan2Text(f2))
		}

		if mode != st.Mode {
			add("info", "Mode berubah ke "+mode)
		}
	} else {
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
	st.Status = status
	st.Mode = mode

	// Firmware belum mengirim data RPM atau fault kipas.
	st.Fan1State = f1
	st.Fan1RPM = nil
	st.Fan1Fault = false

	st.Fan2State = f2
	st.Fan2RPM = nil
	st.Fan2Fault = false

	st.Online = true
	st.LastSeen = now
	st.UpdatedAt = now

	if err := h.db.Save(&st).Error; err != nil {
		log.Println("Gagal menyimpan state:", err)
		return
	}

	if err := h.db.Create(&Reading{
		DeviceID:    id,
		Temperature: t,
		Status:      status,
		CreatedAt:   now,
	}).Error; err != nil {
		log.Println("Gagal menyimpan reading:", err)
	}

	if len(evs) > 0 {
		if err := h.db.Create(&evs).Error; err != nil {
			log.Println("Gagal menyimpan event:", err)
		}
	}

	log.Printf(
		"Telemetry diterima | suhu: %.1f°C | kelembapan: %v | mode: %s | fan cooling: %s | fan stabilisasi: %d%%",
		t,
		in.Humidity,
		mode,
		in.FanCool,
		in.FanStab,
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
