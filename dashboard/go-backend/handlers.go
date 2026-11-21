package main

import (
	"encoding/json"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/gofiber/fiber/v3"
	"gorm.io/gorm"
)

func (h *Hub) routes(app *fiber.App) {
	app.Get("/health", func(c fiber.Ctx) error {
		return c.JSON(fiber.Map{"ok": true})
	})

	api := app.Group("/api", h.auth)

	// Monitoring perangkat.
	api.Get("/devices/:id/latest", h.latest)
	api.Get("/devices/:id/history", h.history)
	api.Get("/devices/:id/events", h.events)
	api.Post("/devices/:id/control", h.control)
	api.Post("/devices/:id/scan", h.scan)

	// Pengelolaan barang.
	api.Get("/items", h.listItems)
	api.Post("/items", h.saveItem)

	// Pengelolaan box.
	api.Get("/boxes", h.listBoxes)
	api.Post("/boxes/bulk", h.createBoxesBulk)
	api.Put("/boxes/:uid", h.updateBox)
	api.Get("/boxes/:uid/validate", h.validateBox)

	// Transaksi pengiriman.
	api.Get("/shipments/latest", h.getLatestShipment)
	api.Post("/shipments", h.createShipment)
	api.Get("/shipments/:id", h.getShipment)
	api.Put("/shipments/:id", h.updateShipment)
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

// ============================================================
// MONITORING PERANGKAT
// ============================================================

type FanOut struct {
	State   string `json:"state"`
	RPM     *int   `json:"rpm"`
	Fault   bool   `json:"fault"`
	Percent int    `json:"percent"`
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

	return c.JSON(Latest{
		DeviceID:    st.DeviceID,
		BoxID:       st.BoxID,
		ItemUID:     st.ItemUID,
		ItemName:    name,
		Temperature: st.Temperature,
		Status:      st.Status,
		Mode:        st.Mode,

		// Fan 1 = Fan A (pendingin).
		Fan1: FanOut{
			State:   st.Fan1State,
			RPM:     st.Fan1RPM,
			Fault:   st.Fan1Fault,
			Percent: st.Fan1Percent,
		},

		// Fan 2 = Fan B (penjaga suhu).
		Fan2: FanOut{
			State:   st.Fan2State,
			RPM:     st.Fan2RPM,
			Fault:   st.Fan2Fault,
			Percent: st.Fan2Percent,
		},

		Online:    st.Online && time.Since(st.LastSeen) < h.cfg.StaleAfter,
		LastSeen:  st.LastSeen,
		UpdatedAt: st.UpdatedAt,
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

// ============================================================
// KONTROL PERANGKAT
// ============================================================

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

// ============================================================
// SCAN ITEM DARI ESP32
// ============================================================

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

	if _, existed := h.loadState(id); !existed {
		return fail(c, fiber.StatusNotFound, "perangkat belum pernah mengirim data")
	}

	b, _ := json.Marshal(req)

	h.onScan(id, b)

	return c.Status(fiber.StatusAccepted).JSON(
		fiber.Map{"message": "scan tersimpan"},
	)
}

// ============================================================
// PENGELOLAAN ITEM
// ============================================================

func (h *Hub) listItems(c fiber.Ctx) error {
	items := []Item{}

	if err := h.db.Order("name asc").Find(&items).Error; err != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal mengambil daftar barang")
	}

	return c.JSON(items)
}

func (h *Hub) saveItem(c fiber.Ctx) error {
	var it Item

	if err := c.Bind().Body(&it); err != nil ||
		strings.TrimSpace(it.UID) == "" ||
		strings.TrimSpace(it.Name) == "" {
		return fail(c, fiber.StatusBadRequest, "uid dan name wajib diisi")
	}

	it.UID = strings.TrimSpace(it.UID)
	it.Name = strings.TrimSpace(it.Name)

	if len(it.UID) > 64 || len(it.Name) > 128 {
		return fail(c, fiber.StatusBadRequest, "panjang UID atau nama barang tidak valid")
	}

	if err := h.db.Save(&it).Error; err != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal menyimpan barang")
	}

	return c.Status(fiber.StatusCreated).JSON(it)
}

// ============================================================
// PENGELOLAAN BOX
// ============================================================

type CreateBoxesBulkReq struct {
	Boxes []BoxInput `json:"boxes"`
}

type BoxInput struct {
	UID      string  `json:"uid"`
	ItemName string  `json:"item_name"`
	WeightKg float64 `json:"weight_kg"`
}

// GET /api/boxes
func (h *Hub) listBoxes(c fiber.Ctx) error {
	var boxes []Box

	if err := h.db.Order("uid ASC").Find(&boxes).Error; err != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal mengambil daftar box")
	}

	return c.JSON(boxes)
}

