# 🖥️ SysMon — Network Operations Center (PHP + MySQL + Agente WMI)

Sistema de monitoramento de computadores com interface NOC. O site em
PHP **não simula mais nada**: ele só lê dados reais, enviados por um
agente Python que roda em cada máquina monitorada e coleta hardware real
via WMI (Windows Management Instrumentation). O agente envia os dados ao
site por **HTTPS** (`api/agent_ingest.php`); só o PHP grava no MySQL.

```
┌────────────────┐        grava métricas         ┌───────────────┐
│ agente Python   │ ── HTTPS (porta 443) ──────▶ │ api/agent_ingest│
│ (roda no PC     │   a cada 15s, com chave      │ (PHP) → MySQL  │
│  monitorado,    │                                │                │
│  lê via WMI)     │                                └───────┬───────┘
└────────────────┘                                          │ lê últimas linhas
                                                              ▼
                                                     ┌────────────────┐
                                                     │ site PHP        │
                                                     │ (só exibe)      │
                                                     └────────────────┘
```

---

## 📁 Estrutura

```
sysmon_php/
├── index.php                 # Redireciona para login ou dashboard
├── login.php                 # Tela de login
├── dashboard.php             # Painel principal
├── includes/
│   ├── config.php            # Credenciais do banco (editar aqui)
│   ├── mail_config.php       # Configuração do envio de e-mail (SMTP)
│   ├── mailer.php            # Envio de e-mail (cliente SMTP próprio, sem bibliotecas)
│   ├── db.php                # Conexão PDO com o MySQL
│   └── data.php               # Helpers + consultas ao banco (sem simulação)
├── api/
│   ├── login.php              # POST → autentica contra a tabela usuarios
│   ├── logout.php             # POST → destrói sessão
│   ├── computers.php          # GET  → busca/detalhe de computador (do banco)
│   ├── metrics.php            # GET  → última métrica real (?code=CM000001)
│   ├── temperatures.php       # GET  → última temperatura real (?code=CM000001)
│   ├── maintenance.php        # GET/POST → histórico de manutenção de um computador
│   ├── email_settings.php     # GET/POST → e-mail do usuário logado (+ e-mail de teste)
│   └── user_email.php         # POST → admin cadastra/altera e-mail de outro usuário
├── cron/
│   └── sync_alertas.php       # (opcional) dispara alertas/e-mails sem ninguém com o painel aberto
├── banco_de_dados/
│   ├── schema.sql             # Cria as tabelas no banco piprojeto2
│   └── criar_admin.php        # Roda 1x para criar o usuário do painel
├── agent/
│   ├── collector.py           # Agente: lê hardware via WMI, envia ao painel por HTTPS
│   ├── config.ini             # Configuração por máquina monitorada
│   ├── requirements.txt
│   └── README.md              # Como instalar/rodar o agente
└── static/                    # CSS/JS do front-end (inalterado)
```

---

## ⚙️ Como colocar no ar

### 1. Banco de dados
No painel do provedor (ou via cliente MySQL), rode:
```bash
mysql -h piprojeto2.mysql.dbaas.com.br -u piprojeto2 -p piprojeto2 < banco_de_dados/schema.sql
```
> Confirme o **usuário** do banco no painel do provedor — em
> `includes/config.php` assumi que é igual ao nome do banco
> (`piprojeto2`), padrão comum em provedores DBaaS. Ajuste se for
> diferente.

### 2. Criar o usuário do painel
Depois do passo acima, rode uma vez (via navegador ou terminal com PHP):
```
http://SEU_SITE/banco_de_dados/criar_admin.php
```
Isso cria o login `admin` / `admin123` (edite o script para trocar
usuário/senha antes de rodar). **Apague o arquivo depois de usar.**

### 3. Subir o site
```bash
cd sysmon_php
php -S localhost:8000
# ou aponte o DocumentRoot do Apache/Nginx para esta pasta (PHP 8.1+)
```

### 4. Instalar o agente em cada computador monitorado
Veja `agent/README.md` — resumindo:
```bat
cd agent
pip install -r requirements.txt
:: edite config.ini com o código/label/local dessa máquina
python collector.py
```
Repita em cada PC que deve aparecer no painel, com um `codigo` único
por máquina (CM000001, CM000002, ...).

---

## 🆕 Novidades

### Gráfico de temperaturas (Monitoramento Geral) sempre visível
O gráfico **não some mais** quando nenhum coletor está ativo: ele é sempre
desenhado (eixos + linha do limite de 85°C) e mostra um aviso dentro dele
("Nenhum coletor ativo…" / "Nenhum computador cadastrado"). PCs offline
aparecem esmaecidos, com o rótulo *offline* e o último valor registrado.

