package main

import "time"

// DeviceState menyimpan kondisi terakhir tiap perangkat (satu baris per perangkat).
type DeviceState struct {
	DeviceID    string `gorm:"primaryKey;size:64"`
	BoxID       string `gorm:"size:64"`
	ItemUID     string `gorm:"size:64"`
	ShipmentID  *uint  // pengiriman aktif di box ini
	Temperature float64
	Status      string `gorm:"size:16"`
	Mode        string `gorm:"size:16"`
	Fan1State   string `gorm:"size:16"`
	Fan1RPM     *int
	Fan1Fault   bool
	Fan2State   string `gorm:"size:16"`
	Fan2RPM     *int
	Fan2Fault   bool
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
	ID        uint   `gorm:"primaryKey"`
	DeviceID  string `gorm:"size:64;index:idx_event_dev_time,priority:1"`
	Type      string `gorm:"size:16"`
	Message   string `gorm:"size:255"`
	CreatedAt time.Time `gorm:"index:idx_event_dev_time,priority:2"`
}

// Item memetakan UID/barcode barang ke nama barang.
type Item struct {
	UID  string `gorm:"primaryKey;size:64" json:"uid"`
	Name string `gorm:"size:128" json:"name"`
}

// Shipment adalah catatan satu pengiriman: hasil scan barcode + form isian.
// ID, ItemUID, dan CreatedAt diisi sistem dan tidak pernah bisa diubah.
// Nama barang, supir, dan jumlah hanya boleh diedit sekali (EditCount maksimal 1).
type Shipment struct {
	ID         uint      `gorm:"primaryKey" json:"id"`
	DeviceID   string    `gorm:"size:64;index" json:"device_id"`
	ItemUID    string    `gorm:"size:64;index" json:"item_uid"`
	ItemName   string    `gorm:"size:128" json:"item_name"`
	DriverName string    `gorm:"size:128" json:"driver_name"`
	Quantity   float64   `json:"quantity"`
	Unit       string    `gorm:"size:16" json:"unit"`
	EditCount  int       `json:"edit_count"`
	CreatedAt  time.Time `json:"created_at"`
	UpdatedAt  time.Time `json:"updated_at"`
}
