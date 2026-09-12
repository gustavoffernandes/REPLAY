import os
import time
import asyncio
import threading
import tempfile
import traceback
from pathlib import Path
from datetime import datetime

import cv2
import numpy as np
import pandas as pd
import streamlit as st
from ultralytics import YOLO
from telegram import Bot

# ---------------------------------------------------------------------------
# Etapa 1 — Esqueleto do dashboard com vídeo ao vivo
# Etapa 2 — Detecção do animal integrada (YOLO pré-treinado, sem treinar nada)
# Etapa 3 — Rastreamento de pose integrado (SuperAnimal-Quadruped)
# ---------------------------------------------------------------------------
# IMPORTANTE sobre esta etapa: a função oficial do DeepLabCut para o
# SuperAnimal (`video_inference_superanimal`) processa ARQUIVOS de vídeo, não
# frames isolados ao vivo. Por isso, em vez de calcular a pose em todo frame,
# o app pega o frame mais recente (o frame INTEIRO da câmera — só reduzido de
# tamanho se estiver muito grande, ver LADO_MAXIMO_ANALISE), grava um vídeo
# curtinho só com ele e manda pro SuperAnimal periodicamente. O esqueleto na
# tela fica "quase ao vivo" — atualiza a cada poucos segundos, não a cada
# frame — mas é o caminho mais fiel ao que existe oficialmente hoje, e
# processando só 1 frame por ciclo (em vez de vários segundos de buffer)
# para não desperdiçar tempo de processamento.
#
# O YOLO continua rodando em todo frame e decide SE existe animal na cena
# (mostrando a caixinha verde) — mas o recorte que ele encontra NÃO é usado
# pra cortar a imagem antes de mandar pro SuperAnimal, porque o SuperAnimal
# tem seu próprio detector por baixo dos panos e precisa de uma cena
# "inteira" pra conseguir localizar o animal sozinho.
#
# Essa etapa é a mais sujeita a dar erro de ambiente/instalação (é uma parte
# do DeepLabCut ainda com bugs conhecidos e relatados pela própria comunidade).
# Por isso ela está isolada dentro de um try/except: se falhar, o app não
# quebra — você continua vendo a webcam e a detecção do YOLO normalmente,
# só sem o esqueleto naquele ciclo, com o erro exato aparecendo na tela.
# ---------------------------------------------------------------------------

import deeplabcut  # noqa: E402  (import pesado, deixado depois dos outros de propósito)

# --- Configuração da etapa de pose -----------------------------------------
INTERVALO_ANALISE_SEGUNDOS = 6    # a cada quantos segundos tenta rodar a pose de novo
LADO_MAXIMO_ANALISE = 640         # maior dimensão (em pixels) do frame mandado pro modelo;
                                  # frames maiores são reduzidos (mantendo a proporção) só pra
                                  # não ficar lento — frames menores não são aumentados
COPIAS_DO_FRAME_NO_VIDEO = 3      # frames duplicados no "vídeo" temporário — só por segurança,
                                  # caso o modelo espere mais de 1 frame; NÃO é uma janela de tempo real

# --- Configuração da Etapa 4 (padrões de movimento e motor de regras) ------
# Os valores abaixo são um ponto de partida razoável para a demo — a Etapa 7
# do roteiro é justamente calibrar esses limiares com o animal real de casa.
JANELA_ANALISE_PADROES_SEGUNDOS = 45       # quanto de histórico de pose entra nas contas (spec pede 30-60s)
LIMIAR_CONFIANCA_PONTO = 0.15               # mesmo corte usado pra desenhar o esqueleto na tela
LIMIAR_VELOCIDADE_PARADO = 4.0              # px/s abaixo disso o animal é considerado "parado" naquele intervalo
LIMIAR_VELOCIDADE_MUDANCA_POSTURA = 15.0    # px/s acima disso conta como uma mudança de postura (não um tremor pequeno)
TEMPO_IMOVEL_POSSIVEL_DOR_SEGUNDOS = 20     # parado por >= isso dentro da janela já liga o alerta de "possível dor"
TEMPO_IMOVEL_EMERGENCIA_SEGUNDOS = 40       # parado por >= isso é o sinal mais grave (possível colapso/inconsciência)
MUDANCAS_POSTURA_INQUIETACAO = 4            # nº de mudanças de postura na janela a partir do qual soa como agitação

