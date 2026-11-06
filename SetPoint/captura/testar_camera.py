"""Diagnóstico da câmera: testa rede, porta RTSP e leitura do vídeo.

Usa o RTSP_URL do .env. Rode ANTES de subir o sistema:

    python testar_camera.py
"""

import os
import re
import socket
import subprocess
import sys
from urllib.parse import urlparse

from dotenv import load_dotenv

from config import PASTA_DO_SCRIPT, localizar_ffmpeg

load_dotenv(PASTA_DO_SCRIPT / ".env")
url = os.environ.get("RTSP_URL", "")
if not url or "SUA_SENHA" in url or "IP_DA_CAMERA" in url:
    sys.exit("RTSP_URL não configurada no .env")

alvo = urlparse(url)
host, porta = alvo.hostname, alvo.port or 554
print(f"Câmera: {host}:{porta}  (usuário: {alvo.username})\n")

# 1) A porta RTSP responde?
print("1) Testando conexão com a porta RTSP...", end=" ", flush=True)
try:
    socket.create_connection((host, porta), timeout=4).close()
    print("OK")
except OSError as erro:
    print("FALHOU")
    print(f"   {erro}")
    print("   -> Câmera desligada/sem cabo? IP errado? RTSP desativado no painel?")
    print(f"   -> Seu IP e o da câmera precisam estar na mesma faixa (ex.: 192.168.1.x).")
    sys.exit(1)

# 2) O FFmpeg consegue ler o vídeo?
print("2) Lendo 3 segundos de vídeo...", end=" ", flush=True)
ffmpeg = localizar_ffmpeg(os.environ.get("FFMPEG_BIN", "").strip())
try:
    r = subprocess.run(
        [ffmpeg, "-hide_banner", "-rtsp_transport", "tcp", "-i", url, "-t", "3", "-f", "null", "-"],
        capture_output=True, text=True, timeout=25,
    )
except subprocess.TimeoutExpired:
    print("FALHOU (tempo esgotado)")
    sys.exit(1)

saida = r.stderr
if r.returncode != 0:
    print("FALHOU")
    if "401" in saida or "Unauthorized" in saida:
        print("   -> Usuário ou senha incorretos.")
    elif "404" in saida or "Not Found" in saida:
        print("   -> Caminho do stream errado (a parte depois da porta). Veja o ONVIF.")
    else:
        print("   " + "\n   ".join(saida.strip().splitlines()[-4:]))
    sys.exit(1)
print("OK")

m = re.search(r"Stream #0:\d+.*Video: (\w+).*?, (\d+x\d+)", saida)
if m:
    codec, res = m.groups()
    print(f"\nVídeo: {codec} {res}")
    if codec.lower() in {"hevc", "h265"}:
        print("⚠️  H.265: o navegador NÃO toca. Mude o codec da câmera para H.264.")
print("\n✅ Câmera pronta. Pode rodar: python main.py")
