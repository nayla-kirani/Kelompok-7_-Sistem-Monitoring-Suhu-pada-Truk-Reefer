package main

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"math"
	"strings"
	"time"

	"github.com/gofiber/fiber/v3"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

var jakartaLocation = func() *time.Location {
	loc, err := time.LoadLocation("Asia/Jakarta")
	if err != nil {
		panic("timezone Asia/Jakarta tidak tersedia: " + err.Error())
	}
	return loc
}()

var (
	errBoxAlreadyUsed = errors.New("box sudah pernah digunakan")
	errBoxDataInvalid = errors.New("data barang pada box belum lengkap")
	errShipmentLocked = errors.New("transaksi sudah pernah diedit dan terkunci")
)

type CreateShipmentReq struct {
	BoxUID                string `json:"box_uid"`
	DriverDistributorName string `json:"driver_distributor_name"`
}

type UpdateShipmentReq struct {
	DriverDistributorName string  `json:"driver_distributor_name"`
	ItemName              string  `json:"item_name"`
	WeightKg              float64 `json:"weight_kg"`
}

func generateTransactionID() (string, error) {
	b := make([]byte, 8)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}

	return "TRX-" + time.Now().In(jakartaLocation).Format("20060102") +
		"-" + strings.ToUpper(hex.EncodeToString(b)), nil
}

func validateDriverName(driver string) error {
	driver = strings.TrimSpace(driver)

	if driver == "" {
		return errors.New("nama supir/distributor wajib diisi")
	}

	if len(driver) > 128 {
		return errors.New("nama supir/distributor maksimal 128 karakter")
	}

	return nil
}

func validateShipmentFields(driver, item string, weight float64) error {
	driver = strings.TrimSpace(driver)
	item = strings.TrimSpace(item)

	if driver == "" || item == "" {
		return errors.New("nama supir/distributor dan nama barang wajib diisi")
	}

	if len(driver) > 128 || len(item) > 128 {
		return errors.New("nama maksimal 128 karakter")
	}

	if math.IsNaN(weight) || math.IsInf(weight, 0) ||
		weight <= 0 || weight > 100000 {
		return errors.New("berat barang harus lebih dari 0 dan maksimal 100000 kg")
	}

	return nil
}

// POST /api/shipments
// Body hanya membutuhkan box_uid dan driver_distributor_name.
func (h *Hub) createShipment(c fiber.Ctx) error {
	var req CreateShipmentReq

	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	req.BoxUID = strings.TrimSpace(req.BoxUID)
	req.DriverDistributorName = strings.TrimSpace(req.DriverDistributorName)

	if req.BoxUID == "" || len(req.BoxUID) > 64 {
		return fail(c, fiber.StatusBadRequest, "UID box wajib diisi")
	}

	if err := validateDriverName(req.DriverDistributorName); err != nil {
		return fail(c, fiber.StatusUnprocessableEntity, err.Error())
	}

	transactionID, err := generateTransactionID()
	if err != nil {
		return fail(c, fiber.StatusInternalServerError, "gagal membuat ID transaksi")
	}

	now := time.Now().In(jakartaLocation)
	var shipment Shipment

	err = h.db.Transaction(func(tx *gorm.DB) error {
		var box Box

		// Kunci baris box agar tidak dipakai dua transaksi bersamaan.
		if err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			First(&box, "uid = ?", req.BoxUID).Error; err != nil {
			return err
		}

		if box.Status != "available" {
			return errBoxAlreadyUsed
		}

		itemName := strings.TrimSpace(box.ItemName)

		if itemName == "" || box.WeightKg == nil {
			return errBoxDataInvalid
		}

		weight := *box.WeightKg

		if math.IsNaN(weight) || math.IsInf(weight, 0) ||
			weight <= 0 || weight > 100000 {
			return errBoxDataInvalid
		}

		shipment = Shipment{
			TransactionID:         transactionID,
			BoxUID:                box.UID,
			DriverDistributorName: req.DriverDistributorName,
			ItemName:              itemName,
			WeightKg:              weight,
			CreatedAt:             now,
			UpdatedAt:             now,
			EditCount:             0,
		}

		if err := tx.Create(&shipment).Error; err != nil {
			return err
		}

		box.Status = "used"
		box.UpdatedAt = now

		if err := tx.Save(&box).Error; err != nil {
			return err
		}

		return nil
	})

	if err != nil {
		switch {
		case errors.Is(err, gorm.ErrRecordNotFound):
			return fail(c, fiber.StatusNotFound, "UID box tidak terdaftar")

		case errors.Is(err, errBoxAlreadyUsed):
			return fail(c, fiber.StatusConflict, err.Error())

		case errors.Is(err, errBoxDataInvalid):
			return fail(c, fiber.StatusUnprocessableEntity,
				"data barang atau berat pada box belum lengkap")

		default:
			return fail(c, fiber.StatusInternalServerError,
				"gagal menyimpan transaksi")
		}
	}

	return c.Status(fiber.StatusCreated).JSON(fiber.Map{
		"message": "transaksi berhasil disimpan",
		"data":    shipment,
	})
}

