package main

import (
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/gofiber/fiber/v3"
)

func (h *Hub) routes(app *fiber.App) {
	app.Get("/health", func(c fiber.Ctx) error {
		return c.JSON(fiber.Map{"ok": true})
	})

	api := app.Group("/api", h.auth)
	api.Get("/devices/:id/latest", h.latest)
	api.Get("/devices/:id/history", h.history)
	api.Get("/devices/:id/events", h.events)
	api.Post("/devices/:id/control", h.control)
	api.Post("/devices/:id/scan", h.scan)
	api.Get("/devices/:id/shipments", h.listShipments)
	api.Post("/devices/:id/shipments", h.createShipment)
	api.Put("/devices/:id/shipments/:sid", h.updateShipment)
	api.Get("/items", h.listItems)
	api.Post("/items", h.saveItem)
}

func (h *Hub) auth(c fiber.Ctx) error {
	if h.cfg.APIToken == "" || c.Get("Authorization") == "Bearer "+h.cfg.APIToken {
		return c.Next()
	}

	return c.Status(fiber.StatusUnauthorized).JSON(
		fiber.Map{"message": "token tidak valid"},
	)
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

	var ship *ShipmentOut

	if st.ShipmentID != nil {
		var sp Shipment
		if h.db.Where("id = ?", *st.ShipmentID).Limit(1).Find(&sp).RowsAffected > 0 {
			o := toShipmentOut(sp)
			ship = &o
		}
	}

	return c.JSON(Latest{
		Shipment:    ship,
		DeviceID:    st.DeviceID,
		BoxID:       st.BoxID,
		ItemUID:     st.ItemUID,
		ItemName:    name,
		Temperature: st.Temperature,
		Status:      st.Status,
		Mode:        st.Mode,
		Fan1:        FanOut{st.Fan1State, st.Fan1RPM, st.Fan1Fault},
		Fan2:        FanOut{st.Fan2State, st.Fan2RPM, st.Fan2Fault},
		Online:      st.Online && time.Since(st.LastSeen) < h.cfg.StaleAfter,
		LastSeen:    st.LastSeen,
		UpdatedAt:   st.UpdatedAt,
	})
}

