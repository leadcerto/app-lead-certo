<?php

namespace App\Http\Controllers\Painel\Concerns;

use App\Models\Kanban;
use Illuminate\Http\Request;

trait ResolveKanbanDoRequest
{
    protected function resolverKanban(Request $request): Kanban
    {
        $tenantId = $request->user()->tenant_id;
        $kanbanId = $request->query('kanban_id');

        if ($kanbanId) {
            return Kanban::where('tenant_id', $tenantId)->findOrFail($kanbanId);
        }

        return Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();
    }
}
