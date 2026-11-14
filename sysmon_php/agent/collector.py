"""
SysMon — Agente coletor (Windows)
==================================

Roda LOCALMENTE em cada computador que você quer monitorar. A cada
`intervalo_segundos` (config.ini), ele:

  1. Lê métricas reais de hardware via WMI (Windows Management
     Instrumentation) — CPU, RAM, disco, rede, GPU (parcial), uptime.
  2. Tenta ler temperaturas via WMI (ver observação importante abaixo).
  3. Grava tudo direto no banco MySQL usado pelo site (piprojeto2),
     nas tabelas `metricas` e `temperaturas`.

O site (sysmon_php) NÃO simula mais nada: ele só lê a última linha
gravada por este agente. Se o agente parar de rodar em uma máquina,
o dashboard automaticamente marca aquele computador como "offline"
depois de alguns segundos sem dados novos.

────────────────────────────────────────────────────────────────
IMPORTANTE — limitações reais do WMI (para não prometer o que
Windows não entrega de fábrica):

  • CPU / RAM / Disco / Rede / Uptime: o WMI padrão do Windows
    fornece esses dados de forma confiável em qualquer PC. ✅

  • GPU: o WMI padrão só dá o MODELO da placa (Win32_VideoController).
    Uso (%) e VRAM (%) de GPU não são expostos pelo WMI genérico —
    isso normalmente vem de APIs do fabricante (NVIDIA/AMD/Intel).
    Este agente tenta primeiro o utilitário `nvidia-smi` (GPU NVIDIA
    com driver instalado); se não existir, cai para os sensores de
    "Load"/"Data" do LibreHardwareMonitor, que funcionam também com
    GPUs AMD Radeon e Intel Arc. Sem nenhuma das duas fontes, os
    campos ficam em branco (NULL) em vez de inventados. ⚠️

  • Temperaturas (CPU/GPU/NVMe/placa-mãe/fonte): a classe WMI padrão
    `MSAcpi_ThermalZoneTemperature` só existe em parte dos notebooks
    e praticamente não existe em desktops/servidores comuns. Não há
    "PSU temp" nem "PSU watts" nativos no WMI de PCs convencionais.
    Para números REAIS de temperatura, este agente lê o servidor web
    local do LibreHardwareMonitor (gratuito, open-source) — habilite
    em Options > Remote Web Server > Run (porta 8085 por padrão) —
    em vez do WMI nativo dele, que tem um bug conhecido em várias
    versões recentes e não funciona de forma confiável. Se o
    LibreHardwareMonitor não estiver rodando com o servidor web
    ligado, o agente cai para MSAcpi_ThermalZoneTemperature (WMI
    nativo) como segunda opção; se nenhum dos dois estiver disponível,
    os campos de temperatura são gravados como NULL (o site mostra
    "—" em vez de número inventado).

    O reconhecimento dos sensores de temperatura NÃO é mais restrito
    à nomenclatura da Intel: CPU aceita tanto "CPU Package" (Intel)
    quanto "Tctl/Tdie" (AMD Ryzen); GPU aceita "GPU Core"/"GPU Hot
    Spot" (NVIDIA, AMD e Intel Arc usam os mesmos rótulos no Libre-
    HardwareMonitor); placa-mãe aceita "Mainboard"/"Motherboard" e
    variações como "Chipset"/"PCH"/"System"; armazenamento é
    identificado pelo tipo de dispositivo (SSD/NVMe/HDD), não pela
    marca — já funcionava para qualquer fabricante de disco.

  • RPM dos fans (CPU e sistema): mesma fonte do item acima (Libre-
    HardwareMonitor). Depende do chip Super I/O da placa — comum em
    placas desktop, raro/inexistente em notebook. Sem esse suporte,
    fica NULL.

  • Modelo/fabricante da placa-mãe e versão da BIOS: ao contrário dos
    itens acima, isso vem do WMI padrão (`Win32_BaseBoard`,
    `Win32_BIOS`) — funciona em qualquer PC ou notebook Windows, sem
    depender do LibreHardwareMonitor estar aberto. ✅
────────────────────────────────────────────────────────────────

Requisitos (na máquina monitorada, Windows):
    pip install -r requirements.txt

Uso:
    python collector.py                # usa config.ini na mesma pasta
    python collector.py --config C:\\caminho\\outro_config.ini

Para rodar continuamente em segundo plano, use o Agendador de Tarefas
do Windows (Task Scheduler) apontando para este script com a opção
"executar estando o usuário conectado ou não", ou registre como
serviço com NSSM (https://nssm.cc/). Veja o README na pasta agent/.
"""

from __future__ import annotations

import argparse
import http.client
import configparser
import json
import os
import platform
import re
import ssl
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path
from urllib.parse import urlparse

try:
    import wmi
except ImportError:
    sys.exit(
        "O módulo 'wmi' não está instalado (ou este script não está "
        "rodando no Windows). Rode: pip install -r requirements.txt"
    )

def carregar_config(caminho: str) -> configparser.ConfigParser:
    cfg = configparser.ConfigParser()
    lidos = cfg.read(caminho, encoding="utf-8")
    if not lidos:
        sys.exit(f"Não foi possível ler o arquivo de configuração: {caminho}")
    return cfg


def auto_identidade() -> tuple[str, str]:
    """Gera (codigo, label) automaticamente a partir do nome do
    computador, para quando config.ini estiver com 'auto'. Assim você
    não precisa digitar um código manualmente em cada máquina — só
    copiar o agente e rodar."""
    hostname = platform.node() or "PC-DESCONHECIDO"
    codigo = re.sub(r"[^A-Z0-9_-]", "", hostname.upper())[:20] or "PC-DESCONHECIDO"
    return codigo, hostname


def normalizar_patrimonio(texto: str) -> str:
    """Deixa só letras e números, em maiúsculas, e tira um "CM" digitado na frente:
    o painel já coloca o "CM" sozinho (código = CM + patrimônio). Sem isso, quem
    digita "CM439647" gerava o código duplicado "CMCM439647"."""
    p = re.sub(r"[^0-9A-Za-z]", "", texto or "").upper()
    m = re.fullmatch(r"CM([0-9A-Z]{3,10})", p)
    if m and re.search(r"[0-9]", m.group(1)):
        p = m.group(1)
    return p


