"""Diagnóstico da placa Zero Delay: mostra qual botão/eixo está sendo apertado.

Use quando a placa chegar, para descobrir o número do botão e colocar em
BOTOEIRA_JOYSTICK_BOTAO no .env.

    pip install pygame
    python testar_botoeira.py
"""

import os
import time

os.environ.setdefault("SDL_VIDEODRIVER", "dummy")
os.environ.setdefault("SDL_JOYSTICK_ALLOW_BACKGROUND_EVENTS", "1")
os.environ.setdefault("PYGAME_HIDE_SUPPORT_PROMPT", "1")

import pygame  # noqa: E402

pygame.display.init()
pygame.joystick.init()

if pygame.joystick.get_count() == 0:
    print("Nenhum joystick/placa USB detectado. Confira o cabo USB.")
    print("Se a placa estiver em modo teclado, use BOTOEIRA_MODO=teclado no .env.")
    raise SystemExit(1)

for i in range(pygame.joystick.get_count()):
    j = pygame.joystick.Joystick(i)
    j.init()
    print(f"[{i}] {j.get_name()} — {j.get_numbuttons()} botões, {j.get_numaxes()} eixos")

print("\nAperte a botoeira (Ctrl+C para sair)...")
try:
    while True:
        for ev in pygame.event.get():
            if ev.type == pygame.JOYBUTTONDOWN:
                print(f"  -> BOTÃO {ev.button} apertado (joystick {ev.joy})  "
                      f"=> use BOTOEIRA_JOYSTICK_BOTAO={ev.button}")
            elif ev.type == pygame.JOYAXISMOTION and abs(ev.value) > 0.5:
                print(f"  -> eixo {ev.axis} movido ({ev.value:+.1f}) — fio ligado numa direção, não num botão")
        time.sleep(0.01)
except KeyboardInterrupt:
    pass
