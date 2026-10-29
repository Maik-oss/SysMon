# Agente coletor SysMon (Windows)

Este agente roda em **cada computador Windows** que você quer ver no
painel. Ele lê CPU/RAM/disco/rede/GPU/uptime via **WMI** e **envia ao
painel por HTTPS** (`api/agent_ingest.php`, porta 443, a mesma do site).
Só o servidor do painel grava no MySQL: o agente **não acessa o banco** e
o computador **não guarda a senha do banco**. O site não simula mais
nada — só mostra o que o agente enviou.

> Antes, o agente gravava direto no MySQL (porta 3306). Redes como a do
> campus bloqueiam essa porta (erro `2003: Can't connect to MySQL server …
> timed out`); a 443 passa, porque é a mesma que abre o painel.

## Modo rápido (recomendado) — `install.bat`

1. Copie a pasta `agent/` para o computador que quer monitorar.
2. Clique com o botão direito em `install.bat` → **Executar como
   administrador**.

Isso já resolve os dois pontos manuais:
- **Identificação do PC**: `config.ini` vem com `codigo = auto` e
  `label = auto` — o agente usa o nome do computador (hostname)
  automaticamente, sem você digitar nada.
- **Início automático**: o instalador registra uma Tarefa Agendada
  do Windows para o agente iniciar sozinho toda vez que o PC ligar —
  não precisa deixar nenhuma janela aberta manualmente.

Se quiser um código diferente do hostname, ou preencher o campo
`location` (local físico do PC, que o Windows não sabe informar
sozinho), edite `config.ini` antes de rodar `install.bat`.

Para testar sem reiniciar o computador:
```bat
schtasks /run /tn "SysMonAgent"
```

---

## Modo manual (passo a passo, se preferir controlar tudo)

## 1. Instalar dependências

No computador monitorado (Windows), com Python 3.9+ instalado:

```bat
cd agent
pip install -r requirements.txt
```

## 2. Configurar

Edite `config.ini`:

```ini
[computador]
codigo = CM000002          ; único por máquina!
label = Workstation RH
location = Sala 2

[servidor]
url = https://projeto2tecnolog2.websiteseguro.com/api/agent_ingest.php
chave = (a mesma chave de includes/agent_config.php no servidor)

[coleta]
intervalo_segundos = 15
```

> Cada máquina precisa do seu próprio `codigo` (CM000001, CM000002...).
> Se dois agentes usarem o mesmo código, um vai sobrescrever o "último
> estado" do outro no painel.

## 3. Testar manualmente

```bat
python collector.py
```

Você deve ver linhas tipo:

```
[sysmon-agent] 14:32:10 OK cpu=23.4% ram=6.1/16.0GB disco=180.0/476.0GB
```

Abra o dashboard no navegador — o computador deve aparecer "online"
em poucos segundos.

## 4. Deixar rodando sempre (produção)

Escolha uma opção:

### Opção A — Agendador de Tarefas do Windows (mais simples)
1. Abra o **Agendador de Tarefas**.
2. Criar Tarefa → aba **Disparadores**: "Ao iniciar o computador".
3. Aba **Ações**: Programa = `pythonw.exe` (do seu ambiente), Argumentos =
   caminho completo para `collector.py`.
4. Marque "Executar estando o usuário conectado ou não".

Como o script roda em loop infinito (`while True`), ele fica coletando
continuamente enquanto a tarefa estiver ativa.

### Opção B — Serviço do Windows com NSSM
1. Baixe o [NSSM](https://nssm.cc/).
2. `nssm install SysMonAgent`
3. Aponte para o `python.exe` do seu ambiente, com o argumento sendo
   o caminho de `collector.py`.
4. `nssm start SysMonAgent`

## Sobre os campos que ficam em branco (NULL)

- **Temperaturas**: o Windows só expõe temperatura real por WMI numa
  classe (`MSAcpi_ThermalZoneTemperature`) que funciona em parte dos
  notebooks — na maioria dos desktops/servidores ela não existe. Para
  ter temperaturas reais de CPU/placa-mãe em qualquer máquina, instale
  o [LibreHardwareMonitor](https://github.com/LibreHardwareMonitor/LibreHardwareMonitor)
  (gratuito) na máquina monitorada, deixe-o aberto e habilite
  **Options → Remote Web Server → Run** (ele expõe os sensores em
  `http://localhost:8085/data.json`, porta padrão). O agente lê esse
  endereço automaticamente. Não usamos o WMI nativo do
  LibreHardwareMonitor porque ele tem um bug conhecido em várias
  versões recentes e não retorna dados de forma confiável. Sem o
  LibreHardwareMonitor rodando, os campos de temperatura ficam `NULL`
  (o painel mostra "—") em vez de números inventados.
- **Uso/VRAM/temperatura de GPU**: só disponíveis se a máquina tiver
  GPU NVIDIA com `nvidia-smi` no PATH. O **modelo** da GPU sempre
  aparece (isso vem do WMI padrão).
- **Potência da fonte (PSU)**: não existe sensor padrão de watts no
  WMI de PCs comuns — fica sempre `NULL`, a menos que você depois
  integre outra fonte de dados específica da sua placa-mãe/fonte.

## Testar a comunicação com o painel

```bat
python collector.py --uma-vez
```
Faz uma única coleta, envia ao painel e mostra o resultado (`OK` ou o
motivo da falha: sem conexão, chave diferente da do servidor, etc.). O
`install.bat` já roda esse teste no final.