def resolver_identidade(cfg: configparser.ConfigParser) -> tuple[str, str, str]:
    codigo_cfg = cfg.get("computador", "codigo", fallback="auto").strip()
    label_cfg = cfg.get("computador", "label", fallback="auto").strip()
    location = cfg.get("computador", "location", fallback="")
    patrimonio = normalizar_patrimonio(cfg.get("computador", "patrimonio", fallback=""))

    auto_codigo, auto_label = auto_identidade()
    if codigo_cfg.lower() in ("", "auto"):
        codigo = f"CM{patrimonio}" if patrimonio else auto_codigo
    else:
        codigo = codigo_cfg
    label = auto_label if label_cfg.lower() in ("", "auto") else label_cfg
    return codigo, label, location


def configurar_patrimonio(caminho: str) -> None:
    """Pergunta o número de patrimônio e grava no config.ini (chamado pelo
    install.bat). Mantém os comentários do arquivo."""
    print()
    print("=== Numero de patrimonio deste computador (letras e/ou numeros, ex.: 406652) ===")
    while True:
        digitado = input("Patrimonio (ENTER para pular): ").strip()
        pat = normalizar_patrimonio(digitado)
        if pat == "" or re.fullmatch(r"[0-9A-Z]{3,10}", pat):
            if pat and pat != re.sub(r"[^0-9A-Za-z]", "", digitado).upper():
                print(f"O prefixo CM e colocado automaticamente; usando {pat}.")
            break
        print("Use de 3 a 10 letras e/ou numeros, sem espacos nem simbolos.")
    if not pat:
        print("Pulado: o computador sera identificado pelo nome do Windows.")
        return
    texto = Path(caminho).read_text(encoding="utf-8")
    if re.search(r"^patrimonio\s*=.*$", texto, re.MULTILINE):
        texto = re.sub(r"^patrimonio\s*=.*$", f"patrimonio = {pat}", texto, count=1, flags=re.MULTILINE)
    else:
        texto = re.sub(r"(\[computador\][^\n]*\n)", lambda m: m.group(1) + f"patrimonio = {pat}\n", texto, count=1)
    Path(caminho).write_text(texto, encoding="utf-8")
    print(f"Salvo: este computador sera CM{pat} no painel.")


def _extrair_numero(valor):
    """Converte uma leitura crua do LibreHardwareMonitor (ex.: '45,3 °C',
    '67 %', '1024 MB') no número float correspondente. Função
    compartilhada por CPU, GPU, placa-mãe e armazenamento — o formato
    do valor é sempre o mesmo, não importa o fabricante do hardware."""
    try:
        token = str(valor).strip().split(" ")[0].replace(",", ".")
        return round(float(token), 1)
    except (ValueError, IndexError, TypeError):
        return None


