<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Services\AgendaImediataService;
use Illuminate\Http\JsonResponse;

class AgendaImediataController extends Controller
{
    public function __construct(private AgendaImediataService $service) {}

    public function index(): JsonResponse
    {
        $agenda = $this->service->getAgenda(auth()->user());

        return response()->json($agenda);
    }
}
