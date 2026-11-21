package main

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/gofiber/fiber/v3"
	"gorm.io/gorm"
)

// Data pengiriman hanya boleh diedit sebanyak ini setelah disimpan pertama kali.
const maxShipmentEdits = 1

type ShipmentOut struct {
	Shipment
	Editable bool `json:"editable"`
}

func toShipmentOut(s Shipment) ShipmentOut {
	return ShipmentOut{Shipment: s, Editable: s.EditCount < maxShipmentEdits}
}

// ShipmentReq adalah isian form. ID, tanggal, dan waktu TIDAK diterima dari klien.
type ShipmentReq struct {
	ItemUID    string  `json:"item_uid"`
	ItemName   string  `json:"item_name"`
	DriverName string  `json:"driver_name"`
	Quantity   float64 `json:"quantity"`
	Unit       string  `json:"unit"`
}

func (r *ShipmentReq) validate() error {
	r.ItemUID = strings.TrimSpace(r.ItemUID)
	r.ItemName = strings.TrimSpace(r.ItemName)
	r.DriverName = strings.TrimSpace(r.DriverName)
	r.Unit = strings.ToLower(strings.TrimSpace(r.Unit))

	switch {
	case r.ItemName == "" || len(r.ItemName) > 128:
		return fmt.Errorf("nama barang wajib diisi (maksimal 128 karakter)")
	case r.DriverName == "" || len(r.DriverName) > 128:
		return fmt.Errorf("nama supir wajib diisi (maksimal 128 karakter)")
	case r.Quantity <= 0 || r.Quantity > 1000000:
		return fmt.Errorf("jumlah harus lebih dari 0")
	case !in(r.Unit, "kg", "pcs", "box"):
		return fmt.Errorf("satuan harus kg, pcs, atau box")
	}

	return nil
}

func (h *Hub) createShipment(c fiber.Ctx) error {
	id := c.Params("id")

	var req ShipmentReq

	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	if err := req.validate(); err != nil {
		return fail(c, fiber.StatusUnprocessableEntity, err.Error())
	}

	if req.ItemUID == "" || len(req.ItemUID) > 64 {
		return fail(c, fiber.StatusUnprocessableEntity, "item_uid wajib diisi")
	}

	// UID harus terdaftar di database.
	var it Item
	if h.db.Where("uid = ?", req.ItemUID).Limit(1).Find(&it).RowsAffected == 0 {
		return fail(c, fiber.StatusNotFound, "barcode/UID tidak terdaftar di database")
	}

	h.mu.Lock()
	defer h.mu.Unlock()

	st, existed := h.loadState(id)
	if !existed {
		return fail(c, fiber.StatusNotFound, "perangkat belum pernah mengirim data")
	}

	// Cegah data ganda: barcode yang sama masih aktif di box ini.
	if st.ShipmentID != nil {
		var cur Shipment
		if h.db.Where("id = ? AND item_uid = ?", *st.ShipmentID, req.ItemUID).
			Limit(1).Find(&cur).RowsAffected > 0 {
			return fail(c, fiber.StatusConflict, "barcode ini sudah tercatat, gunakan edit")
		}
	}

	// Waktu diambil dari server, bukan dari browser, supaya tidak bisa dipalsukan.
	now := time.Now().UTC()

	sp := Shipment{
		DeviceID:   id,
		ItemUID:    req.ItemUID,
		ItemName:   req.ItemName,
		DriverName: req.DriverName,
		Quantity:   req.Quantity,
		Unit:       req.Unit,
		CreatedAt:  now,
		UpdatedAt:  now,
	}

	err := h.db.Transaction(func(tx *gorm.DB) error {
		if err := tx.Create(&sp).Error; err != nil {
			return err
		}

		st.ItemUID = sp.ItemUID
		st.ShipmentID = &sp.ID

		if err := tx.Save(&st).Error; err != nil {
			return err
		}

		return tx.Create(&Event{
			DeviceID: id,
			Type:     "info",
			Message: fmt.Sprintf("Pengiriman dicatat: %s, %g %s, supir %s",
				sp.ItemName, sp.Quantity, sp.Unit, sp.DriverName),
			CreatedAt: now,
		}).Error
	})

	if err != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal menyimpan pengiriman")
	}

	return c.Status(fiber.StatusCreated).JSON(toShipmentOut(sp))
}

func (h *Hub) updateShipment(c fiber.Ctx) error {
	id := c.Params("id")

	sid, err := strconv.Atoi(c.Params("sid"))
	if err != nil || sid < 1 {
		return fail(c, fiber.StatusBadRequest, "id pengiriman tidak valid")
	}

	var req ShipmentReq

	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	if err := req.validate(); err != nil {
		return fail(c, fiber.StatusUnprocessableEntity, err.Error())
	}

	var cur Shipment

	if h.db.Where("id = ? AND device_id = ?", sid, id).Limit(1).Find(&cur).RowsAffected == 0 {
		return fail(c, fiber.StatusNotFound, "data pengiriman tidak ditemukan")
	}

	// Pembatas edit dicek DI DATABASE (edit_count < batas) dalam satu perintah
	// UPDATE, jadi klik ganda atau dua request bersamaan tetap hanya lolos sekali.
	// Hanya tiga kolom ini yang diubah. ID, UID, dan tanggal tidak disentuh.
	res := h.db.Model(&Shipment{}).
		Where("id = ? AND device_id = ? AND edit_count < ?", sid, id, maxShipmentEdits).
		Updates(map[string]any{
			"item_name":   req.ItemName,
			"driver_name": req.DriverName,
			"quantity":    req.Quantity,
			"unit":        req.Unit,
			"edit_count":  gorm.Expr("edit_count + 1"),
			"updated_at":  time.Now().UTC(),
		})

	if res.Error != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal menyimpan perubahan")
	}

	if res.RowsAffected == 0 {
		return fail(c, fiber.StatusForbidden,
			"data ini sudah pernah diedit sekali dan tidak bisa diubah lagi")
	}

	h.db.Create(&Event{
		DeviceID:  id,
		Type:      "info",
		Message:   fmt.Sprintf("Data pengiriman #%d diedit: %s", sid, req.ItemName),
		CreatedAt: time.Now().UTC(),
	})

	var out Shipment
	h.db.Where("id = ?", sid).Limit(1).Find(&out)

	return c.JSON(toShipmentOut(out))
}

func (h *Hub) listShipments(c fiber.Ctx) error {
	limit, err := strconv.Atoi(c.Query("limit", "20"))

	if err != nil || limit < 1 || limit > 100 {
		limit = 20
	}

	var rows []Shipment

	h.db.Where("device_id = ?", c.Params("id")).
		Order("created_at desc").
		Limit(limit).
		Find(&rows)

	out := make([]ShipmentOut, 0, len(rows))
	for _, r := range rows {
		out = append(out, toShipmentOut(r))
	}

	return c.JSON(out)
}