class ColetorWMI:
    def __init__(self):
        self.c = wmi.WMI()
        self._lhm_cache = None

    def resetar_cache_lhm(self) -> None:
        """Chamar no início de cada ciclo de coleta (loop principal) para
        forçar uma nova leitura do LibreHardwareMonitor na próxima vez que
        for necessária, em vez de reusar dados de um ciclo anterior."""
        self._lhm_cache = None

    def _obter_arvore_lhm(self):
        """Busca (e cacheia por ciclo) a árvore JSON do servidor web local
        do LibreHardwareMonitor. Retorna None se não estiver disponível
        (programa fechado ou 'Remote Web Server' desligado)."""
        if self._lhm_cache is not None:
            return self._lhm_cache or None
        try:
            with urllib.request.urlopen("http://localhost:8085/data.json", timeout=3) as resp:
                self._lhm_cache = json.loads(resp.read().decode("utf-8"))
        except Exception:
            self._lhm_cache = False
        return self._lhm_cache or None

    def cpu(self) -> dict:
        modelo = None
        freq_ghz = None
        for p in self.c.Win32_Processor():
            modelo = (p.Name or "").strip()
            if p.CurrentClockSpeed:
                freq_ghz = round(p.CurrentClockSpeed / 1000, 2)
            break

        uso_total = None
        cores = []
        for row in self.c.Win32_PerfFormattedData_PerfOS_Processor():
            try:
                pct = float(row.PercentProcessorTime)
            except (TypeError, ValueError):
                continue
            if row.Name == "_Total":
                uso_total = round(pct, 1)
            elif row.Name and row.Name.isdigit():
                cores.append(round(pct, 1))

        cores.sort(key=lambda _: 0)
        return {
            "usage": uso_total if uso_total is not None else 0.0,
            "model": modelo,
            "freq": freq_ghz,
            "cores": cores,
        }

    def ram(self) -> dict:
        for so in self.c.Win32_OperatingSystem():
            total_kb = float(so.TotalVisibleMemorySize or 0)
            livre_kb = float(so.FreePhysicalMemory or 0)
            total_gb = round(total_kb / 1024 / 1024, 1)
            usado_gb = round((total_kb - livre_kb) / 1024 / 1024, 1)
            return {"used": usado_gb, "total": total_gb}
        return {"used": 0.0, "total": 0.0}

    def disco(self) -> dict:
        total_bytes = 0
        usado_bytes = 0
        for d in self.c.Win32_LogicalDisk(DriveType=3):
            size = int(d.Size or 0)
            free = int(d.FreeSpace or 0)
            total_bytes += size
            usado_bytes += (size - free)
        gb = 1024 ** 3
        return {
            "used": round(usado_bytes / gb, 1),
            "total": round(total_bytes / gb, 1),
        }

    def rede(self) -> dict:
        down_bps = 0.0
        up_bps = 0.0
        for nic in self.c.Win32_PerfFormattedData_Tcpip_NetworkInterface():
            nome = (nic.Name or "")
            if "loopback" in nome.lower() or "isatap" in nome.lower():
                continue
            try:
                down_bps += float(nic.BytesReceivedPersec or 0)
                up_bps += float(nic.BytesSentPersec or 0)
            except (TypeError, ValueError):
                continue
        mb = 1024 ** 2
        return {
            "down": round(down_bps / mb, 1),
            "up": round(up_bps / mb, 1),
        }

    def uptime_horas(self) -> int:
        for so in self.c.Win32_OperatingSystem():
            try:
                boot = so.Properties_("LastBootUpTime").Value
                boot_dt = datetime.strptime(boot.split(".")[0], "%Y%m%d%H%M%S")
                delta = datetime.now() - boot_dt
                return max(0, int(delta.total_seconds() // 3600))
            except Exception:
                return 0
        return 0

    def placa_mae(self) -> dict:
        manufacturer = model = bios = None
        try:
            for b in self.c.Win32_BaseBoard():
                manufacturer = (b.Manufacturer or "").strip() or None
                model = (b.Product or "").strip() or None
                break
        except Exception:
            pass
        try:
            for bi in self.c.Win32_BIOS():
                bios = (bi.SMBIOSBIOSVersion or bi.Version or "").strip() or None
                break
        except Exception:
            pass
        return {"manufacturer": manufacturer, "model": model, "bios": bios}

    def gpu(self) -> dict:
        modelo = None
        for g in self.c.Win32_VideoController():
            if g.Name:
                modelo = g.Name.strip()
                break

        usage = temp = vram_pct = None

        nvidia = self._nvidia_smi()
        if nvidia:
            usage, temp, vram_pct = nvidia
        else:
            via_lhm = self._gpu_via_lhm()
            if via_lhm:
                usage, temp, vram_pct = via_lhm

        return {
            "usage": usage,
            "temp": temp,
            "vram_pct": vram_pct,
            "model": modelo,
        }

    @staticmethod
    def _nvidia_smi():
        """Tenta obter uso/temperatura/VRAM reais via nvidia-smi.
        Retorna None se a ferramenta não existir (GPU não-NVIDIA ou
        driver sem suporte), em vez de inventar valores."""
        try:
            saida = subprocess.check_output(
                [
                    "nvidia-smi",
                    "--query-gpu=utilization.gpu,temperature.gpu,memory.used,memory.total",
                    "--format=csv,noheader,nounits",
                ],
                stderr=subprocess.DEVNULL,
                timeout=5,
                text=True,
            )
            partes = [p.strip() for p in saida.strip().splitlines()[0].split(",")]
            uso, temp, mem_used, mem_total = (float(x) for x in partes)
            vram_pct = round((mem_used / mem_total) * 100, 1) if mem_total else None
            return round(uso, 1), round(temp, 1), vram_pct
        except Exception:
            return None

    def _gpu_via_lhm(self):
        """Fallback para GPUs não-NVIDIA (AMD Radeon, Intel Arc): lê uso,
        temperatura e VRAM do LibreHardwareMonitor em vez do nvidia-smi
        (que só existe/funciona em placas NVIDIA). Os nomes dos sensores
        de GPU no LibreHardwareMonitor são os mesmos independente do
        fabricante ('GPU Core', 'GPU Memory Used', ...)."""
        arvore = self._obter_arvore_lhm()
        if not arvore:
            return None

        usage = temp = vram_used = vram_total = None

        def visitar(no):
            nonlocal usage, temp, vram_used, vram_total
            texto = (no.get("Text") or "")
            n = texto.lower()
            tipo = no.get("Type")
            valor = no.get("Value")

            if valor:
                if tipo == "Load" and n in ("gpu core", "gpu"):
                    if usage is None:
                        usage = _extrair_numero(valor)
                elif tipo == "Temperature" and (
                    "gpu core" in n or "gpu hot spot" in n or n == "gpu"
                ):
                    if temp is None:
                        temp = _extrair_numero(valor)
                elif tipo in ("SmallData", "Data"):
                    if "gpu memory used" in n and vram_used is None:
                        vram_used = _extrair_numero(valor)
                    elif "gpu memory total" in n and vram_total is None:
                        vram_total = _extrair_numero(valor)

            for filho in no.get("Children", []):
                visitar(filho)

        visitar(arvore)

        vram_pct = None
        if vram_used is not None and vram_total:
            vram_pct = round((vram_used / vram_total) * 100, 1)

        if usage is None and temp is None and vram_pct is None:
            return None
        return usage, temp, vram_pct

    def temperaturas(self) -> dict:
        via_lhm = self._temps_libre_hardware_monitor()
        if via_lhm:
            return via_lhm
        via_acpi = self._temps_acpi()
        if via_acpi:
            return via_acpi
        return {
            "cpu_package": None, "cpu_cores": [], "gpu_die": None,
            "gpu_mem": None, "nvme_1": None, "nvme_2": None,
            "psu": None, "motherboard": None, "ambient": None,
            "fan_cpu_rpm": None, "fan_sys_rpm": None,
        }

    def _temps_libre_hardware_monitor(self):
        """Lê temperaturas do servidor web local do LibreHardwareMonitor
        (Options > Remote Web Server > Run, porta padrão 8085), em vez
        do namespace WMI nativo dele — que tem um bug conhecido e
        quebra em várias versões recentes (fica "conectando" mas nunca
        retorna nada, ver
        https://github.com/LibreHardwareMonitor/LibreHardwareMonitor/issues/2143).

        Requer que o LibreHardwareMonitor esteja aberto, com o "Remote
        Web Server" ligado (Options > Remote Web Server > Run), na
        mesma máquina onde este agente roda.

        Os nomes dos sensores abaixo cobrem os rótulos usados pelo
        LibreHardwareMonitor para qualquer fabricante — CPU (Intel e AMD),
        GPU (NVIDIA, AMD e Intel Arc), placa-mãe (qualquer chipset) e
        armazenamento (qualquer marca de SSD/NVMe/HDD) — em vez de só o
        rótulo que a Intel usa ("CPU Package")."""
        arvore = self._obter_arvore_lhm()
        if not arvore:
            return None

        sensores_temp = []
        sensores_fan = []

        PALAVRAS_ARMAZENAMENTO = ("ssd", "nvme", "hdd", "hd ", " hd", "disk", "drive")

        def parece_armazenamento(nome_hw: str) -> bool:
            n = nome_hw.lower()
            return any(p in n for p in PALAVRAS_ARMAZENAMENTO)

        def visitar(no, hw_atual=None):
            texto = no.get("Text", "")
            if no.get("Type") in (None, "") and no.get("Children") and texto:
                hw_atual = texto

            if no.get("Type") == "Temperature" and no.get("Value"):
                sensores_temp.append((texto, no["Value"], hw_atual))
            elif no.get("Type") == "Fan" and no.get("Value"):
                sensores_fan.append((texto, no["Value"]))

            for filho in no.get("Children", []):
                visitar(filho, hw_atual)

        visitar(arvore)
        if not sensores_temp and not sensores_fan:
            return None

        para_float = _extrair_numero

        def eh_cpu_geral(n: str) -> bool:
            if n == "cpu package":
                return True
            if "tctl" in n and "tdie" in n:
                return True
            if "ccd" not in n and ("tctl" in n or "tdie" in n):
                return True
            return False

        def eh_gpu_temp(n: str) -> bool:
            return "gpu core" in n or "gpu hot spot" in n or n == "gpu"

        def eh_gpu_memoria(n: str) -> bool:
            return "gpu memory" in n or "memory junction" in n

        def eh_placa_mae(n: str) -> bool:
            return any(p in n for p in ("mainboard", "motherboard", "system", "chipset", "pch"))

        cpu_pkg = gpu_die = gpu_mem = mobo = None
        cpu_pkg_fallback = None
        cpu_cores = []
        armazenamento = {}

        for nome, valor, hw_atual in sensores_temp:
            v = para_float(valor)
            if v is None:
                continue
            n = nome.lower()
            if eh_cpu_geral(n):
                if cpu_pkg is None:
                    cpu_pkg = v
            elif re.fullmatch(r"cpu core #\d+", n):
                cpu_cores.append(v)
            elif "ccd" in n and ("tctl" in n or "tdie" in n):
                if cpu_pkg_fallback is None:
                    cpu_pkg_fallback = v
            elif eh_gpu_temp(n):
                if gpu_die is None:
                    gpu_die = v
            elif eh_gpu_memoria(n):
                if gpu_mem is None:
                    gpu_mem = v
            elif eh_placa_mae(n):
                if mobo is None:
                    mobo = v
            elif hw_atual and parece_armazenamento(hw_atual):
                armazenamento.setdefault(hw_atual, v)

        if cpu_pkg is None:
            cpu_pkg = cpu_pkg_fallback

        discos = list(armazenamento.items())
        nvme_1 = discos[0][1] if len(discos) > 0 else None
        nvme_2 = discos[1][1] if len(discos) > 1 else None

        def para_int(valor):
            try:
                token = str(valor).strip().split(" ")[0].replace(",", ".")
                return int(round(float(token)))
            except (ValueError, IndexError):
                return None

        fan_cpu = fan_sys = None
        for nome, valor in sensores_fan:
            v = para_int(valor)
            if v is None or v <= 0:
                continue
            n = nome.lower()
            if fan_cpu is None and "cpu" in n:
                fan_cpu = v
            elif fan_sys is None and "cpu" not in n:
                fan_sys = v

        if (cpu_pkg is None and not cpu_cores and gpu_die is None and mobo is None
                and nvme_1 is None and fan_cpu is None and fan_sys is None):
            return None

        return {
            "cpu_package": cpu_pkg, "cpu_cores": cpu_cores, "gpu_die": gpu_die,
            "gpu_mem": gpu_mem, "nvme_1": nvme_1, "nvme_2": nvme_2,
            "psu": None, "motherboard": mobo, "ambient": None,
            "fan_cpu_rpm": fan_cpu, "fan_sys_rpm": fan_sys,
        }

    def _temps_acpi(self):
        """Segunda tentativa: classe ACPI padrão (só existe em parte
        dos notebooks, e em algumas máquinas existe mas devolve lixo
        tipo 0 bruto — por isso a validação de faixa plausível abaixo).
        Valor vem em décimos de Kelvin."""
        try:
            conexao = wmi.WMI(namespace="root/WMI")
            zonas = conexao.MSAcpi_ThermalZoneTemperature()
        except Exception:
            return None
        if not zonas:
            return None
        try:
            kelvin_decimo = float(zonas[0].CurrentTemperature)
            celsius = round((kelvin_decimo / 10) - 273.15, 1)
        except Exception:
            return None

        if celsius < -20 or celsius > 120:
            return None

        return {
            "cpu_package": celsius, "cpu_cores": [], "gpu_die": None,
            "gpu_mem": None, "nvme_1": None, "nvme_2": None,
            "psu": None, "motherboard": None, "ambient": None,
            "fan_cpu_rpm": None, "fan_sys_rpm": None,
        }


class ErroEnvio(Exception):
    """Falha ao entregar os dados ao painel (rede, chave ou servidor)."""


class ErroCertificado(ErroEnvio):
    """O certificado HTTPS do painel não pôde ser verificado neste computador."""


PASTA_AGENTE = Path(__file__).resolve().parent
ARQ_CADEIA = PASTA_AGENTE / "cadeia.pem"
ARQ_ESTADO = PASTA_AGENTE / "estado.json"


def _registrar_estado(**campos) -> None:
    """Guarda, em estado.json, o último envio bem-sucedido e a última falha."""
    try:
        dados = json.loads(ARQ_ESTADO.read_text(encoding="utf-8"))
    except Exception:
        dados = {}
    dados.update(campos)
    try:
        ARQ_ESTADO.write_text(json.dumps(dados, ensure_ascii=False), encoding="utf-8")
    except OSError:
        pass


def _contexto_ssl(cfg: configparser.ConfigParser) -> ssl.SSLContext:
    """Contexto HTTPS do agente: certificados do Windows + cadeia.pem (criada
    por --certificado) + ca_arquivo do config.ini, se houver."""
    ctx = ssl.create_default_context()
    extras = [ARQ_CADEIA]
    ca = cfg.get("servidor", "ca_arquivo", fallback="").strip()
    if ca:
        extras.append(Path(ca))
    for arq in extras:
        if arq.is_file():
            try:
                ctx.load_verify_locations(cafile=str(arq))
            except (ssl.SSLError, OSError):
                pass
    if cfg.get("servidor", "verificar_ssl", fallback="sim").strip().lower() in ("nao", "não", "no", "0", "false"):
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    return ctx


def _explicar_oserror(e) -> str:
    """Texto do erro + dica quando a conexão foi derrubada no caminho."""
    texto = str(e)
    derrubada = isinstance(e, ConnectionResetError) or "10054" in texto or "Connection reset" in texto
    if derrubada:
        texto += (" — a conexão foi derrubada no caminho (firewall, proxy ou filtro da rede). "
                  "Rode verificar.bat ou 'python collector.py --diagnostico' para ver em que etapa cai.")
    elif "timed out" in texto or "10060" in texto:
        texto += " — sem resposta (porta ou endereço bloqueado na rede)."
    return texto


def _post(cfg: configparser.ConfigParser, payload: dict) -> dict:
    url = cfg.get("servidor", "url", fallback="").strip()
    chave = cfg.get("servidor", "chave", fallback="").strip()
    if not url or not chave:
        raise ErroEnvio("config.ini sem [servidor] url/chave — use o config.ini novo do agente")
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        method="POST",
        headers={
            "Content-Type": "application/json; charset=utf-8",
            "X-SysMon-Key": chave,
            "User-Agent": "sysmon-agent/3.0",
        },
    )
    ctx = _contexto_ssl(cfg)
    proxy = cfg.get("servidor", "proxy", fallback="").strip()
    try:
        if proxy:
            abridor = urllib.request.build_opener(
                urllib.request.ProxyHandler({"https": proxy, "http": proxy}),
                urllib.request.HTTPSHandler(context=ctx))
            resp_cm = abridor.open(req, timeout=20)
        else:
            resp_cm = urllib.request.urlopen(req, timeout=20, context=ctx)
        with resp_cm as resp:
            corpo = resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        try:
            msg = json.loads(e.read().decode("utf-8", "replace")).get("error", "")
        except Exception:
            msg = ""
        dica = " (chave diferente da do servidor?)" if e.code == 401 else ""
        raise ErroEnvio(f"o painel respondeu HTTP {e.code}: {msg or e.reason}{dica}")
    except urllib.error.URLError as e:
        if isinstance(e.reason, ssl.SSLCertVerificationError):
            raise ErroCertificado(f"certificado do painel não reconhecido: {e.reason}")
        raise ErroEnvio(f"sem conexão com o painel: {_explicar_oserror(e.reason)}")
    except ssl.SSLCertVerificationError as e:
        raise ErroCertificado(f"certificado do painel não reconhecido: {e}")
    except OSError as e:
        raise ErroEnvio(f"sem conexão com o painel: {_explicar_oserror(e)}")
    try:
        return json.loads(corpo)
    except ValueError:
        raise ErroEnvio(f"resposta inesperada do painel: {corpo[:200]}")


def enviar_ao_painel(cfg: configparser.ConfigParser, payload: dict) -> dict:
    """Envia a leitura ao painel por HTTPS (porta 443). O agente não fala
    mais com o MySQL: só o servidor do painel grava no banco."""
    resp = _post(cfg, payload)
    if resp.get("ok") is not True:
        raise ErroEnvio(f"resposta inesperada do painel: {str(resp)[:200]}")
    return resp


_OID_CA_ISSUERS = bytes.fromhex("2b06010505073002")


def _aia_urls(der: bytes) -> list[str]:
    """URLs de download do certificado emissor (extensão AIA) de um certificado DER."""
    urls, i = [], 0
    while True:
        i = der.find(_OID_CA_ISSUERS, i)
        if i < 0:
            return urls
        i += len(_OID_CA_ISSUERS)
        if i + 2 <= len(der) and der[i] == 0x86:
            tam, ini = der[i + 1], i + 2
            if tam == 0x81:
                tam, ini = der[i + 2], i + 3
            urls.append(der[ini:ini + tam].decode("ascii", "ignore"))


def _baixar_certificado(url: str) -> str | None:
    """Baixa um certificado (DER ou PEM) e devolve em PEM; None se não servir."""
    if not url.lower().startswith(("http://", "https://")):
        return None
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "sysmon-agent/3.0"})
        with urllib.request.urlopen(req, timeout=15) as r:
            dados = r.read(200000)
    except Exception:
        return None
    if dados.lstrip().startswith(b"-----BEGIN"):
        pem = dados.decode("ascii", "ignore")
    else:
        pem = ssl.DER_cert_to_PEM_cert(dados)
    try:
        ssl.create_default_context().load_verify_locations(cadata=pem)
    except ssl.SSLError:
        return None
    return pem