### Histórico de manutenção
Na tela de um computador há a aba **Manutenção** (funciona mesmo com o PC
offline). Qualquer usuário logado registra: tipo (preventiva, corretiva,
limpeza, troca de peça, atualização, outro), **data/hora em que foi feita** e
a descrição. O sistema grava **quem registrou** (usuário logado) e quando.
Só administradores podem excluir um registro. Tabela: `manutencoes`.

### E-mail dos usuários e notificações por e-mail
- Os administradores já vêm com e-mail cadastrado:
  `maikson` → maikson.santiago@escolar.ifrn.edu.br,
  `samuel` → samuel.pedrosa@escolar.ifrn.edu.br,
  `matheus` → m.gessle@escolar.ifrn.edu.br
  (definido em `ADMIN_DEFAULT_EMAILS`, `includes/data.php`; só preenche se o
  campo estiver vazio, então se o admin trocar o próprio e-mail, a troca vale).
  O admin `pedrosa` (que existe em `ADMIN_USERNAMES`) não tem e-mail padrão —
  ele cadastra o dele em Configurações.
- **Todo usuário novo precisa ter e-mail**: a tela *Usuários* agora exige o
  campo. O admin também pode cadastrar/alterar o e-mail de usuários já
  existentes (botão na lista). Cada pessoa edita o próprio e-mail, liga/desliga
  os avisos e envia um e-mail de teste em *Configurações*.
- Quando um PC fica offline/em alerta ou uma métrica/temperatura passa do
  limite, **cada pessoa com e-mail e avisos ligados recebe UM e-mail resumindo
  os problemas novos** (não um e-mail por problema).

#### Configurar o envio (obrigatório para os e-mails saírem)
Edite `includes/mail_config.php` (ou use variáveis de ambiente):

| Opção | Exemplo |
|-------|---------|
| `host` | `smtp.gmail.com` (Gmail/Google Workspace) ou `smtp.office365.com` |
| `port` / `secure` | `587` / `tls` (ou `465` / `ssl`) |
| `user` / `password` | conta que envia (no Gmail, use uma **senha de app**) |
| `from_email` | remetente, ex.: `sysmon@seudominio.com.br` |

Se `host` ficar vazio, usa o `mail()` do PHP (depende do servidor; pode ir
para o spam). Depois de configurar, abra *Configurações → Enviar e-mail de
teste*. Falhas de envio ficam no log de erros do PHP e **nunca derrubam o painel**.

#### Receber e-mails sem ninguém com o painel aberto (opcional)
O painel só percebe problemas quando alguém o abre. Para os e-mails saírem
sempre, agende o script a cada 2–5 minutos:
```
# crontab -e   (a cada 2 minutos)
*/2 * * * * php /caminho/sysmon_php/cron/sync_alertas.php
```
Hospedagem que só agenda URL: defina `cron_token` em `includes/mail_config.php`
e agende `https://SEU_SITE/cron/sync_alertas.php?token=SEU_TOKEN`.

### Banco de dados
Em bancos já existentes **não é preciso rodar nada na mão**: na primeira
requisição o painel cria a coluna `usuarios.email`/`notificar_email` e a tabela
`manutencoes` sozinho (`ensure_schema()` em `includes/data.php`). Instalações
novas já recebem tudo via `schema.sql`. O usuário do banco precisa de permissão
de `ALTER`/`CREATE`.

---

## 🔍 Endpoints da API (sem alteração de contrato — só a origem dos dados mudou)

| Método | URL                                    | Descrição                          |
|--------|-----------------------------------------|-------------------------------------|
| POST   | `api/login.php`                        | Login (JSON body) contra o MySQL    |
| POST   | `api/logout.php`                       | Logout                              |
| GET    | `api/computers.php?search=1&q=CM0001`  | Buscar computadores (do MySQL)      |
| GET    | `api/computers.php?code=CM000001`      | Detalhe de um computador            |
| GET    | `api/metrics.php?code=CM000001`        | Última métrica real gravada pelo agente |
| GET    | `api/temperatures.php?code=CM000001`   | Última temperatura real (se disponível) |
| GET    | `api/maintenance.php?code=CM000001`    | Histórico de manutenção do computador |
| POST   | `api/maintenance.php`                  | `{acao:"criar", codigo, tipo, descricao, realizado_em}` ou `{acao:"excluir", id}` (admin) |
| GET/POST | `api/email_settings.php`             | E-mail do usuário logado (`salvar` / `testar`) |
| POST   | `api/user_email.php`                   | Admin altera o e-mail de um usuário |

Status (`online` / `warning` / `offline`) agora é calculado
automaticamente: se o agente daquela máquina não grava dados há mais
de 90s, ela vira `offline` sozinha — não existe mais status fixo.

---

## ⚠️ Limitações reais do WMI (transparência, não simulação)

- CPU, RAM, disco, rede e uptime: 100% reais em qualquer PC Windows.
- GPU: modelo sempre real; uso/VRAM/temperatura só se houver
  `nvidia-smi` disponível (GPU NVIDIA).
