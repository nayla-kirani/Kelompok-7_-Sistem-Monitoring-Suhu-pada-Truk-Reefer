package main

import (
	"encoding/json"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/gofiber/fiber/v3"
)

func (h *Hub) routes(app *fiber.App) {
	app.Get("/health", func(c fiber.Ctx) error { return c.JSON(fiber.Map{"ok": true}) })

	api := app.Group("/api", h.auth)
	api.Get("/devices/:id/latest", h.latest)
	api.Get("/devices/:id/history", h.history)
	api.Get("/devices/:id/events", h.events)
	api.Post("/devices/:id/control", h.control)
	api.Post("/devices/:id/scan", h.scan)
	api.Get("/shipments/:uid", h.getShipment)
	api.Post("/devices/:id/shipments", h.createShipment)
	api.Put("/shipments/:uid", h.editShipment)
	api.Get("/items", h.listItems)
	api.Post("/items", h.saveItem)
}

// auth memeriksa Bearer token. Kalau API_TOKEN kosong, pemeriksaan dilewati (hanya untuk development).
func (h *Hub) auth(c fiber.Ctx) error {
	if h.cfg.APIToken == "" || c.Get("Authorization") == "Bearer "+h.cfg.APIToken {
		return c.Next()
	}
	return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"message": "token tidak valid"})
}

func fail(c fiber.Ctx, code int, msg string) error {
	return c.Status(code).JSON(fiber.Map{"message": msg})
}

type FanOut struct {
	State string `json:"state"`
	RPM   *int   `json:"rpm"`
	Fault bool   `json:"fault"`
}

type Latest struct {
	DeviceID    string    `json:"device_id"`
	BoxID       string    `json:"box_id"`
	ItemUID     string    `json:"item_uid"`
	ItemName    string    `json:"item_name"`
	Temperature float64   `json:"temperature"`
	Status      string    `json:"status"`
	Mode        string    `json:"mode"`
	Fan1        FanOut    `json:"fan1"`
	Fan2        FanOut    `json:"fan2"`
	Online      bool      `json:"online"`
	LastSeen    time.Time `json:"last_seen"`
	UpdatedAt   time.Time `json:"updated_at"`
	Shipment    *ShipmentOut `json:"shipment"`
}

func (h *Hub) latest(c fiber.Ctx) error {
	id := c.Params("id")
	st, existed := h.loadState(id)
	if !existed {
		return fail(c, fiber.StatusNotFound, "perangkat belum pernah mengirim data")
	}

	var name string
	if st.ItemUID != "" {
		var it Item
		if h.db.Where("uid = ?", st.ItemUID).Limit(1).Find(&it).RowsAffected > 0 {
			name = it.Name
		}
	}

	// Kalau ID sudah punya catatan muatan, nama barang diambil dari catatan itu.
	ship := h.shipmentOut(st.ItemUID)
	if ship != nil {
		name = ship.ItemName
	}

	return c.JSON(Latest{
		DeviceID:    st.DeviceID,
		BoxID:       st.BoxID,
		ItemUID:     st.ItemUID,
		ItemName:    name,
		Temperature: st.Temperature,
		Status:      st.Status,
		Mode:        st.Mode,
		Fan1:        FanOut{st.Fan1State, st.Fan1RPM, st.Fan1Fault},
		Fan2:        FanOut{st.Fan2State, st.Fan2RPM, st.Fan2Fault},
		// Dianggap offline kalau tidak ada data baru lebih lama dari DEVICE_STALE_SECONDS.
		Online:    st.Online && time.Since(st.LastSeen) < h.cfg.StaleAfter,
		LastSeen:  st.LastSeen,
		Shipment:  ship,
		UpdatedAt: st.UpdatedAt,
	})
}

type Point struct {
	T    time.Time `json:"t"`
	Temp float64   `json:"temp"`
}

// history mengembalikan suhu rata-rata per bucket waktu supaya grafik tetap ringan.
func (h *Hub) history(c fiber.Ctx) error {
	id := c.Params("id")
	dur, bucket := time.Hour, int64(10)
	switch c.Query("range", "1h") {
	case "6h":
		dur, bucket = 6*time.Hour, 60
	case "24h":
		dur, bucket = 24*time.Hour, 300
	}

	var rows []Reading
	h.db.Select("created_at", "temperature").
		Where("device_id = ? AND created_at >= ?", id, time.Now().UTC().Add(-dur)).
		Order("created_at asc").Find(&rows)

	out := []Point{}
	var sum float64
	var n int
	var key int64 = -1
	flush := func() {
		if n > 0 {
			out = append(out, Point{T: time.Unix(key*bucket, 0).UTC(), Temp: math.Round(sum/float64(n)*100) / 100})
		}
	}
	for _, r := range rows {
		k := r.CreatedAt.Unix() / bucket
		if k != key {
			flush()
			key, sum, n = k, 0, 0
		}
		sum += r.Temperature
		n++
	}
	flush()
	return c.JSON(out)
}

