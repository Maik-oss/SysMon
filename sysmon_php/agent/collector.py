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
import configparser
import json
import platform
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path

try:
    import wmi  # type: ignore
except ImportError:
    sys.exit(
        "O módulo 'wmi' não está instalado (ou este script não está "
        "rodando no Windows). Rode: pip install -r requirements.txt"
    )

# ─────────────────────────── Configuração ───────────────────────────

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


def resolver_identidade(cfg: configparser.ConfigParser) -> tuple[str, str, str]:
    codigo_cfg = cfg.get("computador", "codigo", fallback="auto").strip()
    label_cfg = cfg.get("computador", "label", fallback="auto").strip()
    location = cfg.get("computador", "location", fallback="")
    patrimonio = re.sub(r"\D", "", cfg.get("computador", "patrimonio", fallback=""))

    auto_codigo, auto_label = auto_identidade()
    if codigo_cfg.lower() in ("", "auto"):
        # Com o patrimônio informado, o código é CM + patrimônio (ex.: CM406652),
        # o mesmo que o painel usa na lista importada da planilha do campus.
        codigo = f"CM{patrimonio}" if patrimonio else auto_codigo
    else:
        codigo = codigo_cfg
    label = auto_label if label_cfg.lower() in ("", "auto") else label_cfg
    return codigo, label, location


def configurar_patrimonio(caminho: str) -> None:
    """Pergunta o número de patrimônio e grava no config.ini (chamado pelo
    install.bat). Mantém os comentários do arquivo."""
    print()
    print("=== Numero de patrimonio deste computador (6 digitos, ex.: 406652) ===")
    while True:
        pat = input("Patrimonio (ENTER para pular): ").strip()
        if pat == "" or re.fullmatch(r"\d{6}", pat):
            break
        print("Use exatamente 6 numeros.")
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


# ─────────────────────────── Coleta via WMI ──────────────────────────

