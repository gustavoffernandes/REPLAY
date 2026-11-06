# SetPoint

Sistema de gestão e agendamento de quadras de areia (vôlei, futevôlei e beach tennis), com **replays instantâneos** por câmera: ao apertar a botoeira (ou o botão **Salvar replay** na tela), os últimos 30 segundos da quadra viram um vídeo.

Este guia é para **Windows** e segue a ordem em que as coisas devem ser feitas.

**Índice**

1. [Instalação (uma vez só)](#1-instalação-uma-vez-só)
2. [Execução (todo dia)](#2-execução-todo-dia)
3. [Anexos](#anexos): usuários de exemplo, endereços, problemas comuns, botoeira física e mais

---

## 1. Instalação (uma vez só)

Os comandos usam dois tipos de terminal:

- **Terminal do Laragon:** abra o Laragon e clique em **Terminal**. Use para PHP, Composer, Node e MySQL.
- **Prompt de Comando (cmd):** Menu Iniciar, digite `cmd`. Use para o Python. (Não use o PowerShell: ele bloqueia a ativação do ambiente Python.)

### 1.1 Instalar os programas

| Programa | Para quê | Como instalar |
|---|---|---|
| **Laragon (versão Full)** | PHP 8.3+, MySQL, Composer e Node 20+ | Baixe em https://laragon.org e instale |
| **Python 3.12** | Serviço de captura (o FFmpeg já vem junto, pelo pacote `imageio-ffmpeg`) | Baixe em https://www.python.org/downloads/ e **marque "Add Python to PATH"** |
| **Git** | Baixar o projeto | Baixe em https://git-scm.com e instale com as opções padrão |

### 1.2 Liberar vídeos grandes no PHP

O PHP vem limitado a 2 MB por upload, e um replay de 30 segundos tem dezenas de MB. Sem este ajuste, os vídeos são recusados.

1. No Laragon: **Menu → PHP → php.ini**.
2. Altere estas duas linhas:
   ```ini
   upload_max_filesize = 512M
   post_max_size = 520M
   ```
3. Salve o arquivo.

### 1.3 Baixar o projeto

No **cmd** ou no Terminal do Laragon:

```bat
mkdir C:\replay
```

```bat
cd C:\replay
```

```bat
git clone -b atualização https://github.com/gustavoffernandes/REPLAY.git
```

Se o terminal mostrar o nome da branch errado ou der "Remote branch not found", rode `chcp 65001` e tente de novo. Se ainda não funcionar, no site do GitHub escolha a branch **atualização**, clique em **Code → Download ZIP**, extraia em `C:\replay` e renomeie a pasta para `REPLAY`.

O projeto fica em `C:\replay\REPLAY\SetPoint`, com três pastas: `backend`, `web` e `captura`.

### 1.4 Instalar o back-end (pasta `backend`)

Abra o Laragon e clique em **Start All** (o MySQL precisa estar ligado). Depois, no **Terminal do Laragon**:

```bat
cd C:\replay\REPLAY\SetPoint\backend
```

```bat
composer install
```

```bat
copy .env.example .env
```

```bat
php artisan key:generate
```

Crie o banco de dados:

```bat
mysql -uroot -e "CREATE DATABASE setpoint CHARACTER SET utf8mb4"
```

Crie as tabelas e carregue os dados de exemplo:

```bat
php artisan migrate --seed
```

> Se já existia um banco do SetPoint de uma versão anterior, rode só `php artisan migrate` (cria as tabelas de replay sem apagar nada).

### 1.5 Instalar o front-end (pasta `web`)

No **Terminal do Laragon**:

```bat
cd C:\replay\REPLAY\SetPoint\web
```

```bat
npm install
```

### 1.6 Instalar a captura (pasta `captura`)

No **cmd** (não no PowerShell):

```bat
cd C:\replay\REPLAY\SetPoint\captura
```

```bat
python -m venv .venv
```

```bat
.venv\Scripts\activate
```

```bat
pip install -r requirements.txt
```

```bat
copy .env.example .env
```

Abra o arquivo de configuração para colocar a senha da câmera:

```bat
notepad .env
```

O `.env` já vem com `FONTE=rtsp` e o IP da câmera atual. Troque apenas `SUA_SENHA` pela senha da câmera:

```ini
FONTE=rtsp
RTSP_URL=rtsp://admin:SUA_SENHA@192.168.1.10:554/cam1/onvif-h264
REPLAY_TOKEN=cole-aqui-o-token-gerado-no-setpoint
```

Salve e feche. O `REPLAY_TOKEN` você preenche depois, no passo 2.5.

> O `.env` guarda a senha e o token e **não vai para o GitHub**.

A instalação termina aqui. Não precisa repetir.

---

## 2. Execução (todo dia)

Siga **nesta ordem**. Ao todo são 3 terminais abertos ao mesmo tempo, mais o MySQL e a câmera.

### 2.1 Ligar a câmera

1. Fonte 12V na tomada e no plugue da câmera.
2. Cabo de rede da câmera ao roteador, com o computador na **mesma rede**.
3. Espere 1 a 2 minutos a câmera iniciar.
4. Confirme no VLC (Mídia → Abrir fluxo de rede) a URL da câmera. Se a imagem aparecer, está pronta.

### 2.2 Ligar o banco de dados (MySQL)

Abra o Laragon e clique em **Start All**. O MySQL deve ficar com a luz verde.

O back-end **não sobe sem o banco**, então faça este passo antes do próximo.

### 2.3 Ligar o back-end (Terminal 1)

No **Terminal do Laragon**:

```bat
cd C:\replay\REPLAY\SetPoint\backend
```

```bat
php artisan serve
```

Deve aparecer `Server running on [http://127.0.0.1:8000]`. Deixe a janela aberta.

### 2.4 Ligar o front-end (Terminal 2)

Em **outro** Terminal do Laragon:

```bat
cd C:\replay\REPLAY\SetPoint\web
```

```bat
npm run dev
```

Deve aparecer `Local: http://localhost:5173/`. Deixe a janela aberta.

### 2.5 Configurar a câmera no SetPoint (só na primeira vez)

Isso liga a quadra ao serviço de captura. Depois de feito, **não precisa repetir**.

1. Abra **http://localhost:5173** e entre com `owner@arena.test` e a senha `password` (outros usuários nos [Anexos](#usuários-de-exemplo)).
2. Vá em **Quadras** e clique em **Editar** na quadra onde está a câmera.
3. Em **Câmera de replay**, preencha o endereço do serviço de captura:
   ```
   http://127.0.0.1:8765
   ```
4. Clique em **Salvar**.
5. Abra **Editar** de novo, clique em **Gerar token** e depois em **Copiar**. O token aparece **uma única vez**.
6. Cole o token no `C:\replay\REPLAY\SetPoint\captura\.env`, na linha do `REPLAY_TOKEN`, e salve:
   ```ini
   REPLAY_TOKEN=<token copiado>
   ```

### 2.6 Ligar a captura (Terminal 3)

No **cmd**:

```bat
cd C:\replay\REPLAY\SetPoint\captura
```

```bat
.venv\Scripts\activate
```

Teste a câmera (opcional, mas recomendado na primeira vez). Deve terminar com **"Câmera pronta"**:

```bat
python testar_camera.py
```

Ligue a captura:

```bat
python main.py
```

Deve aparecer `Iniciando gravação contínua (câmera RTSP)` e `Gatilho HTTP ouvindo em http://127.0.0.1:8765`. Deixe a janela aberta. Se o Firewall do Windows perguntar sobre o Python, clique em **Permitir acesso**.

### 2.7 Usar

1. Em http://localhost:5173, abra **Replays** no menu.
2. A câmera deve mostrar **Gravando** (verde).
3. **Espere uns 30 segundos** para o buffer encher.
4. Clique em **Salvar replay**. Em poucos segundos o vídeo aparece em **Vídeos**: tocar, baixar ou excluir.

### 2.8 Para parar

1. **Ctrl+C** na captura (Terminal 3), no front-end (Terminal 2) e no back-end (Terminal 1).
2. Por último, no Laragon, clique em **Stop All** (desliga o MySQL).

### Resumo do dia a dia

Depois de tudo instalado e configurado, só isto:

| Ordem | Ação | Onde |
|---|---|---|
| 1 | Ligar a câmera | Hardware |
| 2 | **Start All** | Laragon |
| 3 | `cd C:\replay\REPLAY\SetPoint\backend` e `php artisan serve` | Terminal do Laragon 1 |
| 4 | `cd C:\replay\REPLAY\SetPoint\web` e `npm run dev` | Terminal do Laragon 2 |
| 5 | `cd C:\replay\REPLAY\SetPoint\captura`, `.venv\Scripts\activate` e `python main.py` | cmd 3 |
| 6 | Abrir http://localhost:5173 → **Replays** | Navegador |

### Opcional: agendador (Terminal 4)

Só se você usa reservas online com sinal ou horários fixos. Ele expira reservas não pagas e mantém as reservas recorrentes em dia. No Terminal do Laragon:

```bat
cd C:\replay\REPLAY\SetPoint\backend
```

```bat
php artisan schedule:work
```

---

## Anexos

### Endereços

| O quê | Endereço |
|---|---|
| Tela do SetPoint | http://localhost:5173 |
| API (back-end) | http://localhost:8000 |
| Serviço de captura (interno) | http://127.0.0.1:8765 |
| Câmera | `192.168.1.10`, porta 554 (RTSP) |

### Usuários de exemplo

Criados pelo seeder (`php artisan migrate --seed`). Senha de todos: `password`.

| E-mail | Papel | Escopo |
|---|---|---|
| `admin@setpoint.test` | Administrador | Tudo |
| `owner@arena.test` | Proprietário | Arena Beach Sul (Centro e Praia) |
| `gerente@arena.test` | Gerente | Unidade Centro |
| `atendente@arena.test` | Atendente | Unidade Praia |

Para criar usuários novos: **Administração → Usuários → + Novo usuário**. Troque as senhas e remova os usuários de exemplo antes de usar de verdade.

### Como funciona a configuração da câmera (Quadras → Editar)

| Campo | Para quê | Valor |
|---|---|---|
| **Endereço do serviço de captura** | Onde o SetPoint encontra o programa que grava a câmera | `http://127.0.0.1:8765` |
| **Token da câmera** | O SetPoint e a captura confiarem um no outro, e o SetPoint saber de qual quadra veio cada vídeo | Gerado pelo botão, colado no `REPLAY_TOKEN` |

- A **URL RTSP da câmera** não fica nessa tela. Ela fica só no `captura\.env`.
- O token aparece **uma única vez**. **Gerar novo token** invalida o anterior, e a captura para de enviar até você atualizar o `.env`.
- Em **Replays**, a quadra só aparece quando tem endereço **e** token. No card da quadra em **Quadras** aparece o selo **● Câmera**.
- Uma quadra, uma câmera, uma captura. Quando vierem mais câmeras, cada uma terá a sua cópia da captura, com porta (`GATILHO_PORTA`) e token próprios.

### Testar sem câmera

No `captura\.env`, troque `FONTE=rtsp` por `FONTE=teste`. O sistema gera um vídeo de teste (barras coloridas com contador) e tudo funciona igual.

### Botoeira física (placa USB Zero Delay)

Quando a botoeira chegar, ligue os fios do botão (contato NA) a um borne de botão da placa Zero Delay + GND, e a placa na USB. O clique da botoeira faz exatamente o mesmo que o botão **Salvar replay**.

No **cmd**, com o ambiente ativo (`.venv\Scripts\activate`), dentro de `captura`:

```bat
pip install pygame
```

```bat
python testar_botoeira.py
```

Aperte o botão e anote o número que aparecer. No `captura\.env`:

```ini
BOTOEIRA_MODO=joystick
BOTOEIRA_JOYSTICK_BOTAO=<número anotado>
```

Reinicie o `python main.py`. Para conferir que o Windows reconheceu a placa: `Win + R`, digite `joy.cpl`. Se a placa for reconhecida como teclado, use `pip install pynput`, `BOTOEIRA_MODO=teclado` e `BOTOEIRA_TECLA=` com a tecla que ela envia.

### Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| Back-end dá erro de conexão com o banco | O MySQL não está ligado: clique em **Start All** no Laragon (passo 2.2). |
| Tela abre mas dá erro ao entrar | O back-end (Terminal 1) não está rodando. |
| `.venv\Scripts\activate` não funciona | Use o **cmd**, não o PowerShell. |
| `'python'` não é reconhecido | Reinstale o Python marcando "Add Python to PATH" e abra um novo cmd. |
| Replays: câmera **offline** | O `python main.py` (Terminal 3) não está rodando, ou o endereço da quadra não é `http://127.0.0.1:8765`. |
| Replays: **sem vídeo chegando** | Rode `python testar_camera.py`: senha, IP ou caminho RTSP errados, ou câmera desligada. |
| `testar_camera.py`: porta 554 não responde, mas o VLC abre | O VLC está em **outro computador** ou rede. Rode o sistema no computador que enxerga a câmera. |
| Vídeo salvo não toca no navegador | A câmera está em **H.265**: mude para **H.264** no painel da câmera. |
| Captura: "Token recusado" (401) | Token errado ou gerado de novo. Gere outro em **Quadras → Editar** e atualize o `REPLAY_TOKEN`. |
| Captura: "maior que o limite do PHP" (413) | Faltou o passo 1.2. Ajuste o `php.ini` e reinicie o Terminal 1. O vídeo fica guardado e é reenviado sozinho. |
| Captura: "Não consegui abrir a porta 8765" | Já existe outra captura aberta. Feche com Ctrl+C. |
| "Buffer vazio" ao salvar | Clicou logo após ligar: espere uns 30 segundos. |
| `REPLAY_TOKEN` sumiu depois de recriar o banco | `migrate:fresh` apaga os tokens. Repita o passo 2.5. |
| `git clone` não acha a branch `atualização` | Rode `chcp 65001` ou baixe o ZIP (passo 1.3). |

### Estrutura do projeto

```
REPLAY/
├── README.md              Este guia
└── SetPoint/
    ├── backend/           API Laravel (PHP) e banco de dados
    ├── web/               Tela do sistema (Vue)
    ├── captura/           Serviço Python que grava a câmera e salva os replays
    ├── docs/REPLAYS.md    Arquitetura dos replays
    └── COMO_RODAR_WINDOWS.txt
```

### Segurança

- Troque a senha padrão da câmera no painel dela e atualize a `RTSP_URL` no `captura\.env`.
- Os arquivos `.env` guardam senhas e tokens: não compartilhe e não envie ao GitHub.
- Troque as senhas dos usuários de exemplo antes de usar de verdade.

### Mais documentação

- [SetPoint/README.md](SetPoint/README.md): funcionalidades, papéis e permissões, segurança, agendamento online e API.
- [SetPoint/docs/REPLAYS.md](SetPoint/docs/REPLAYS.md): hardware, fluxo do vídeo, Switch PoE e mapa do código.
- [SetPoint/COMO_RODAR_WINDOWS.txt](SetPoint/COMO_RODAR_WINDOWS.txt): versão em texto simples deste guia.