def _testar_tls(host: str, porta: int, ctx: ssl.SSLContext) -> str | None:
    """Faz só o handshake HTTPS. Devolve None se o certificado foi aceito."""
    try:
        with socket.create_connection((host, porta), timeout=15) as s:
            with ctx.wrap_socket(s, server_hostname=host):
                return None
    except ssl.SSLCertVerificationError as e:
        return e.verify_message or str(e)
    except (ssl.SSLError, OSError) as e:
        return str(e)


def _certificados_do_servidor(host: str, porta: int) -> tuple[bytes, list[bytes]]:
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    with socket.create_connection((host, porta), timeout=15) as s:
        with ctx.wrap_socket(s, server_hostname=host) as ss:
            folha = ss.getpeercert(binary_form=True)
            try:
                servidos = list(ss.get_unverified_chain())
            except Exception:
                servidos = []
    return folha, servidos


def criar_certificado(cfg: configparser.ConfigParser) -> bool:
    """Cria o arquivo cadeia.pem com os certificados que faltam. True se, depois
    disso, o painel passou a ser aceito."""
    partes = urlparse(cfg.get("servidor", "url", fallback=""))
    host, porta = partes.hostname, partes.port or 443
    if not host:
        print("[sysmon-agent] config.ini sem [servidor] url.")
        return False
    print(f"[sysmon-agent] Criando o certificado para {host} ...")
    try:
        folha, servidos = _certificados_do_servidor(host, porta)
    except OSError as e:
        print(f"[sysmon-agent] Nem a conexão com {host}:{porta} abre: {e}")
        return False

    def ctx_com(pems: list[str]) -> ssl.SSLContext:
        c = ssl.create_default_context()
        if pems:
            c.load_verify_locations(cadata="".join(pems))
        return c

    pems = [ssl.DER_cert_to_PEM_cert(d) for d in servidos[1:]]
    atual = servidos[-1] if len(servidos) > 1 else folha
    if pems and _testar_tls(host, porta, ctx_com(pems)) is None:
        ARQ_CADEIA.write_text("".join(pems), encoding="ascii")
        print(f"[sysmon-agent] Certificado criado: {ARQ_CADEIA}")
        return True

    for _ in range(5):
        baixado = None
        for url in _aia_urls(atual):
            print(f"[sysmon-agent]   baixando {url}")
            baixado = _baixar_certificado(url)
            if baixado:
                break
        if not baixado:
            break
        pems.append(baixado)
        atual = ssl.PEM_cert_to_DER_cert(baixado)
        if _testar_tls(host, porta, ctx_com(pems)) is None:
            ARQ_CADEIA.write_text("".join(pems), encoding="ascii")
            print(f"[sysmon-agent] Certificado criado: {ARQ_CADEIA}")
            return True

    print("[sysmon-agent] Não consegui montar a cadeia sozinho. Isso acontece quando a rede")
    print("[sysmon-agent] do campus troca o certificado do site por um próprio. Peça à TI o")
    print("[sysmon-agent] certificado raiz da rede (arquivo .pem) e coloque o caminho em")
    print("[sysmon-agent] config.ini → [servidor] → ca_arquivo = C:\\caminho\\certificado.pem")
    return False


