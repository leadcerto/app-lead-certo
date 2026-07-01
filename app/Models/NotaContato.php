<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotaContato extends Model
{
    public $timestamps = false;

    protected $table = 'notas_contato';

    protected $fillable = ['contato_id', 'tenant_id', 'user_id', 'texto'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
