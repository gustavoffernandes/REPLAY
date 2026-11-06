# SetPoint

Sistema de gestão e agendamento de quadras de areia (vôlei, futevôlei e beach tennis).

> **Para rodar o sistema por inteiro** (câmera, banco, back-end, front-end e captura, na ordem certa), veja o [README principal](../README.md).

Por enquanto inclui:
- **Dashboard** com ocupação, reservas e receita do dia/mês, receita dos últimos 14 dias, receita por esporte, horários de pico e próximas reservas.
- **Agenda** em grade (horas × quadras) com reserva por clique e bloqueio de conflito de horário.
- **Detalhes da reserva**: clicar num horário ocupado da agenda abre os dados e permite confirmar, concluir ou cancelar.
- **Reservas**: listagem com filtros e ações de confirmar, concluir e cancelar.
- **Clientes**: cadastro com busca; **clientes fixos** (mensalistas) têm horários semanais recorrentes que geram reservas automáticas.
- **Turmas**: turmas por esporte, com professor, vagas, mensalidade, alunos matriculados e horários semanais numa quadra.
- **Quadras**: cadastro, edição, preço por hora e horário de funcionamento. A quadra é de areia e o esporte é escolhido em cada reserva.
- **Replays**: cada quadra pode ter uma câmera IP; a botoeira física (ou o botão "Salvar replay" do painel) salva os **últimos 30 segundos** em vídeo, que aparece na tela Replays para tocar, baixar ou excluir.
- **Multiempresa**: empresas contratantes com filiais; cada usuário só enxerga e altera o que é da sua empresa/filiais.
- **Acesso e administração**: login, papéis e permissões, gestão de empresas, filiais e usuários.

## Stack

| Camada | Tecnologia |
|---|---|
| Back-end | PHP 8.3+ · Laravel 13 (API JSON) |
| Banco | MySQL 8 |
| Front-end | Vue 3 · Vue Router · Vite |

## Estrutura

```
SetPoint/
├── backend/   API Laravel (rotas em routes/api.php, migrations e seeder em database/)
├── web/       Front-end Vue + Vite
├── captura/   Serviço Python de captura de replays (uma cópia por câmera)
└── docs/      Documentação (replays: hardware, fluxo e mapa do código)
```

**Guias:** [COMO_RODAR_WINDOWS.txt](COMO_RODAR_WINDOWS.txt) (passo a passo completo no Windows, da câmera ao replay) e [docs/REPLAYS.md](docs/REPLAYS.md) (arquitetura dos replays).

## Pré-requisitos

PHP 8.3+, Composer, Node 20+ e MySQL 8 (no Laragon basta deixar o MySQL iniciado).

Para os **replays**, também: Python 3.10+ (o FFmpeg vem junto, pelo pacote `imageio-ffmpeg`) e uma câmera IP com RTSP. Dá para testar tudo **sem câmera** com `FONTE=teste`.

## Como rodar manualmente

### 1. Back-end
```bash
cd backend
composer install
cp .env.example .env          # no Windows (cmd): copy .env.example .env
php artisan key:generate
```
Crie o banco vazio `setpoint` (HeidiSQL do Laragon, ou `mysql -uroot -e "CREATE DATABASE setpoint CHARACTER SET utf8mb4"`).
Confira `DB_*` no `.env` (padrão: `root` sem senha, `127.0.0.1:3306`) e rode:
```bash
php artisan migrate --seed    # cria as tabelas e carrega dados de exemplo
php artisan serve             # API em http://localhost:8000
```
Para recriar o banco do zero: `php artisan migrate:fresh --seed`. Se você já tinha um banco de uma versão anterior (sem empresas/filiais), use este comando: as migrations de multiempresa exigem o banco vazio. Se o seu banco já tem empresas/filiais e só falta o módulo de replays, rode apenas `php artisan migrate` (não apaga nada).

### 2. Front-end (em outro terminal)
```bash
cd web
npm install
npm run dev                   # http://localhost:5173
```
O Vite repassa `/api` para `http://localhost:8000`, então o back-end precisa estar rodando.

Acesse **http://localhost:5173**.

### 3. Captura de replays (opcional, em outro terminal)

