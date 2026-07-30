<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class WhatsAppController extends Controller
{
    public function view(): View
    {
        return view('configuracoes.whatsapp');
    }
}
