package main

import "time"

// DeviceState menyimpan kondisi terakhir tiap perangkat (satu baris per perangkat).
type DeviceState struct {
	DeviceID    string `gorm:"primaryKey;size:64"`
	BoxID       string `gorm:"size:64"`
	ItemUID     string `gorm:"size:64"`
	Temperature float64
	Status      string `gorm:"size:16"`
	Mode        string `gorm:"size:16"`
	Fan1State   string `gorm:"size:16"`
	Fan1RPM     *int
	Fan1Fault   bool
	Fan1Percent int `gorm:"not null;default:0"`

	Fan2State   string `gorm:"size:16"`
	Fan2RPM     *int
	Fan2Fault   bool
	Fan2Percent int `gorm:"not null;default:0"`
	Online      bool
	LastSeen    time.Time
	UpdatedAt   time.Time
}

// Reading adalah riwayat suhu untuk grafik.
type Reading struct {
	ID          uint   `gorm:"primaryKey"`
	DeviceID    string `gorm:"size:64;index:idx_dev_time,priority:1"`
	Temperature float64
	Status      string    `gorm:"size:16"`
	CreatedAt   time.Time `gorm:"index:idx_dev_time,priority:2"`
}

// Event mencatat perubahan kondisi, kipas, mode, dan status perangkat.
type Event struct {
	ID        uint      `gorm:"primaryKey"`
	DeviceID  string    `gorm:"size:64;index:idx_event_dev_time,priority:1"`
	Type      string    `gorm:"size:16"`
	Message   string    `gorm:"size:255"`
	CreatedAt time.Time `gorm:"index:idx_event_dev_time,priority:2"`
}

// Item memetakan UID/barcode barang ke nama barang.
type Item struct {
	UID  string `gorm:"primaryKey;size:64" json:"uid"`
	Name string `gorm:"size:128" json:"name"`
}

type Box struct {
	UID       string    `gorm:"primaryKey;size:64" json:"uid"`
	Status    string    `gorm:"size:16;not null;default:available" json:"status"`
	ItemName  string    `gorm:"size:128" json:"item_name"`
	WeightKg  *float64  `json:"weight_kg"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

// Shipment menyimpan data transaksi pengiriman.
type Shipment struct {
	TransactionID string `gorm:"primaryKey;size:64" json:"transaction_id"`
	BoxUID        string `gorm:"uniqueIndex;size:64;not null" json:"box_uid"`

	DriverDistributorName string  `gorm:"size:128;not null" json:"driver_distributor_name"`
	ItemName              string  `gorm:"size:128;not null" json:"item_name"`
	WeightKg              float64 `gorm:"not null" json:"weight_kg"`

	CreatedAt time.Time  `json:"created_at"`
	UpdatedAt time.Time  `json:"updated_at"`
	EditCount uint8      `gorm:"not null;default:0" json:"edit_count"`
	EditedAt  *time.Time `json:"edited_at"`
}