def coletar_e_enviar(cfg, coletor, codigo: str, label: str, location: str) -> str | None:
    """Coleta e envia. Devolve um resumo, ou None se a coleta deste computador
    está PAUSADA no painel (nesse caso o painel não grava nada)."""
    coletor.resetar_cache_lhm()
    mobo = coletor.placa_mae()
    cpu = coletor.cpu()
    gpu = coletor.gpu()
    ram = coletor.ram()
    disco = coletor.disco()
    rede = coletor.rede()
    uptime_h = coletor.uptime_horas()
    temps = coletor.temperaturas()

    resp = enviar_ao_painel(cfg, {
        "codigo": codigo,
        "label": label,
        "location": location,
        "mobo": mobo,
        "metrica": {"cpu": cpu, "gpu": gpu, "ram": ram, "disco": disco,
                    "rede": rede, "uptime_h": uptime_h},
        "temperaturas": temps,
    })
    if resp.get("pausado"):
        return None
    _registrar_estado(codigo=codigo, ultimo_envio=time.time(),
                      ultimo_envio_texto=f"{datetime.now():%d/%m/%Y %H:%M:%S}")
    return (f"cpu={cpu['usage']}% ram={ram['used']}/{ram['total']}GB "
            f"disco={disco['used']}/{disco['total']}GB")


