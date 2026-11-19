package main

import (
	"fmt"
	"log"

	"github.com/gofiber/fiber/v3"
	"github.com/joho/godotenv"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
)

func main() {
	_ = godotenv.Load() // baca file .env kalau ada
	cfg := loadConfig()

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=UTC",
		cfg.DBUser, cfg.DBPass, cfg.DBHost, cfg.DBPort, cfg.DBName)
	db, err := gorm.Open(mysql.Open(dsn), &gorm.Config{})
	if err != nil {
		log.Fatal("gagal konek MySQL: ", err)
	}
	if err := db.AutoMigrate(&DeviceState{}, &Reading{}, &Event{}, &Item{}); err != nil {
		log.Fatal("gagal migrasi tabel: ", err)
	}

	hub := &Hub{db: db, cfg: cfg}
	hub.connectMQTT()

	app := fiber.New()
	hub.routes(app)

	log.Fatal(app.Listen(":" + cfg.Port))
}
