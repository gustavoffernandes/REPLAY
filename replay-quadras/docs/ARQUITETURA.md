# 📋 Documentação de Infraestrutura e Arquitetura do Sistema

O objetivo principal deste projeto é criar um ecossistema local de captura e
gerenciamento de **replays instantâneos para quadras esportivas**. O sistema é
baseado em eventos: o fluxo de vídeo é gerenciado em tempo real por um software
local e, ao comando de uma botoeira (física ou virtual), os últimos 30 segundos
são consolidados e enviados para a plataforma Web.

---

## 🧱 1. Componentes de Hardware (fase atual de testes)

Para garantir velocidade de desenvolvimento em laboratório e isolamento de
escopo, a topologia atual roda sem dependências complexas de rede.

| Componente | Função | Status |
|---|---|---|
| **Câmera IP (RTSP)** | Fluxo de vídeo Full HD/4K extraído direto via RTSP, sem NVR físico. | URL provisória no `.env` — falta testar |
| **Botoeira (cogumelo, contato NA)** | Passiva: só fecha o circuito no momento do clique. | Ainda não comprada — simulada pelo site |
| **Placa USB Zero Delay** (Sanwa/Eletromatic) | Converte o pulso do botão em entrada USB (gamepad ou teclado). | Ainda não comprada — código pronto |
| **Cabo de rede Cat5e/Cat6** | Dados da câmera → placa de rede do servidor ou modem local. | — |
| **Fonte 12V (P4)** | Alimentação dedicada da câmera nesta fase. | — |

URL RTSP padrão (provisória):

```
rtsp://admin:SUA_SENHA@IP_DA_CAMERA:554/cam1/onvif-h264
```

> ⚠️ **Lembrete:** essa URL é só o padrão. Usuário, senha, IP, porta e o caminho
> `/cam1/onvif-h264` variam por fabricante/modelo e ainda precisam ser testados
> com a câmera real (ver README, seção "Ligando a câmera real").

---

## 🔄 2. Fluxo do Vídeo e Integração Web

```
                ┌──────────────── PC LOCAL DA QUADRA ────────────────────────────┐
 Câmera IP ─RTSP─► [1] Gravador (FFmpeg -c copy)                                  │
                │      └─► dados/buffer/seg_*.ts   (buffer circular ~30s)         │
                │                                                                 │
 Botoeira ─USB──► [4] Botoeira (pygame/pynput) ─┐                                 │
 (Zero Delay)   │                               ├─► [2] Montador.disparar()       │
 Site Laravel ──HTTP POST /disparar──► [3] ─────┘      │  pausa a exclusão       │
 (botão "Salvar │      Gatilho HTTP :8765              │  espera o fragmento atual│
  vídeo")       │                                      │  FFmpeg concat -c copy   │
                │                                      ▼                          │
                │                        dados/replays/replay_*.mp4               │
                │                                      │                          │
                │                    [5] Enviador (Watchdog) ── HTTP POST ───────────► Laravel /api/replays
                └─────────────────────────────────────────────────────────────────┘        │
                                                                                     storage/app/public/replays
                                                                                     + tabela "replays"
                                                                                           │
                                                                                     Página "/" lista e toca
```

1. **Gravação contínua (buffer local)** — `captura/gravador.py`
   O FFmpeg consome a URL RTSP e grava fragmentos `.ts` de ~2s em
   `captura/dados/buffer/`, sem reencodar (`-c copy`, usa o codec nativo da
   câmera). Uma thread apaga fragmentos mais velhos que a janela do replay.

2. **Disparo do gatilho** — `captura/montador.py` → `Montador.disparar(origem)`
   É o **ponto único** de disparo. Três origens chamam a mesma função:
   - **Site** (botoeira virtual): página Laravel → `POST /replays/disparar` →
     Laravel chama o serviço Python em `POST http://127.0.0.1:8765/disparar`.
   - **Botoeira física**: botão → placa Zero Delay → USB → `captura/botoeira.py`.
   - **Terminal** (teste): ENTER no terminal.

   No clique, a exclusão de fragmentos é **interrompida** até o replay ficar pronto.

3. **Consolidação do arquivo** — o montador espera o fragmento em gravação
   fechar (ele contém o próprio lance), seleciona os fragmentos dos últimos
   30s e o FFmpeg concatena tudo em um `.mp4` (`-f concat -c copy`) —
   instantâneo e sem consumo de CPU.

4. **Envio e sincronização Web** — `captura/enviador.py`
   Um Watchdog vigia `captura/dados/replays/`. Assim que um `.mp4` aparece, faz
   `POST multipart` para `/api/replays` do Laravel com token Bearer. Se o
   portal estiver fora do ar, tenta de novo a cada 30s (nada se perde).

### Por que o site não roda o script Python diretamente?

O buffer precisa estar **gravando o tempo todo** — senão não existem "os
últimos 30 segundos" no momento do clique. Por isso o Python é um serviço que
fica sempre ligado, e o site apenas *avisa* esse serviço (via HTTP local) que o
botão foi apertado — exatamente como a placa USB faz. Isso garante que site e
botoeira física usam o mesmo caminho e produzem o mesmo resultado.

### Precisão da janela de 30s

Com `-c copy` o FFmpeg só consegue cortar em *keyframes*. O replay terá
aproximadamente **30s + 1 fragmento** (o do momento do clique). Para cortes
mais justos, configure na câmera um GOP/intervalo de I-frame de 1–2s.

---

## 🚀 3. Próximo passo: Switch PoE

Assim que a lógica for validada em laboratório:

* **Switch PoE (Power over Ethernet)** substitui as fontes 12V individuais.
* Dados + energia no **mesmo cabo de rede** até a câmera: dispensa tomadas no
  alto dos postes, reduz pontos de falha e profissionaliza o produto.

**Impacto no software: nenhum.** A câmera continua acessível via RTSP; só muda
o IP na `RTSP_URL` se o endereçamento da rede mudar. Para várias câmeras/quadras,
rode uma instância do serviço de captura por câmera (cada uma com seu `.env`,
`QUADRA_ID` e `GATILHO_PORTA` próprios).

---

## 🗂️ Mapa do código

| Arquivo | Responsabilidade |
|---|---|
| `captura/main.py` | Sobe todos os componentes num processo |
| `captura/config.py` | Lê o `.env` e valida a configuração |
| `captura/gravador.py` | FFmpeg contínuo + limpeza do buffer circular |
| `captura/montador.py` | `disparar()` e concatenação do `.mp4` |
| `captura/gatilho_http.py` | `POST /disparar` e `GET /status` (para o site) |
| `captura/botoeira.py` | Leitura da placa Zero Delay (joystick/teclado/terminal) |
| `captura/enviador.py` | Watchdog + upload para o Laravel |
| `captura/testar_botoeira.py` | Descobre o número do botão na placa |
| `web/overlay/…` | Arquivos que o `instalar.sh` aplica sobre um Laravel 12 novo |
| `web/overlay/app/Http/Controllers/ReplayController.php` | Página, botoeira virtual, status |
| `web/overlay/app/Http/Controllers/Api/ReplayUploadController.php` | Recebe o `.mp4` |
| `web/overlay/resources/views/replays.blade.php` | Página com o botão "Salvar vídeo" |