class ColetorWMI:
    def __init__(self):
        self.c = wmi.WMI()
        # Cache da árvore JSON do LibreHardwareMonitor: várias leituras
        # (CPU, GPU, placa-mãe, armazenamento) usam a mesma consulta HTTP
        # dentro de um mesmo ciclo de coleta — busca uma vez só e reusa.
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
            self._lhm_cache = False  # marca "já tentei nesse ciclo e falhou"
        return self._lhm_cache or None

    # -- CPU -----------------------------------------------------
    def cpu(self) -> dict:
        modelo = None
        freq_ghz = None
        for p in self.c.Win32_Processor():
            modelo = (p.Name or "").strip()
            if p.CurrentClockSpeed:
                freq_ghz = round(p.CurrentClockSpeed / 1000, 2)
            break  # pega o primeiro processador físico

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

        cores.sort(key=lambda _: 0)  # mantém ordem de leitura (núcleos 0..N)
        return {
            "usage": uso_total if uso_total is not None else 0.0,
            "model": modelo,
            "freq": freq_ghz,
            "cores": cores,
        }

    # -- RAM -------------------------------------------------------
    def ram(self) -> dict:
        for so in self.c.Win32_OperatingSystem():
            total_kb = float(so.TotalVisibleMemorySize or 0)
            livre_kb = float(so.FreePhysicalMemory or 0)
            total_gb = round(total_kb / 1024 / 1024, 1)
            usado_gb = round((total_kb - livre_kb) / 1024 / 1024, 1)
            return {"used": usado_gb, "total": total_gb}
        return {"used": 0.0, "total": 0.0}

    # -- Disco (soma de todos os discos fixos) ----------------------
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

    # -- Rede (soma de todas as interfaces ativas, exceto loopback) -
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

    # -- Uptime em horas ---------------------------------------------
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

    # -- Placa-mãe: modelo/fabricante/BIOS via WMI puro ----------------
    # Diferente de temperatura/fan (que dependem do LibreHardwareMonitor
    # e do chip Super I/O da placa), isso é informação estática que o
    # Windows sempre expõe via WMI padrão — funciona em qualquer PC ou
    # notebook, sem depender de programa nenhum instalado.
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

    # -- GPU: modelo sempre via WMI; uso/VRAM/temp via nvidia-smi        --
    # (NVIDIA) com fallback para LibreHardwareMonitor (AMD / Intel Arc) --
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
            # Sem nvidia-smi (GPU AMD/Intel Arc, ou NVIDIA sem driver
            # completo instalado): tenta o LibreHardwareMonitor, que lê
            # os sensores de qualquer fabricante de GPU da mesma forma.
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

    # -- Temperaturas: LibreHardwareMonitor (se instalado) -> ACPI --
    def temperaturas(self) -> dict:
        via_lhm = self._temps_libre_hardware_monitor()
        if via_lhm:
            return via_lhm
        via_acpi = self._temps_acpi()
        if via_acpi:
            return via_acpi
        # Nenhuma fonte de temperatura disponível nesta máquina.
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

        # Palavras que identificam um nó de hardware como uma unidade de
        # armazenamento (SSD/NVMe/HDD, de qualquer marca — Samsung, WD,
        # Kingston, Crucial, Seagate...) no LibreHardwareMonitor. O nome
        # exato do disco (ex.: "Samsung SSD 970 EVO Plus 500GB") aparece no
        # nó pai do grupo de sensores, não no sensor em si — por isso
        # precisamos descer a árvore levando esse "rótulo do hardware
        # atual" junto. Essa checagem já é genérica por fabricante, pois
        # se baseia no TIPO de dispositivo (ssd/nvme/hdd), não na marca.
        PALAVRAS_ARMAZENAMENTO = ("ssd", "nvme", "hdd", "hd ", " hd", "disk", "drive")

        def parece_armazenamento(nome_hw: str) -> bool:
            n = nome_hw.lower()
            return any(p in n for p in PALAVRAS_ARMAZENAMENTO)

        def visitar(no, hw_atual=None):
            texto = no.get("Text", "")
            # Nós de hardware físico (Motherboard, CPU, GPU, unidades de disco...)
            # ficam logo abaixo da raiz/computador e não têm "Type" de sensor
            # (Temperature/Load/Clock/...). Ao entrar num desses, atualizamos
            # qual é o "hardware atual" para os sensores filhos herdarem.
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

        # -- CPU: rótulo do sensor "principal" muda por fabricante -----
        # Intel:            "CPU Package"
        # AMD (Ryzen/Zen):  "Core (Tctl/Tdie)", "CPU Core (Tctl/Tdie)"
        #                   (às vezes só "Tctl" ou só "Tdie")
        # Alguns chipsets:  "CPU CCD1 (Tdie)" / "CPU CCD2 (Tdie)" — são
        #                   temperaturas por chiplet, não o valor geral.
        def eh_cpu_geral(n: str) -> bool:
            if n == "cpu package":
                return True
            if "tctl" in n and "tdie" in n:
                return True
            if "ccd" not in n and ("tctl" in n or "tdie" in n):
                return True
            return False

        # -- GPU: "GPU Core"/"GPU Hot Spot" (NVIDIA/AMD/Intel Arc) ou
        # apenas "GPU" em placas mais simples/integradas.
        def eh_gpu_temp(n: str) -> bool:
            return "gpu core" in n or "gpu hot spot" in n or n == "gpu"

        def eh_gpu_memoria(n: str) -> bool:
            return "gpu memory" in n or "memory junction" in n

        # -- Placa-mãe: "Mainboard"/"Motherboard" (nome do sensor não
        # muda por fabricante de chipset), com fallback para variações
        # como "System" ou "PCH"/"Chipset" usadas por algumas placas.
        def eh_placa_mae(n: str) -> bool:
            return any(p in n for p in ("mainboard", "motherboard", "system", "chipset", "pch"))

        cpu_pkg = gpu_die = gpu_mem = mobo = None
        cpu_pkg_fallback = None  # sensor secundário (ex.: CCD isolado) só usado se não achar o principal
        cpu_cores = []
        armazenamento = {}  # nome do disco -> temperatura (primeiro sensor encontrado para cada um)

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
                # Chiplet isolado (AMD Ryzen multi-CCD): guarda como
                # aproximação só se nenhum sensor "geral" for encontrado.
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
                # Guarda só a primeira leitura de cada disco (geralmente a
                # "Temperature"/"Composite" principal) — discos NVMe às vezes
                # expõem vários sensores extras que não interessam aqui.
                armazenamento.setdefault(hw_atual, v)

        if cpu_pkg is None:
            cpu_pkg = cpu_pkg_fallback

        discos = list(armazenamento.items())
        nvme_1 = discos[0][1] if len(discos) > 0 else None
        nvme_2 = discos[1][1] if len(discos) > 1 else None

        # Fans: RPM vem sem casas decimais. Separa o fan da CPU (nome
        # geralmente contém "cpu") do primeiro fan de sistema/gabinete
        # encontrado — placas com vários fans de gabinete só mostram
        # o primeiro, pra manter a UI simples.
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
                continue  # 0 RPM normalmente é sensor presente mas fan parado/ausente, não confiável
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

        # Faixa plausível de temperatura de CPU/gabinete. Fora disso,
        # é sensor ausente/quebrado devolvendo 0 ou lixo — não grava
        # número inventado (ex.: -273°C = zero absoluto, sensor vazio).
        if celsius < -20 or celsius > 120:
            return None

        return {
            "cpu_package": celsius, "cpu_cores": [], "gpu_die": None,
            "gpu_mem": None, "nvme_1": None, "nvme_2": None,
            "psu": None, "motherboard": None, "ambient": None,
            "fan_cpu_rpm": None, "fan_sys_rpm": None,
        }


