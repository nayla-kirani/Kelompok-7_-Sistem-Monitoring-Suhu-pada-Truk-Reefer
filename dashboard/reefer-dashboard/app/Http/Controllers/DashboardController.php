<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    /**
     * Mengambil ID perangkat reefer dari konfigurasi.
     */
    private function deviceId(): string
    {
        return config('services.reefer.device_id');
    }

    /**
     * Meneruskan request dari Laravel ke API Go.
     * Browser tidak berkomunikasi langsung dengan Go.
     */
    private function forward(
        string $method,
        string $path,
        array $data = []
    ): JsonResponse {
        try {
            $res = Http::baseUrl(config('services.reefer.url'))
                ->withToken(config('services.reefer.token'))
                ->acceptJson()
                ->timeout(5)
                ->send(
                    $method,
                    $path,
                    $method === 'GET'
                        ? ['query' => $data]
                        : ['json' => $data]
                );

            return response()->json(
                $res->json(),
                $res->status()
            );

        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'API Go tidak bisa dihubungi',
            ], 502);
        }
    }

    /**
     * Halaman utama dashboard.
     */
    public function index()
    {
        return view('dashboard', [
            'deviceId' => $this->deviceId(),
        ]);
    }

    /**
     * Mengambil telemetry terbaru.
     */
    public function latest(): JsonResponse
    {
        return $this->forward(
            'GET',
            "/api/devices/{$this->deviceId()}/latest"
        );
    }

    /**
     * Mengambil riwayat telemetry.
     */
    public function history(Request $request): JsonResponse
    {
        $range = $request->validate([
            'range' => 'in:1h,6h,24h',
        ])['range'] ?? '1h';

        return $this->forward(
            'GET',
            "/api/devices/{$this->deviceId()}/history",
            ['range' => $range]
        );
    }

    /**
     * Mengambil daftar event perangkat.
     */
    public function events(): JsonResponse
    {
        return $this->forward(
            'GET',
            "/api/devices/{$this->deviceId()}/events",
            ['limit' => 30]
        );
    }

    /**
     * Scan UID barang (endpoint lama).
     * Dipertahankan untuk kompatibilitas.
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_uid' => 'required|string|max:64',
        ]);

        return $this->forward(
            'POST',
            "/api/devices/{$this->deviceId()}/scan",
            $data
        );
    }

    /**
     * Menyimpan data barang (endpoint lama).
     * Dipertahankan untuk kompatibilitas.
     */
    public function saveItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uid' => 'required|string|max:64',
            'name' => 'required|string|max:128',
        ]);

        return $this->forward(
            'POST',
            '/api/items',
            $data
        );
    }

    /**
     * Mengambil seluruh daftar box.
     */
    public function listBoxes(): JsonResponse
    {
        return $this->forward(
            'GET',
            '/api/boxes'
        );
    }

    /**
     * Mendaftarkan beberapa box sekaligus.
     */
    public function createBoxesBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'boxes' => 'required|array|min:1|max:100',
            'boxes.*.uid' => 'required|string|max:64|distinct',
            'boxes.*.item_name' => 'required|string|max:128',
            'boxes.*.weight_kg' => 'required|numeric|gt:0|lte:100000',
        ]);

        return $this->forward(
            'POST',
            '/api/boxes/bulk',
            $data
        );
    }

    /**
     * Mengedit data box yang belum digunakan.
     * Backend Go tetap memeriksa status box.
     */
    public function updateBox(Request $request, string $uid): JsonResponse
    {
        $uid = trim($uid);

        if ($uid === '' || strlen($uid) > 64) {
            return response()->json([
                'message' => 'UID box tidak valid',
            ], 422);
        }

        $data = $request->validate([
            'item_name' => 'required|string|max:128',
            'weight_kg' => 'required|numeric|gt:0|lte:100000',
        ]);

        return $this->forward(
            'PUT',
            '/api/boxes/' . rawurlencode($uid),
            $data
        );
    }

    /**
     * Memvalidasi UID box hasil scan barcode.
     * Data barang dan berat diambil dari database Box.
     */
    public function validateBox(string $uid): JsonResponse
    {
        $uid = trim($uid);

        if ($uid === '' || strlen($uid) > 64) {
            return response()->json([
                'valid' => false,
                'message' => 'UID box tidak valid',
            ], 422);
        }

        return $this->forward(
            'GET',
            '/api/boxes/' . rawurlencode($uid) . '/validate'
        );
    }

    /**
     * Membuat transaksi pengiriman baru.
     *
     * Nama barang dan berat tidak dikirim dari form.
     * Keduanya otomatis diambil dari data Box oleh Go Backend.
     */
    public function createShipment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'box_uid' => 'required|string|max:64',
            'driver_distributor_name' => 'required|string|max:128',
        ]);

        return $this->forward(
            'POST',
            '/api/shipments',
            $data
        );
    }

    /**
     * Mengambil transaksi terakhir.
     */
    public function latestShipment(): JsonResponse
    {
        return $this->forward(
            'GET',
            '/api/shipments/latest'
        );
    }

    /**
     * Mengambil seluruh riwayat transaksi pengiriman.
     */
    public function listShipments(): JsonResponse
    {
        return $this->forward(
            'GET',
            '/api/shipments'
        );
    }

    /**
     * Mengambil detail transaksi berdasarkan ID.
     */
    public function getShipment(string $id): JsonResponse
    {
        return $this->forward(
            'GET',
            '/api/shipments/' . rawurlencode($id)
        );
    }

    /**
     * Mengedit transaksi.
     * Backend Go memastikan edit hanya boleh dilakukan satu kali.
     */
    public function updateShipment(
        Request $request,
        string $id
    ): JsonResponse {
        $data = $request->validate([
            'driver_distributor_name' => 'required|string|max:128',
            'item_name' => 'required|string|max:128',
            'weight_kg' => 'required|numeric|gt:0|lte:100000',
        ]);

        return $this->forward(
            'PUT',
            '/api/shipments/' . rawurlencode($id),
            $data
        );
    }

    /**
     * Mengirim perintah kontrol perangkat.
     */
    public function control(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'sometimes|in:auto,manual',
            'fan1' => 'sometimes|in:off,normal,high',
            'fan2' => 'sometimes|in:on,off',
        ]);

        return $this->forward(
            'POST',
            "/api/devices/{$this->deviceId()}/control",
            $data
        );
    }
}
