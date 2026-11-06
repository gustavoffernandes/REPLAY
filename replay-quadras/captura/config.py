"""Leitura da configuração (arquivo .env + variáveis de ambiente)."""

import os
import shutil
from dataclasses import dataclass
from pathlib import Path

try:
    from dotenv import load_dotenv
except ImportError:  # python-dotenv é opcional: sem ele, valem só as variáveis de ambiente
    load_dotenv = None

PASTA_DO_SCRIPT = Path(__file__).resolve().parent


def _bool(valor: str) -> bool:
    return str(valor).strip().lower() in {"1", "true", "sim", "yes", "on"}


def _caminho(valor: str) -> Path:
    p = Path(valor)
    return p if p.is_absolute() else (PASTA_DO_SCRIPT / p).resolve()


def localizar_ffmpeg(preferido: str = "") -> str:
    """Procura o FFmpeg: variável FFMPEG_BIN -> PATH -> pacote imageio-ffmpeg."""
    if preferido:
        return preferido
    no_path = shutil.which("ffmpeg")
    if no_path:
        return no_path
    try:
        import imageio_ffmpeg

        return imageio_ffmpeg.get_ffmpeg_exe()
    except Exception:
        raise SystemExit(
            "FFmpeg não encontrado. Instale (macOS: brew install ffmpeg) "
            "ou rode: pip install imageio-ffmpeg"
        )


@dataclass
class Config:
    quadra_id: str
    fonte: str
    rtsp_url: str
    incluir_audio: bool
    duracao_replay: float
    duracao_segmento: float
    intervalo_minimo_cliques: float
    pasta_buffer: Path
    pasta_saida: Path
    manter_copia_local: bool
    gatilho_host: str
    gatilho_porta: int
    botoeira_modo: str
    botoeira_joystick_botao: int
    botoeira_tecla: str
    portal_url_upload: str
    replay_token: str
    ffmpeg_bin: str

    @property
    def pasta_enviados(self) -> Path:
        return self.pasta_saida / "enviados"


def carregar_config() -> Config:
    if load_dotenv:
        load_dotenv(PASTA_DO_SCRIPT / ".env")

    e = os.environ.get
    cfg = Config(
        quadra_id=e("QUADRA_ID", "quadra-01"),
        fonte=e("FONTE", "teste").strip().lower(),
        rtsp_url=e("RTSP_URL", "rtsp://admin:SUA_SENHA@IP_DA_CAMERA:554/cam1/onvif-h264"),
        incluir_audio=_bool(e("INCLUIR_AUDIO", "false")),
        duracao_replay=float(e("DURACAO_REPLAY_SEGUNDOS", "30")),
        duracao_segmento=float(e("DURACAO_SEGMENTO_SEGUNDOS", "2")),
        intervalo_minimo_cliques=float(e("INTERVALO_MINIMO_ENTRE_CLIQUES", "3")),
        pasta_buffer=_caminho(e("PASTA_BUFFER", "./dados/buffer")),
        pasta_saida=_caminho(e("PASTA_SAIDA", "./dados/replays")),
        manter_copia_local=_bool(e("MANTER_COPIA_LOCAL", "true")),
        gatilho_host=e("GATILHO_HOST", "127.0.0.1"),
        gatilho_porta=int(e("GATILHO_PORTA", "8765")),
        botoeira_modo=e("BOTOEIRA_MODO", "desativado").strip().lower(),
        botoeira_joystick_botao=int(e("BOTOEIRA_JOYSTICK_BOTAO", "0")),
        botoeira_tecla=e("BOTOEIRA_TECLA", "space").strip().lower(),
        portal_url_upload=e("PORTAL_URL_UPLOAD", "http://127.0.0.1:8000/api/replays"),
        replay_token=e("REPLAY_TOKEN", ""),
        ffmpeg_bin=localizar_ffmpeg(e("FFMPEG_BIN", "").strip()),
    )

    if cfg.fonte not in {"rtsp", "teste"}:
        raise SystemExit(f"FONTE inválida: {cfg.fonte!r} (use 'rtsp' ou 'teste')")
    if cfg.fonte == "rtsp" and ("SUA_SENHA" in cfg.rtsp_url or "IP_DA_CAMERA" in cfg.rtsp_url):
        raise SystemExit(
            "RTSP_URL ainda está com o valor de exemplo. Ajuste usuário/senha/IP no .env "
            "ou use FONTE=teste para rodar sem câmera."
        )

    for pasta in (cfg.pasta_buffer, cfg.pasta_saida, cfg.pasta_enviados):
        pasta.mkdir(parents=True, exist_ok=True)
    return cfg
