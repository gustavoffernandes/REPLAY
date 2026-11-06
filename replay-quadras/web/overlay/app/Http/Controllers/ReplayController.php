<?php

namespace App\Http\Controllers;

use App\Models\Replay;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Páginas do portal + "botoeira virtual".
 *
 * O botão "Salvar vídeo" da página chama disparar(), que repassa o clique ao
 * serviço Python de captura — o mesmo caminho que a botoeira física usa.
 */
class ReplayController extends Controller
{
    public function index(): View
    {
        return view('replays', [
            'replays' => Replay::latest()->limit(50)->get(),
        ]);
    }

    /** Lista em JSON (a página consulta a cada poucos segundos). */
    public function lista(): JsonResponse
    {
        return response()->json(Replay::latest()->limit(50)->get());
    }

    /** Botoeira virtual: pede ao serviço Python para salvar os últimos 30s. */
    public function disparar(): JsonResponse
    {
        try {
            $resposta = $this->captura()->post('/disparar');
        } catch (ConnectionException) {
            return response()->json([
                'aceito' => false,
                'motivo' => 'Serviço de captura offline. Ele está rodando? (python main.py)',
            ], 503);
        }

        return response()->json($resposta->json() ?? [], $resposta->status());
    }

    /** Saúde do serviço de captura (gravando? quantos fragmentos no buffer?). */
    public function status(): JsonResponse
    {
        try {
            $resposta = $this->captura()->get('/status');

            return response()->json(['online' => true] + ($resposta->json() ?? []));
        } catch (ConnectionException) {
            return response()->json(['online' => false]);
        }
    }

    private function captura()
    {
        return Http::baseUrl(config('replay.captura_url'))
            ->withToken(config('replay.token'))
            ->acceptJson()
            ->timeout(5);
    }
}
