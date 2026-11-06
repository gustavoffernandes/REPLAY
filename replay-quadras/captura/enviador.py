"""Enviador (Watchdog): vigia a pasta de saída e envia cada .mp4 ao portal.

Assim que um replay novo aparece, faz um HTTP POST (multipart) para a API do
Laravel. Se o portal estiver fora do ar, o arquivo fica na pasta e é
reenviado automaticamente a cada 30s — nenhum replay se perde.
"""

import json
import logging
import queue
import shutil
import threading
from pathlib import Path

import requests
from watchdog.events import FileSystemEventHandler
from watchdog.observers import Observer

from config import Config

log = logging.getLogger("enviador")

INTERVALO_REENVIO = 30


class Enviador:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self._fila: "queue.Queue[Path]" = queue.Queue()
        self._na_fila: set[Path] = set()
        self._trava = threading.Lock()
        self._observer = Observer()

    def _enfileirar(self, caminho: Path):
        caminho = caminho.resolve()
        if caminho.suffix != ".mp4" or caminho.parent != self.cfg.pasta_saida:
            return
        with self._trava:
            if caminho in self._na_fila:
                return
            self._na_fila.add(caminho)
        self._fila.put(caminho)

    def _varrer_pendentes(self):
        for arq in sorted(self.cfg.pasta_saida.glob("*.mp4")):
            self._enfileirar(arq)

    def _enviar(self, video: Path) -> bool:
        meta_arquivo = video.with_suffix(".json")
        meta = json.loads(meta_arquivo.read_text()) if meta_arquivo.exists() else {}
        dados = {
            "quadra_id": meta.get("quadra_id", self.cfg.quadra_id),
            "origem": meta.get("origem", "desconhecida"),
            "clicado_em": meta.get("clicado_em", ""),
            "replay_id": meta.get("id", video.stem),
        }
        with video.open("rb") as f:
            resposta = requests.post(
                self.cfg.portal_url_upload,
                headers={
                    "Authorization": f"Bearer {self.cfg.replay_token}",
                    "Accept": "application/json",
                },
                data=dados,
                files={"video": (video.name, f, "video/mp4")},
                timeout=(5, 120),
            )
        if resposta.status_code >= 300:
            log.error("Portal recusou %s: HTTP %s %s", video.name, resposta.status_code, resposta.text[:300])
            return False

        # Enviado: arquiva (ou apaga) o vídeo e seus metadados.
        for arq in (video, meta_arquivo):
            if not arq.exists():
                continue
            if self.cfg.manter_copia_local:
                shutil.move(str(arq), self.cfg.pasta_enviados / arq.name)
            else:
                arq.unlink()
        log.info("Replay enviado ao portal: %s", video.name)
        return True

    def _laco_envio(self):
        while True:
            video = self._fila.get()
            try:
                if video.exists():
                    self._enviar(video)
            except requests.RequestException as erro:
                log.warning("Portal indisponível (%s). Vou tentar de novo em %ss.", erro.__class__.__name__, INTERVALO_REENVIO)
            except Exception:
                log.exception("Erro inesperado ao enviar %s", video.name)
            finally:
                with self._trava:
                    self._na_fila.discard(video)

    def _laco_reenvio(self):
        while True:
            threading.Event().wait(INTERVALO_REENVIO)
            self._varrer_pendentes()

    def iniciar(self):
        enviador = self

        class Handler(FileSystemEventHandler):
            def on_created(self, evento):
                if not evento.is_directory:
                    enviador._enfileirar(Path(evento.src_path))

            def on_moved(self, evento):  # o montador faz rename .part -> .mp4
                if not evento.is_directory:
                    enviador._enfileirar(Path(evento.dest_path))

        self._observer.schedule(Handler(), str(self.cfg.pasta_saida), recursive=False)
        self._observer.start()
        threading.Thread(target=self._laco_envio, name="envio", daemon=True).start()
        threading.Thread(target=self._laco_reenvio, name="reenvio", daemon=True).start()
        self._varrer_pendentes()  # envia o que ficou pendente de execuções anteriores
        log.info("Watchdog vigiando %s -> %s", self.cfg.pasta_saida, self.cfg.portal_url_upload)

    def parar(self):
        self._observer.stop()
