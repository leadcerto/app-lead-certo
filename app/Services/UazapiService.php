<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UazapiService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.uazapi.base_url', ''), '/');
        $this->apiKey  = config('services.uazapi.key', '');
    }

    public function enviarMensagem(string $telefone, string $mensagem): bool
    {
        try {
            $response = Http::withHeaders(['apikey' => $this->apiKey])
                ->post("{$this->baseUrl}/message/send", [
                    'phone'        => $telefone,
                    'message'      => $mensagem,
                    'delayTyping'  => 2,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Uazapi enviarMensagem falhou', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    public function verificarStatus(): array
    {
        try {
            $response = Http::withHeaders(['apikey' => $this->apiKey])
                ->timeout(5)
                ->get("{$this->baseUrl}/instance/status");

            if ($response->successful()) {
                return $response->json() ?? ['status' => 'disconnected'];
            }
        } catch (\Exception $e) {
            Log::error('Uazapi verificarStatus falhou', ['erro' => $e->getMessage()]);
        }

        return ['status' => 'disconnected'];
    }

    public function gerarQrCode(int $tenantId): ?string
    {
        try {
            $response = Http::withHeaders(['apikey' => $this->apiKey])
                ->timeout(5)
                ->get("{$this->baseUrl}/instance/qrcode");

            if ($response->successful()) {
                return $response->json('qrcode');
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Uazapi gerarQrCode falhou', ['erro' => $e->getMessage()]);
            return null;
        }
    }
}
