<?php

namespace App\Http\Controllers;

use App\Models\Court;
use App\Models\Replay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas usadas fora do painel (sem login):
 *  - upload: o serviço de captura envia o .mp4, autenticado pelo token da câmera;
 *  - video:  o navegador toca/baixa o vídeo por um link assinado e temporário.
 */
class ReplayIngestController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        // O token identifica a quadra (e portanto a filial/empresa) dona do vídeo.
        $token = (string) $request->bearerToken();
        $court = $token === '' ? null : Court::withoutGlobalScopes()->where('replay_token_hash', hash('sha256', $token))->first();
        if (! $court) {
            return response()->json(['message' => 'Token da câmera inválido'], 401);
        }

        // Vídeo maior que o upload_max_filesize do php.ini: o PHP descarta o arquivo antes de chegar aqui.
        $upload = $request->file('video');
        if ($upload && in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return response()->json([
                'message' => 'Vídeo maior que o limite do PHP (upload_max_filesize = ' . ini_get('upload_max_filesize')
                    . '). Aumente upload_max_filesize e post_max_size no php.ini e reinicie o servidor.',
            ], 413);
        }

        // Campos em português: é o formato que o serviço de captura envia.
        $data = $request->validate([
            'video' => ['required', 'file', 'extensions:mp4', 'max:' . config('replays.max_upload_kb')],
            'replay_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'origem' => ['nullable', 'string', 'max:30'],
            'clicado_em' => ['nullable', 'date'],
        ]);

        $file = $request->file('video');
        $path = $file->storeAs("replays/{$court->branch_id}/{$court->id}", $data['replay_id'] . '.mp4', 'local');
        abort_unless($path, 500, 'Não foi possível gravar o vídeo');

        $source = in_array($data['origem'] ?? null, Replay::SOURCES, true) ? $data['origem'] : 'botoeira';
        // O horário chega com fuso (ex.: -03:00); grava no fuso da aplicação, como o resto do sistema.
        $triggeredAt = ! empty($data['clicado_em'])
            ? Carbon::parse($data['clicado_em'])->setTimezone(config('app.timezone'))
            : now();

        // updateOrCreate: se o serviço reenviar o mesmo vídeo (ex.: timeout de rede), não duplica.
        $replay = Replay::updateOrCreate(
            ['court_id' => $court->id, 'capture_id' => $data['replay_id']],
            [
                'branch_id' => $court->branch_id,
                'source' => $source,
                'triggered_at' => $triggeredAt,
                'path' => $path,
                'size_bytes' => $file->getSize(),
            ],
        );

        return response()->json(['id' => $replay->id], 201);
    }

    public function video(Request $request, Replay $replay): Response
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($replay->path), 404, 'Arquivo do replay não encontrado');
        $file = $disk->path($replay->path);

        if ($request->boolean('download')) {
            $name = 'replay-' . optional($replay->triggered_at)->format('Y-m-d-His') . '.mp4';

            return response()->download($file, $name, ['Content-Type' => 'video/mp4']);
        }

        // BinaryFileResponse atende "Range", então dá para avançar/voltar o vídeo.
        return response()->file($file, ['Content-Type' => 'video/mp4']);
    }
}