type EventOut struct {
	Time    time.Time `json:"time"`
	Type    string    `json:"type"`
	Message string    `json:"message"`
}

func (h *Hub) events(c fiber.Ctx) error {
	limit, err := strconv.Atoi(c.Query("limit", "30"))
	if err != nil || limit < 1 || limit > 100 {
		limit = 30
	}
	var rows []Event
	h.db.Where("device_id = ?", c.Params("id")).Order("created_at desc").Limit(limit).Find(&rows)

	out := make([]EventOut, 0, len(rows))
	for _, e := range rows {
		out = append(out, EventOut{Time: e.CreatedAt, Type: e.Type, Message: e.Message})
	}
	return c.JSON(out)
}

type ControlReq struct {
	Mode *string `json:"mode,omitempty"`
	Fan1 *string `json:"fan1,omitempty"`
	Fan2 *string `json:"fan2,omitempty"`
}

func in(v string, allowed ...string) bool {
	for _, a := range allowed {
		if v == a {
			return true
		}
	}
	return false
}

func (h *Hub) control(c fiber.Ctx) error {
	id := c.Params("id")
	var req ControlReq
	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}
	if req.Mode == nil && req.Fan1 == nil && req.Fan2 == nil {
		return fail(c, fiber.StatusBadRequest, "tidak ada perintah yang dikirim")
	}
	if (req.Mode != nil && !in(*req.Mode, "auto", "manual")) ||
		(req.Fan1 != nil && !in(*req.Fan1, "off", "normal", "high")) ||
		(req.Fan2 != nil && !in(*req.Fan2, "off", "on")) {
		return fail(c, fiber.StatusUnprocessableEntity, "nilai perintah tidak valid")
	}

	st, existed := h.loadState(id)
	if !existed {
		return fail(c, fiber.StatusNotFound, "perangkat belum pernah mengirim data")
	}
	// Perintah kipas hanya dipakai di mode manual.
	if (req.Fan1 != nil || req.Fan2 != nil) && st.Mode != "manual" && (req.Mode == nil || *req.Mode != "manual") {
		return fail(c, fiber.StatusConflict, "ubah ke mode manual dulu sebelum mengatur kipas")
	}

	payload, _ := json.Marshal(req)
	if err := h.publishCmd(id, payload); err != nil {
		return fail(c, fiber.StatusBadGateway, "perintah gagal dikirim: "+err.Error())
	}

	var parts []string
	if req.Mode != nil {
		parts = append(parts, "mode "+*req.Mode)
	}
	if req.Fan1 != nil {
		parts = append(parts, "kipas 1 "+*req.Fan1)
	}
	if req.Fan2 != nil {
		parts = append(parts, "kipas 2 "+*req.Fan2)
	}
	h.db.Create(&Event{DeviceID: id, Type: "info", Message: "Perintah dari dashboard: " + strings.Join(parts, ", "), CreatedAt: time.Now().UTC()})

	return c.Status(fiber.StatusAccepted).JSON(fiber.Map{"message": "perintah dikirim"})
}

type ScanReq struct {
	ItemUID string `json:"item_uid"`
	BoxID   string `json:"box_id"`
}

// scan menerima hasil scan barcode dari kamera dashboard.
// Alurnya sama dengan scan dari ESP32 lewat MQTT (topic .../scan), jadi memakai onScan.
func (h *Hub) scan(c fiber.Ctx) error {
	id := c.Params("id")
	var req ScanReq
	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}
	req.ItemUID = strings.TrimSpace(req.ItemUID)
	if req.ItemUID == "" || len(req.ItemUID) > 64 {
		return fail(c, fiber.StatusUnprocessableEntity, "item_uid wajib diisi (maksimal 64 karakter)")
	}
	if _, existed := h.loadState(id); !existed {
		return fail(c, fiber.StatusNotFound, "perangkat belum pernah mengirim data")
	}
	b, _ := json.Marshal(req)
	h.onScan(id, b)
	return c.Status(fiber.StatusAccepted).JSON(fiber.Map{"message": "scan tersimpan"})
}

func (h *Hub) listItems(c fiber.Ctx) error {
	items := []Item{}
	h.db.Order("name asc").Find(&items)
	return c.JSON(items)
}

// saveItem mendaftarkan atau memperbarui nama barang untuk sebuah UID.
func (h *Hub) saveItem(c fiber.Ctx) error {
	var it Item
	if err := c.Bind().Body(&it); err != nil || it.UID == "" || it.Name == "" {
		return fail(c, fiber.StatusBadRequest, "uid dan name wajib diisi")
	}
	if err := h.db.Save(&it).Error; err != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal menyimpan barang")
	}
	return c.Status(fiber.StatusCreated).JSON(it)
}