# --- Configuração da Etapa 6 (alerta via Telegram) --------------------------
# Token e chat_id NÃO ficam escritos no código — vêm de variáveis de ambiente
# pra não vazar credencial se o arquivo for compartilhado. Configure antes de
# rodar, por exemplo:
#   export TELEGRAM_BOT_TOKEN="123456:ABC-..."
#   export TELEGRAM_CHAT_ID="123456789"
TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN")
TELEGRAM_CHAT_ID = os.getenv("TELEGRAM_CHAT_ID")

st.set_page_config(page_title="Monitoramento Pós-Operatório — MVP", layout="wide")

st.title("🐾 Monitoramento Comportamental Pós-Operatório — MVP")
st.caption(
    "Etapa 3: esqueleto de pose (SuperAnimal-Quadruped) atualizado a cada "
    f"~{INTERVALO_ANALISE_SEGUNDOS}s, sobre o vídeo e a detecção ao vivo."
)


@st.cache_resource(show_spinner="Carregando o modelo YOLO (só na primeira vez, pode baixar os pesos)...")
def carregar_modelo_deteccao():
    return YOLO("yolov8n.pt")


modelo_deteccao = carregar_modelo_deteccao()

nome_para_id = {nome: id_classe for id_classe, nome in modelo_deteccao.names.items()}
classes_de_interesse = [nome_para_id[n] for n in ("cat", "dog") if n in nome_para_id]
if not classes_de_interesse:
    classes_de_interesse = None


def analisar_pose_de_um_frame(frame_para_analise):
    """
    Recebe UM frame (o frame da câmera praticamente inteiro — só reduzido de
    tamanho se for muito grande, ver LADO_MAXIMO_ANALISE), grava como um
    vídeo temporário curtinho (o mesmo frame repetido algumas vezes, só por
    segurança) e roda o SuperAnimal-Quadruped oficial sobre esse vídeo.
    Retorna uma lista de pontos (x, y, confiança), ou levanta uma exceção se
    algo falhar — quem chama essa função decide o que fazer com o erro.

    Importante: mandamos o frame praticamente INTEIRO, não um recorte já
    cortado só no animal. O SuperAnimal usa, por baixo dos panos, o próprio
    detector dele (fasterrcnn) pra achar o animal antes de calcular a pose —
    um recorte já bem apertado e espremido num quadrado fixo pode ficar
    "estranho" demais pra esse detector reconhecer, e foi exatamente isso
    que causava o erro "need at least one array to stack" (o detector não
    achava nada pra empilhar). Deixando o detector do SuperAnimal trabalhar
    sobre uma cena quase normal, ele consegue localizar o animal sozinho.

    Importante também: processar 1 frame por chamada (em vez de vários
    segundos de buffer) é o que faz essa etapa ficar bem mais rápida — a
    "memória ao longo do tempo" que o projeto precisa vem de repetir esse
    ciclo a cada alguns segundos e guardar o histórico dos resultados, não
    de mandar vários frames de uma vez na mesma chamada.
    """
    altura, largura = frame_para_analise.shape[:2]

    with tempfile.TemporaryDirectory() as pasta_temp:
        caminho_video = str(Path(pasta_temp) / "trecho.mp4")

        gravador = cv2.VideoWriter(
            caminho_video,
            cv2.VideoWriter_fourcc(*"mp4v"),
            15,  # fps do arquivo — irrelevante aqui, só precisa ser um valor válido
            (largura, altura),
        )
        for _ in range(COPIAS_DO_FRAME_NO_VIDEO):
            gravador.write(frame_para_analise)
        gravador.release()

        # Chamada oficial do SuperAnimal, exatamente como no Colab de exemplo
        # do próprio DeepLabCut — sem treinar nada, modelo pronto de uso geral
        # para quadrúpedes.
        deeplabcut.video_inference_superanimal(
            [caminho_video],
            "superanimal_quadruped",
            model_name="hrnet_w32",
            detector_name="fasterrcnn_resnet50_fpn_v2",
            videotype=".mp4",
            video_adapt=False,
            pcutoff=0.15,
        )

        # A função salva o resultado como um .h5 na mesma pasta do vídeo.
        # Em vez de tentar adivinhar o nome exato do arquivo (isso mudou entre
        # versões do DeepLabCut), pegamos o .h5 mais recente dessa pasta.
        arquivos_h5 = sorted(
            Path(pasta_temp).glob("*.h5"), key=lambda p: p.stat().st_mtime, reverse=True
        )
        if not arquivos_h5:
            raise RuntimeError(
                "O SuperAnimal rodou sem erro, mas não gerou nenhum arquivo .h5 "
                "de resultado — verifique a versão do pacote 'deeplabcut' instalada."
            )

        tabela = pd.read_hdf(arquivos_h5[0])
        ultima_linha = tabela.iloc[-1]

        # As colunas do resultado são um MultiIndex terminando em "x" / "y" /
        # "likelihood". Só que a quantidade de níveis ANTES disso varia: o
        # pipeline do SuperAnimal usa detector + estimador de pose (uma
        # arquitetura pensada pra múltiplos animais), então às vezes o
        # resultado vem com um nível extra de "indivíduo" no meio
        # (scorer, indivíduo, parte_do_corpo, coord) em vez do formato mais
        # simples (scorer, parte_do_corpo, coord). Em vez de supor um número
        # fixo de níveis (o que causou o KeyError: 'x'), descobrimos os
        # níveis dinamicamente: o último nível são sempre as coordenadas, e
        # tudo antes dele identifica um "ponto" (não importa quantos níveis
        # sejam).
        prefixos_unicos = tabela.columns.droplevel(-1).unique()

        pontos = []
        for prefixo in prefixos_unicos:
            if not isinstance(prefixo, tuple):
                prefixo = (prefixo,)
            try:
                x = float(ultima_linha[prefixo + ("x",)])
                y = float(ultima_linha[prefixo + ("y",)])
                confianca = float(ultima_linha[prefixo + ("likelihood",)])
            except KeyError:
                # Esse prefixo não tem as 3 coordenadas esperadas — pula.
                continue
            pontos.append((x, y, confianca))

        if not pontos:
            raise RuntimeError(
                "O SuperAnimal gerou um resultado, mas não foi possível "
                "encontrar colunas de x/y/likelihood nele. Colunas encontradas: "
                f"{list(tabela.columns)[:6]}..."
            )

        return pontos


