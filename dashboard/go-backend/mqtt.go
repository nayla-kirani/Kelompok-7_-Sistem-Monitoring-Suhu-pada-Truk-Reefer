package main

import (
	"fmt"
	"log"
	"time"

	mqtt "github.com/eclipse/paho.mqtt.golang"
)

func (h *Hub) connectMQTT() {
	opts := mqtt.NewClientOptions().
		AddBroker(h.cfg.MQTTBroker).
		SetClientID(h.cfg.MQTTClientID).
		SetUsername(h.cfg.MQTTUser).
		SetPassword(h.cfg.MQTTPass).
		SetAutoReconnect(true).
		SetConnectRetry(true).
		SetConnectRetryInterval(5 * time.Second).
		SetKeepAlive(30 * time.Second)

	// Subscribe ulang setiap kali MQTT berhasil terhubung.
	opts.SetOnConnectHandler(func(c mqtt.Client) {
		log.Println("MQTT terhubung ke", h.cfg.MQTTBroker)

		topic := h.cfg.MQTTDataTopic

		token := c.Subscribe(topic, 1, func(_ mqtt.Client, m mqtt.Message) {
			h.dispatch("telemetry", m)
		})

		if token.Wait() && token.Error() != nil {
			log.Printf("Gagal subscribe %s: %v", topic, token.Error())
		} else {
			log.Println("Berhasil subscribe ke:", topic)
		}
	})

	opts.SetConnectionLostHandler(func(_ mqtt.Client, err error) {
		log.Println("MQTT terputus:", err)
	})

	h.mq = mqtt.NewClient(opts)

	token := h.mq.Connect()
	if token.Wait() && token.Error() != nil {
		log.Println("Gagal menghubungkan MQTT:", token.Error())
	}
}

func (h *Hub) dispatch(kind string, m mqtt.Message) {
	switch kind {
	case "telemetry":
		// Semua data berasal dari satu perangkat ESP32.
		h.onTelemetry(h.cfg.DeviceID, m.Payload())

	case "status":
		h.onStatus(h.cfg.DeviceID, string(m.Payload()))

	case "scan":
		h.onScan(h.cfg.DeviceID, m.Payload())
	}
}

func (h *Hub) publishCmd(deviceID string, payload []byte) error {
	if h.mq == nil || !h.mq.IsConnectionOpen() {
		return fmt.Errorf("broker MQTT belum terhubung")
	}

	// ESP32 menerima perintah berupa teks biasa, bukan JSON.
	token := h.mq.Publish(
		h.cfg.MQTTCommandTopic,
		1,
		false,
		string(payload),
	)

	if !token.WaitTimeout(3 * time.Second) {
		return fmt.Errorf("publish ke broker timeout")
	}

	if token.Error() != nil {
		return token.Error()
	}

	log.Printf("Perintah dikirim ke ESP32 (%s): %s",
		h.cfg.MQTTCommandTopic, string(payload))

	return nil
}
