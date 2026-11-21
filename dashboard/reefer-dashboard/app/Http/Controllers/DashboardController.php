<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException; 
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    private function deviceId(): string
    {
        return config('services.reefer.device_id');
    }

    /** Meneruskan request ke API Go. Browser tidak pernah bicara langsung ke Go. */
    private function forward(string $method, string $path, array $data = []): JsonResponse
    {
        try {
            $res = Http::baseUrl(config('services.reefer.url'))
                ->withToken(config('services.reefer.token'))
                ->acceptJson()
                ->timeout(5)
                ->send($method, $path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);

            return response()->json($res->json(), $res->status());
        } catch (ConnectionException $e) {
            return response()->json(['message' => 'API Go tidak bisa dihubungi'], 502);
        }
    }

    public function index()
    {
        return view('dashboard', ['deviceId' => $this->deviceId()]);
    }

    public function latest(): JsonResponse
    {
        return $this->forward('GET', "/api/devices/{$this->deviceId()}/latest");
    }

    public function history(Request $request): JsonResponse
    {
        $range = $request->validate(['range' => 'in:1h,6h,24h'])['range'] ?? '1h';

        return $this->forward('GET', "/api/devices/{$this->deviceId()}/history", ['range' => $range]);
    }

    public function events(): JsonResponse
    {
        return $this->forward('GET', "/api/devices/{$this->deviceId()}/events", ['limit' => 30]);
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_uid' => 'required|string|max:64',
        ]);

        return $this->forward('POST', "/api/devices/{$this->deviceId()}/scan", $data);
    }

    public function saveItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uid'  => 'required|string|max:64',
            'name' => 'required|string|max:128',
        ]);

        return $this->forward('POST', '/api/items', $data);
    }

    public function control(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'sometimes|in:auto,manual',
            'fan1' => 'sometimes|in:off,normal,high',
            'fan2' => 'sometimes|in:on,off',
        ]);

        return $this->forward('POST', "/api/devices/{$this->deviceId()}/control", $data);
    }
}