# ---------------------------------------------------------------------------
# Estado compartilhado com a thread de análise em segundo plano.
#
# IMPORTANTE: o Streamlit não é thread-safe para atualizar a tela — só a
# thread principal (a que roda o loop `while True` abaixo) pode chamar
# `st.algumacoisa`. Por isso a thread de background NUNCA toca nos
# `placeholder`s diretamente: ela só escreve nessas variáveis simples aqui,
# protegidas por um lock, e é o loop principal que lê esse estado a cada
# volta e decide o que mostrar na tela. Assim o vídeo nunca espera a análise
# terminar para continuar atualizando.
# ---------------------------------------------------------------------------
lock_pose = threading.Lock()
estado_pose = {
    "em_andamento": False,   # True enquanto uma análise está rodando na thread
    "pontos": None,          # último resultado de pose pronto (coordenadas no frame reduzido)
    "escala": None,          # fator de redução usado nesse resultado (pra converter de volta ao frame real)
    "mensagem": None,        # texto de status mais recente
    "tipo_mensagem": "info",  # "info" | "success" | "error"
    "versao": 0,             # incrementado a cada pose nova calculada com sucesso — usado pra
                             # a thread principal saber se já é um resultado novo (Etapa 4)
}


def analisar_pose_em_segundo_plano(frame_para_analise, escala):
    """Roda em uma thread separada — não pode chamar nada do Streamlit aqui."""
    try:
        pontos = analisar_pose_de_um_frame(frame_para_analise)
        with lock_pose:
            estado_pose["pontos"] = pontos
            estado_pose["escala"] = escala
            estado_pose["mensagem"] = f"Esqueleto atualizado às {datetime.now().strftime('%H:%M:%S')}."
            estado_pose["tipo_mensagem"] = "success"
            estado_pose["versao"] += 1
    except Exception:
        erro = traceback.format_exc(limit=3)
        with lock_pose:
            estado_pose["mensagem"] = (
                "Falhou ao calcular a pose com o SuperAnimal — o resto do app "
                f"continua funcionando normalmente. Detalhe do erro:\n\n```\n{erro}\n```"
            )
            estado_pose["tipo_mensagem"] = "error"
    finally:
        with lock_pose:
            estado_pose["em_andamento"] = False


