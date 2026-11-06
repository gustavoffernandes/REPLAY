"""Gravação contínua (buffer circular em disco).

O FFmpeg lê a câmera e grava fragmentos .ts de poucos segundos na pasta de
buffer, SEM reencodar (-c copy). Uma thread de limpeza apaga os fragmentos
mais velhos que a janela do replay — exceto enquanto algum replay está sendo
montado (aí a exclusão fica pausada, para não sumir nada da janela).
"""

import logging
import subprocess
import threading
import time
from datetime import datetime

from config import Config

log = logging.getLogger("gravador")

PREFIXO_SEGMENTO = "seg_"


class Gravador:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self._processo: subprocess.Popen | None = None
        self._parar = threading.Event()
        self._trava_limpeza = threading.Lock()
        self._replays_em_andamento = 0
        self.ultimo_erro: str | None = None

    # --- controle de exclusão (usado pelo montador de replays) --------------
    def pausar_limpeza(self):
        with self._trava_limpeza:
            self._replays_em_andamento += 1

    def liberar_limpeza(self):
        with self._trava_limpeza:
            self._replays_em_andamento = max(0, self._replays_em_andamento - 1)

    # --- comando do FFmpeg ----------------------------------------------------
    def _montar_comando(self) -> list[str]:
        cfg = self.cfg
        cmd = [cfg.ffmpeg_bin, "-hide_banner", "-loglevel", "warning", "-nostdin"]

        if cfg.fonte == "rtsp":
            cmd += ["-rtsp_transport", "tcp", "-i", cfg.rtsp_url]
            cmd += ["-map", "0:v:0"]
            if cfg.incluir_audio:
                cmd += ["-map", "0:a:0?"]
            cmd += ["-c", "copy"]  # usa o codec nativo da câmera: zero CPU de encode
        else:
            # Fonte sintética (sem câmera): barras de teste com contador de
            # frames, 30 fps, keyframe a cada 1s. Aqui há encode, só para teste.
            cmd += ["-re", "-f", "lavfi", "-i", "testsrc=size=1280x720:rate=30"]
            cmd += [
                "-c:v", "libx264", "-preset", "ultrafast", "-tune", "zerolatency",
                "-pix_fmt", "yuv420p", "-g", "30", "-an",
            ]

        # Nome dos fragmentos: seg_<inicio desta execução>_<contador>.ts — a
        # ordem alfabética é a ordem cronológica, mesmo após reinícios.
        inicio = datetime.now().strftime("%Y%m%d-%H%M%S")
        padrao = str(self.cfg.pasta_buffer / f"{PREFIXO_SEGMENTO}{inicio}_%06d.ts")
        cmd += [
            "-f", "segment",
            "-segment_time", str(cfg.duracao_segmento),
            "-segment_format", "mpegts",
            "-reset_timestamps", "1",
            padrao,
        ]
        return cmd

    # --- laço principal: mantém o FFmpeg vivo (reconecta se a câmera cair) --
    def _laco_gravacao(self):
        while not self._parar.is_set():
            cmd = self._montar_comando()
            log.info("Iniciando gravação contínua (%s)", "câmera RTSP" if self.cfg.fonte == "rtsp" else "fonte de teste")
            self._processo = subprocess.Popen(
                cmd, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True
            )
            for linha in self._processo.stderr:  # repassa avisos do FFmpeg para o log
                linha = linha.strip()
                if linha:
                    self.ultimo_erro = linha
                    log.warning("ffmpeg: %s", linha)
            codigo = self._processo.wait()
            if self._parar.is_set():
                break
            log.error("FFmpeg parou (código %s). Tentando reconectar em 5s...", codigo)
            self._parar.wait(5)

    def _laco_limpeza(self):
        # Mantém a janela do replay + folga de 3 fragmentos.
        manter_por = self.cfg.duracao_replay + 3 * self.cfg.duracao_segmento
        while not self._parar.wait(1):
            with self._trava_limpeza:
                if self._replays_em_andamento:
                    continue  # exclusão interrompida: tem replay sendo montado
                limite = time.time() - manter_por
                for arq in self.cfg.pasta_buffer.glob(f"{PREFIXO_SEGMENTO}*.ts"):
                    try:
                        if arq.stat().st_mtime < limite:
                            arq.unlink()
                    except FileNotFoundError:
                        pass

    def iniciar(self):
        threading.Thread(target=self._laco_gravacao, name="gravacao", daemon=True).start()
        threading.Thread(target=self._laco_limpeza, name="limpeza", daemon=True).start()

    def parar(self):
        self._parar.set()
        if self._processo and self._processo.poll() is None:
            self._processo.terminate()
            try:
                self._processo.wait(5)
            except subprocess.TimeoutExpired:
                self._processo.kill()

    def segmentos(self):
        """Fragmentos atuais do buffer, em ordem cronológica."""
        return sorted(self.cfg.pasta_buffer.glob(f"{PREFIXO_SEGMENTO}*.ts"))

    def status(self) -> dict:
        segs = self.segmentos()
        ativo = self._processo is not None and self._processo.poll() is None
        recente = bool(segs) and (time.time() - segs[-1].stat().st_mtime) < 3 * self.cfg.duracao_segmento + 2
        return {
            "gravando": ativo and recente,
            "fonte": self.cfg.fonte,
            "segmentos_no_buffer": len(segs),
            "ultimo_aviso_ffmpeg": self.ultimo_erro,
        }
