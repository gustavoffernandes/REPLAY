# SetPoint

Sistema de gestão e agendamento de quadras de areia (vôlei, futevôlei e beach tennis), com **replays instantâneos** por câmera: ao apertar a botoeira (ou o botão **Salvar replay** na tela), os últimos 30 segundos da quadra viram um vídeo.

Todo o código está na pasta [`SetPoint/`](SetPoint/). Este README mostra **como executar o sistema por inteiro**. Para detalhes de cada parte, veja [SetPoint/README.md](SetPoint/README.md) (funcionalidades, papéis, API) e [SetPoint/docs/REPLAYS.md](SetPoint/docs/REPLAYS.md) (arquitetura dos replays).

> Este guia assume que **tudo já está instalado e configurado** (PHP, Composer, Node, MySQL, Python, banco `setpoint` criado, dependências instaladas). Ele vai direto para a execução. Se for a primeira vez numa máquina nova, veja [Primeira instalação](#primeira-instalação-só-em-máquina-nova) no fim.

---

## O que precisa estar rodando

São **4 coisas**, nesta ordem. Cada uma fica numa janela de terminal aberta:

| # | O quê | Onde roda | Endereço |
|---|---|---|---|
| 1 | **Câmera** ligada na rede | Hardware | `192.168.1.10` |
| 2 | **Banco de dados MySQL** | Serviço do sistema | porta `3306` |
| 3 | **Back-end** (API Laravel) | Terminal 1 | http://localhost:8000 |
| 4 | **Front-end** (tela Vue) | Terminal 2 | http://localhost:5173 |
| 5 | **Captura** (grava a câmera e salva os replays) | Terminal 3 | `127.0.0.1:8765` (interno) |

Os comandos abaixo partem da pasta raiz do projeto (`REPLAY/`).

---

## Passo a passo de execução

### Passo 1: ligar a câmera

1. Fonte 12V na tomada e no plugue da câmera.
2. Cabo de rede da câmera ao roteador, com o computador na **mesma rede** (Wi-Fi ou cabo).
3. Espere 1 a 2 minutos a câmera iniciar.
4. Confirme abrindo no VLC (Mídia → Abrir fluxo de rede) a URL da câmera:
   `rtsp://admin:SENHA@192.168.1.10:554/cam1/onvif-h264`

Se a imagem aparecer, a câmera está pronta.

### Passo 2: ligar o banco de dados (MySQL)

O back-end **não sobe** sem o banco. Ligue-o **antes** do Passo 3.

| Sistema | Como ligar o MySQL |
|---|---|
| **Windows (Laragon)** | Abra o Laragon e clique em **Start All**. O MySQL deve ficar com a luz verde. |
| **macOS (Homebrew)** | `brew services start mysql` |

Para conferir que está no ar (opcional):

```bash
mysql -uroot -e "select 1"
```

### Passo 3: ligar o back-end (Terminal 1)

```bash
cd SetPoint/backend
php artisan serve
```

Deve aparecer `Server running on [http://127.0.0.1:8000]`. Deixe a janela aberta.

### Passo 4: ligar o front-end (Terminal 2)

```bash
cd SetPoint/web
npm run dev
```

Deve aparecer `Local: http://localhost:5173/`. Deixe a janela aberta.

### Passo 5: configurar a câmera no SetPoint (só na primeira vez)

Isso liga a quadra ao serviço de captura. Depois de feito, **não precisa repetir**.

1. Abra **http://localhost:5173** e entre com um usuário **Proprietário** ou **Administrador** (veja [Usuários de exemplo](#usuários-de-exemplo)).
2. Vá em **Quadras → Editar** (na quadra onde está a câmera).
3. Em **Câmera de replay**, preencha o endereço do serviço de captura: `http://127.0.0.1:8765` e clique em **Salvar**.
4. Abra **Editar** de novo e clique em **Gerar token** e depois em **Copiar**. O token aparece **uma única vez**.
5. Abra o arquivo `SetPoint/captura/.env` e cole o token na linha:
   ```ini
   REPLAY_TOKEN=cole-aqui-o-token-gerado-no-setpoint
   ```
   Salve o arquivo.

O restante do `captura/.env` já vem preenchido para a câmera atual:

```ini
FONTE=rtsp
RTSP_URL=rtsp://admin:SENHA@192.168.1.10:554/cam1/onvif-h264
```

> O `.env` guarda a senha e o token e **não vai para o GitHub**. O `.env.example` (que vai) traz o mesmo IP e caminho, com `SUA_SENHA` no lugar da senha.

### Passo 6: ligar a captura (Terminal 3)

Antes, o ambiente Python precisa estar ativo.

**Windows (cmd):**
```bat
cd SetPoint\captura
.venv\Scripts\activate
python testar_camera.py
python main.py
```

**macOS:**
```bash
cd SetPoint/captura
source .venv/bin/activate
python testar_camera.py
python main.py
```

- O `testar_camera.py` confere rede, porta, senha, caminho e codec. Deve terminar com **"Câmera pronta"**. Depois de testar uma vez, pode pular.
- O `main.py` deve mostrar `Iniciando gravação contínua (câmera RTSP)` e `Gatilho HTTP ouvindo em http://127.0.0.1:8765`. Deixe a janela aberta.

### Passo 7: usar

1. Em http://localhost:5173, abra **Replays** no menu.
2. A câmera deve mostrar **Gravando** (verde).
3. **Espere uns 30 segundos** para o buffer encher.
4. Clique em **Salvar replay**. Em poucos segundos o vídeo aparece em **Vídeos**: tocar, baixar ou excluir.

### Opcional: agendador (Terminal 4)

Só se você usa reservas online com sinal ou horários fixos. Ele expira reservas não pagas e mantém as reservas recorrentes em dia:

```bash
cd SetPoint/backend
php artisan schedule:work
```

---

## Para parar

1. **Ctrl+C** na captura (Terminal 3), no front-end (Terminal 2) e no back-end (Terminal 1).
2. Por último, desligue o MySQL: **Stop All** no Laragon (Windows) ou `brew services stop mysql` (macOS).

---

## Resumo do dia a dia

Com a câmera ligada:

| Ordem | Ação | Comando |
|---|---|---|
| 1 | Ligar o MySQL | Laragon **Start All** (Windows) · `brew services start mysql` (macOS) |
| 2 | Terminal 1 | `cd SetPoint/backend && php artisan serve` |
| 3 | Terminal 2 | `cd SetPoint/web && npm run dev` |
| 4 | Terminal 3 | `cd SetPoint/captura`, ativar o `.venv`, `python main.py` |
| 5 | Navegador | http://localhost:5173 → **Replays** |

---

## Usuários de exemplo

Criados pelo seeder (`php artisan migrate --seed`). Senha de todos: `password`.

| E-mail | Papel | Escopo |
|---|---|---|
| `admin@setpoint.test` | Administrador | Tudo |
| `owner@arena.test` | Proprietário | Arena Beach Sul (Centro e Praia) |
| `gerente@arena.test` | Gerente | Unidade Centro |
| `atendente@arena.test` | Atendente | Unidade Praia |

> Troque as senhas e remova esses usuários antes de usar de verdade. Para criar usuários novos: **Administração → Usuários → + Novo usuário**.

---

## Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| Back-end dá erro de conexão com o banco | O MySQL não está ligado: faça o **Passo 2** primeiro. |
| Tela abre mas dá erro ao entrar | O back-end (Terminal 1) não está rodando. |
| Replays: câmera **offline** | O `python main.py` (Terminal 3) não está rodando, ou o endereço da quadra não é `http://127.0.0.1:8765`. |
| Replays: **sem vídeo chegando** | Rode `python testar_camera.py`: senha, IP ou caminho RTSP errados, ou câmera desligada. |
| `testar_camera.py`: `No route to host` (macOS) | Falta a permissão de **Rede Local** para o terminal: Ajustes do Sistema → Privacidade e Segurança → Rede Local. |
| `testar_camera.py`: porta 554 não responde, mas o VLC abre | O VLC está em **outro computador** ou rede. Rode o sistema no computador que enxerga a câmera. |
| Vídeo salvo não toca no navegador | A câmera está em **H.265**: mude para **H.264** no painel da câmera. |
| Captura: "Token recusado" (401) | Token errado ou gerado de novo. Gere outro em **Quadras → Editar** e atualize `REPLAY_TOKEN`. |
| Captura: "maior que o limite do PHP" (413) | Aumente `upload_max_filesize = 512M` e `post_max_size = 520M` no `php.ini` e reinicie o Terminal 1. O vídeo fica guardado e é reenviado sozinho. |
| Captura: "Não consegui abrir a porta 8765" | Já existe outra captura aberta. Feche com Ctrl+C. |
| "Buffer vazio" ao salvar | Clicou logo após ligar: espere uns 30 segundos. |
| `REPLAY_TOKEN` sumiu depois de recriar o banco | `migrate:fresh` apaga os tokens. Repita o **Passo 5**. |
| `.venv\Scripts\activate` não funciona (Windows) | Use o **Prompt de Comando (cmd)**, não o PowerShell. |

---

## Primeira instalação (só em máquina nova)

Só precisa disso uma vez por computador.

**1. Programas:** PHP 8.3+, Composer, Node 20+, MySQL 8 (no Windows, o [Laragon](https://laragon.org) traz tudo isso) e Python 3.10+ (o FFmpeg vem junto, pelo pacote `imageio-ffmpeg`).

**2. Baixar o projeto:**
```bash
git clone -b atualização https://github.com/gustavoffernandes/REPLAY.git
cd REPLAY
```

**3. Back-end:** com o MySQL ligado (Passo 2):
```bash
cd SetPoint/backend
composer install
cp .env.example .env            # Windows (cmd): copy .env.example .env
php artisan key:generate
mysql -uroot -e "CREATE DATABASE setpoint CHARACTER SET utf8mb4"
php artisan migrate --seed
```
Se já existia um banco do SetPoint, rode só `php artisan migrate` (não apaga nada).

**4. Front-end:**
```bash
cd SetPoint/web
npm install
```

**5. Captura:**
```bash
cd SetPoint/captura
python3 -m venv .venv           # Windows (cmd): python -m venv .venv
source .venv/bin/activate       # Windows (cmd): .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env            # Windows (cmd): copy .env.example .env
```
No `.env` recém-criado, troque `SUA_SENHA` pela senha da câmera na `RTSP_URL` e siga o **Passo 5** para o token.

**6. Limite de upload do PHP (obrigatório para replays):** no `php.ini` (Laragon: Menu → PHP → php.ini):
```ini
upload_max_filesize = 512M
post_max_size = 520M
```
Reinicie o back-end depois.

Para **testar sem câmera**, use `FONTE=teste` no `captura/.env`: o sistema gera um vídeo de teste e tudo funciona igual.

---

## Mais documentação

- [SetPoint/README.md](SetPoint/README.md): funcionalidades, papéis e permissões, segurança, agendamento online, API.
- [SetPoint/docs/REPLAYS.md](SetPoint/docs/REPLAYS.md): hardware, fluxo do vídeo, botoeira física (Zero Delay), Switch PoE e mapa do código.
- [SetPoint/COMO_RODAR_WINDOWS.txt](SetPoint/COMO_RODAR_WINDOWS.txt): guia detalhado de instalação no Windows, do zero.
