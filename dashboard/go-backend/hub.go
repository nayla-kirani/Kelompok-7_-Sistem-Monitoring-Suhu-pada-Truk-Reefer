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
	mu  sync.Mutex // menjaga pembaruan state dari callback MQTT tetap berurutan
}

type FanIn struct {
	State string `json:"state"`
	RPM   *int   `json:"rpm"`
	Fault bool   `json:"fault"`
}

type Telemetry struct {
	Temperature *float64 `json:"temperature"`
	Mode        string   `json:"mode"`
	Fan1        FanIn    `json:"fan1"`
	Fan2        FanIn    `json:"fan2"`
	BoxID       string   `json:"box_id"`
	ItemUID     string   `json:"item_uid"`
}

type Scan struct {
	ItemUID string `json:"item_uid"`
	BoxID   string `json:"box_id"`
}

var statusLabel = map[string]string{"normal": "Normal", "warning": "Warning", "critical": "Kritis"}

func fan1Text(s string) string {
	switch s {
	case "normal":
		return "menyala, kecepatan normal"
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

// clean mengembalikan s kalau termasuk nilai yang diizinkan, selain itu nilai pertama (default).
func clean(s string, allowed ...string) string {
	for _, a := range allowed {
		if s == a {
			return s
		}
	}
	return allowed[0]
}

// classify menentukan kondisi dari suhu:
// normal (< WarnAt), warning (WarnAt sampai CritAt), kritis (> CritAt).
// Hysteresis mencegah status berganti-ganti saat suhu naik turun tipis di sekitar batas.
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
		if (prev == "warning" || prev == "critical") && t >= cfg.WarnAt-cfg.Hysteresis {
			return "warning"
		}
		return "normal"
	}
}

// loadState memuat state perangkat; existed=false kalau perangkat belum pernah mengirim telemetry.
func (h *Hub) loadState(id string) (st DeviceState, existed bool) {
	existed = h.db.Where("device_id = ?", id).Limit(1).Find(&st).RowsAffected > 0
	if !existed {
		st.DeviceID = id
	}
	return
}

func (h *Hub) onTelemetry(id string, b []byte) {
	var in Telemetry
	if err := json.Unmarshal(b, &in); err != nil || in.Temperature == nil {
		log.Printf("telemetry %s tidak valid: %s", id, b)
		return
	}

	h.mu.Lock()
	defer h.mu.Unlock()

	st, existed := h.loadState(id)
	now := time.Now().UTC()
	t := *in.Temperature
	status := classify(st.Status, t, h.cfg)
	mode := clean(in.Mode, "auto", "manual")
	f1 := clean(in.Fan1.State, "off", "normal", "high")
	f2 := clean(in.Fan2.State, "off", "on")

	var evs []Event
	add := func(typ, msg string) {
		evs = append(evs, Event{DeviceID: id, Type: typ, Message: msg, CreatedAt: now})
	}

	if existed {
		if !st.Online {
			add("info", "Perangkat kembali online")
		}
		if status != st.Status {
			add(status, fmt.Sprintf("Kondisi %s, suhu %.1f°C", statusLabel[status], t))
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
		if in.Fan1.Fault && !st.Fan1Fault {
			add("critical", "Kipas 1 bermasalah, tidak berputar")
		}
		if in.Fan2.Fault && !st.Fan2Fault {
			add("critical", "Kipas 2 bermasalah, tidak berputar")
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

	st.Temperature, st.Status, st.Mode = t, status, mode
	st.Fan1State, st.Fan1RPM, st.Fan1Fault = f1, in.Fan1.RPM, in.Fan1.Fault
	st.Fan2State, st.Fan2RPM, st.Fan2Fault = f2, in.Fan2.RPM, in.Fan2.Fault
	st.Online, st.LastSeen, st.UpdatedAt = true, now, now

	h.db.Save(&st)
	h.db.Create(&Reading{DeviceID: id, Temperature: t, Status: status, CreatedAt: now})
	if len(evs) > 0 {
		h.db.Create(&evs)
	}
}

// onStatus menerima "online"/"offline" (offline dikirim broker lewat Last Will ESP32).
func (h *Hub) onStatus(id, payload string) {
	online := strings.TrimSpace(payload) == "online"

	h.mu.Lock()
	defer h.mu.Unlock()

	st, existed := h.loadState(id)
	if !existed {
		return // tunggu telemetry pertama supaya state punya data lengkap
	}
	if st.Online != online {
		msg := "Perangkat offline"
		if online {
			msg = "Perangkat online"
		}
		h.db.Create(&Event{DeviceID: id, Type: "info", Message: msg, CreatedAt: time.Now().UTC()})
	}
	st.Online = online
	if online {
		st.LastSeen = time.Now().UTC()
	}
	h.db.Save(&st)
}

// onScan menerima hasil scan barcode/UID dari ESP32.
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
	h.db.Create(&Event{DeviceID: id, Type: "info", Message: "Barang discan: " + h.itemLabel(in.ItemUID), CreatedAt: time.Now().UTC()})
}

// itemLabel menampilkan "Nama (UID)" kalau barang sudah terdaftar, kalau belum cukup UID.
func (h *Hub) itemLabel(uid string) string {
	var it Item
	if h.db.Where("uid = ?", uid).Limit(1).Find(&it).RowsAffected > 0 {
		return fmt.Sprintf("%s (%s)", it.Name, uid)
	}
	return uid
}
