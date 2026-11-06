"""Montagem do replay: junta os fragmentos dos últimos N segundos em um .mp4.

Ponto único de disparo para TODAS as origens (site, botoeira física, terminal).
Quem quiser salvar um replay só precisa chamar `Montador.disparar(origem)`.
"""

import json
import logging
import subprocess
import tempfile
import threading
import time
import uuid
from datetime import datetime
from pathlib import Path

from config import Config
from gravador import Gravador

log = logging.getLogger("montador")


class Montador:
    def __init__(self, cfg: Config, gravador: Gravador):
        self.cfg = cfg
        self.gravador = gravador
        self._trava = threading.Lock()
        self._ultimo_disparo = 0.0
        self.historico: list[dict] = []  # últimos disparos, para o /status

    def disparar(self, origem: str) -> dict:
        """Registra o clique e monta o replay em segundo plano.

        Retorna imediatamente (o .mp4 fica pronto alguns segundos depois,
        pois é preciso esperar o fragmento atual fechar).
        """
        agora = time.time()
        with self._trava:
            espera = self.cfg.intervalo_minimo_cliques - (agora - self._ultimo_disparo)
            if espera > 0:
                log.info("Clique ignorado (%s): muito próximo do anterior", origem)
                return {"aceito": False, "motivo": f"aguarde {espera:.1f}s entre cliques"}
            self._ultimo_disparo = agora

        replay_id = datetime.fromtimestamp(agora).strftime("%Y%m%d-%H%M%S") + "-" + uuid.uuid4().hex[:6]
        registro = {"id": replay_id, "origem": origem, "clicado_em": agora, "estado": "montando"}
        self.historico = (self.historico + [registro])[-20:]

        # Interrompe a exclusão ANTES de qualquer espera, para a janela não sumir.
        self.gravador.pausar_limpeza()
        threading.Thread(
            target=self._montar, args=(registro,), name=f"replay-{replay_id}", daemon=True
        ).start()
        log.info("Clique recebido (%s) -> replay %s", origem, replay_id)
        return {"aceito": True, "id": replay_id}

    def _montar(self, registro: dict):
        try:
            caminho = self._montar_arquivo(registro)
            registro["estado"] = "pronto"
            registro["arquivo"] = caminho.name
            log.info("Replay pronto: %s", caminho)
        except Exception as erro:
            registro["estado"] = "erro"
            registro["erro"] = str(erro)
            log.exception("Falha ao montar replay %s", registro["id"])
        finally:
            self.gravador.liberar_limpeza()

    def _esperar_fragmento_atual_fechar(self, clique: float) -> None:
        """O fragmento que está sendo gravado no momento do clique contém o
        próprio lance. Esperamos ele ser fechado (surge um fragmento mais novo)."""
        segs = self.gravador.segmentos()
        atual = segs[-1] if segs else None
        limite = clique + 3 * self.cfg.duracao_segmento + 5
        while time.time() < limite:
            segs = self.gravador.segmentos()
            if segs and atual is not None and segs[-1] != atual:
                return
            if segs and atual is None:
                atual = segs[-1]
            time.sleep(0.2)
        log.warning("Fragmento atual não fechou a tempo; usando o que já existe")

    def _montar_arquivo(self, registro: dict) -> Path:
        clique = registro["clicado_em"]
        self._esperar_fragmento_atual_fechar(clique)

        todos = self.gravador.segmentos()
        fechados = todos[:-1]  # o último ainda está sendo escrito pelo FFmpeg
        inicio_janela = clique - self.cfg.duracao_replay
        # mtime de um fragmento ≈ instante em que ele terminou de ser gravado
        selecionados = [s for s in fechados if s.stat().st_mtime > inicio_janela]
        if not selecionados:
            raise RuntimeError("Buffer vazio — a câmera está gravando? (veja /status)")

        nome_final = f"replay_{self.cfg.quadra_id}_{registro['id']}.mp4"
        destino = self.cfg.pasta_saida / nome_final
        temporario = destino.with_suffix(".mp4.part")

        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as lista:
            for s in selecionados:
                lista.write(f"file '{s.as_posix()}'\n")
            caminho_lista = lista.name

        cmd = [
            self.cfg.ffmpeg_bin, "-hide_banner", "-loglevel", "error", "-nostdin", "-y",
            "-f", "concat", "-safe", "0", "-i", caminho_lista,
            "-c", "copy",                 # sem reencode: instantâneo
            "-movflags", "+faststart",    # permite tocar no navegador antes de baixar tudo
            "-f", "mp4", str(temporario),
        ]
        try:
            resultado = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        finally:
            Path(caminho_lista).unlink(missing_ok=True)
        if resultado.returncode != 0:
            temporario.unlink(missing_ok=True)
            raise RuntimeError(f"FFmpeg falhou ao concatenar: {resultado.stderr.strip()}")

        # Metadados ao lado do vídeo (lidos pelo enviador). Gravado antes do
        # .mp4 aparecer, para o enviador sempre encontrá-lo.
        meta = {
            "id": registro["id"],
            "quadra_id": self.cfg.quadra_id,
            "origem": registro["origem"],
            # Com fuso horário (ex.: 2026-11-06T08:10:44-03:00), para o portal
            # (que guarda em UTC) converter certo.
            "clicado_em": datetime.fromtimestamp(clique).astimezone().isoformat(timespec="seconds"),
            "fragmentos": len(selecionados),
        }
        destino.with_suffix(".json").write_text(json.dumps(meta, ensure_ascii=False, indent=2))
        temporario.rename(destino)  # rename é atômico: o watchdog só vê o .mp4 completo
        return destino
