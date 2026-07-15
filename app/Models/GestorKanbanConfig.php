<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GestorKanbanConfig extends Model
{
    protected $table = 'gestor_kanban_config';

    protected $fillable = [
        'prompt_coluna',
        'prompt_sintese',
        'updated_by',
    ];
}
