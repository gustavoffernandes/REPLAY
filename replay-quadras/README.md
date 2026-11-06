# 🎥 Replay Instantâneo para Quadras

Sistema local que grava a câmera da quadra o tempo todo e, quando alguém aperta
a botoeira, salva **os últimos 30 segundos** em `.mp4` e publica no portal Web.

O replay pode ser disparado de **duas formas, ao mesmo tempo**:

- 🖱️ **Site** — botão "Salvar vídeo" no portal Laravel (botoeira virtual, fase atual);
- 🔴 **Botoeira física** — botão cogumelo + placa USB Zero Delay (quando for comprada).

As duas chamam exatamente a mesma função, então o que você testar hoje pelo site
é o mesmo fluxo que vai rodar com o botão de verdade.

> Detalhes de hardware, diagrama e decisões de projeto: [docs/ARQUITETURA.md](docs/ARQUITETURA.md)

---

## Como funciona (em 30 segundos)

```
Câmera ──RTSP──► captura (Python) ──► buffer: fragmentos de 2s, só os últimos ~30s
                      ▲                    │
     clique ──────────┘                    ▼  no clique: junta os fragmentos (FFmpeg)
 (site ou botoeira)                   replay_xxx.mp4
                                           │  Watchdog envia (HTTP POST)
                                           ▼
                                  portal (Laravel) ──► página com o vídeo
```

São **dois programas** rodando no mesmo PC:

| Pasta | O que é | Porta |
|---|---|---|
| `captura/` | Serviço Python: grava, monta o replay, escuta a botoeira, envia ao portal | `8765` (só local) |
| `web/` | Portal Laravel: página com o botão "Salvar vídeo" e a lista de replays; API que recebe os vídeos | `8000` |

---

## ✅ Pré-requisitos (macOS)

```bash
brew install php composer ffmpeg
```

- **Python 3.10+** (você já tem)
- **FFmpeg** — se não quiser instalar pelo brew, o `requirements.txt` instala o
  `imageio-ffmpeg`, que já traz um FFmpeg embutido. O sistema usa ele automaticamente.

---

## 🚀 Passo a passo para rodar

### 1. Instalar o serviço de captura (Python)

```bash
cd replay-quadras/captura
```

```bash
python3 -m venv .venv
```

```bash
source .venv/bin/activate
```

```bash
pip install -r requirements.txt
```

### 2. Instalar o portal (Laravel)

```bash
cd ../web
```

```bash
chmod +x instalar.sh servir.sh && ./instalar.sh
```

O `instalar.sh`:
1. cria um projeto Laravel 12 novo em `web/portal/`;
2. copia por cima os arquivos do sistema (`web/overlay/`: rotas, controllers, página, migration);
3. gera um **token** secreto e grava o mesmo valor em `web/portal/.env` **e** em `captura/.env`
   (é assim que o Python e o Laravel confiam um no outro);
4. cria a tabela `replays` (SQLite) e o link público `storage/` para os vídeos.

### 3. Subir o portal (Terminal 1)

```bash
cd replay-quadras/web && ./servir.sh
```

Abra **http://127.0.0.1:8000** — o indicador vai mostrar "Serviço de captura offline"
(normal, ainda não ligamos o Python).

### 4. Subir a captura (Terminal 2)

```bash
cd replay-quadras/captura && source .venv/bin/activate && python main.py
```

Por padrão o `.env` vem com `FONTE=teste`: o FFmpeg gera um vídeo sintético
(barras coloridas com contador), então **dá para testar tudo sem câmera**.
Você deve ver:

```
[gravador] Iniciando gravação contínua (fonte de teste)
[enviador] Watchdog vigiando .../dados/replays -> http://127.0.0.1:8000/api/replays
[gatilho_http] Gatilho HTTP ouvindo em http://127.0.0.1:8765
[botoeira] Botoeira física desativada — disparo apenas pelo site
```

### 5. Testar

1. Na página, o indicador fica **verde** ("Gravando — buffer ativo").
2. Espere ~30s (para o buffer encher) e clique em **Salvar vídeo**.
3. Em 2–4 segundos o replay aparece na lista, pronto para tocar ou baixar.

No terminal da captura aparece:

```
[montador] Clique recebido (site) -> replay 20261106-075842-dd826c
[montador] Replay pronto: .../dados/replays/replay_quadra-01_20261106-075842-dd826c.mp4
[enviador] Replay enviado ao portal: replay_quadra-01_20261106-075842-dd826c.mp4
```

---

## 📷 Ligando a câmera real

Edite `captura/.env`:

```ini
FONTE=rtsp
RTSP_URL=rtsp://admin:SUA_SENHA@IP_DA_CAMERA:554/cam1/onvif-h264   # PROVISÓRIO: testar usuário/senha/IP/caminho
```

Antes de rodar o sistema, teste a URL sozinha (deve abrir uma janela com a imagem;
`ffplay` vem com o `brew install ffmpeg`):

```bash
ffplay -rtsp_transport tcp "rtsp://admin:SUA_SENHA@IP_DA_CAMERA:554/cam1/onvif-h264"
```

Dicas:
- O caminho depois da porta (`/cam1/onvif-h264`) muda conforme o fabricante.
  Procure "RTSP URL" + modelo da câmera, ou use o app **ONVIF Device Manager**.
