<?php

namespace App\Services;

use App\Models\Kanban;
use App\Models\WhatsappCanal;

class SelecaoCanalWhatsappService
{
    public function naoOficialAleatorioParaKanban(Kanban $kanban): ?WhatsappCanal
    {
        return $kanban->canais()
            ->where('tipo', 'nao_oficial')
            ->where('status', 'connected')
            ->inRandomOrder()
            ->first();
    }
}