def calcular_metricas_de_padrao(historico, agora):
    """
    Recebe o histórico de pose já recortado pela janela de tempo — uma lista
    de (timestamp, pontos_reais), do mais antigo pro mais recente — e devolve
    (tempo_parado_segundos, mudancas_de_postura, velocidade_media_px_por_s).

    "Velocidade" de um instante é a distância média percorrida pelos pontos
    (comparando com a leitura de pose anterior) dividida pelo tempo entre as
    duas leituras. "Mudança de postura" é simplesmente um instante em que essa
    velocidade ultrapassa LIMIAR_VELOCIDADE_MUDANCA_POSTURA — simples de
    propósito, é o suficiente pra um MVP. "Tempo parado" é o tempo desde a
    última vez que o animal se moveu acima de LIMIAR_VELOCIDADE_PARADO; se
    nenhuma leitura na janela teve movimento, assumimos que está parado desde
    o início da janela (não dá pra saber se está parado há mais tempo que
    isso, já que o histórico mais antigo já foi descartado).
    """
    if len(historico) < 2:
        tempo_parado = agora - historico[0][0] if historico else 0.0
        return tempo_parado, 0, 0.0

    velocidades_por_instante = []  # (timestamp_da_leitura_mais_recente_do_par, velocidade_px_por_s)

    for (t_anterior, pontos_anteriores), (t_atual, pontos_atuais) in zip(historico, historico[1:]):
        dt = t_atual - t_anterior
        if dt <= 0:
            continue

        distancias = [
            ((x1 - x0) ** 2 + (y1 - y0) ** 2) ** 0.5
            for (x0, y0, conf0), (x1, y1, conf1) in zip(pontos_anteriores, pontos_atuais)
            if conf0 >= LIMIAR_CONFIANCA_PONTO and conf1 >= LIMIAR_CONFIANCA_PONTO
        ]
        if not distancias:
            continue

        velocidades_por_instante.append((t_atual, (sum(distancias) / len(distancias)) / dt))

    if not velocidades_por_instante:
        return agora - historico[0][0], 0, 0.0

    velocidade_media = sum(v for _, v in velocidades_por_instante) / len(velocidades_por_instante)
    mudancas_de_postura = sum(
        1 for _, v in velocidades_por_instante if v > LIMIAR_VELOCIDADE_MUDANCA_POSTURA
    )

    for t_instante, v in reversed(velocidades_por_instante):
        if v > LIMIAR_VELOCIDADE_PARADO:
            tempo_parado = agora - t_instante
            break
    else:
        tempo_parado = agora - historico[0][0]

    return tempo_parado, mudancas_de_postura, velocidade_media


def classificar_estado(tempo_parado, mudancas_de_postura, velocidade_media):
    """
    Motor de regras da Etapa 4. Recebe as métricas da janela de tempo e
    devolve um dos 4 estados, do mais pro menos grave: "emergência" >
    "inquietação" > "possível dor" > "normal".

    A ordem dos "if" importa: imobilidade prolongada é tratada como o sinal
    mais crítico e checada primeiro (possível colapso/inconsciência), mesmo
    que isoladamente pareceria "calmo" por ter poucas mudanças de postura.
    Os limiares são só um ponto de partida — a Etapa 7 calibra com o animal
    real de casa.
    """
    if tempo_parado >= TEMPO_IMOVEL_EMERGENCIA_SEGUNDOS:
        return "emergência"

    if (
        mudancas_de_postura >= MUDANCAS_POSTURA_INQUIETACAO
        or velocidade_media >= LIMIAR_VELOCIDADE_MUDANCA_POSTURA
    ):
        return "inquietação"

    if tempo_parado >= TEMPO_IMOVEL_POSSIVEL_DOR_SEGUNDOS:
        return "possível dor"

    return "normal"


# Mesmo padrão de lock + dict usado para a pose (estado_pose/lock_pose): o
# envio ao Telegram é uma chamada de rede, roda em thread separada pra não
# travar o vídeo, e só a thread principal pode atualizar a tela do Streamlit.
lock_alerta = threading.Lock()
estado_alerta = {"mensagem": None, "tipo_mensagem": "info"}