# ─────────────────────────── Gravação no MySQL ───────────────────────

class ErroEnvio(Exception):
    """Falha ao entregar os dados ao painel (rede, chave ou servidor)."""


def enviar_ao_painel(cfg: configparser.ConfigParser, payload: dict) -> None:
    """Envia a leitura ao painel por HTTPS (porta 443). O agente não fala
    mais com o MySQL: só o servidor do painel grava no banco."""
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
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            corpo = resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        try:
            msg = json.loads(e.read().decode("utf-8", "replace")).get("error", "")
        except Exception:
            msg = ""
        dica = " (chave diferente da do servidor?)" if e.code == 401 else ""
        raise ErroEnvio(f"o painel respondeu HTTP {e.code}: {msg or e.reason}{dica}")
    except urllib.error.URLError as e:
        raise ErroEnvio(f"sem conexão com o painel: {e.reason}")
    except OSError as e:
        raise ErroEnvio(f"sem conexão com o painel: {e}")
    try:
        ok = json.loads(corpo).get("ok") is True
    except ValueError:
        ok = False
    if not ok:
        raise ErroEnvio(f"resposta inesperada do painel: {corpo[:200]}")


def coletar_e_enviar(cfg, coletor, codigo: str, label: str, location: str) -> str:
    coletor.resetar_cache_lhm()
    mobo = coletor.placa_mae()
    cpu = coletor.cpu()
    gpu = coletor.gpu()
    ram = coletor.ram()
    disco = coletor.disco()
    rede = coletor.rede()
    uptime_h = coletor.uptime_horas()
    temps = coletor.temperaturas()

    enviar_ao_painel(cfg, {
        "codigo": codigo,
        "label": label,
        "location": location,
        "mobo": mobo,
        "metrica": {"cpu": cpu, "gpu": gpu, "ram": ram, "disco": disco,
                    "rede": rede, "uptime_h": uptime_h},
        "temperaturas": temps,
    })
    return (f"cpu={cpu['usage']}% ram={ram['used']}/{ram['total']}GB "
            f"disco={disco['used']}/{disco['total']}GB")


# ─────────────────────────── Loop principal ──────────────────────────

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
    args = parser.parse_args()

    if args.configurar:
        configurar_patrimonio(args.config)
        return

    cfg = carregar_config(args.config)
    codigo, label, location = resolver_identidade(cfg)
    intervalo = cfg.getint("coleta", "intervalo_segundos", fallback=15)

    coletor = ColetorWMI()

    if args.uma_vez:
        print(f"[sysmon-agent] Teste: coletando '{codigo}' e enviando ao painel...")
        try:
            resumo = coletar_e_enviar(cfg, coletor, codigo, label, location)
        except ErroEnvio as e:
            print(f"[sysmon-agent] FALHOU: {e}", file=sys.stderr)
            sys.exit(1)
        except Exception as e:
            print(f"[sysmon-agent] FALHOU na coleta: {e}", file=sys.stderr)
            sys.exit(1)
        print(f"[sysmon-agent] OK — o painel recebeu os dados de '{codigo}' ({resumo}).")
        return

    print(f"[sysmon-agent] Iniciando coleta para '{codigo}' a cada {intervalo}s. Ctrl+C para parar.")

    while True:
        inicio = time.time()
        try:
            resumo = coletar_e_enviar(cfg, coletor, codigo, label, location)
            print(f"[sysmon-agent] {datetime.now():%H:%M:%S} OK {resumo}")
        except ErroEnvio as e:
            print(f"[sysmon-agent] ERRO ao enviar ao painel: {e}", file=sys.stderr)
        except Exception as e:
            print(f"[sysmon-agent] ERRO na coleta: {e}", file=sys.stderr)

        decorrido = time.time() - inicio
        time.sleep(max(1.0, intervalo - decorrido))


if __name__ == "__main__":
    main()
