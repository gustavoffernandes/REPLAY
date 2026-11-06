<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Replay extends Model
{
    protected $fillable = ['replay_id', 'quadra_id', 'origem', 'clicado_em', 'arquivo', 'tamanho_bytes'];

    protected $casts = ['clicado_em' => 'datetime'];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        // URL relativa: funciona em qualquer host/porta sem depender do APP_URL.
        return '/storage/'.ltrim($this->arquivo, '/');
    }
}