def _proxies_do_windows() -> list[str]:
    """Mostra a configuração de proxy do Windows (a que o navegador usa)."""
    linhas = []
    try:
        import winreg
        chave = winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                               r"Software\Microsoft\Windows\CurrentVersion\Internet Settings")
        for nome in ("ProxyEnable", "ProxyServer", "ProxyOverride", "AutoConfigURL", "AutoDetect"):
            try:
                linhas.append(f"{nome} = {winreg.QueryValueEx(chave, nome)[0]}")
            except OSError:
                pass
    except Exception:
        linhas.append("(não consegui ler o registro do Windows)")
    env = urllib.request.getproxies()
    if env:
        linhas.append(f"proxies que o Python vai usar: {env}")
    return linhas


def diagnostico(cfg: configparser.ConfigParser, codigo: str) -> int:
    """Testa, etapa por etapa, o caminho até o painel, para achar onde a
    conexão cai (DNS, porta 443, TLS, HTTPS, POST, proxy)."""
    url = cfg.get("servidor", "url", fallback="").strip()
    partes = urlparse(url)
    host, porta, caminho = partes.hostname, partes.port or 443, partes.path or "/"
    proxy = cfg.get("servidor", "proxy", fallback="").strip()
    print()
    print(f"=== Diagnóstico da conexão com o painel ({host}) ===")
    if not host:
        print("config.ini sem [servidor] url.")
        return 1
    if proxy:
        print(f"(usando o proxy do config.ini: {proxy})")
    ctx = _contexto_ssl(cfg)
    falhou = []

    def etapa(nome, fn):
        try:
            print(f"[ OK ] {nome}: {fn()}")
            return True
        except Exception as e:
            print(f"[FALHA] {nome}: {type(e).__name__}: {e}")
            falhou.append(nome)
            return False

    etapa("1) DNS (achar o endereço do painel)",
          lambda: ", ".join(sorted({a[4][0] for a in socket.getaddrinfo(host, porta, proto=socket.IPPROTO_TCP)})))

    def tcp():
        with socket.create_connection((host, porta), timeout=15):
            return f"porta {porta} aberta"
    if not proxy:
        etapa(f"2) Conexão TCP direta na porta {porta}", tcp)

    def tls():
        with socket.create_connection((host, porta), timeout=15) as sk:
            with ctx.wrap_socket(sk, server_hostname=host) as ss:
                return f"{ss.version()}, {ss.cipher()[0]}"
    if not proxy:
        etapa("3) Handshake TLS / certificado", tls)

    def pedir(metodo, corpo=None, cabecalhos=None):
        if proxy:
            pp = urlparse(proxy if "//" in proxy else "//" + proxy)
            conn = http.client.HTTPSConnection(pp.hostname, pp.port or 3128, context=ctx, timeout=20)
            conn.set_tunnel(host, porta)
        else:
            conn = http.client.HTTPSConnection(host, porta, context=ctx, timeout=20)
        h = {"User-Agent": "sysmon-agent/3.0"}
        h.update(cabecalhos or {})
        conn.request(metodo, caminho, body=corpo, headers=h)
        r = conn.getresponse()
        txt = r.read(300).decode("utf-8", "replace").replace("\n", " ")
        conn.close()
        return r.status, txt

    def get():
        st, txt = pedir("GET")
        return f"HTTP {st} (esperado 405) {txt[:80]}"
    etapa("4) Pedido HTTPS simples (GET)", get)

    def post_sem_chave():
        st, txt = pedir("POST", json.dumps({"acao": "status", "codigo": codigo}),
                        {"Content-Type": "application/json", "X-SysMon-Key": "chave-de-teste"})
        return f"HTTP {st} (esperado 401) {txt[:80]}"
    etapa("5) POST com chave errada", post_sem_chave)

    def post_real():
        r = _post(cfg, {"acao": "status", "codigo": codigo})
        return f"painel respondeu {r}"
    etapa("6) POST com a chave do config.ini (como o agente faz)", post_real)

    print()
    print("Proxy do Windows (o que o navegador deste PC usa):")
    for ln in _proxies_do_windows():
        print("   ", ln)

    print()
    if not falhou:
        print(">>> Toda a cadeia funciona. O agente deve conseguir enviar.")
        return 0
    if any(n.startswith("2)") or n.startswith("3)") for n in falhou):
        print(">>> A conexão direta com o painel é bloqueada nesta rede. Se o navegador abre o site,")
        print("    ele está passando por um PROXY. Veja acima (ProxyServer / AutoConfigURL) e coloque")
        print("    no config.ini, em [servidor]:  proxy = http://ENDERECO:PORTA   (a TI informa).")
    elif any(n.startswith("4)") for n in falhou):
        print(">>> O TLS abre, mas o pedido HTTPS é derrubado: filtro/inspeção da rede ou do servidor.")
        print("    Teste no navegador deste PC: https://projeto2tecnolog2.websiteseguro.com/api/agent_ingest.php")
        print("    (deve mostrar \"Método não permitido\"). Se o navegador abre e o Python não, é proxy.")
    elif any(n.startswith("5)") or n.startswith("6)") for n in falhou):
        print(">>> O GET passa, mas o POST do agente é derrubado: a rede ou o servidor bloqueia POST/JSON")
        print("    vindo de programa (não de navegador). Me mande esta tela que eu ajusto o envio.")
    return 1