type Point struct {
	T    time.Time `json:"t"`
	Temp float64   `json:"temp"`
}

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
		Order("created_at asc").
		Find(&rows)

	out := []Point{}
	var sum float64
	var n int
	var key int64 = -1

	flush := func() {
		if n > 0 {
			out = append(out, Point{
				T:    time.Unix(key*bucket, 0).UTC(),
				Temp: math.Round(sum/float64(n)*100) / 100,
			})
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

	h.db.Where("device_id = ?", c.Params("id")).
		Order("created_at desc").
		Limit(limit).
		Find(&rows)

	out := make([]EventOut, 0, len(rows))

	for _, e := range rows {
		out = append(out, EventOut{
			Time:    e.CreatedAt,
			Type:    e.Type,
			Message: e.Message,
		})
	}

	return c.JSON(out)
}

// ControlReq menerima perintah dari dashboard.
type ControlReq struct {
	Mode    *string `json:"mode,omitempty"`
	Fan1    *string `json:"fan1,omitempty"`
	Fan2    *string `json:"fan2,omitempty"`
	Command *string `json:"command,omitempty"`
}

func in(v string, allowed ...string) bool {
	for _, a := range allowed {
		if v == a {
			return true
		}
	}

	return false
}

// translateCommand mengubah input dashboard menjadi perintah teks ESP32.
func translateCommand(req ControlReq) (string, string, error) {
	count := 0

	if req.Mode != nil {
		count++
	}
	if req.Fan1 != nil {
		count++
	}
	if req.Fan2 != nil {
		count++
	}
	if req.Command != nil {
		count++
	}

	if count != 1 {
		return "", "", fmt.Errorf("kirim satu perintah dalam satu waktu")
	}

	if req.Command != nil {
		command := strings.ToUpper(strings.TrimSpace(*req.Command))

		allowed := []string{
			"COOLER_ON",
			"COOLER_OFF",
			"MUTE",
			"UNMUTE",
			"TEMP_UP",
			"TEMP_DOWN",
			"TEMP_RESET",
			"PID_ON",
			"PID_OFF",
		}

		if !in(command, allowed...) {
			return "", "", fmt.Errorf("perintah ESP32 tidak dikenali")
		}

		return command, command, nil
	}

	if req.Mode != nil {
		return "", "", fmt.Errorf(
			"firmware ESP32 belum mendukung pengaturan mode auto/manual",
		)
	}

	if req.Fan1 != nil {
		switch *req.Fan1 {
		case "normal":
			return "COOLER_ON", "kipas pendingin menyala", nil
		case "off":
			return "COOLER_OFF", "kipas pendingin mati", nil
		case "high":
			return "", "", fmt.Errorf(
				"pengaturan kecepatan tinggi belum didukung firmware ESP32",
			)
		default:
			return "", "", fmt.Errorf("nilai kipas 1 tidak valid")
		}
	}

	if req.Fan2 != nil {
		switch *req.Fan2 {
		case "on":
			return "PID_ON", "PID aktif", nil
		case "off":
			return "PID_OFF", "PID nonaktif", nil
		default:
			return "", "", fmt.Errorf("nilai kipas 2 tidak valid")
		}
	}

	return "", "", fmt.Errorf("perintah tidak valid")
}

func (h *Hub) control(c fiber.Ctx) error {
	id := c.Params("id")

	var req ControlReq

	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	payload, description, err := translateCommand(req)

	if err != nil {
		return fail(c, fiber.StatusUnprocessableEntity, err.Error())
	}

	_, existed := h.loadState(id)

	if !existed {
		return fail(c, fiber.StatusNotFound, "perangkat belum pernah mengirim data")
	}

	if err := h.publishCmd(id, []byte(payload)); err != nil {
		return fail(
			c,
			fiber.StatusBadGateway,
			"perintah gagal dikirim: "+err.Error(),
		)
	}

	h.db.Create(&Event{
		DeviceID:  id,
		Type:      "info",
		Message:   "Perintah dari dashboard: " + description,
		CreatedAt: time.Now().UTC(),
	})

	return c.Status(fiber.StatusAccepted).JSON(fiber.Map{
		"message": "perintah berhasil dikirim",
		"command": payload,
	})
}

type ScanReq struct {
	ItemUID string `json:"item_uid"`
	BoxID   string `json:"box_id"`
}

func (h *Hub) scan(c fiber.Ctx) error {
	id := c.Params("id")

	var req ScanReq

	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	req.ItemUID = strings.TrimSpace(req.ItemUID)

	if req.ItemUID == "" || len(req.ItemUID) > 64 {
		return fail(
			c,
			fiber.StatusUnprocessableEntity,
			"item_uid wajib diisi (maksimal 64 karakter)",
		)
	}

	st, existed := h.loadState(id)
	if !existed {
		return fail(c, fiber.StatusNotFound, "perangkat belum pernah mengirim data")
	}

	// Cocokkan UID/barcode dengan database.
	var it Item
	if h.db.Where("uid = ?", req.ItemUID).Limit(1).Find(&it).RowsAffected == 0 {
		return fail(c, fiber.StatusNotFound, "barcode/UID tidak terdaftar di database")
	}

	// Kalau barcode ini sudah punya pengiriman aktif di box ini, kirim datanya
	// supaya form terbuka dalam mode edit/lihat, bukan membuat data ganda.
	var active *ShipmentOut

	if st.ShipmentID != nil {
		var sp Shipment
		if h.db.Where("id = ? AND item_uid = ?", *st.ShipmentID, req.ItemUID).
			Limit(1).Find(&sp).RowsAffected > 0 {
			o := toShipmentOut(sp)
			active = &o
		}
	}

	return c.JSON(fiber.Map{
		"item_uid":    it.UID,
		"item_name":   it.Name,
		"server_time": time.Now().UTC(),
		"active":      active,
	})
}

func (h *Hub) listItems(c fiber.Ctx) error {
	items := []Item{}

	h.db.Order("name asc").Find(&items)

	return c.JSON(items)
}

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
