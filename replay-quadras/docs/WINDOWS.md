# 🪟 Guia para Windows (10/11)

Passo a passo completo, do download do repositório até o replay funcionando.
Use o **Prompt de Comando (cmd)** em todos os passos (Menu Iniciar → digite `cmd`).
Evite o PowerShell: ele bloqueia a ativação do ambiente Python por padrão.

> Este guia foi montado a partir do projeto testado no macOS. Os scripts do
> Windows (`.bat`) ainda não foram executados em um PC com Windows — se algo
> falhar, copie a mensagem de erro completa.

---

## Parte 1 — Hardware (câmera)

```
Tomada ──► Fonte 12V (P4) ──► Câmera ──cabo de rede──► Roteador ◄── PC (Windows)
                                                  (câmera em 192.168.1.10)
```

1. Ligue a fonte 12V na tomada e no plugue da câmera.
2. Ligue o cabo de rede da câmera ao **roteador** (não direto no PC).
3. Espere 1 a 2 minutos a câmera iniciar.
4. O PC precisa estar na **mesma rede** do roteador (Wi-Fi ou cabo).
5. No painel da câmera confirme: **RTSP ligado** e **codec H.264** (não H.265).
6. Sugestão: no roteador, reserve o IP `192.168.1.10` para a câmera.

**Teste no VLC** (Mídia → Abrir fluxo de rede) com:

```
rtsp://admin:SUA_SENHA@192.168.1.10:554/cam1/onvif-h264
```

Se abrir a imagem, a câmera, a senha e o caminho estão certos.

---

## Parte 2 — Instalar os programas (uma vez só)

Abra o **cmd** e rode, um por vez. O `winget` já vem no Windows 10/11.

```
winget install Git.Git
winget install Python.Python.3.12
winget install Gyan.FFmpeg
winget install PHP.PHP.8.4
winget install Composer.Composer
```

Depois **feche o cmd e abra outro**, para o Windows reconhecer os programas.
Confira se todos foram instalados:

```
git --version
python --version
ffmpeg -version
php -v
composer --version
```

