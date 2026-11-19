package main

import (
	"os"
	"strconv"
	"time"
)

type Config struct {
	Port     string
	APIToken string

	DBUser, DBPass, DBHost, DBPort, DBName string

	MQTTBroker, MQTTUser, MQTTPass, MQTTClientID, TopicPrefix string

	WarnAt     float64 // batas masuk kondisi warning (>= nilai ini)
	CritAt     float64 // batas masuk kondisi kritis (> nilai ini)
	Hysteresis float64 // selisih turun supaya status tidak berganti-ganti di sekitar batas
	StaleAfter time.Duration
}

func env(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func envFloat(key string, def float64) float64 {
	if v, err := strconv.ParseFloat(os.Getenv(key), 64); err == nil {
		return v
	}
	return def
}

func loadConfig() Config {
	return Config{
		Port:         env("PORT", "8080"),
		APIToken:     env("API_TOKEN", ""),
		DBUser:       env("DB_USER", "root"),
		DBPass:       env("DB_PASS", ""),
		DBHost:       env("DB_HOST", "127.0.0.1"),
		DBPort:       env("DB_PORT", "3306"),
		DBName:       env("DB_NAME", "reefer"),
		MQTTBroker:   env("MQTT_BROKER", "tcp://broker.hivemq.com:1883"),
		MQTTUser:     env("MQTT_USER", ""),
		MQTTPass:     env("MQTT_PASS", ""),
		MQTTClientID: env("MQTT_CLIENT_ID", "reefer-backend"),
		TopicPrefix:  env("MQTT_TOPIC_PREFIX", "reefer"),
		WarnAt:       envFloat("TEMP_WARN", 2),
		CritAt:       envFloat("TEMP_CRIT", 8),
		Hysteresis:   envFloat("TEMP_HYSTERESIS", 0.2),
		StaleAfter:   time.Duration(envFloat("DEVICE_STALE_SECONDS", 30)) * time.Second,
	}
}