Só é necessário para usar câmeras. Veja [Replays](#replays-câmera-por-quadra) para instalar e ligar a câmera; no dia a dia basta, dentro de `captura/` com o ambiente Python ativo:
```bash
python main.py
```

## Agendamento online (clientes avulsos)

Página pública, sem login, no endereço **`/reservar/{empresa}/{filial}`** (ex.: `/reservar/arena-beach-sul/unidade-centro`). O link de cada filial aparece em **Administração → Filiais → Copiar link**. Se a empresa tem uma única filial, `/reservar/{empresa}` já basta.

Fluxo do cliente: escolhe esporte, data, quadra e horário (1 a 3 h) → informa **nome e telefone** (obrigatórios) e **e-mail** (opcional) → paga o **sinal** (padrão **50%**) por Pix → a reserva é confirmada automaticamente. O restante é pago no balcão.

- O horário fica **retido por 15 minutos** (`PAYMENT_HOLD_MINUTES`) aguardando o sinal. Se não for pago, a reserva expira e o horário volta a ficar livre. A expiração é feita na hora em que alguém consulta a agenda e também pelo comando `bookings:expire` (agendado a cada minuto, requer `php artisan schedule:work`).
- O percentual do sinal é configurável por empresa (Administração → Empresas; `0` confirma sem pagamento).
- No painel, a reserva online aparece como **Aguardando sinal** (tracejada na agenda) e depois **Confirmada**, com valor do sinal e restante a cobrar. O atendente pode confirmar manualmente (sinal recebido por outro meio) ou cancelar.
- **Balcão:** a agenda interna continua criando reservas confirmadas na hora, sem sinal.
- Proteções: a página pública não expõe dados de clientes (só livre/ocupado), há limite de requisições por IP, no máximo 3 reservas aguardando pagamento por telefone e o cadastro público nunca sobrescreve dados de um cliente já existente.

> **Pagamento:** por padrão o Pix é **simulado** (`PAYMENT_DRIVER=fake`): a tela mostra o QR e um botão "Simular pagamento" só fora de produção. Para cobrar de verdade é preciso integrar um gateway: implemente `App\Services\Payments\PaymentGateway` (criar a cobrança Pix) e registre em `config/payments.php`. Também será necessário um webhook que chame `PaymentService::markPaid()` quando o pagamento for compensado.

## Replays (câmera por quadra)

Cada quadra pode ter uma câmera IP. Um serviço local em Python (`captura/`) grava a câmera o tempo todo num buffer circular e, ao clique da botoeira, junta os **últimos 30 segundos** num `.mp4` e envia para o SetPoint.

```
Câmera ──RTSP──► captura (Python) ──► buffer de ~30 s em fragmentos
                     ▲                   │ clique: FFmpeg junta os fragmentos (sem reprocessar)
  botoeira física ───┤                   ▼
  "Salvar replay" ───┘ (SetPoint → POST /disparar)   POST /api/replays/upload ──► tela Replays
```

Hardware, fluxo detalhado, segurança e mapa do código: [docs/REPLAYS.md](docs/REPLAYS.md).

### Como colocar uma câmera para funcionar

1. **Ligar a câmera:** fonte 12V + cabo de rede até o roteador, na mesma rede do computador. No painel da câmera: RTSP ligado e codec **H.264** (H.265 não toca no navegador). Teste a URL no VLC.
2. **Instalar o serviço de captura** (uma vez):
   ```bash
   cd captura
   python3 -m venv .venv && source .venv/bin/activate     # Windows (cmd): .venv\Scripts\activate
   pip install -r requirements.txt
   cp .env.example .env                                   # Windows (cmd): copy .env.example .env
   ```
3. **No SetPoint:** *Quadras → Editar → Câmera de replay*: informe o endereço do serviço (`http://127.0.0.1:8765`), salve, e clique em **Gerar token** (aparece uma única vez).
4. **No `captura/.env`** (o `.env.example` já vem com `FONTE=rtsp` e o IP da câmera atual, `192.168.1.10`): troque `SUA_SENHA` pela senha da câmera e cole o token:
   ```ini
   FONTE=rtsp
   RTSP_URL=rtsp://admin:SUA_SENHA@192.168.1.10:554/cam1/onvif-h264   # o caminho varia por câmera
   REPLAY_TOKEN=<token gerado no passo 3>
   ```
5. **Testar a câmera e rodar:**
   ```bash
   python testar_camera.py     # confere rede, porta, senha, caminho e codec
   python main.py              # deixe rodando
   ```
6. Em **Replays**, a câmera mostra "Gravando". Espere ~30 s e clique em **Salvar replay**.

### Botoeira física (placa USB Zero Delay)

O botão cogumelo (contato NA) vai ligado à placa, que vai na USB do computador. É o mesmo caminho do botão do painel; só muda a origem do clique.

```bash
pip install pygame && python testar_botoeira.py    # mostra o número do botão
```

No `captura/.env`: `BOTOEIRA_MODO=joystick` e `BOTOEIRA_JOYSTICK_BOTAO=<número>`. Placa em modo teclado: `pip install pynput`, `BOTOEIRA_MODO=teclado` e `BOTOEIRA_TECLA=`. Sem placa, `BOTOEIRA_MODO=terminal` simula a botoeira com ENTER.

### Detalhes

- **Botoeira virtual:** o back-end repassa o clique do painel ao serviço de captura da quadra.
- **Token da câmera:** identifica a quadra (e portanto a filial/empresa) dos vídeos recebidos e autoriza o SetPoint a disparar a câmera. No banco fica criptografado e em hash, e nunca sai na API.
- **Várias câmeras:** uma cópia do serviço de captura por câmera, cada uma com seu `.env`, porta (`GATILHO_PORTA`) e token.
- **Vídeos:** ficam em `backend/storage/app/private/replays/{filial}/{quadra}/` (fora da pasta pública). A tela toca e baixa por **links assinados e temporários**, porque o player do navegador não envia o token de login.
- **Permissões:** `replays.view` (ver e baixar), `replays.create` (botoeira virtual) e `replays.delete`. Proprietário e Gerente têm as três; Atendente, ver e salvar. Configurar a câmera exige `courts.update`.
- **Tamanho do upload:** um replay de 30 s em Full HD tem dezenas de MB, e o PHP vem limitado a 2 MB. No `php.ini`, defina `upload_max_filesize = 512M` e `post_max_size = 520M` e reinicie o `php artisan serve`. Sem isso, a API responde `413` com a explicação e o serviço de captura mostra a dica no log (o vídeo fica guardado e é reenviado sozinho).
- **Se o SetPoint estiver fora do ar**, a captura guarda o vídeo e tenta de novo a cada 30 s.

### Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| Câmera "offline" na tela | O `python main.py` não está rodando, ou o endereço da quadra não bate com `GATILHO_PORTA`. |
| "Sem vídeo chegando" | Rode `python testar_camera.py`: IP, senha ou caminho RTSP errados. |
| `No route to host` (macOS) | Falta a permissão de **Rede Local** para o app do terminal (Ajustes do Sistema → Privacidade e Segurança → Rede Local). |
| Vídeo não toca no navegador | A câmera está em H.265: mude para H.264. |
| Log: "Token recusado" (401) | Gere o token de novo em Quadras e atualize `REPLAY_TOKEN`. |
| Log: "maior que o limite do PHP" (413) | Ajuste o `php.ini` (veja acima). |
| "Não consegui abrir a porta 8765" | Já existe outra captura aberta: feche com Ctrl+C. |
| "Buffer vazio" | Clicou logo após ligar: espere uns 30 s. |

> Troque a senha padrão da câmera no painel dela e atualize a `RTSP_URL`. O `captura/.env` guarda senha e token e não vai para o Git.

## Acesso, papéis e empresas

Hierarquia: **Empresa → Filiais → Quadras**. Clientes pertencem à empresa (compartilhados entre as filiais dela); quadras, turmas e reservas pertencem a uma filial.

| Papel | Alcance | Pode |
|---|---|---|
| **Administrador** (`super_admin`) | Todas as empresas e filiais | Tudo, inclusive criar empresas e proprietários |
| **Proprietário** (`owner`) | Todas as filiais da própria empresa | Operação completa + filiais e usuários (gerente/atendente) da empresa |
| **Gerente** (`manager`) | Filiais atribuídas | Agenda, reservas, clientes, turmas e quadras |
| **Atendente** (`staff`) | Filiais atribuídas | Agenda, reservas e clientes; apenas visualiza turmas e quadras |

### Papéis e permissões (tela do administrador)

Em **Administração → Papéis** o administrador edita o que cada papel pode fazer, num quadro de permissões **por ação**: ver, criar, editar, confirmar, cancelar etc. em cada área (agenda/reservas, clientes, turmas, quadras, filiais, usuários). Também pode criar **papéis personalizados** (ex.: "Recepção") com alcance *todas as filiais da empresa* ou *somente filiais atribuídas*.

- As mudanças valem **imediatamente** para todos os usuários do papel (sem novo login). O front esconde botões sem permissão e a API recusa a ação com 403.
- Marcar uma ação inclui automaticamente o "ver" da área.
- O papel **Administrador** é fixo (acesso total). Os grupos *Empresas* e *Papéis* são exclusivos dele e não podem ser concedidos a outros papéis.
- Papéis do sistema não podem ser excluídos nem renomeados; papéis personalizados só são excluídos se nenhum usuário os usar.
- O proprietário só consegue atribuir papéis com alcance *somente filiais atribuídas*.
- O catálogo de permissões fica no código ([Permissions.php](backend/app/Support/Permissions.php)), pois cada permissão protege uma rota real; os padrões dos papéis do sistema nascem na migration e depois são editados na tela.

**Como o isolamento funciona:** a autenticação usa tokens Sanctum (validade de 7 dias). A cada requisição, o middleware `ResolveTenant` monta o escopo do usuário (`App\Support\TenantContext`) e os models de domínio aplicam *global scopes* por empresa/filial. Assim, nenhuma query escapa do escopo, e registros de outra empresa retornam **404**. O front envia o header `X-Branch-Id` com a filial escolhida no seletor da lateral (o back valida o acesso; sem o header, vale "todas as filiais" que o usuário acessa). Empresas, filiais ou usuários inativos perdem o acesso imediatamente. O login tem limite de 10 tentativas por minuto.

### Usuários de exemplo (senha de todos: `password`)

| E-mail | Papel | Escopo |
|---|---|---|
| `admin@setpoint.test` | Administrador | Tudo |
| `owner@arena.test` | Proprietário | Arena Beach Sul (Centro e Praia) |
| `gerente@arena.test` | Gerente | Unidade Centro |
| `atendente@arena.test` | Atendente | Unidade Praia |
| `owner@areianorte.test` | Proprietário | Areia Norte Esportes (Matriz) |

> Troque as senhas e remova esses usuários antes de usar em produção.

## Segurança

- **Senha forte** (criação e troca): 10 a 64 caracteres, com minúscula, maiúscula, número e símbolo; sem palavras comuns (`password`, `senha`, `admin`...), sem repetir o mesmo caractere 4 vezes e sem conter o e-mail. O formulário mostra o medidor e os requisitos em tempo real, e o back-end valida de novo (`App\Rules\StrongPassword`). Opcional: `PASSWORD_CHECK_BREACHED=true` recusa senhas que apareceram em vazamentos (consulta k-anonimato ao Have I Been Pwned; falha aberta se estiver offline).
- **Bloqueio por tentativas:** 5 logins errados para o mesmo e-mail e IP bloqueiam por 15 minutos (mesmo com a senha certa), com aviso e `Retry-After`. A mensagem de erro é a mesma exista o e-mail ou não.
- **Limites de requisição** (por minuto): login 20/IP · recuperar senha 5/IP e 5/hora por e-mail · redefinir senha 10/IP · trocar senha 5/usuário · API autenticada 300 leituras e 90 escritas por usuário · agendamento online 120 consultas e 10 reservas por IP. Ao estourar, a API responde `429` e a tela mostra um aviso.
- **Recuperação de senha:** "Esqueci minha senha" envia um link (válido por 60 minutos, uso único). A resposta é igual para e-mails existentes e inexistentes, e usuários ou empresas inativos não recebem o link. Em desenvolvimento o e-mail vai para `backend/storage/logs/laravel.log` (`MAIL_MAILER=log`); em produção configure um SMTP no `.env`.
- **Trocar a senha** (Minha conta, clicando no seu nome na lateral) ou **redefinir** encerra os acessos abertos em outros dispositivos.
- **Cabeçalhos e CORS:** a API envia `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, CSP restritiva, `Cache-Control: no-store` e HSTS (em HTTPS). O CORS só aceita a origem do front (`FRONTEND_URL` / `CORS_ALLOWED_ORIGINS`).
- **Evita duplo clique:** toda criação, edição ou exclusão exibe um modal de carregamento que bloqueia a tela até a resposta chegar, e o front não reenvia uma requisição idêntica que já esteja em andamento. Consultas mostram só uma barra fina no topo.

> Antes de publicar: defina `APP_DEBUG=false`, `APP_ENV=production`, um `FRONTEND_URL`/`CORS_ALLOWED_ORIGINS` reais, um SMTP, e troque as senhas de exemplo (as do seed e a do seu admin são fracas de propósito para desenvolvimento e **não** passariam na política; use "Minha conta" para trocá-las).

## API

Todas as rotas, exceto `POST /api/auth/login`, as públicas e as de vídeo dos replays, exigem `Authorization: Bearer <token>`.

| Método | Rota | Descrição |
|---|---|---|
| GET, POST | `/api/public/...` | Agendamento online sem login: `companies/{slug}`, `branches/{id}/availability`, `branches/{id}/bookings`, `bookings/{codigo}` |
| POST | `/api/auth/forgot-password`, `/api/auth/reset-password` | Recuperar e redefinir senha (sem login) |
| POST | `/api/auth/password` | Trocar a própria senha (exige a senha atual) |
| POST | `/api/auth/login` | Login (retorna token, permissões e filiais acessíveis) |
| GET, POST | `/api/auth/me`, `/api/auth/logout` | Sessão atual / encerrar sessão |
| GET, POST, PUT | `/api/companies` | Empresas (somente administrador) |
| GET, POST, PUT | `/api/branches` | Filiais (`branches.view`/`create`/`update`) |
| GET, POST, PUT | `/api/users` | Usuários (`users.view`/`create`/`update`) |
| GET, POST, PUT, DELETE | `/api/roles` | Papéis e permissões (somente administrador); `GET /api/roles/assignable` lista os papéis atribuíveis |
| GET | `/api/dashboard` | KPIs, série diária, por esporte, horários de pico e próximas reservas |
| GET | `/api/availability?date=` | Grade horária por quadra |
| GET | `/api/bookings` | Lista reservas (filtros `date`, `from`, `to`, `status`) |
| POST | `/api/bookings` | Cria reserva (valida conflito, horário e funcionamento) |
| PATCH | `/api/bookings/{id}/status` | Confirmar, concluir ou cancelar |
| GET, POST, PUT | `/api/courts` | Listar, criar e editar quadras |
| GET | `/api/bookings/{id}` | Detalhes de uma reserva |
| GET, POST, PUT | `/api/customers` | Clientes (filtros `q`, `fixed`); detalhe em `/api/customers/{id}` |
| POST | `/api/customers/{id}/schedules` | Horário fixo semanal de um cliente fixo |
| GET, POST, PUT | `/api/classes` | Turmas; detalhe em `/api/classes/{id}` |
| POST, DELETE | `/api/classes/{id}/students` | Matricular / remover aluno |
| POST | `/api/classes/{id}/schedules` | Horário semanal da turma |
| DELETE | `/api/schedules/{id}` | Remove o horário e cancela as ocorrências futuras |
| GET | `/api/replays` | Replays (filtros `court_id`, `date`), com links assinados do vídeo |
| GET | `/api/replay-cameras` | Quadras com câmera configurada |
| GET | `/api/courts/{id}/replay-status` | Câmera online? Recebendo vídeo? |
| POST | `/api/courts/{id}/replays` | Botoeira virtual: salva os últimos 30 s |
| POST | `/api/courts/{id}/replay-token` | Gera um novo token da câmera (mostrado uma vez) |
| DELETE | `/api/replays/{id}` | Exclui o replay e o arquivo |
| POST | `/api/replays/upload` | Recebe o `.mp4` do serviço de captura (token da câmera; sem login) |
| GET | `/api/replays/{id}/video` | Toca/baixa o vídeo (link assinado; sem login) |

## Horários recorrentes (turmas e clientes fixos)

Cada horário semanal vira reservas reais nas próximas **12 semanas**; datas que conflitam com reservas existentes são puladas e informadas. Para manter o horizonte sempre completo existe o comando diário `schedules:extend`:
```bash
php artisan schedule:work       # desenvolvimento: deixa o agendador rodando
php artisan schedules:extend    # ou rode manualmente quando quiser
```
Em produção, use o cron `* * * * * php artisan schedule:run`.

## Build de produção do front
```bash
cd web && npm run build       # gera web/dist
```
