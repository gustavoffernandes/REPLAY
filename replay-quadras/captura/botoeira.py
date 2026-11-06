"""Botoeira física (botão cogumelo + placa USB Zero Delay).

A placa Zero Delay transforma o fechamento do contato NA do botão em um
"aperto de botão" de gamepad (ou de tecla, dependendo da placa). Este módulo
escuta esse evento e chama `disparar("botoeira")` — exatamente a mesma
função usada pelo site. Ou seja: quando a placa chegar, basta mudar
BOTOEIRA_MODO no .env; nada mais no sistema precisa mudar.

Modos (BOTOEIRA_MODO):
  desativado  só o site dispara (fase atual)
  joystick    placa em modo gamepad  -> requer: pip install pygame
  teclado     placa em modo teclado  -> requer: pip install pynput
              (no macOS, dê permissão de "Monitoramento de Entrada" ao Terminal)
  terminal    simulação: aperte ENTER no terminal onde o serviço está rodando
"""

import logging
import os
import sys
import threading
import time
from typing import Callable

from config import Config

log = logging.getLogger("botoeira")

Disparo = Callable[[str], dict]


def escutar_botoeira(cfg: Config, disparar: Disparo, parar: threading.Event):
    """Bloqueia escutando a botoeira até `parar` ser sinalizado.

    Deve rodar na thread principal: no macOS o SDL (pygame) só lê o joystick
    a partir da thread principal.
    """
    modo = cfg.botoeira_modo
    if modo == "joystick":
        _modo_joystick(cfg, disparar, parar)
    elif modo == "teclado":
        _modo_teclado(cfg, disparar, parar)
    elif modo == "terminal":
        _modo_terminal(disparar, parar)
    else:
        if modo != "desativado":
            log.warning("BOTOEIRA_MODO=%r desconhecido; botoeira desativada", modo)
        log.info("Botoeira física desativada — disparo apenas pelo site")
        parar.wait()


# --- gamepad (padrão da placa Zero Delay) -----------------------------------
def _modo_joystick(cfg: Config, disparar: Disparo, parar: threading.Event):
    os.environ.setdefault("SDL_VIDEODRIVER", "dummy")                 # sem janela
    os.environ.setdefault("SDL_JOYSTICK_ALLOW_BACKGROUND_EVENTS", "1")  # lê mesmo sem foco
    os.environ.setdefault("PYGAME_HIDE_SUPPORT_PROMPT", "1")
    try:
        import pygame
    except ImportError:
        raise SystemExit("Modo joystick precisa do pygame: pip install pygame")

    pygame.display.init()
    pygame.joystick.init()
    botao = cfg.botoeira_joystick_botao
    controle = None
    estava_apertado = False

    while not parar.is_set():
        if controle is None:
            pygame.joystick.quit()
            pygame.joystick.init()
            if pygame.joystick.get_count() == 0:
                log.warning("Nenhuma placa/joystick USB encontrada. Tentando de novo em 3s...")
                parar.wait(3)
                continue
            controle = pygame.joystick.Joystick(0)
            controle.init()
            log.info("Botoeira conectada: %s (%s botões) — usando botão %s",
                     controle.get_name(), controle.get_numbuttons(), botao)

        for evento in pygame.event.get():
            if evento.type == pygame.JOYDEVICEREMOVED:
                log.warning("Placa USB desconectada")
                controle = None
        if controle is None:
            continue

        try:
            apertado = bool(controle.get_button(botao))
        except pygame.error:
            controle = None
            continue
        if apertado and not estava_apertado:  # borda de subida = um clique
            disparar("botoeira")
        estava_apertado = apertado
        time.sleep(0.01)  # 100 leituras por segundo


# --- teclado (placa configurada como teclado, ou teste com o teclado do PC) -
def _modo_teclado(cfg: Config, disparar: Disparo, parar: threading.Event):
    try:
        from pynput import keyboard
    except ImportError:
        raise SystemExit("Modo teclado precisa do pynput: pip install pynput")

    alvo = cfg.botoeira_tecla
    tecla_alvo = getattr(keyboard.Key, alvo, None) or keyboard.KeyCode.from_char(alvo)
    segurando = False

    def ao_apertar(tecla):
        nonlocal segurando
        if tecla == tecla_alvo and not segurando:  # ignora auto-repetição da tecla
            segurando = True
            disparar("botoeira")

    def ao_soltar(tecla):
        nonlocal segurando
        if tecla == tecla_alvo:
            segurando = False

    log.info("Botoeira em modo teclado — tecla '%s'", alvo)
    with keyboard.Listener(on_press=ao_apertar, on_release=ao_soltar):
        parar.wait()


# --- simulação pelo terminal -------------------------------------------------
def _modo_terminal(disparar: Disparo, parar: threading.Event):
    if not sys.stdin.isatty():
        log.warning("Modo terminal precisa de um terminal interativo; botoeira desativada")
        parar.wait()
        return
    log.info("Botoeira simulada: aperte ENTER para salvar um replay (Ctrl+C para sair)")
    while not parar.is_set():
        linha = sys.stdin.readline()
        if not linha:
            break
        disparar("botoeira-simulada")