def _agente_em_execucao() -> int | None:
    """PID de um agente (collector.py) rodando agora neste PC, se houver."""
    try:
        for p in wmi.WMI().Win32_Process():
            cl = p.CommandLine or ""
            if ("collector.py" in cl and p.ProcessId != os.getpid()
                    and not any(f in cl for f in ("--status", "--uma-vez", "--configurar", "--certificado"))):
                return int(p.ProcessId)
    except Exception:
        pass
    return None


def mostrar_status(cfg, coletor, codigo: str, label: str, location: str, corrigir: bool) -> int:
    """Responde: o programa JÁ FOI executado neste computador? Verifica a tarefa
    agendada, se o agente está rodando, o último envio, o certificado HTTPS e o
    que o painel enxerga. Com --corrigir, cria o certificado se for o que falta."""
    ok, nao = "[ OK ]", "[ -- ]"
    print()
    print(f"=== SysMon — situação deste computador ({codigo}) ===")

    try:
        r = subprocess.run(["schtasks", "/query", "/tn", "SysMonAgent", "/fo", "LIST"],
                           capture_output=True, text=True, errors="replace")
        tarefa = r.returncode == 0
    except OSError:
        tarefa = False
    print(f"{ok if tarefa else nao} Tarefa agendada SysMonAgent: "
          f"{'existe' if tarefa else 'NÃO existe (rode install.bat como administrador)'}")

    pid = _agente_em_execucao()
    print(f"{ok if pid else nao} Agente em execução agora: "
          f"{'sim (PID ' + str(pid) + ')' if pid else 'não'}")

    try:
        estado = json.loads(ARQ_ESTADO.read_text(encoding="utf-8"))
    except Exception:
        estado = {}
    envio = estado.get("ultimo_envio")
    if envio:
        seg = int(time.time() - envio)
        print(f"{ok} Último envio deste PC com sucesso: {estado.get('ultimo_envio_texto', '?')} (há {seg}s)")
    else:
        print(f"{nao} Nenhum envio com sucesso registrado neste PC")
    if estado.get("ultima_falha"):
        print(f"{nao} Última falha ({estado.get('ultima_falha_texto', '?')}): {estado['ultima_falha']}")

    partes = urlparse(cfg.get("servidor", "url", fallback=""))
    host, porta = partes.hostname, partes.port or 443
    erro_tls = _testar_tls(host, porta, _contexto_ssl(cfg)) if host else "config.ini sem [servidor] url"
    erro_cert = bool(erro_tls) and "certificate" in erro_tls.lower()
    print(f"{ok if not erro_tls else nao} Certificado HTTPS do painel: "
          f"{'aceito' if not erro_tls else 'PROBLEMA — ' + erro_tls}")
    if ARQ_CADEIA.is_file():
        print(f"{ok} Certificado criado pelo agente: {ARQ_CADEIA.name}")

    if erro_tls and erro_cert and corrigir:
        print()
        if criar_certificado(cfg):
            erro_tls = None
            print(f"{ok} Certificado criado e aceito. Fazendo um envio de teste...")
            try:
                resumo = coletar_e_enviar(cfg, coletor, codigo, label, location)
                if resumo is None:
                    print(f"{ok} O painel respondeu que a coleta está pausada (nada foi gravado).")
                else:
                    print(f"{ok} Enviado ao painel ({resumo}).")
                    envio = time.time()
            except Exception as e:
                print(f"{nao} O envio de teste falhou: {e}")

    visto = None
    pausado_painel = False
    if not erro_tls:
        try:
            r = _post(cfg, {"acao": "status", "codigo": codigo, "label": label})
            pausado_painel = bool(r.get("pausado"))
            if pausado_painel:
                quem = f" por {r['pausada_por']}" if r.get("pausada_por") else ""
                quando = f" em {r['pausada_em']}" if r.get("pausada_em") else ""
                print(f"{nao} Painel: a coleta deste PC está PAUSADA{quem}{quando}")
            elif not r.get("existe"):
                print(f"{nao} Painel: este computador ainda NÃO está cadastrado")
            elif r.get("segundos_desde_ultimo") is None:
                print(f"{nao} Painel: cadastrado (patrimônio/sala da planilha), mas nunca recebeu dados — 'Sem agente'")
            else:
                visto = int(r["segundos_desde_ultimo"])
                print(f"{ok} Painel: recebeu dados deste PC há {visto}s"
                      + (f" — sala: {r['sala']}" if r.get("sala") else ""))
        except ErroEnvio as e:
            print(f"{nao} Painel: não consegui consultar ({e})")

    print()
    if pausado_painel:
        print(">>> A coleta está PAUSADA pelo painel, de propósito. O computador aparece como")
        print("    offline (sem alertas) até alguém clicar em \"Retomar coleta\" no painel.")
        return 0
    if visto is not None and visto < 180:
        print(">>> O programa JÁ FOI EXECUTADO e o painel está recebendo os dados.")
        if not tarefa and not pid:
            print("    ATENÇÃO: não há tarefa agendada nem agente rodando — foi só um envio isolado.")
            print("    Para ele iniciar sozinho a cada vez que o PC ligar, rode install.bat como administrador.")
            return 1
        return 0
    if visto is not None or envio:
        print(">>> O programa JÁ FOI EXECUTADO, mas não está enviando agora.")
        print("    Para iniciar:  schtasks /run /tn \"SysMonAgent\"")
        return 1
    print(">>> O programa NÃO FOI EXECUTADO com sucesso neste computador.")
    if erro_tls and erro_cert and not corrigir:
        print("    O motivo parece ser o certificado. Rode:  python collector.py --certificado")
    elif erro_tls and not erro_cert:
        print("    Sem acesso ao painel (rede/firewall/proxy). Rode:  python collector.py --diagnostico")
    elif not tarefa:
        print("    Rode install.bat como administrador para instalar e iniciar o agente.")
    return 1


