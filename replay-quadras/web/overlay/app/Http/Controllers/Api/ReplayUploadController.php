<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Replay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST /api/replays — recebe o .mp4 enviado pelo Watchdog do serviço Python.
 *
 * Campos (multipart/form-data):
 *   video       arquivo .mp4
 *   replay_id   id único gerado na captura
 *   quadra_id   identificação da quadra/câmera
 *   origem      site | botoeira | botoeira-simulada
 *   clicado_em  data/hora do clique (ISO 8601)
 *
 * Autenticação: header "Authorization: Bearer <REPLAY_TOKEN>".
 */
class ReplayUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = (string) config('replay.token');
        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['erro' => 'token inválido'], 401);
        }

        $dados = $request->validate([
            'video' => ['required', 'file', 'extensions:mp4', 'max:'.config('replay.tamanho_maximo_kb')],
            'replay_id' => ['required', 'string', 'max:100'],
            'quadra_id' => ['required', 'string', 'max:100'],
            'origem' => ['nullable', 'string', 'max:50'],
            'clicado_em' => ['nullable', 'string'],
        ]);

        $arquivo = $request->file('video');
        $caminho = $arquivo->storeAs(
            'replays/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $dados['quadra_id']),
            basename($arquivo->getClientOriginalName()),
            'public'
        );

        // updateOrCreate: se o Python reenviar o mesmo replay (ex.: timeout de
        // rede depois de já ter salvo), não duplica o registro.
        $replay = Replay::updateOrCreate(
            ['replay_id' => $dados['replay_id']],
            [
                'quadra_id' => $dados['quadra_id'],
                'origem' => $dados['origem'] ?? 'desconhecida',
                // Converte para UTC: o Eloquent grava a hora "como veio", sem
                // ajustar o fuso. A página converte de volta para o fuso local.
                'clicado_em' => ! empty($dados['clicado_em']) ? Carbon::parse($dados['clicado_em'])->utc() : now(),
                'arquivo' => $caminho,
                'tamanho_bytes' => $arquivo->getSize(),
            ]
        );

        return response()->json($replay, 201);
    }
}
