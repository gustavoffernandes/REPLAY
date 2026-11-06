<?php

namespace App\Http\Controllers;

use App\Models\Court;
use App\Models\Replay;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Replays no painel: listar, salvar pela botoeira virtual, ver o status da câmera e excluir.
 *
 * "Salvar replay" repassa o clique ao serviço de captura da quadra — o mesmo caminho que a
 * botoeira física usa. O vídeo chega depois, pelo ReplayIngestController.
 */
class ReplayController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'court_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Replay::with('court:id,name', 'branch:id,name')
            ->when($filters['court_id'] ?? null, fn ($q, $id) => $q->where('court_id', $id))
            ->when($filters['date'] ?? null, fn ($q, $d) => $q->whereDate('triggered_at', $d))
            ->latest('triggered_at')->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Replay $r) => [
                'id' => $r->id,
                'court_id' => $r->court_id,
                'court_name' => $r->court?->name,
                'branch_name' => $r->branch?->name,
                'source' => $r->source,
                'triggered_at' => $r->triggered_at,
                'size_bytes' => $r->size_bytes,
                'video_url' => $r->signedUrl(),
                'download_url' => $r->signedUrl(download: true),
            ]);
    }

    /** Quadras ativas com câmera configurada (independe da permissão de ver quadras). */
    public function cameras()
    {
        return Court::with('branch:id,name')->where('active', true)
            ->whereNotNull('replay_url')->whereNotNull('replay_token_hash')
            ->orderBy('branch_id')->orderBy('name')
            ->get()
            ->map(fn (Court $c) => ['id' => $c->id, 'name' => $c->name, 'branch_name' => $c->branch?->name]);
    }

    /** Botoeira virtual: pede ao serviço de captura da quadra para salvar os últimos segundos. */
    public function trigger(Court $court): JsonResponse
    {
        $court->replay_ready || abort(422, 'Esta quadra não tem câmera de replay configurada');

        try {
            $response = $this->capture($court)->post('/disparar');
        } catch (ConnectionException) {
            return response()->json(['message' => 'Câmera offline: o serviço de captura desta quadra não respondeu.'], 503);
        }

        $body = $response->json() ?? [];
        if ($response->status() === 401) {
            return response()->json(['message' => 'O token da câmera não confere. Gere um novo token em Quadras e atualize o captura/.env.'], 502);
        }
        if (! ($body['aceito'] ?? false)) {
            return response()->json(['message' => ucfirst($body['motivo'] ?? 'O serviço de captura recusou o pedido.')], 429);
        }

        return response()->json(['ok' => true, 'capture_id' => $body['id'] ?? null], 202);
    }

    /** Saúde da câmera: online? recebendo vídeo? */
    public function status(Court $court): array
    {
        if (! $court->replay_ready) {
            return ['configured' => false];
        }
        try {
            $s = $this->capture($court)->get('/status')->json() ?? [];
        } catch (ConnectionException) {
            return ['configured' => true, 'online' => false];
        }

        return [
            'configured' => true,
            'online' => true,
            'recording' => (bool) ($s['gravando'] ?? false),
            'source' => $s['fonte'] ?? null,
            'segments' => $s['segmentos_no_buffer'] ?? null,
            'button_mode' => $s['botoeira_modo'] ?? null,
            'last_warning' => $s['ultimo_aviso_ffmpeg'] ?? null,
        ];
    }

    public function destroy(Replay $replay): array
    {
        Storage::disk('local')->delete($replay->path);
        $replay->delete();

        return ['ok' => true];
    }

    /** Gera um novo token da câmera. Ele é mostrado uma única vez, para colar no captura/.env. */
    public function rotateToken(Court $court): array
    {
        $token = bin2hex(random_bytes(24));
        $court->forceFill(['replay_token' => $token, 'replay_token_hash' => hash('sha256', $token)])->save();

        return ['token' => $token];
    }

    private function capture(Court $court): PendingRequest
    {
        return Http::baseUrl(rtrim($court->replay_url, '/'))
            ->withToken($court->replay_token)
            ->acceptJson()
            ->timeout(config('replays.capture_timeout'));
    }
}