def main():
    parser = argparse.ArgumentParser(description="Agente coletor SysMon (WMI -> painel por HTTPS)")
    parser.add_argument(
        "--config",
        default=str(Path(__file__).with_name("config.ini")),
        help="Caminho do arquivo config.ini",
    )
    parser.add_argument(
        "--configurar",
        action="store_true",
        help="Pergunta o número de patrimônio e grava no config.ini",
    )
    parser.add_argument(
        "--uma-vez",
        action="store_true",
        help="Faz uma única coleta, envia ao painel, mostra o resultado e sai (teste)",
    )
    parser.add_argument(
        "--status",
        action="store_true",
        help="Mostra se o programa JÁ FOI executado neste computador e o que falta",
    )
    parser.add_argument(
        "--corrigir",
        action="store_true",
        help="Com --status: se o problema for o certificado, cria o certificado e testa de novo",
    )
    parser.add_argument(
        "--diagnostico",
        action="store_true",
        help="Testa etapa por etapa a conexão com o painel (DNS, porta, TLS, HTTPS, proxy)",
    )
    parser.add_argument(
        "--certificado",
        action="store_true",
        help="Cria o certificado (cadeia.pem) que falta para o HTTPS do painel",
    )
    args = parser.parse_args()

    if args.configurar:
        configurar_patrimonio(args.config)
        return

    cfg = carregar_config(args.config)
    codigo, label, location = resolver_identidade(cfg)
    intervalo = cfg.getint("coleta", "intervalo_segundos", fallback=15)

    if args.certificado:
        sys.exit(0 if criar_certificado(cfg) else 1)

    if args.diagnostico:
        sys.exit(diagnostico(cfg, codigo))

    coletor = ColetorWMI()

    if args.status:
        sys.exit(mostrar_status(cfg, coletor, codigo, label, location, args.corrigir))

    if args.uma_vez:
        print(f"[sysmon-agent] Teste: coletando '{codigo}' e enviando ao painel...")
        for tentativa in (1, 2):
            try:
                resumo = coletar_e_enviar(cfg, coletor, codigo, label, location)
                break
            except ErroCertificado as e:
                print(f"[sysmon-agent] {e}")
                if tentativa == 1 and criar_certificado(cfg):
                    print("[sysmon-agent] Tentando enviar de novo com o certificado criado...")
                    continue
                print("[sysmon-agent] FALHOU: certificado não aceito.", file=sys.stderr)
                sys.exit(1)
            except ErroEnvio as e:
                print(f"[sysmon-agent] FALHOU: {e}", file=sys.stderr)
                sys.exit(1)
            except Exception as e:
                print(f"[sysmon-agent] FALHOU na coleta: {e}", file=sys.stderr)
                sys.exit(1)
        if resumo is None:
            print(f"[sysmon-agent] A coleta de '{codigo}' está PAUSADA no painel (nada foi gravado).")
            print("[sysmon-agent] Clique em \"Retomar coleta\" no painel para voltar a monitorar.")
            return
        print(f"[sysmon-agent] OK — o painel recebeu os dados de '{codigo}' ({resumo}).")
        return

    print(f"[sysmon-agent] Iniciando coleta para '{codigo}' a cada {intervalo}s. Ctrl+C para parar.")

    certificado_ja_tentado = False
    pausado = False
    while True:
        inicio = time.time()
        try:
            if pausado:
                pausado = bool(_post(cfg, {"acao": "status", "codigo": codigo}).get("pausado"))
                if pausado:
                    time.sleep(60)
                    continue
                print(f"[sysmon-agent] {datetime.now():%H:%M:%S} Coleta RETOMADA pelo painel.")
            resumo = coletar_e_enviar(cfg, coletor, codigo, label, location)
            if resumo is None:
                pausado = True
                print(f"[sysmon-agent] {datetime.now():%H:%M:%S} Coleta PAUSADA pelo painel. "
                      "Verificando a cada 60s se foi retomada.")
                time.sleep(60)
                continue
            print(f"[sysmon-agent] {datetime.now():%H:%M:%S} OK {resumo}")
        except ErroCertificado as e:
            print(f"[sysmon-agent] ERRO ao enviar ao painel: {e}", file=sys.stderr)
            _registrar_estado(ultima_falha=str(e), ultima_falha_texto=f"{datetime.now():%d/%m/%Y %H:%M:%S}")
            if not certificado_ja_tentado:
                certificado_ja_tentado = True
                criar_certificado(cfg)
        except ErroEnvio as e:
            print(f"[sysmon-agent] ERRO ao enviar ao painel: {e}", file=sys.stderr)
            _registrar_estado(ultima_falha=str(e), ultima_falha_texto=f"{datetime.now():%d/%m/%Y %H:%M:%S}")
        except Exception as e:
            print(f"[sysmon-agent] ERRO na coleta: {e}", file=sys.stderr)

        decorrido = time.time() - inicio
        time.sleep(max(1.0, intervalo - decorrido))


if __name__ == "__main__":
    main()
