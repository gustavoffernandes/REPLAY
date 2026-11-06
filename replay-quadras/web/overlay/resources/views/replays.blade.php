<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Replays da Quadra</title>
    <style>
        :root {
            --bg: #f4f5f7; --card: #fff; --texto: #1b1f24; --suave: #5f6b7a; --borda: #dde1e6;
            --acao: #d7263d; --acao-hover: #b51f33; --ok: #1f9d55; --alerta: #c27c0e;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #111418; --card: #1a1f25; --texto: #e8ecf1; --suave: #98a3b1; --borda: #2b323b; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--texto);
               font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 64px; }
        h1 { font-size: 1.6rem; margin: 0 0 4px; }
        .sub { color: var(--suave); margin: 0 0 24px; }
        .painel { background: var(--card); border: 1px solid var(--borda); border-radius: 14px;
                  padding: 24px; display: flex; flex-wrap: wrap; gap: 24px; align-items: center; }
        #salvar { background: var(--acao); color: #fff; border: 0; border-radius: 999px;
                  width: 168px; height: 168px; font-size: 1.15rem; font-weight: 700; cursor: pointer;
                  box-shadow: 0 6px 0 #7d1422; transition: transform .05s, box-shadow .05s, background .15s; }
        #salvar:hover { background: var(--acao-hover); }
        #salvar:active { transform: translateY(5px); box-shadow: 0 1px 0 #7d1422; }
        #salvar:disabled { opacity: .55; cursor: wait; }
        .info { flex: 1; min-width: 240px; }
        .status { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
        .ponto { width: 10px; height: 10px; border-radius: 50%; background: var(--suave); }
        .ponto.ok { background: var(--ok); } .ponto.alerta { background: var(--alerta); } .ponto.off { background: var(--acao); }
        #mensagem { min-height: 1.5em; margin-top: 12px; color: var(--suave); }
        h2 { font-size: 1.2rem; margin: 32px 0 12px; }
        .grade { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
        .replay { background: var(--card); border: 1px solid var(--borda); border-radius: 12px; overflow: hidden; }
        .replay video { width: 100%; display: block; background: #000; aspect-ratio: 16 / 9; }
        .replay .meta { padding: 10px 14px; font-size: .9rem; color: var(--suave);
                        display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
        .replay .meta a { color: inherit; }
        .tag { font-size: .75rem; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--borda); }
        .vazio { color: var(--suave); }
    </style>
</head>
<body>
<main>
    <h1>Replays da Quadra</h1>
    <p class="sub">Botoeira virtual — simula o botão físico da quadra. Salva os últimos 30 segundos.</p>

    <section class="painel">
        <button id="salvar" type="button">Salvar vídeo</button>
        <div class="info">
            <div class="status"><span class="ponto" id="ponto"></span><span id="status-texto">Verificando serviço de captura…</span></div>
            <div id="status-detalhe" class="sub" style="margin:4px 0 0"></div>
            <div id="mensagem" aria-live="polite"></div>
        </div>
    </section>

    <h2>Últimos replays</h2>
    <div class="grade" id="lista">
        @forelse ($replays as $r)
            <article class="replay">
                <video src="{{ $r->url }}" controls preload="metadata" playsinline></video>
                <div class="meta">
                    <span>{{ $r->quadra_id }} · <time data-iso="{{ $r->clicado_em?->toIso8601String() }}"></time></span>
                    <span class="tag">{{ $r->origem }}</span>
                    <a href="{{ $r->url }}" download>baixar</a>
                </div>
            </article>
        @empty
            <p class="vazio">Nenhum replay ainda. Clique em “Salvar vídeo”.</p>
        @endforelse
    </div>
</main>

<script>
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const botao = document.getElementById('salvar');
    const msg = document.getElementById('mensagem');
    let idsConhecidos = new Set(@json($replays->pluck('replay_id')));
    let aguardando = 0;

    function formatarData(iso) {
        if (!iso) return '';
        return new Date(iso).toLocaleString('pt-BR');
    }

    function cartao(r) {
        const el = document.createElement('article');
        el.className = 'replay';
        el.innerHTML = `
            <video controls preload="metadata" playsinline></video>
            <div class="meta"><span></span><span class="tag"></span><a download>baixar</a></div>`;
        el.querySelector('video').src = r.url;
        el.querySelector('.meta span').textContent = `${r.quadra_id} · ${formatarData(r.clicado_em)}`;
        el.querySelector('.tag').textContent = r.origem;
        el.querySelector('a').href = r.url;
        return el;
    }

    async function atualizarLista() {
        try {
            const lista = await (await fetch('{{ route('replays.lista') }}')).json();
            const novos = lista.filter(r => !idsConhecidos.has(r.replay_id));
            if (!novos.length) return;
            const grade = document.getElementById('lista');
            grade.querySelector('.vazio')?.remove();
            novos.reverse().forEach(r => { grade.prepend(cartao(r)); idsConhecidos.add(r.replay_id); });
            if (aguardando > 0) {
                aguardando = Math.max(0, aguardando - novos.length);
                msg.textContent = '✅ Replay disponível!';
            }
        } catch (e) { /* tenta de novo no próximo ciclo */ }
    }

    async function atualizarStatus() {
        const ponto = document.getElementById('ponto');
        const texto = document.getElementById('status-texto');
        const detalhe = document.getElementById('status-detalhe');
        try {
            const s = await (await fetch('{{ route('captura.status') }}')).json();
            if (!s.online) {
                ponto.className = 'ponto off'; texto.textContent = 'Serviço de captura offline';
                detalhe.textContent = 'Inicie com: python main.py (pasta captura/)';
            } else if (s.gravando) {
                ponto.className = 'ponto ok'; texto.textContent = 'Gravando — buffer ativo';
                detalhe.textContent = `${s.quadra_id} · fonte: ${s.fonte} · ${s.segmentos_no_buffer} fragmentos · botoeira física: ${s.botoeira_modo}`;
            } else {
                ponto.className = 'ponto alerta'; texto.textContent = 'Serviço online, mas sem vídeo chegando';
                detalhe.textContent = s.ultimo_aviso_ffmpeg || 'Verifique a câmera / RTSP_URL';
            }
        } catch (e) {
            ponto.className = 'ponto off'; texto.textContent = 'Portal sem resposta';
        }
    }

    botao.addEventListener('click', async () => {
        botao.disabled = true;
        msg.textContent = 'Salvando os últimos 30 segundos…';
        try {
            const resp = await fetch('{{ route('replays.disparar') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const dados = await resp.json().catch(() => ({}));
            if (resp.ok && dados.aceito) {
                aguardando++;
                msg.textContent = '⏳ Clique registrado! Montando e enviando o replay…';
            } else {
                msg.textContent = '⚠️ ' + (dados.motivo || dados.erro || `Erro ${resp.status}`);
            }
        } catch (e) {
            msg.textContent = '⚠️ Não foi possível falar com o portal.';
        } finally {
            setTimeout(() => (botao.disabled = false), 1500);
        }
    });

    // Datas vêm em UTC do servidor; mostramos no fuso de quem está vendo.
    document.querySelectorAll('time[data-iso]').forEach(t => (t.textContent = formatarData(t.dataset.iso)));

    atualizarStatus();
    setInterval(atualizarStatus, 5000);
    setInterval(atualizarLista, 3000);
</script>
</body>
</html>
