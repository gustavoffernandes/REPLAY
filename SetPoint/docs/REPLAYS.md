# Replays: infraestrutura e arquitetura

O módulo de replays cria um ecossistema local de captura de **replays instantâneos
para quadras esportivas**. O sistema é baseado em eventos: o vídeo da câmera é
gravado continuamente por um software local e, ao comando de uma botoeira (física
ou o botão "Salvar replay" do SetPoint), os últimos 30 segundos são consolidados
e enviados para o SetPoint.

---

## 1. Hardware (fase atual de testes)

A topologia atual roda sem dependências complexas de rede.

| Componente | Função | Status |
|---|---|---|
| **Câmera IP (RTSP)** | Fluxo Full HD/4K extraído direto via RTSP, sem NVR físico. | Testada no VLC |
| **Botoeira (cogumelo, contato NA)** | Passiva: só fecha o circuito no momento do clique. | Ainda não comprada; o botão do painel faz o mesmo papel |
| **Placa USB Zero Delay** (Sanwa/Eletromatic) | Converte o pulso do botão em entrada USB (gamepad ou teclado). | Ainda não comprada; o código já está pronto |
| **Cabo de rede Cat5e/Cat6** | Dados da câmera até o roteador/switch. | n/a |
| **Fonte 12V (P4)** | Alimentação dedicada da câmera nesta fase. | n/a |

URL RTSP padrão, ajustada no `captura/.env`:

```
rtsp://admin:SUA_SENHA@IP_DA_CAMERA:554/cam1/onvif-h264
```

O caminho depois da porta (`/cam1/onvif-h264`) varia por fabricante e modelo.
O `python testar_camera.py` confere rede, porta, senha, caminho e codec.

---

## 2. Fluxo do vídeo

```
 Câmera IP ─RTSP─► [1] Gravador (FFmpeg -c copy)
                        └─► dados/buffer/seg_*.ts   (buffer circular ~30 s)

 Botoeira ─USB──► [4] Botoeira (pygame/pynput) ─┐
 (Zero Delay)                                    ├─► [2] Montador.disparar()
 SetPoint ─HTTP POST /disparar─► [3] Gatilho ────┘      │ pausa a exclusão do buffer
 ("Salvar replay")              (porta 8765)            │ espera o fragmento atual fechar
                                                        │ FFmpeg concat -c copy
                                                        ▼
                                          dados/replays/replay_*.mp4
                                                        │
                                  [5] Enviador (Watchdog) ── POST /api/replays/upload ──► SetPoint
                                                                                            │
                                                              storage/app/private/replays/{filial}/{quadra}
                                                              + tabela "replays"  ──► tela Replays
```

1. **Gravação contínua** (`captura/gravador.py`): o FFmpeg consome a URL RTSP e
   grava fragmentos `.ts` de ~2 s, sem reprocessar o vídeo (`-c copy`). Uma
   thread apaga os fragmentos mais antigos que a janela do replay.

2. **Disparo** (`captura/montador.py`, `Montador.disparar(origem)`): é o **ponto
   único** de disparo. Três origens chamam a mesma função:
   - **SetPoint**: tela Replays → `POST /api/courts/{id}/replays` → o back-end chama
     o serviço de captura em `POST http://127.0.0.1:8765/disparar`;
   - **Botoeira física**: botão → placa Zero Delay → USB → `captura/botoeira.py`;
   - **Terminal** (teste): ENTER no terminal.

   No clique, a exclusão do buffer é interrompida até o replay ficar pronto.

3. **Consolidação**: o montador espera o fragmento em gravação fechar (ele contém
   o próprio lance), seleciona os dos últimos 30 s e o FFmpeg junta tudo em um
   `.mp4` (`-f concat -c copy`), instantâneo e sem consumo de CPU.

4. **Envio** (`captura/enviador.py`): um Watchdog vigia `dados/replays/` e faz
   `POST multipart` para `/api/replays/upload` com o token da câmera. Se o
   SetPoint estiver fora do ar, tenta de novo a cada 30 s; nada se perde.

### Por que o SetPoint não roda o script Python diretamente?

O buffer precisa estar **gravando o tempo todo**, senão não existem "os últimos
30 segundos" no momento do clique. Por isso o Python é um serviço sempre ligado, e
o SetPoint só *avisa* esse serviço que o botão foi apertado, exatamente como a
placa USB faz. Assim, painel e botoeira física usam o mesmo caminho.

### Segurança

- O **token da câmera** é gerado em *Quadras → Editar → Câmera de replay* e mostrado
  uma única vez. No banco fica criptografado (para o SetPoint disparar a câmera) e
  em hash (para identificar a quadra quando um vídeo chega). Nunca sai na API.
- O token identifica a quadra, e portanto a filial e a empresa dona do vídeo.
- Os vídeos ficam em `storage/app/private` (fora da pasta pública) e são servidos
  por **links assinados e temporários**.
- O serviço de captura escuta só em `127.0.0.1` por padrão.

### Precisão da janela de 30 s

Com `-c copy` o FFmpeg só corta em *keyframes*. O replay terá ~**30 s + 1 fragmento**
(o do momento do clique). Para cortes mais justos, configure na câmera um
intervalo de I-frame/GOP de 1 a 2 s.

---

## 3. Próximo passo: Switch PoE

Assim que a lógica for validada em laboratório:

- **Switch PoE (Power over Ethernet)** substitui as fontes 12V individuais.
- Dados e energia no **mesmo cabo de rede** até a câmera: dispensa tomadas no alto
  dos postes, reduz pontos de falha e profissionaliza o produto.

**Impacto no software: nenhum.** A câmera continua acessível via RTSP; só muda o
IP na `RTSP_URL` se o endereçamento mudar. Para várias câmeras, rode uma instância
do serviço de captura por câmera (cada uma com seu `.env`, `GATILHO_PORTA` e token).

---

## 4. Mapa do código

| Arquivo | Responsabilidade |
|---|---|
| `captura/main.py` | Sobe todos os componentes num processo |
| `captura/config.py` | Lê o `.env` e valida a configuração |
| `captura/gravador.py` | FFmpeg contínuo + limpeza do buffer circular |
| `captura/montador.py` | `disparar()` e concatenação do `.mp4` |
| `captura/gatilho_http.py` | `POST /disparar` e `GET /status` (para o SetPoint) |
| `captura/botoeira.py` | Leitura da placa Zero Delay (joystick/teclado/terminal) |
| `captura/enviador.py` | Watchdog + upload para o SetPoint |
| `captura/testar_camera.py` | Diagnóstico da câmera (rede, porta, senha, codec) |
| `captura/testar_botoeira.py` | Descobre o número do botão na placa |
| `backend/app/Http/Controllers/ReplayController.php` | Lista, botoeira virtual, status, token, exclusão |
| `backend/app/Http/Controllers/ReplayIngestController.php` | Recebe o `.mp4` e serve o vídeo por link assinado |
| `backend/app/Models/Replay.php` | Modelo com escopo por filial |
| `backend/database/migrations/2026_11_11_000001_create_replays.php` | Tabela `replays`, colunas da câmera em `courts` e permissões |
| `backend/config/replays.php` | Limites de upload e validade dos links |
| `web/src/views/Replays.vue` | Tela de replays |
| `web/src/views/Courts.vue` | Configuração da câmera por quadra |