Se algum `winget` não achar o pacote ou o comando não for reconhecido, instale
manualmente: [git-scm.com](https://git-scm.com), [python.org](https://www.python.org/downloads/)
(marque **"Add Python to PATH"**), [gyan.dev/ffmpeg](https://www.gyan.dev/ffmpeg/builds/)
(adicione a pasta `bin` ao PATH), [windows.php.net](https://windows.php.net/download/)
e [getcomposer.org](https://getcomposer.org/download/).

### Habilitar extensões do PHP (importante)

O Laravel precisa de algumas extensões que vêm desligadas no PHP do Windows.

1. Descubra onde está o `php.ini`: `php --ini`
   (se aparecer `Loaded Configuration File: (none)`, copie o `php.ini-development`
   da pasta do PHP para `php.ini` na mesma pasta).
2. Abra o `php.ini` no Bloco de Notas e **tire o `;` do começo** destas linhas:

```
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
```

3. Salve e confira com `php -m` (devem aparecer na lista).

---

## Parte 3 — Baixar o projeto

Escolha uma pasta simples, **sem espaços e sem acentos** (ex.: `C:\replay`):

```
mkdir C:\replay
cd C:\replay
git clone -b claude/infraestrutura-arquitetura-docs-c04d6a https://github.com/gustavoffernandes/REPLAY.git
cd REPLAY\replay-quadras
```

---

## Parte 4 — Instalar o projeto (uma vez só)

**1. Serviço de captura (Python)**

```
cd captura
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

O prompt passa a mostrar `(.venv)` no começo: o ambiente está ativo.

**2. Portal (Laravel)**

```
cd ..\web
instalar.bat
```

O `instalar.bat` baixa o Laravel (leva alguns minutos), aplica os arquivos do
sistema, gera um **token** e grava o mesmo valor em `web\portal\.env` e
`captura\.env`.

**3. Configurar a câmera**

```
notepad ..\captura\.env
```

Altere estas duas linhas, salve e feche:

```ini
FONTE=rtsp
RTSP_URL=rtsp://admin:SUA_SENHA@192.168.1.10:554/cam1/onvif-h264
```

---

## Parte 5 — Testar a câmera

```
cd ..\captura
.venv\Scripts\activate
python testar_camera.py
```

Resultado esperado:

```
1) Testando conexão com a porta RTSP... OK
2) Lendo 3 segundos de vídeo... OK
✅ Câmera pronta.
```

---

## Parte 6 — Rodar o sistema

Abra **dois cmd** e deixe os dois abertos.

**cmd 1 — portal:**

```
cd C:\replay\REPLAY\replay-quadras\web
servir.bat
```

**cmd 2 — captura:**

```
cd C:\replay\REPLAY\replay-quadras\captura
.venv\Scripts\activate
python main.py
```

Se o **Firewall do Windows** perguntar sobre o Python ou o PHP, clique em
**Permitir acesso** (rede privada).

---

## Parte 7 — Usar

1. Abra **http://127.0.0.1:8000** no navegador.
2. Espere o indicador ficar **verde** ("Gravando — buffer ativo").
3. Espere cerca de **30 segundos** para o buffer encher.
4. Clique em **Salvar vídeo**.
5. Em poucos segundos o replay aparece na lista.

Para parar: **Ctrl+C** em cada cmd.

**Dia a dia** (depois de instalado), só os dois comandos da Parte 6.

---

## Parte 8 — Quando a botoeira chegar

1. Ligue os dois fios do botão (contato NA) num borne de botão da placa Zero
   Delay + GND. Plugue a placa na USB.
2. Confira que o Windows reconheceu: `Win + R` → `joy.cpl` → a placa deve aparecer.
3. Descubra o número do botão:

```
cd C:\replay\REPLAY\replay-quadras\captura
.venv\Scripts\activate
pip install pygame
python testar_botoeira.py
```

4. Aperte o botão e anote o número. No `captura\.env`:

```ini
BOTOEIRA_MODO=joystick
BOTOEIRA_JOYSTICK_BOTAO=<número>
```

5. Reinicie o `python main.py`. Botão e site funcionam juntos.

Se a placa for reconhecida como **teclado**: `pip install pynput`,
`BOTOEIRA_MODO=teclado` e `BOTOEIRA_TECLA=` com a tecla que ela envia.

---

## Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| `'python' não é reconhecido` | Python sem PATH. Reinstale marcando "Add Python to PATH" e abra um novo cmd. |
| `'php' não é reconhecido` | Abra um **novo** cmd depois de instalar, ou adicione a pasta do PHP ao PATH. |
| Erro do Composer sobre extensões (`ext-...`) | Faltou habilitar no `php.ini` (Parte 2). |
| `could not find driver` | Falta `extension=pdo_sqlite` e `extension=sqlite3` no `php.ini`. |
| `.venv\Scripts\activate` não funciona | Use o **cmd**, não o PowerShell. |
| `testar_camera.py` falha na porta 554 | Câmera desligada, IP errado ou PC em outra rede. O VLC abre? |
| Indicador amarelo ("sem vídeo chegando") | Veja o erro abaixo do indicador e rode o `testar_camera.py`. |
| Replay não toca no navegador | Câmera em H.265. Mude para H.264 no painel dela. |
| "Serviço de captura offline" | O `python main.py` (cmd 2) não está rodando. |
| "Não consegui abrir a porta 8765" | Já existe outra captura aberta. Feche com Ctrl+C. |
| "token inválido" | `REPLAY_TOKEN` diferente nos dois `.env`. Rode `instalar.bat` de novo. |

## Segurança

A senha padrão de fábrica de muitas câmeras é simples e conhecida. Troque no painel da câmera
e atualize o `RTSP_URL` no `captura\.env` (esse arquivo não vai para o GitHub).