- Se a câmera tiver opção, configure o **intervalo de I-frame/GOP em 1–2s**:
  deixa o corte dos 30s mais preciso.
- Se o portal mostrar "Serviço online, mas sem vídeo chegando", a mensagem
  abaixo dele traz o erro do FFmpeg (senha errada, IP inacessível, etc.).

---

## 🔴 Quando a botoeira física chegar

Ligação: os dois fios do botão (contato NA) vão em um dos bornes de botão da
placa Zero Delay (ex.: `K1`/`B1` + `GND`). A placa vai na USB do PC.

1. Instale a biblioteca do joystick:
   ```bash
   pip install pygame
   ```
2. Descubra o número do botão:
   ```bash
   python testar_botoeira.py
   ```
   Aperte o botão — vai aparecer algo como `BOTÃO 2 apertado => use BOTOEIRA_JOYSTICK_BOTAO=2`.
3. No `captura/.env`:
   ```ini
   BOTOEIRA_MODO=joystick
   BOTOEIRA_JOYSTICK_BOTAO=2
   ```
4. Reinicie o `python main.py`. Pronto: **botão e site funcionam juntos**.
   No portal, cada replay mostra a origem (`site` ou `botoeira`).

Se a sua placa se apresentar como **teclado** em vez de gamepad:
`pip install pynput`, use `BOTOEIRA_MODO=teclado` e `BOTOEIRA_TECLA=` com a tecla
que ela envia. No macOS é preciso liberar o Terminal em
*Ajustes do Sistema → Privacidade e Segurança → Monitoramento de Entrada*.

**Para simular a botoeira sem placa**, use `BOTOEIRA_MODO=terminal` e aperte
**ENTER** no terminal da captura.

---

## ⚙️ Configurações principais (`captura/.env`)

| Variável | Padrão | Para que serve |
|---|---|---|
| `FONTE` | `teste` | `teste` (vídeo sintético) ou `rtsp` (câmera) |
| `RTSP_URL` | exemplo | URL da câmera (provisória) |
| `DURACAO_REPLAY_SEGUNDOS` | `30` | Tamanho do replay |
| `DURACAO_SEGMENTO_SEGUNDOS` | `2` | Tamanho de cada fragmento do buffer |
| `INTERVALO_MINIMO_ENTRE_CLIQUES` | `3` | Anti-repique: ignora cliques seguidos |
| `BOTOEIRA_MODO` | `desativado` | `desativado`, `joystick`, `teclado` ou `terminal` |
| `QUADRA_ID` | `quadra-01` | Nome da quadra (aparece no portal) |
| `MANTER_COPIA_LOCAL` | `true` | Guarda o `.mp4` em `dados/replays/enviados/` após enviar |
| `INCLUIR_AUDIO` | `false` | Liga o áudio da câmera (testar compatibilidade antes) |

O `.env` está no `.gitignore` — senha da câmera e token não vão para o Git.

---

## 🩺 Problemas comuns

| Sintoma | Causa provável / solução |
|---|---|
| Página: "Serviço de captura offline" | O `python main.py` não está rodando, ou `CAPTURA_URL` no `web/portal/.env` está diferente da porta `GATILHO_PORTA`. |
| Clique dá "token inválido" | `REPLAY_TOKEN` diferente entre `web/portal/.env` e `captura/.env`. Rode `./instalar.sh` de novo ou copie o valor manualmente. |
| "Buffer vazio — a câmera está gravando?" | Clicou logo após ligar, ou o FFmpeg não está recebendo vídeo. Veja o log da captura. |
| Replay gerado mas não aparece no portal | Veja o log `[enviador]`. Se o portal estava fora, ele reenvia sozinho a cada 30s. |
| Erro 413 / upload falha com vídeos grandes | Suba o portal com `./servir.sh` (não com `php artisan serve`), que libera uploads de até 512 MB. |
| Captura: "Não consegui abrir a porta 8765" | Já tem outra captura rodando (talvez em outro terminal). Feche-a com Ctrl+C. Duas capturas ao mesmo tempo gravariam no mesmo buffer. |
| "aguarde Xs entre cliques" | Anti-repique funcionando. Ajuste `INTERVALO_MINIMO_ENTRE_CLIQUES`. |

---

## 🔌 Referência rápida das APIs

**Serviço de captura** (`http://127.0.0.1:8765`)

| Método | Rota | Descrição |
|---|---|---|
| `POST` | `/disparar` | Salva um replay. Header `Authorization: Bearer <token>`. Responde `202` (aceito) ou `429` (anti-repique). |
| `GET` | `/status` | Gravando? Quantos fragmentos? Últimos disparos. |

Teste manual: `curl -X POST -H "Authorization: Bearer SEU_TOKEN" http://127.0.0.1:8765/disparar`

**Portal Laravel** (`http://127.0.0.1:8000`)

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/` | Página com o botão e os replays |
| `POST` | `/replays/disparar` | Botoeira virtual (usado pela página) |
| `GET` | `/replays/lista` | Replays em JSON |
| `GET` | `/captura/status` | Status do serviço de captura |
| `POST` | `/api/replays` | Recebe o `.mp4` (usado pelo Python). Campos: `video`, `replay_id`, `quadra_id`, `origem`, `clicado_em`. |
