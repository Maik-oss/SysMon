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

## 🚀 PASSO A PASSO RÁPIDO — PC do campus e notebook

> ### ⚡ Vale para **qualquer computador Windows**: PC do campus ou notebook
>
> **1.** Copie a pasta `agent/` para o computador.
>
> **2.** Abra o **CMD** (tecla Windows → digite `cmd` → Enter).
>
> **3.** Entre na pasta do agente:
> ```bat
> cd agent
> ```
> *(se a pasta estiver em outro lugar, use o caminho completo, por exemplo `cd C:\Users\SeuUsuario\Desktop\agent`)*
>
> **4.** Instale as dependências:
> ```bat
> pip install -r requirements.txt
> ```
>
> **5.** Execute o agente:
> ```bat
> python collector.py
> ```
>
> ✅ Deve aparecer uma linha como `[sysmon-agent] 14:32:10 OK cpu=23.4% ...`.
> Abra o painel no navegador: o computador aparece **online** em poucos segundos.
>
> **Observações**
> - Mantenha a janela do CMD **aberta**: se fechar, o agente para de enviar dados.
> - Requer **Python 3.9+** instalado com a opção *Add python.exe to PATH* marcada.
> - O computador é identificado pelo nome do Windows (`codigo = auto` no `config.ini`).
>   Para informar o patrimônio, rode antes `python collector.py --configurar`.
> - No **notebook**, o agente funciona em qualquer rede com internet (envia por HTTPS, porta 443),
>   inclusive fora do campus.
> - Para o agente iniciar sozinho sempre que o PC ligar, use o `install.bat` (veja abaixo).

---

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

## Saber se o programa já foi executado neste PC: `verificar.bat`

Dê um duplo clique em `verificar.bat` (ou rode `python collector.py --status`).
Ele mostra, em linhas `[ OK ]` / `[ -- ]`:

- se a tarefa agendada `SysMonAgent` existe;
- se o agente está rodando agora;
- quando foi o último envio com sucesso deste PC;
- se o certificado HTTPS do painel é aceito;
- o que o **painel** enxerga (se já recebeu dados deste PC e a sala dele).

No fim, diz se o programa **já foi executado** ou **não foi**. Se não foi e o
motivo for o certificado, o `verificar.bat` **cria o certificado sozinho**
(`--corrigir`) e faz um envio de teste.

## Erro `CERTIFICATE_VERIFY_FAILED ... unable to get local issuer certificate`

O site não envia o certificado **intermediário** e o navegador o busca sozinho;
o Python não. O agente faz o mesmo: baixa o que falta e guarda em
`cadeia.pem` (nesta pasta). Isso acontece automaticamente no `install.bat`,
no `verificar.bat` e na primeira falha do agente, ou manualmente:

```bat
python collector.py --certificado
```

Se não funcionar (a rede do campus troca o certificado do site por um próprio),
peça à TI o certificado raiz da rede (`.pem`) e use no `config.ini`:

```ini
[servidor]
ca_arquivo = C:\caminho\certificado.pem
```

Último recurso, **inseguro** (não confere o certificado; a chave pode ser
interceptada): `verificar_ssl = nao` na seção `[servidor]`.

## Erro `WinError 10054` (conexão derrubada) e rede com proxy

`Foi forçado o cancelamento de uma conexão existente pelo host remoto` quer
dizer que algo no caminho (firewall, proxy ou filtro da rede) derrubou a
conexão. Rode `verificar.bat` (ele chama o diagnóstico) ou:

```bat
python collector.py --diagnostico
```

Ele testa DNS, porta 443, certificado, GET, POST e mostra o **proxy do
Windows**. Se o navegador abre o painel mas o agente não, o navegador está
passando por um proxy. Informe o proxy no `config.ini` (a TI diz qual é):

```ini
[servidor]
proxy = http://ENDERECO-DO-PROXY:PORTA
```

## Sem ser administrador

Se `install.bat` não for executado como administrador, ele não consegue criar
a tarefa agendada. Nesse caso cria um atalho de inicialização na pasta
Inicializar do usuário (o agente inicia quando o usuário entra no Windows) e
já inicia o agente. O ideal continua sendo executar como administrador.

## Patrimônio com letras

O número de patrimônio pode ter **letras e números** (de 3 a 10 caracteres,
sem espaços ou símbolos), por exemplo `406652` ou um patrimônio alterado como
`1M2345`. O instalador (`install.bat`) e o `config.ini` (`patrimonio = ...`)
aceitam os dois; as letras são gravadas em maiúsculas e o computador aparece
no painel como `CM` + patrimônio (por exemplo, `CM1M2345`). Computadores que
não estão na planilha entram no painel sem sala e podem ser ligados ao cadastro
pelo script de importação (lista de vínculos).

> **Digite só o patrimônio, sem o "CM".** O painel coloca o `CM` sozinho
> (patrimônio `439647` → computador `CM439647`). Se o `CM` for digitado, o
> instalador e o agente o retiram; o servidor também junta qualquer
> `CMCM...` com o cadastro certo (`CM...`), em vez de criar um computador
> duplicado.

## Pausar a coleta de um computador

No painel, abra o computador e clique em **Pausar coleta**. Enquanto estiver
pausado:

- o painel **não grava** mais as leituras dele e ele aparece como **Pausado**
  (offline), **sem gerar alertas nem e-mails**;
- o agente **para de coletar** e só pergunta ao painel, a cada 1 minuto, se já
  foi retomado (o programa continua aberto no PC);
- os alertas que estavam abertos são encerrados.

Para voltar, clique em **Retomar coleta**: o agente volta a enviar em até
1 minuto. Fica registrado quem pausou e quando. Qualquer usuário logado pode
pausar e retomar. Para ver todos os pausados: lista de computadores → filtro
**Só com coleta pausada**.

