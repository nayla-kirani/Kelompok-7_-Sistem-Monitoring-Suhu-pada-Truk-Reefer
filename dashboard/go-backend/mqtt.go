package main

import (
	"fmt"
	"log"
	"strings"
	"time"

	mqtt "github.com/eclipse/paho.mqtt.golang"
)

// Topic yang dipakai (prefix default "reefer"):
//   reefer/{device_id}/telemetry  ESP32 -> backend  (JSON suhu, kipas, mode, item)
//   reefer/{device_id}/status     ESP32 -> backend  ("online"/"offline", retained + Last Will)
//   reefer/{device_id}/scan       ESP32 -> backend  (JSON hasil scan barcode/UID)
//   reefer/{device_id}/cmd        backend -> ESP32  (JSON perintah kontrol)

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

	// Dipanggil tiap kali (re)connect, jadi subscribe otomatis pulih setelah putus.
	opts.SetOnConnectHandler(func(c mqtt.Client) {
		log.Println("MQTT terhubung ke", h.cfg.MQTTBroker)
		for _, kind := range []string{"telemetry", "status", "scan"} {
			kind := kind
			topic := fmt.Sprintf("%s/+/%s", h.cfg.TopicPrefix, kind)
			if tok := c.Subscribe(topic, 1, func(_ mqtt.Client, m mqtt.Message) { h.dispatch(kind, m) }); tok.Wait() && tok.Error() != nil {
				log.Printf("gagal subscribe %s: %v", topic, tok.Error())
			}
		}
	})
	opts.SetConnectionLostHandler(func(_ mqtt.Client, err error) {
		log.Println("MQTT terputus:", err)
	})

	h.mq = mqtt.NewClient(opts)
	h.mq.Connect() // berjalan di background; ConnectRetry mencoba lagi sampai berhasil
}

func (h *Hub) dispatch(kind string, m mqtt.Message) {
	parts := strings.Split(m.Topic(), "/")
	if len(parts) < 3 {
		return
	}
	id := parts[len(parts)-2]

	switch kind {
	case "telemetry":
		h.onTelemetry(id, m.Payload())
	case "status":
		h.onStatus(id, string(m.Payload()))
	case "scan":
		h.onScan(id, m.Payload())
	}
}

func (h *Hub) publishCmd(deviceID string, payload []byte) error {
	if h.mq == nil || !h.mq.IsConnectionOpen() {
		return fmt.Errorf("broker MQTT belum terhubung")
	}
	tok := h.mq.Publish(fmt.Sprintf("%s/%s/cmd", h.cfg.TopicPrefix, deviceID), 1, false, payload)
	if !tok.WaitTimeout(3 * time.Second) {
		return fmt.Errorf("publish ke broker timeout")
	}
	return tok.Error()
}