- Temperaturas de CPU/placa-mãe: nativas do WMI só em parte dos
  notebooks. Para temperaturas reais em qualquer máquina, instale o
  LibreHardwareMonitor (gratuito) — o agente já sabe usá-lo se
  estiver rodando. Sem isso, o campo fica vazio (não inventado).
- Potência da fonte (PSU) em watts: sem sensor padrão via WMI, fica
  sempre vazio.

Detalhes completos em `agent/README.md`.

---

## 🛠️ Tecnologias

- PHP 8.1+ (PDO, sessões nativas, password_hash/password_verify)
- MySQL (DBaaS)
- Python 3.9+ (agente: `wmi`, `pywin32`; só usa a biblioteca padrão para HTTPS)
- HTML5 + CSS3 + JavaScript Vanilla (frontend inalterado)

## Histórico de problemas

Menu lateral **Histórico**: resumo semanal (7 dias) ou mensal (30 dias) das
notificações — total de problemas, vermelhos (crítico/offline), computadores
afetados, tempo em problema, gráfico por dia, ranking por computador, tipos de
problema e lista das ocorrências. As notificações agora ficam guardadas por
12 meses (antes eram apagadas após 1 mês).

## Lista de computadores do campus (planilha)

- `banco_de_dados/computadores_ifrn.php` tem os 347 computadores da planilha
  (patrimônio, sala/local, modelo e situação). `importar_computadores.php`
  grava tudo no banco (uma vez, logado como administrador): cada PC vira
  `CM` + patrimônio (ex.: `CM406652`) e aparece em **Listar Computadores**
  como **Sem agente** até o agente rodar nele.
- A lista tem filtros por **sala**, **modelo** e **com/sem agente**, e a
  busca aceita código, patrimônio, sala ou modelo.
- PCs **sem agente** não geram alerta de offline nem entram nos gráficos de
  monitoramento; a pizza mostra só os que já enviaram dados.
- No PC, o `install.bat` pergunta o número de patrimônio e grava em
  `agent/config.ini` (`patrimonio = 406652`). Com isso o agente se identifica
  como `CM406652` e o painel mostra sala e modelo da planilha.



## 🗺️ Plantas do campus (menu **Plantas do campus**)

As plantas dos dois prédios (**Principal** e **Anexo**, térreo, 1º e 2º andar)
ficam em `static/plantas/` e aparecem com uma **bolinha por sala**:

| Cor | Significa |
|-----|-----------|
| 🟢 verde | todos os computadores monitorados da sala estão online e sem problemas |
| 🔴 vermelha | algum computador com problema (alerta/crítico) **ou** algum offline |
| ⚪ cinza | nenhum computador online (tudo offline ou ainda sem agente) |

O número dentro da bolinha é o total de computadores da sala. Clicando nela
(ou na lista ao lado) aparecem: total, **sem problemas**, **com problemas**,
**offline**, **sem agente** e o código de cada PC — clicar no código abre o
detalhe do computador (o botão *Voltar* retorna à planta). Tudo é calculado ao
vivo com a mesma regra do Monitoramento Geral e atualiza no intervalo definido
em Configurações.

### Posicionando as bolinhas (administradores)
As plantas são só desenho (sem nome de sala), então cada bolinha precisa ser
ligada uma vez à sala do cadastro:

1. Abra a planta e clique em **✎ Editar bolinhas**.
2. Clique no ponto da planta onde fica a sala → marque a(s) sala(s) da lista
   (use o filtro) → **Salvar**. Marque mais de uma sala quando a planilha usa
   nomes diferentes para o mesmo lugar (ex.: `LAB. GAMES` e `LAB. JOGOS`).
3. Arraste uma bolinha para mudar a posição; clique nela para editar ou excluir.
4. Em **Salas ainda sem bolinha** (rodapé) aparece o que falta posicionar;
   clique no nome da sala e depois no ponto da planta.

Uma sala só pode estar em uma bolinha. Usuários comuns só visualizam.

### Detalhes
- Tabelas `planta_marcadores` e `planta_marcador_salas`: criadas sozinhas na
  primeira requisição (`ensure_schema`) e também em `schema.sql`.
- Para renomear andares ou trocar o arquivo de uma planta, edite `PLANTAS` em
  `includes/plantas.php`.
- A regra das cores está em `plantas_cor()` (`includes/plantas.php`).

## Envio dos dados do agente por HTTPS

- O agente faz `POST` em `api/agent_ingest.php` com o cabeçalho
  `X-SysMon-Key`. A chave fica em `includes/agent_config.php` (servidor) e
  em `agent/config.ini` (linha `chave`); precisam ser iguais.
- A chave só permite **enviar** leituras; não dá acesso ao banco nem ao
  painel. Para trocar, gere outra, atualize os dois lugares e reinstale o
  `config.ini` nos computadores.
- Se o servidor estiver sem chave, o endpoint responde 503 (não aceita dados).
- Os computadores **não têm mais a senha do banco**.