// POST /api/boxes/bulk
func (h *Hub) createBoxesBulk(c fiber.Ctx) error {
	var req CreateBoxesBulkReq

	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	if len(req.Boxes) == 0 || len(req.Boxes) > 100 {
		return fail(c, fiber.StatusBadRequest, "jumlah box harus 1 sampai 100")
	}

	seen := make(map[string]bool)
	boxes := make([]Box, 0, len(req.Boxes))

	now := time.Now().In(jakartaLocation)

	for _, input := range req.Boxes {
		input.UID = strings.TrimSpace(input.UID)
		input.ItemName = strings.TrimSpace(input.ItemName)

		if input.UID == "" || len(input.UID) > 64 {
			return fail(c, fiber.StatusBadRequest, "UID box wajib diisi dan maksimal 64 karakter")
		}

		if input.ItemName == "" || len(input.ItemName) > 128 {
			return fail(c, fiber.StatusBadRequest, "nama barang wajib diisi dan maksimal 128 karakter")
		}

		if math.IsNaN(input.WeightKg) || math.IsInf(input.WeightKg, 0) ||
			input.WeightKg <= 0 || input.WeightKg > 100000 {
			return fail(c, fiber.StatusBadRequest, "berat harus lebih dari 0 dan maksimal 100000 kg")
		}

		if seen[input.UID] {
			return fail(c, fiber.StatusBadRequest, "terdapat UID box yang duplikat")
		}

		seen[input.UID] = true

		weight := input.WeightKg

		boxes = append(boxes, Box{
			UID:       input.UID,
			Status:    "available",
			ItemName:  input.ItemName,
			WeightKg:  &weight,
			CreatedAt: now,
			UpdatedAt: now,
		})
	}

	err := h.db.Transaction(func(tx *gorm.DB) error {
		for i := range boxes {
			if err := tx.Create(&boxes[i]).Error; err != nil {
				return err
			}
		}

		return nil
	})

	if err != nil {
		return fail(c, fiber.StatusConflict, "gagal membuat box. Pastikan UID belum terdaftar")
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{
		"message": "daftar box berhasil dibuat",
		"data":    boxes,
	})
}

// PUT /api/boxes/:uid
func (h *Hub) updateBox(c fiber.Ctx) error {
	uid := strings.TrimSpace(c.Params("uid"))

	if uid == "" || len(uid) > 64 {
		return fail(c, fiber.StatusBadRequest, "UID box tidak valid")
	}

	var input BoxInput

	if err := c.Bind().Body(&input); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	input.ItemName = strings.TrimSpace(input.ItemName)

	if input.ItemName == "" || len(input.ItemName) > 128 {
		return fail(c, fiber.StatusBadRequest, "nama barang wajib diisi")
	}

	if math.IsNaN(input.WeightKg) || math.IsInf(input.WeightKg, 0) ||
		input.WeightKg <= 0 || input.WeightKg > 100000 {
		return fail(c, fiber.StatusBadRequest, "berat barang tidak valid")
	}

	var box Box

	err := h.db.First(&box, "uid = ?", uid).Error

	if err != nil {
		if err == gorm.ErrRecordNotFound {
			return fail(c, fiber.StatusNotFound, "box tidak ditemukan")
		}

		return fail(c, fiber.StatusInternalServerError, "gagal mengambil data box")
	}

	if box.Status != "available" {
		return fail(c, fiber.StatusConflict, "data box yang sudah digunakan tidak dapat diubah")
	}

	weight := input.WeightKg

	box.ItemName = input.ItemName
	box.WeightKg = &weight
	box.UpdatedAt = time.Now().In(jakartaLocation)

	if err := h.db.Save(&box).Error; err != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal memperbarui data box")
	}

	return c.JSON(fiber.Map{
		"message": "data box berhasil diperbarui",
		"data":    box,
	})
}

// GET /api/boxes/:uid/validate
func (h *Hub) validateBox(c fiber.Ctx) error {
	uid := strings.TrimSpace(c.Params("uid"))

	if uid == "" || len(uid) > 64 {
		return fail(c, fiber.StatusBadRequest, "UID box tidak valid")
	}

	var box Box

	result := h.db.First(&box, "uid = ?", uid)

	if result.Error != nil {
		if result.Error == gorm.ErrRecordNotFound {
			return fail(c, fiber.StatusNotFound, "UID box tidak terdaftar")
		}

		return fail(c, fiber.StatusInternalServerError, "gagal memeriksa UID box")
	}

	itemName := strings.TrimSpace(box.ItemName)

	// Pastikan data barang dan berat tersedia.
	if itemName == "" || box.WeightKg == nil ||
		*box.WeightKg <= 0 {
		return c.Status(fiber.StatusUnprocessableEntity).JSON(fiber.Map{
			"valid":     false,
			"reusable":  false,
			"message":   "Data barang dan berat box belum dilengkapi",
			"uid":       box.UID,
			"status":    box.Status,
			"item_name": itemName,
			"weight_kg": box.WeightKg,
		})
	}

	// Box available boleh digunakan.
	// Box used tetap bisa menampilkan detail barang,
	// tetapi tidak boleh digunakan untuk transaksi baru.
	reusable := box.Status == "available"

	message := "UID box valid dan dapat digunakan"

	if !reusable {
		message = "Barang ini sudah di-scan dan tidak dapat digunakan kembali"
	}

	return c.JSON(fiber.Map{
		"valid":     true,
		"reusable":  reusable,
		"message":   message,
		"uid":       box.UID,
		"status":    box.Status,
		"item_name": itemName,
		"weight_kg": *box.WeightKg,
	})
}
