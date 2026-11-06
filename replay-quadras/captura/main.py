"""Serviço local de captura de replays — ponto de entrada.

Sobe, num único processo:
  1. Gravador     -> FFmpeg grava a câmera em fragmentos (buffer circular)
  2. Montador     -> no clique, junta os últimos N segundos num .mp4
  3. Gatilho HTTP -> recebe o clique vindo do site Laravel
  4. Botoeira     -> recebe o clique vindo da placa Zero Delay (opcional)
  5. Enviador     -> Watchdog que manda cada .mp4 pronto para a API do portal

Uso:  python main.py
"""

import logging
import signal
import sys
import threading

from botoeira import escutar_botoeira
from config import carregar_config
from enviador import Enviador
from gatilho_http import iniciar_servidor
from gravador import Gravador
from montador import Montador


def main():
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(name)s] %(message)s",
        datefmt="%H:%M:%S",
    )
    log = logging.getLogger("main")
    cfg = carregar_config()
    log.info("Quadra: %s | fonte: %s | replay de %ss | FFmpeg: %s",
             cfg.quadra_id, cfg.fonte, int(cfg.duracao_replay), cfg.ffmpeg_bin)

    gravador = Gravador(cfg)
    montador = Montador(cfg, gravador)
    enviador = Enviador(cfg)

    # O gatilho HTTP sobe primeiro: se a porta estiver ocupada (outra captura
    # já rodando), sai antes de abrir a câmera e não deixa um FFmpeg órfão.
    try:
        servidor = iniciar_servidor(cfg, gravador, montador)
    except OSError as erro:
        raise SystemExit(
            f"Não consegui abrir a porta {cfg.gatilho_porta} ({erro.strerror}). "
            "Já existe outra captura rodando? Feche-a ou mude GATILHO_PORTA no .env."
        )
    gravador.iniciar()
    enviador.iniciar()

    parar = threading.Event()
    signal.signal(signal.SIGTERM, lambda *_: parar.set())

    try:
        # A botoeira fica na thread principal (exigência do pygame no macOS).
        escutar_botoeira(cfg, montador.disparar, parar)
    except KeyboardInterrupt:
        pass
    finally:
        log.info("Encerrando...")
        servidor.shutdown()
        enviador.parar()
        gravador.parar()


if __name__ == "__main__":
    sys.exit(main())