def enviar_alerta_telegram(estado, frame_bgr):
    """
    Manda uma mensagem de texto (nome do estado) e uma foto do frame atual
    pro chat do Telegram configurado. É chamada em uma thread separada, no
    exato mesmo ponto do código em que a Etapa 5 registra a mudança de estado
    na tabela — assim tabela e alerta nascem sempre da mesma transição, sem
    risco de ficarem dessincronizados.
    """
    if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_ID:
        with lock_alerta:
            estado_alerta["mensagem"] = (
                "Alerta não enviado: configure as variáveis de ambiente "
                "TELEGRAM_BOT_TOKEN e TELEGRAM_CHAT_ID para ativar o Telegram."
            )
            estado_alerta["tipo_mensagem"] = "warning"
        return

    ok, imagem_codificada = cv2.imencode(".jpg", frame_bgr)
    if not ok:
        with lock_alerta:
            estado_alerta["mensagem"] = "Falha ao gerar a imagem do alerta para o Telegram."
            estado_alerta["tipo_mensagem"] = "error"
        return

    try:
        asyncio.run(
            Bot(token=TELEGRAM_BOT_TOKEN).send_photo(
                chat_id=TELEGRAM_CHAT_ID,
                photo=imagem_codificada.tobytes(),
                caption=f"🚨 Novo estado: {estado.upper()} às {datetime.now().strftime('%H:%M:%S')}",
            )
        )
        with lock_alerta:
            estado_alerta["mensagem"] = (
                f'Alerta de "{estado}" enviado ao Telegram às {datetime.now().strftime("%H:%M:%S")}.'
            )
            estado_alerta["tipo_mensagem"] = "success"
    except Exception:
        erro = traceback.format_exc(limit=3)
        with lock_alerta:
            estado_alerta["mensagem"] = f"Falha ao enviar alerta ao Telegram:\n\n```\n{erro}\n```"
            estado_alerta["tipo_mensagem"] = "error"


# Espaços reservados do dashboard.
estado_destaque_placeholder = st.empty()
status_placeholder = st.empty()
deteccao_placeholder = st.empty()
pose_status_placeholder = st.empty()
frame_placeholder = st.empty()

# --- Etapa 5: histórico de eventos ------------------------------------------
# Guardado em st.session_state (não numa variável comum) porque é o
# mecanismo do Streamlit que sobrevive a reruns do script dentro da mesma
# sessão do navegador — uma lista comum seria recriada vazia a cada rerun.
if "historico_eventos" not in st.session_state:
    st.session_state.historico_eventos = []

st.subheader("Histórico de eventos da sessão")
historico_eventos_placeholder = st.empty()
alerta_status_placeholder = st.empty()

status_placeholder.info("Abrindo a câmera...")

cap = cv2.VideoCapture(0)

if not cap.isOpened():
    status_placeholder.error(
        "Não foi possível abrir a webcam.\n\n"
        "Verifique duas coisas comuns no macOS:\n"
        "1. Se outro programa (Zoom, FaceTime, Photo Booth etc.) não está "
        "usando a câmera no momento — feche-o e rode o app de novo.\n"
        "2. Se o Terminal (ou o app/IDE de onde você rodou o Streamlit) tem "
        "permissão de câmera em Ajustes do Sistema > Privacidade e Segurança > Câmera."
    )
    st.stop()

status_placeholder.success(
    f"Câmera aberta com sucesso às {datetime.now().strftime('%H:%M:%S')}. "
    "Exibindo vídeo, detecção e (a cada alguns segundos) o esqueleto de pose."
)

momento_da_ultima_analise = 0.0
ultimo_frame_para_analise = None   # frame (talvez reduzido) mais recente, pronto pra mandar pro SuperAnimal
ultima_escala_para_analise = 1.0   # fator de redução aplicado a esse frame

# Histórico de pose da Etapa 4 — só a thread principal lê e escreve aqui
# (o valor já vem lido de estado_pose, que esse sim é protegido pelo lock),
# então não precisa de lock próprio.
historico_pose = []            # lista de (timestamp, pontos_em_coordenadas_reais), do mais antigo pro mais novo
ultima_versao_pose_registrada = -1   # pra só adicionar ao histórico quando a pose realmente mudou

# Etapa 5 — guarda o último estado exibido pra só registrar evento na
# transição (não a cada frame, senão a tabela ficaria cheia de linhas
# repetidas do mesmo estado).
ultimo_estado_registrado_no_historico = None

CORES_E_EMOJIS_POR_ESTADO = {
    "normal": ("#2ecc71", "🟢"),
    "inquietação": ("#f1c40f", "🟡"),
    "possível dor": ("#e67e22", "🟠"),
    "emergência": ("#e74c3c", "🔴"),
}