// GET /api/shipments/:id
func (h *Hub) getShipment(c fiber.Ctx) error {
	id := strings.TrimSpace(c.Params("id"))

	var shipment Shipment

	if err := h.db.First(&shipment, "transaction_id = ?", id).Error; err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return fail(c, fiber.StatusNotFound, "transaksi tidak ditemukan")
		}

		return fail(c, fiber.StatusInternalServerError,
			"gagal mengambil transaksi")
	}

	return c.JSON(fiber.Map{
		"data": shipment,
	})
}

// PUT /api/shipments/:id
// Pengeditan transaksi tetap dibatasi satu kali.
func (h *Hub) updateShipment(c fiber.Ctx) error {
	id := strings.TrimSpace(c.Params("id"))

	var req UpdateShipmentReq

	if err := c.Bind().Body(&req); err != nil {
		return fail(c, fiber.StatusBadRequest, "body JSON tidak valid")
	}

	req.DriverDistributorName = strings.TrimSpace(req.DriverDistributorName)
	req.ItemName = strings.TrimSpace(req.ItemName)

	if err := validateShipmentFields(
		req.DriverDistributorName,
		req.ItemName,
		req.WeightKg,
	); err != nil {
		return fail(c, fiber.StatusUnprocessableEntity, err.Error())
	}

	var updated Shipment

	err := h.db.Transaction(func(tx *gorm.DB) error {
		var shipment Shipment

		if err := tx.Clauses(clause.Locking{Strength: "UPDATE"}).
			First(&shipment, "transaction_id = ?", id).Error; err != nil {
			return err
		}

		if shipment.EditCount >= 1 {
			return errShipmentLocked
		}

		shipment.DriverDistributorName = req.DriverDistributorName
		shipment.ItemName = req.ItemName
		shipment.WeightKg = req.WeightKg
		shipment.EditCount = 1

		now := time.Now().In(jakartaLocation)
		shipment.EditedAt = &now
		shipment.UpdatedAt = now

		if err := tx.Save(&shipment).Error; err != nil {
			return err
		}

		updated = shipment
		return nil
	})

	if err != nil {
		switch {
		case errors.Is(err, gorm.ErrRecordNotFound):
			return fail(c, fiber.StatusNotFound, "transaksi tidak ditemukan")

		case errors.Is(err, errShipmentLocked):
			return fail(c, fiber.StatusConflict, err.Error())

		default:
			return fail(c, fiber.StatusInternalServerError,
				"gagal memperbarui transaksi")
		}
	}

	return c.JSON(fiber.Map{
		"message": "transaksi berhasil diedit; pengeditan selanjutnya dikunci",
		"data":    updated,
	})
}

// GET /api/shipments/latest
func (h *Hub) getLatestShipment(c fiber.Ctx) error {
	var shipment Shipment

	err := h.db.
		Order("created_at DESC").
		First(&shipment).Error

	if err != nil {
		if errors.Is(err, gorm.ErrRecordNotFound) {
			return c.JSON(fiber.Map{
				"data": nil,
			})
		}

		return fail(c, fiber.StatusInternalServerError,
			"gagal mengambil transaksi terakhir")
	}

	return c.JSON(fiber.Map{
		"data": shipment,
	})
}
