"""Gatilho via HTTP — é por aqui que o site Laravel "aperta a botoeira".

  POST /disparar   (header Authorization: Bearer <REPLAY_TOKEN>)  -> salva replay
  GET  /status                                                     -> saúde do buffer

Usa só a biblioteca padrão do Python. Por padrão escuta apenas em 127.0.0.1
(mesma máquina do Laravel); mude GATILHO_HOST se o site rodar em outro PC.
"""

import hmac
import json
import logging
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from config import Config
from gravador import Gravador
from montador import Montador

log = logging.getLogger("gatilho_http")


class _Servidor(ThreadingHTTPServer):
    daemon_threads = True

    def server_bind(self):
        # O HTTPServer padrão chama socket.getfqdn(), que no macOS pode levar
        # ~30s (DNS reverso). Não precisamos do nome do host.
        import socketserver

        socketserver.TCPServer.server_bind(self)
        self.server_name, self.server_port = self.server_address[:2]


def iniciar_servidor(cfg: Config, gravador: Gravador, montador: Montador) -> ThreadingHTTPServer:
    class Handler(BaseHTTPRequestHandler):
        def _responder(self, codigo: int, corpo: dict):
            dados = json.dumps(corpo, ensure_ascii=False).encode()
            self.send_response(codigo)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(dados)))
            self.end_headers()
            self.wfile.write(dados)

        def _autorizado(self) -> bool:
            if not cfg.replay_token:
                return True
            recebido = self.headers.get("Authorization", "").removeprefix("Bearer ").strip()
            return hmac.compare_digest(recebido, cfg.replay_token)

        def do_GET(self):
            if self.path == "/status":
                self._responder(200, {
                    **gravador.status(),
                    "quadra_id": cfg.quadra_id,
                    "botoeira_modo": cfg.botoeira_modo,
                    "ultimos_disparos": montador.historico[-5:],
                })
            else:
                self._responder(404, {"erro": "rota não encontrada"})

        def do_POST(self):
            if self.path != "/disparar":
                return self._responder(404, {"erro": "rota não encontrada"})
            if not self._autorizado():
                return self._responder(401, {"erro": "token inválido"})
            resultado = montador.disparar(origem="site")
            self._responder(202 if resultado["aceito"] else 429, resultado)

        def log_message(self, formato, *args):  # silencia o log padrão do http.server
            log.debug(formato, *args)

    servidor = _Servidor((cfg.gatilho_host, cfg.gatilho_porta), Handler)
    threading.Thread(target=servidor.serve_forever, name="gatilho-http", daemon=True).start()
    log.info("Gatilho HTTP ouvindo em http://%s:%s", cfg.gatilho_host, cfg.gatilho_porta)
    return servidor