try:
    while True:
        ret, frame = cap.read()

        if not ret:
            status_placeholder.warning(
                "Não foi possível ler um novo frame da câmera. Tentando de novo..."
            )
            continue

        altura_frame, largura_frame = frame.shape[:2]

        resultados = modelo_deteccao(
            frame, classes=classes_de_interesse, conf=0.4, verbose=False
        )

        animal_detectado = False

        for resultado in resultados:
            for caixa in resultado.boxes:
                animal_detectado = True

                x1, y1, x2, y2 = map(int, caixa.xyxy[0])
                confianca = float(caixa.conf[0])
                nome_classe = modelo_deteccao.names[int(caixa.cls[0])]

                cv2.rectangle(frame, (x1, y1), (x2, y2), (0, 200, 0), 2)
                cv2.putText(
                    frame,
                    f"{nome_classe} {confianca:.0%}",
                    (x1, max(y1 - 10, 0)),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.7,
                    (0, 200, 0),
                    2,
                )

        deteccao_placeholder.markdown(
            "**Animal detectado:** 🟢 Sim" if animal_detectado else "**Animal detectado:** ⚪️ Não, no momento"
        )

        # Guarda o frame inteiro (talvez reduzido) mais recente enquanto há
        # animal na cena — não faz sentido mandar cena vazia pro SuperAnimal.
        # Mandamos o frame INTEIRO (não um recorte só do animal): o
        # SuperAnimal tem seu próprio detector por baixo dos panos e precisa
        # de uma cena "normal" pra conseguir achar o animal sozinho.
        if animal_detectado:
            ultima_escala_para_analise = min(1.0, LADO_MAXIMO_ANALISE / max(largura_frame, altura_frame))
            if ultima_escala_para_analise < 1.0:
                ultimo_frame_para_analise = cv2.resize(
                    frame,
                    (
                        int(largura_frame * ultima_escala_para_analise),
                        int(altura_frame * ultima_escala_para_analise),
                    ),
                )
            else:
                ultimo_frame_para_analise = frame.copy()

        agora = time.time()

        with lock_pose:
            ja_tem_analise_rodando = estado_pose["em_andamento"]

        pronto_para_novo_ciclo = (
            animal_detectado
            and ultimo_frame_para_analise is not None
            and not ja_tem_analise_rodando
            and (agora - momento_da_ultima_analise) > INTERVALO_ANALISE_SEGUNDOS
        )

        if pronto_para_novo_ciclo:
            momento_da_ultima_analise = agora
            with lock_pose:
                estado_pose["em_andamento"] = True
                estado_pose["mensagem"] = "Calculando o esqueleto de pose (SuperAnimal) em segundo plano..."
                estado_pose["tipo_mensagem"] = "info"

            # .copy() por segurança: o array não deveria ser alterado depois,
            # mas assim a thread trabalha na própria cópia, sem depender do
            # que o loop principal fizer com `ultimo_frame_para_analise` daqui
            # pra frente.
            threading.Thread(
                target=analisar_pose_em_segundo_plano,
                args=(ultimo_frame_para_analise.copy(), ultima_escala_para_analise),
                daemon=True,
            ).start()

        # Lê o estado mais recente da análise (sem esperar por ela) e
        # atualiza a tela — é só aqui, na thread principal, que os
        # `placeholder`s do Streamlit podem ser chamados.
        with lock_pose:
            mensagem = estado_pose["mensagem"]
            tipo_mensagem = estado_pose["tipo_mensagem"]
            pontos_atuais = estado_pose["pontos"]
            escala_usada_na_ultima_pose = estado_pose["escala"]
            versao_atual_pose = estado_pose["versao"]

        if mensagem:
            getattr(pose_status_placeholder, tipo_mensagem)(mensagem)

        with lock_alerta:
            mensagem_alerta = estado_alerta["mensagem"]
            tipo_mensagem_alerta = estado_alerta["tipo_mensagem"]

        if mensagem_alerta:
            getattr(alerta_status_placeholder, tipo_mensagem_alerta)(mensagem_alerta)

        # Desenha a última pose calculada (mesmo que tenha sido há alguns
        # segundos), convertendo as coordenadas do frame reduzido de volta
        # para a posição real no frame ao vivo (dividindo pela escala usada
        # naquele ciclo). O vídeo em si nunca parou de atualizar enquanto
        # isso era calculado em segundo plano. Feito ANTES da Etapa 4/6 de
        # propósito: assim, se um alerta for disparado agora, o print
        # mandado pro Telegram já sai com o esqueleto desenhado em cima,
        # igual ao que aparece no dashboard.
        if pontos_atuais and escala_usada_na_ultima_pose:
            for x_analise, y_analise, confianca_ponto in pontos_atuais:
                if confianca_ponto < 0.15:
                    continue
                x_real = int(x_analise / escala_usada_na_ultima_pose)
                y_real = int(y_analise / escala_usada_na_ultima_pose)
                cv2.circle(frame, (x_real, y_real), 4, (255, 140, 0), -1)

        # --- Etapa 4: alimenta o histórico só quando a pose muda de verdade
        # (o resultado do SuperAnimal fica "parado" por vários frames de vídeo
        # entre uma análise e outra — sem esse controle de versão, a mesma
        # leitura entraria dezenas de vezes no histórico e a "velocidade"
        # calculada entre leituras idênticas ficaria zerada à toa).
        if (
            versao_atual_pose != ultima_versao_pose_registrada
            and pontos_atuais
            and escala_usada_na_ultima_pose
        ):
            pontos_em_coordenadas_reais = [
                (x / escala_usada_na_ultima_pose, y / escala_usada_na_ultima_pose, confianca_ponto)
                for x, y, confianca_ponto in pontos_atuais
            ]
            historico_pose.append((agora, pontos_em_coordenadas_reais))
            ultima_versao_pose_registrada = versao_atual_pose

        limite_da_janela = agora - JANELA_ANALISE_PADROES_SEGUNDOS
        historico_pose[:] = [amostra for amostra in historico_pose if amostra[0] >= limite_da_janela]

        if len(historico_pose) >= 2:
            tempo_parado, mudancas_de_postura, velocidade_media = calcular_metricas_de_padrao(
                historico_pose, agora
            )
            estado_atual = classificar_estado(tempo_parado, mudancas_de_postura, velocidade_media)
            cor_estado, emoji_estado = CORES_E_EMOJIS_POR_ESTADO[estado_atual]
            estado_destaque_placeholder.markdown(
                f"<div style='padding:16px;border-radius:10px;background-color:{cor_estado};"
                "color:white;font-size:1.8rem;font-weight:bold;text-align:center;'>"
                f"{emoji_estado} Estado: {estado_atual.upper()}</div>",
                unsafe_allow_html=True,
            )

            # Etapa 5: só grava uma linha nova no histórico quando o estado
            # realmente muda em relação ao último registrado — é essa
            # comparação que evita linhas repetidas do mesmo estado.
            if estado_atual != ultimo_estado_registrado_no_historico:
                st.session_state.historico_eventos.insert(
                    0,
                    {"Horário": datetime.now().strftime("%H:%M:%S"), "Estado": estado_atual},
                )
                ultimo_estado_registrado_no_historico = estado_atual

                # Etapa 6: mesmo ponto do código que acabou de registrar a
                # linha na tabela — garante que tabela e alerta nascem da
                # mesma transição. Só dispara quando o novo estado NÃO é
                # "normal" (voltar a "normal" não gera alerta, só a tabela).
                if estado_atual != "normal":
                    with lock_alerta:
                        estado_alerta["mensagem"] = f'Enviando alerta de "{estado_atual}" ao Telegram...'
                        estado_alerta["tipo_mensagem"] = "info"
                    threading.Thread(
                        target=enviar_alerta_telegram,
                        args=(estado_atual, frame.copy()),
                        daemon=True,
                    ).start()
        else:
            estado_destaque_placeholder.markdown(
                "<div style='padding:16px;border-radius:10px;background-color:#7f8c8d;"
                "color:white;font-size:1.4rem;font-weight:bold;text-align:center;'>"
                "⏳ Aguardando dados suficientes de pose para calcular o estado...</div>",
                unsafe_allow_html=True,
            )

        if st.session_state.historico_eventos:
            historico_eventos_placeholder.dataframe(
                pd.DataFrame(st.session_state.historico_eventos),
                use_container_width=True,
                hide_index=True,
            )

        frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        frame_placeholder.image(frame_rgb, channels="RGB", use_container_width=True)

finally:
    cap.release()
