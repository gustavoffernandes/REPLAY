<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/** Vídeo dos últimos segundos de uma quadra, salvo pela botoeira (física ou virtual). */
class Replay extends Model
{
    public const SOURCES = ['site', 'botoeira', 'botoeira-simulada'];

    protected $fillable = ['branch_id', 'court_id', 'capture_id', 'source', 'triggered_at', 'path', 'size_bytes'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['triggered_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                $q->whereIn('replays.branch_id', $t->branchIds());
            }
        });
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Link assinado e temporário do vídeo. O <video> do navegador não envia o token de login,
     * então o acesso é liberado pela assinatura (gerada só para quem já pode ver o replay).
     * Relativo, para funcionar atrás do proxy do Vite e no mesmo domínio em produção.
     */
    public function signedUrl(bool $download = false): string
    {
        $params = ['replay' => $this->id] + ($download ? ['download' => 1] : []);

        return URL::temporarySignedRoute('replays.video', now()->addMinutes(config('replays.video_url_ttl')), $params, absolute: false);
    }
}
