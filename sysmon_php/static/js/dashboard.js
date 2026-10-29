/* SysMon — dashboard.js */

const $ = id => document.getElementById(id);

// Escapa texto vindo do banco/usuário antes de colocar em innerHTML.
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c]));

const sidebar          = $("sidebar");
const sidebarToggle    = $("sidebarToggle");
const dashClock        = $("dashClock");
const topbarTitle      = $("topbarTitle");
const computerSearch   = $("computerSearch");
const computerGrid     = $("computerGrid");
const emptyState       = $("emptyState");
const categoryTabs     = $("categoryTabs");
const tempSubmenu      = $("tempSubmenu");
const metricArea       = $("metricArea");
const offlineState     = $("offlineState");
const monitorGrid      = $("monitorGrid");
const btnBack          = $("btnBack");
const computerTitle    = $("computerTitle");
const computerSubtitle = $("computerSubtitle");
const computerStatus   = $("computerStatus");

const views = {
  list:        $("viewListComputers"),
  detail:      $("viewComputer"),
  monitor:     $("viewMonitorAll"),
  alerts:      $("viewAlerts"),
  notifications: $("viewNotifications"),
  history:     $("viewHistory"),
  plantas:     $("viewPlantas"),
  settings:    $("viewSettings"),
  users:       $("viewUsers"),        // só existe no DOM se o logado for um dos admins (ver ADMIN_USERNAMES no PHP)
  placeholder: $("viewPlaceholder"),
};

const navBtns = {
  list:     $("btnListComputers"),
  monitor:  $("btnMonitorAll"),
  alerts:   $("btnAlerts"),
  notifications: $("btnNotifications"),
  history:  $("btnHistory"),
  plantas:  $("btnPlantas"),
  settings: $("btnSettings"),
  users:    $("btnUsers"),            // idem: pode ser null para não-admin
};

let currentComputer   = null;
let currentCat        = "overview";
let currentTempFilter = "all";
let refreshTimer      = null;
let monitorRefreshTimer = null;
let badgeTimer        = null;

// Intervalo de atualização (configurável em Configurações; padrão 15 segundos)
let REFRESH_MS = parseInt(localStorage.getItem("sysmon_refresh_ms"), 10) || 15000;

// ── URLs da API PHP ────────────────────────────
const API = {
  logout:       "api/logout.php",
  search:       q    => `api/computers.php?search=1&q=${encodeURIComponent(q)}`,
  computer:     code => `api/computers.php?code=${code}`,
  metrics:      code => `api/metrics.php?code=${code}`,
  temperatures: code => `api/temperatures.php?code=${code}`,
  changePassword: "api/change_password.php",
  changeUsername: "api/change_username.php",
  users:          "api/users.php",
  userPassword:   "api/user_password.php",
  userToggle:     "api/user_toggle.php",
  deleteComputer: "api/computer_delete.php",
  tempHistory:    code => `api/temp_history.php?code=${code}&limit=30`,
  notifications:  "api/notifications.php",
  tempsOverview:  "api/temps_overview.php",
  maintenance:    code => `api/maintenance.php?code=${encodeURIComponent(code)}`,
  maintenanceSave: "api/maintenance.php",
  emailSettings:  "api/email_settings.php",
  userEmail:      "api/user_email.php",
  history:        periodo => `api/history.php?periodo=${periodo}`,
  fleetSummary:   "api/fleet_summary.php",
  plantas:        "api/plantas.php",
};

// Mesmo limite usado no back-end (includes/data.php: ALERT_THRESHOLD) —
// só para desenhar a linha de limite no gráfico e destacar valores altos.
const ALERT_THRESHOLD_C = 85;

// Mesmo valor de MANY_PROBLEMS_THRESHOLD no back-end — quantos problemas
// críticos ativos ao mesmo tempo disparam o banner na aba Alertas.
const MANY_PROBLEMS_THRESHOLD = 3;

// Clock
setInterval(() => {
  dashClock.textContent = new Date().toLocaleString("pt-BR", { day:"2-digit", month:"2-digit", year:"numeric", hour:"2-digit", minute:"2-digit", second:"2-digit" });
}, 1000);

// Sidebar toggle (mobile)
sidebarToggle.addEventListener("click", () => sidebar.classList.toggle("open"));
document.addEventListener("click", e => {
  if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target))
    sidebar.classList.remove("open");
});

// Logout
$("btnLogout").addEventListener("click", async () => {
  await fetch(API.logout, { method: "POST" });
  location.href = "login.php";
});

// View switching
function showView(view, title) {
  Object.values(views).filter(Boolean).forEach(v => v.classList.remove("active"));
  view.classList.add("active");
  topbarTitle.textContent = title;
}

function setNavActive(btn) {
  document.querySelectorAll(".nav-btn").forEach(b => b.classList.remove("active"));
  btn.classList.add("active");
}

navBtns.list.addEventListener("click", () => {
  setNavActive(navBtns.list);
  showView(views.list, "Listar Computadores");
  clearRefresh();
  loadComputers();
});

navBtns.monitor.addEventListener("click", () => {
  setNavActive(navBtns.monitor);
  showView(views.monitor, "Monitoramento Geral");
  clearRefresh();
  loadMonitorAll();
  monitorRefreshTimer = setInterval(loadMonitorAll, REFRESH_MS);
});

navBtns.alerts.addEventListener("click", () => {
  setNavActive(navBtns.alerts);
  showView(views.alerts, "Alertas");
  clearRefresh();
  loadAlerts();
  refreshTimer = setInterval(loadAlerts, REFRESH_MS);
});

navBtns.notifications.addEventListener("click", () => {
  setNavActive(navBtns.notifications);
  showView(views.notifications, "Notificações");
  clearRefresh();
  loadNotifications();
  refreshTimer = setInterval(loadNotifications, REFRESH_MS);
});

navBtns.settings.addEventListener("click", () => {
  setNavActive(navBtns.settings);
  showView(views.settings, "Configurações");
  clearRefresh();
  openSettings();
});

if (navBtns.users) {
  navBtns.users.addEventListener("click", () => {
    setNavActive(navBtns.users);
    showView(views.users, "Usuários");
    clearRefresh();
    openUsers();
  });
}

// Search
computerSearch.addEventListener("input", renderComputerList);
computerSearch.addEventListener("keydown", e => { if (e.key === "Escape") { computerSearch.value = ""; renderComputerList(); } });

// "Sem agente": cadastrado pela planilha, mas o agente ainda não rodou nele.
function statusInfo(comp) {
  if (comp.sem_agente) return { cls: "pendente", label: "Sem agente" };
  const nomes = { online: "Online", warning: "Alerta", offline: "Offline" };
  return { cls: comp.status, label: nomes[comp.status] };
}

function situacaoTag(comp) {
  if (!comp.situacao || comp.situacao === "OK") return "";
  const cls = comp.situacao === "PARA DAR BAIXA" ? "baixa" : "atencao";
  return `<span class="situacao-tag ${cls}" title="Situação na planilha do patrimônio">${esc(comp.situacao)}</span>`;
}

function makeComputerCard(comp, i) {
  const st = statusInfo(comp);
  const card = document.createElement("div");
  card.className = `computer-card ${comp.sem_agente ? "off" : comp.status === "warning" ? "warn" : comp.status === "offline" ? "off" : ""}`;
  card.style.animationDelay = `${Math.min(i, 20) * 0.03}s`;
  const salaTxt = comp.sala || comp.location || "Sala não informada";
  card.innerHTML = `
    <div class="card-top">
      <span class="computer-code">${esc(comp.code)}</span>
      <span class="status-badge ${st.cls}"><span class="status-dot ${st.cls}"></span>${st.label}</span>
    </div>
    <div class="computer-label">${esc(salaTxt)}</div>
    ${comp.modelo ? `<div class="computer-modelo">${esc(comp.modelo)}</div>` : ""}
    <div class="computer-loc">
      <svg width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="14" height="10" rx="1.5"/><path d="M7 17h6M10 13v4"/></svg>
      ${comp.patrimonio ? "Patrimônio " + esc(comp.patrimonio) : esc(comp.label)}
      ${situacaoTag(comp)}
    </div>`;
  // Sempre clicável — mesmo offline, precisa poder abrir pra ver
  // detalhes e remover o computador do painel se for o caso.
  card.addEventListener("click", () => openComputer(comp));
  return card;
}

// Lista completa vem do servidor uma vez; busca e filtros rodam no navegador.
let allComputers = [];

function buildListFilters() {
  const fill = (sel, campo, vazio) => {
    const atual = sel.value;
    const cont = new Map();
    allComputers.forEach(c => { const v = c[campo] || ""; cont.set(v, (cont.get(v) || 0) + 1); });
    const itens = [...cont.entries()].filter(([v]) => v).sort((x, y) => x[0].localeCompare(y[0], "pt-BR", { numeric: true }));
    const semInfo = cont.get("") || 0;
    sel.innerHTML = `<option value="">${vazio}</option>` +
      itens.map(([v, n]) => `<option value="${esc(v)}">${esc(v)} (${n})</option>`).join("") +
      (semInfo ? `<option value="__sem__">Sem informação (${semInfo})</option>` : "");
    sel.value = [...sel.options].some(o => o.value === atual) ? atual : "";
  };
  fill($("filterSala"), "sala", "Todas as salas");
  fill($("filterModelo"), "modelo", "Todos os modelos");
}

function matchesListFilters(comp) {
  const q = computerSearch.value.toUpperCase().trim();
  if (q) {
    const texto = [comp.code, comp.label, comp.patrimonio, comp.sala, comp.modelo].join(" ").toUpperCase();
    if (!texto.includes(q)) return false;
  }
  const sala = $("filterSala").value, modelo = $("filterModelo").value, ag = $("filterAgente").value;
  if (sala && (sala === "__sem__" ? comp.sala : comp.sala !== sala)) return false;
  if (modelo && (modelo === "__sem__" ? comp.modelo : comp.modelo !== modelo)) return false;
  if (ag === "com" && comp.sem_agente) return false;
  if (ag === "sem" && !comp.sem_agente) return false;
  return true;
}

function renderComputerList() {
  computerGrid.innerHTML = "";
  emptyState.classList.add("hidden");
  const list = allComputers.filter(matchesListFilters);
  $("listCount").textContent = `${list.length} de ${allComputers.length} computadores`;
  if (!list.length) { emptyState.classList.remove("hidden"); return; }
  list.forEach((comp, i) => computerGrid.appendChild(makeComputerCard(comp, i)));
}

async function loadComputers() {
  try {
    allComputers = await fetch(API.search("")).then(r => r.json());
    buildListFilters();
    renderComputerList();
  } catch (e) { console.error(e); }
}

["filterSala", "filterModelo", "filterAgente"].forEach(id => $(id).addEventListener("change", renderComputerList));
$("filterClear").addEventListener("click", () => {
  computerSearch.value = "";
  ["filterSala", "filterModelo", "filterAgente"].forEach(id => { $(id).value = ""; });
  renderComputerList();
});

// Open computer detail
async function openComputer(comp) {
  currentComputer = comp;
  currentCat = "overview";
  clearRefresh();

  const st = statusInfo(comp);
  computerTitle.textContent    = `${comp.code} — ${comp.sala || comp.label}`;
  computerSubtitle.textContent = comp.location || "";
  computerStatus.className     = `status-badge ${st.cls}`;
  computerStatus.innerHTML     = `<span class="status-dot ${st.cls}"></span>${st.label}`;
  $("cadastroInfo").innerHTML = [
    ["Patrimônio", comp.patrimonio], ["Sala / local", comp.sala || comp.location],
    ["Modelo", comp.modelo], ["Situação na planilha", comp.situacao],
  ].filter(([, v]) => v).map(([k, v]) =>
    `<div class="cadastro-item"><div class="cadastro-k">${k}</div><div class="cadastro-v">${esc(v)}</div></div>`).join("");

  document.querySelectorAll(".cat-btn").forEach(b => b.classList.remove("active"));
  document.querySelector(".cat-btn[data-cat='overview']").classList.add("active");
  showView(views.detail, `${comp.code} — Detalhe`);
  setNavActive(navBtns.list);

  if (comp.status === "offline") {
    metricArea.innerHTML = "";
    tempSubmenu.classList.add("hidden");
    offlineState.querySelector("div").textContent = comp.sem_agente
      ? "Sem agente — instale o agente neste computador para começar o monitoramento"
      : "Computador Offline";
    offlineState.classList.remove("hidden");
    return;
  }

  offlineState.classList.add("hidden");
  tempSubmenu.classList.add("hidden");
  await renderCategory("overview");
  refreshTimer = setInterval(refreshDetail, REFRESH_MS);
}

// A atualização automática não pode redesenhar a aba Manutenção, senão
// apagaria o que a pessoa está digitando no formulário.
function refreshDetail() {
  if (currentCat !== "maintenance") renderCategory(currentCat);
}

btnBack.addEventListener("click", () => {
  clearRefresh();
  if (voltarParaPlantas) {            // abriu o computador a partir de uma planta
    voltarParaPlantas = false;
    abrirPlantas();
    return;
  }
  showView(views.list, "Listar Computadores");
  loadComputers();
});

const btnDeleteComputer = $("btnDeleteComputer");
if (btnDeleteComputer) {
  btnDeleteComputer.addEventListener("click", async () => {
    if (!currentComputer) return;
    const code = currentComputer.code;
    const ok = confirm(
      `Remover "${code}" do painel?\n\nIsso apaga todo o histórico de métricas e temperaturas ` +
      `desse computador. Não tem como desfazer.`
    );
    if (!ok) return;

    btnDeleteComputer.disabled = true;
    try {
      const data = await fetch(API.deleteComputer, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ codigo: code }),
      }).then(r => r.json());

      if (data.ok) {
        clearRefresh();
        showView(views.list, "Listar Computadores");
        loadComputers();
      } else {
        alert(data.error || "Não foi possível remover esse computador.");
        btnDeleteComputer.disabled = false;
      }
    } catch (e) {
      alert("Erro de conexão. Tente novamente.");
      btnDeleteComputer.disabled = false;
    }
  });
}

// Category tabs
categoryTabs.addEventListener("click", async e => {
  const btn = e.target.closest(".cat-btn");
  if (!btn) return;
  document.querySelectorAll(".cat-btn").forEach(b => b.classList.remove("active"));
  btn.classList.add("active");
  currentCat = btn.dataset.cat;
  if (currentCat === "temp") {
    tempSubmenu.classList.remove("hidden");
    currentTempFilter = "all";
    document.querySelectorAll(".temp-btn").forEach(b => b.classList.toggle("active", b.dataset.temp === "all"));
  } else {
    tempSubmenu.classList.add("hidden");
  }

  // Manutenção é um registro feito por pessoas, não depende do coletor —
  // por isso fica disponível mesmo com o PC offline. As outras abas
  // continuam mostrando "Computador Offline".
  const offline = currentComputer && currentComputer.status === "offline";
  if (offline && currentCat !== "maintenance") {
    metricArea.innerHTML = "";
    tempSubmenu.classList.add("hidden");
    offlineState.classList.remove("hidden");
    return;
  }
  offlineState.classList.add("hidden");
  await renderCategory(currentCat);
});

tempSubmenu.addEventListener("click", async e => {
  const btn = e.target.closest(".temp-btn");
  if (!btn) return;
  document.querySelectorAll(".temp-btn").forEach(b => b.classList.remove("active"));
  btn.classList.add("active");
  currentTempFilter = btn.dataset.temp;
  await renderCategory("temp");
});

// Fetch helper
const fetchJSON = url => fetch(url).then(r => r.json());

async function renderCategory(cat) {
  metricArea.innerHTML = `<div style="color:var(--text3);font-family:var(--mono);font-size:12px;padding:20px 0;">Carregando...</div>`;
  const code = currentComputer.code;

  if (cat === "maintenance") { await renderMaintenance(code); return; }

  if (cat === "temp") {
    const t = await fetchJSON(API.temperatures(code));
    if (!t.offline) renderTemps(t, currentTempFilter);
    else metricArea.innerHTML = "";
    return;
  }

  const d = await fetchJSON(API.metrics(code));
  if (d.offline) { metricArea.innerHTML = ""; return; }

  const renders = { overview: () => renderOverview(d), cpu: () => renderCPU(d.cpu), gpu: () => renderGPU(d.gpu), memory: () => renderMemory(d) };
  renders[cat]?.();
}

// UI helpers
const gaugeColor = p => p >= 85 ? "red" : p >= 65 ? "yellow" : "green";
const tempColor  = t => t >= 85 ? "hot" : t >= 70 ? "warn" : "ok";
const tempBarClr = t => t >= 85 ? "var(--red)" : t >= 70 ? "var(--yellow)" : "var(--green)";

// Formata um valor numérico para exibição, ou "—" quando o sensor não está disponível (null/undefined)
function fmt(val, decimals = null) {
  if (val === null || val === undefined || Number.isNaN(val)) return "—";
  return decimals !== null ? Number(val).toFixed(decimals) : val;
}

function gauge(label, val, unit, pct, color) {
  const has = val !== null && val !== undefined && !Number.isNaN(val);
  color = color || (has ? gaugeColor(pct) : "");
  const barStyle = has ? `width:${pct}%` : `width:100%;background:var(--bg4);box-shadow:none;`;
  return `<div class="m-card"><div class="gauge-wrap">
    <div class="gauge-label"><span>${label}</span><span class="gauge-val">${fmt(val)}<span style="font-size:11px;color:var(--text3)"> ${has ? unit : ""}</span></span></div>
    <div class="gauge-bar"><div class="gauge-fill ${color}" style="${barStyle}"></div></div>
  </div></div>`;
}

function metricCard(label, val, unit, sub = "", texto = false) {
  const has = val !== null && val !== undefined && !Number.isNaN(val);
  // texto=true: valor é um texto longo (modelo da placa, versão da BIOS),
  // não um número — usa fonte menor e quebra a linha em vez de vazar do card.
  return `<div class="m-card ${texto ? "m-card-textual" : ""}">
    <div class="m-card-label">${label}</div>
    <div class="m-card-val ${texto ? "texto" : ""}">${texto ? esc(val) : fmt(val)}<span class="m-card-unit">${has ? unit : ""}</span></div>
    ${sub ? `<div class="m-card-sub">${sub}</div>` : ""}
  </div>`;
}

// Render functions
function renderOverview(d) {
  const uptime = d.uptime_h >= 24 ? `${Math.floor(d.uptime_h/24)}d ${d.uptime_h%24}h` : `${d.uptime_h}h`;
  const mobo = currentComputer || {};
  metricArea.innerHTML = `
    <div class="section-title">Resumo do Sistema</div>
    <div class="metrics-grid">
      ${metricCard("CPU", d.cpu.usage, "%", d.cpu.model)}
      ${metricCard("GPU", d.gpu.usage, "%", d.gpu.model)}
      ${metricCard("RAM", d.ram.pct, "%", `${d.ram.used} / ${d.ram.total} GB`)}
      ${metricCard("DISCO", d.disk.pct, "%", `${d.disk.used} / ${d.disk.total} GB`)}
      ${metricCard("REDE ↓", d.net.down, "MB/s", `Upload: ${d.net.up} MB/s`)}
      ${metricCard("UPTIME", uptime, "", "Tempo ativo")}
    </div>
    <div class="section-title">Placa-Mãe</div>
    <div class="metrics-grid">
      ${metricCard("Modelo", mobo.mobo_model || "—", "", esc(mobo.mobo_manufacturer || ""), true)}
      ${metricCard("BIOS", mobo.bios_version || "—", "", "Versão instalada", true)}
    </div>
    <div class="section-title">Utilização</div>
    <div class="gauges-grid">
      ${gauge("CPU", d.cpu.usage, "%", d.cpu.usage)}
      ${gauge("GPU", d.gpu.usage, "%", d.gpu.usage)}
      ${gauge("RAM", d.ram.pct, "%", d.ram.pct)}
      ${gauge("Disco", d.disk.pct, "%", d.disk.pct)}
      ${gauge("VRAM", d.gpu.vram_pct, "%", d.gpu.vram_pct)}
    </div>`;
}

function renderCPU(cpu) {
  const coresHtml = cpu.cores.map((p, i) => `
    <div class="core-item">
      <div class="core-num">C${i}</div>
      <div class="core-val">${p}%</div>
      <div class="core-bar"><div class="core-fill" style="width:${p}%; background:${p>=85?'var(--red)':p>=65?'var(--yellow)':'var(--cyan)'};"></div></div>
    </div>`).join("");

  metricArea.innerHTML = `
    <div class="section-title">Processador</div>
    <div class="metrics-grid">
      ${metricCard("Uso Total", cpu.usage, "%", cpu.model)}
      ${metricCard("Temperatura", cpu.temp, "°C", "Package")}
      ${metricCard("Frequência", cpu.freq, "GHz", "Clock atual")}
      ${metricCard("Núcleos", cpu.cores.length, "", "Threads ativos")}
    </div>
    <div class="gauges-grid">
      ${gauge("Uso CPU", cpu.usage, "%", cpu.usage)}
      ${gauge("Temperatura", cpu.temp, "°C", Math.min(100, cpu.temp), cpu.temp>=85?"red":cpu.temp>=70?"yellow":"green")}
    </div>
    <div class="section-title">Núcleos Individuais</div>
    <div class="cores-grid">${coresHtml}</div>`;
}

function renderGPU(gpu) {
  metricArea.innerHTML = `
    <div class="section-title">Placa de Vídeo</div>
    <div class="metrics-grid">
      ${metricCard("Uso", gpu.usage, "%", gpu.model)}
      ${metricCard("Temperatura", gpu.temp, "°C", "GPU Die")}
      ${metricCard("VRAM", gpu.vram_pct, "%", "Uso de memória")}
    </div>
    <div class="gauges-grid">
      ${gauge("Uso GPU", gpu.usage, "%", gpu.usage)}
      ${gauge("Temperatura GPU", gpu.temp, "°C", Math.min(100, gpu.temp), gpu.temp>=85?"red":gpu.temp>=70?"yellow":"green")}
      ${gauge("VRAM", gpu.vram_pct, "%", gpu.vram_pct, "cyan")}
    </div>`;
}

function renderMemory(d) {
  const { ram, disk } = d;
  metricArea.innerHTML = `
    <div class="section-title">Memória RAM</div>
    <div class="metrics-grid">
      ${metricCard("Usada", ram.used, "GB", `Livre: ${(ram.total - ram.used).toFixed(1)} GB`)}
      ${metricCard("Total", ram.total, "GB", "DDR4")}
      ${metricCard("Uso", ram.pct, "%", "Utilização")}
    </div>
    <div class="gauges-grid">${gauge("RAM", ram.pct, "%", ram.pct, "cyan")}</div>
    <div class="section-title">Armazenamento</div>
    <div class="metrics-grid">
      ${metricCard("Usado", disk.used, "GB", `Livre: ${(disk.total - disk.used).toFixed(0)} GB`)}
      ${metricCard("Capacidade", disk.total, "GB", "NVMe SSD")}
      ${metricCard("Uso", disk.pct, "%", "Utilização")}
    </div>
    <div class="gauges-grid">${gauge("Disco", disk.pct, "%", disk.pct)}</div>`;
}

function renderTemps(t, filter) {
  const all = [
    { group:"cpu",     comp:"CPU Package",    val: t.cpu_package },
    ...t.cpu_cores.map((v, i) => ({ group:"cpu", comp:`CPU Core ${i}`, val: v })),
    { group:"gpu",     comp:"GPU Die",         val: t.gpu_die },
    { group:"gpu",     comp:"GPU Memory",      val: t.gpu_mem },
    { group:"storage", comp:"NVMe 1",          val: t.nvme_1 },
    { group:"storage", comp:"NVMe 2",          val: t.nvme_2 },
    { group:"board",   comp:"Placa-Mãe",       val: t.motherboard },
  ];
  const rows = (filter === "all" ? all : all.filter(r => r.group === filter))
                 .filter(r => r.val !== null && r.val !== undefined);

  // RPM dos fans: exibido só na aba "Todos" (não é um dado de temperatura,
  // então não faz parte dos filtros CPU/GPU/Armazenamento/Placa-Mãe).
  const fans = filter === "all"
    ? [{ label: "Fan CPU", val: t.fan_cpu_rpm }, { label: "Fan Sistema", val: t.fan_sys_rpm }]
        .filter(f => f.val !== null && f.val !== undefined)
    : [];

  if (!rows.length && !fans.length) {
    metricArea.innerHTML = `<div class="empty-state"><svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1"><path d="M24 10v16M24 34v1"/><circle cx="24" cy="24" r="20"/></svg><div>Nenhum sensor disponível para este filtro</div><div class="mono-text" style="font-size:11px;margin-top:4px;">Este PC/sensor não expõe esse dado no momento</div></div>`;
    return;
  }

  const fansHtml = fans.length ? `
    <div class="section-title">Fans</div>
    <div class="metrics-grid" style="margin-bottom:20px;">
      ${fans.map(f => metricCard(f.label, f.val, "RPM")).join("")}
    </div>` : "";

  const rowsHtml = rows.map(r => {
    const cls = tempColor(r.val);
    const badgeClass = cls === "ok" ? "online" : cls === "warn" ? "warning" : "offline";
    const badgeLabel = cls === "ok" ? "Normal" : cls === "warn" ? "Atenção" : "Crítico";
    return `<tr>
      <td class="temp-comp">${r.comp}</td>
      <td class="temp-val ${cls}">${r.val} °C</td>
      <td class="temp-bar-cell"><div class="temp-bar-mini"><div class="temp-bar-fill" style="width:${Math.min(100,(r.val/100)*100)}%; background:${tempBarClr(r.val)};"></div></div></td>
      <td><span class="status-badge ${badgeClass}">${badgeLabel}</span></td>
    </tr>`;
  }).join("");

  const tableHtml = rows.length ? `
    <div class="section-title">Sensores de Temperatura</div>
    <div class="m-card" style="padding:0;overflow:hidden;">
      <table class="temp-table">
        <thead><tr><th>Componente</th><th>Temperatura</th><th>Nível</th><th>Status</th></tr></thead>
        <tbody>${rowsHtml}</tbody>
      </table>
    </div>` : "";

  metricArea.innerHTML = fansHtml + tableHtml + `
    <div class="temp-chart-wrap">
      <div class="section-title">Histórico de temperatura</div>
      <div class="temp-chart-legend">
        <span><span class="temp-chart-dot" style="background:var(--cyan);"></span>CPU Package</span>
        <span><span class="temp-chart-dot" style="background:#ff8a3d;"></span>GPU Die</span>
        <span><span class="temp-chart-dot" style="background:var(--red);"></span>Acima do limite (${ALERT_THRESHOLD_C}°C)</span>
      </div>
      <div id="tempChartBox"><div class="temp-chart-empty">Carregando histórico...</div></div>
    </div>`;
  loadTempChart(currentComputer.code);
}

// ── Gráfico de temperatura (histórico), SVG puro, sem libs externas ──
async function loadTempChart(code) {
  const box = $("tempChartBox");
  if (!box) return; // usuário já trocou de tela antes da resposta chegar
  try {
    const data = await fetchJSON(API.tempHistory(code));
    if (!data.ok || !data.pontos || !data.pontos.length) {
      box.innerHTML = `<div class="temp-chart-empty">Ainda não há histórico suficiente para o gráfico.</div>`;
      return;
    }
    box.innerHTML = buildTempChartSVG(data.pontos, data.threshold || ALERT_THRESHOLD_C);
  } catch (e) {
    box.innerHTML = `<div class="temp-chart-empty">Não foi possível carregar o histórico.</div>`;
  }
}

function buildTempChartSVG(pontos, threshold) {
  const W = 640, H = 220, padL = 34, padR = 12, padT = 14, padB = 24;
  const plotW = W - padL - padR, plotH = H - padT - padB;

  const maxVal = Math.max(threshold + 15, ...pontos.flatMap(p => [p.cpu_package, p.gpu_die]).filter(v => v !== null && v !== undefined), 40);
  const yFor = v => padT + plotH - (v / maxVal) * plotH;
  const xFor = i => padL + (pontos.length <= 1 ? plotW / 2 : (i / (pontos.length - 1)) * plotW);

  const series = (key, color) => {
    const pts = pontos.map((p, i) => ({ i, v: p[key] })).filter(p => p.v !== null && p.v !== undefined);
    if (!pts.length) return "";
    const line = pts.map(p => `${xFor(p.i)},${yFor(p.v)}`).join(" ");
    const dots = pts.map(p => {
      const alerta = p.v >= threshold;
      return `<circle cx="${xFor(p.i)}" cy="${yFor(p.v)}" r="${alerta ? 3.6 : 2.4}" fill="${alerta ? "var(--red)" : color}" ${alerta ? `stroke="var(--red)" stroke-width="1"` : ""}/>`;
    }).join("");
    return `<polyline points="${line}" fill="none" stroke="${color}" stroke-width="2" opacity="0.9"/>${dots}`;
  };

  // Eixo Y: 3 linhas-guia (0, metade, topo) só pra dar noção de escala.
  const gridLines = [0, 0.5, 1].map(f => {
    const val = Math.round(maxVal * f);
    const y = yFor(val);
    return `<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" stroke="var(--border)" stroke-width="1"/>
            <text x="${padL - 6}" y="${y + 3}" text-anchor="end" font-size="9" fill="var(--text3)" font-family="var(--mono)">${val}°</text>`;
  }).join("");

  const thresholdY = yFor(threshold);
  const thresholdLine = `<line x1="${padL}" y1="${thresholdY}" x2="${W - padR}" y2="${thresholdY}" stroke="var(--red)" stroke-width="1.2" stroke-dasharray="4,4" opacity="0.75"/>`;

  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;display:block;" preserveAspectRatio="none">
    ${gridLines}
    ${thresholdLine}
    ${series("cpu_package", "var(--cyan)")}
    ${series("gpu_die", "#ff8a3d")}
  </svg>`;
}


// ── Gráfico de pizza: situação geral dos computadores ────────────────
const FLEET_COLORS = {
  normal:  "var(--green)",
  alerta:  "var(--yellow)",
  critico: "var(--red)",
  offline: "var(--text3)",
};

async function loadFleetPie() {
  const box = $("fleetPie");
  if (!box) return;
  try {
    const d = await fetchJSON(API.fleetSummary);
    if (!d.ok) throw new Error("resposta inválida");
    renderFleetPie(d);
  } catch (e) {
    box.innerHTML = `<div class="temp-chart-empty">Não foi possível carregar a situação geral.</div>`;
  }
}

function renderFleetPie(d) {
  const box = $("fleetPie");
  const nota = d.sem_agente
    ? `<div class="fleet-note mono-text">${d.sem_agente} de ${d.cadastrados} cadastrados ainda sem agente</div>` : "";
  if (!d.total) {
    box.innerHTML = `<div class="temp-chart-empty">${d.cadastrados ? "Nenhum computador enviando dados ainda." : "Nenhum computador cadastrado."}</div>${nota}`;
    return;
  }

  const R = 80, C = 100;                       // raio e centro (viewBox 200x200)
  const cats = d.categorias.filter(c => c.total > 0);
  let ang = -Math.PI / 2;                      // começa no topo
  const pt = (a, r = R) => [C + r * Math.cos(a), C + r * Math.sin(a)];

  const fatias = cats.map(c => {
    const frac = c.total / d.total;
    const cor = FLEET_COLORS[c.chave];
    const txt = c.chave === "offline" ? "#d0e0f0" : "#06101a";   // contraste do número na fatia
    if (frac >= 0.9999) {                      // 100% numa categoria só
      return `<circle cx="${C}" cy="${C}" r="${R}" fill="${cor}"><title>${esc(c.rotulo)}: ${c.total}</title></circle>
              <text x="${C}" y="${C + 5}" text-anchor="middle" font-size="16" font-weight="700" fill="${txt}" font-family="var(--mono)">100%</text>`;
    }
    const a0 = ang, a1 = ang + frac * 2 * Math.PI;
    ang = a1;
    const [x0, y0] = pt(a0), [x1, y1] = pt(a1);
    const grande = frac > 0.5 ? 1 : 0;
    const [tx, ty] = pt((a0 + a1) / 2, R * 0.62);
    const pct = Math.round(frac * 100);
    const rotulo = frac >= 0.07
      ? `<text x="${tx}" y="${ty + 4}" text-anchor="middle" font-size="12" font-weight="700" fill="${txt}" font-family="var(--mono)">${pct}%</text>`
      : "";
    return `<path d="M${C},${C} L${x0},${y0} A${R},${R} 0 ${grande} 1 ${x1},${y1} Z" fill="${cor}" stroke="var(--bg2)" stroke-width="2">
              <title>${esc(c.rotulo)}: ${c.total} de ${d.total} (${pct}%)</title></path>${rotulo}`;
  }).join("");

  const problema = d.com_problema;
  const manchete = problema
    ? `<span class="fleet-num bad">${problema}</span> de ${d.total} monitorados com problema`
    : `<span class="fleet-num ok">${d.total}</span> de ${d.total} monitorados sem problemas`;

  const legenda = d.categorias.map(c => {
    const pct = Math.round((c.total / d.total) * 100);
    const lista = (c.pcs || []).join(", ");
    return `<div class="fleet-row ${c.total ? "" : "zero"}" title="${esc(lista)}">
      <span class="fleet-dot" style="background:${FLEET_COLORS[c.chave]}"></span>
      <span class="fleet-name">${esc(c.rotulo)}</span>
      <span class="fleet-count">${c.total}</span>
      <span class="fleet-pct">${pct}%</span>
    </div>`;
  }).join("");

  box.innerHTML = `
    <div class="fleet-headline">${manchete}</div>
    <svg class="fleet-svg" viewBox="0 0 200 200" role="img" aria-label="Gráfico de pizza com a situação dos computadores">${fatias}</svg>
    <div class="fleet-legend">${legenda}</div>${nota}`;
}

// Monitor geral
async function loadMonitorAll() {
  // Não espera os cartões: o gráfico geral é desenhado de imediato e
  // independe de haver (ou não) algum coletor ativo.
  loadOverviewTempChart();
  loadFleetPie();
  monitorGrid.innerHTML = `<div style="color:var(--text3);font-family:var(--mono);font-size:12px;">Carregando...</div>`;
  try {
    const todos = await fetchJSON(API.search(""));
    const list = todos.filter(c => !c.sem_agente);   // sem agente: nada a monitorar ainda
    monitorGrid.innerHTML = "";
    if (!list.length) {
      monitorGrid.innerHTML = `<div style="color:var(--text3);font-family:var(--mono);font-size:12px;">Nenhum computador enviando dados ainda${todos.length ? ` (${todos.length} cadastrados aguardando o agente)` : ""}.</div>`;
      return;
    }
    for (const comp of list) monitorGrid.appendChild(await makeMonitorCard(comp));
  } catch (e) {
    monitorGrid.innerHTML = `<div style="color:var(--red);font-size:13px;">Erro ao carregar.</div>`;
  }
}

async function loadOverviewTempChart() {
  try {
    const data = await fetchJSON(API.tempsOverview);
    if (!data.ok) throw new Error("resposta inválida");
    // Mesma fonte da aba "Temperatura" de cada PC (tabela temperaturas),
    // já pronta para todo mundo num único pedido. Vai TODOS os PCs: o
    // gráfico aparece sempre, mesmo sem nenhum coletor ativo.
    renderOverviewTempChart(data.pcs || []);
  } catch (e) {
    renderOverviewTempChart([], "Não foi possível carregar as temperaturas.");
  }
}

async function makeMonitorCard(comp) {
  const st = statusInfo(comp);
  const card = document.createElement("div");
  card.className = "monitor-card";
  card.innerHTML = `
    <div class="monitor-card-header">
      <div>
        <div class="computer-code">${comp.code}</div>
        <div class="computer-label" style="font-size:12px;margin-top:2px;">${comp.label}</div>
      </div>
      <span class="status-badge ${st.cls}"><span class="status-dot ${st.cls}"></span>${st.label}</span>
    </div>
    <div class="monitor-gauges" id="mg-${comp.code}">
      <div class="mono-text" style="font-size:11px;">${comp.status === "offline" ? "— Offline —" : "Carregando métricas..."}</div>
    </div>`;

  if (comp.status !== "offline") {
    try {
      const d = await fetchJSON(API.metrics(comp.code));
      if (!d.offline) {
        card.querySelector(`#mg-${comp.code}`).innerHTML =
          gauge("CPU", d.cpu.usage, "%", d.cpu.usage) +
          gauge("RAM", d.ram.pct, "%", d.ram.pct, "cyan") +
          gauge("GPU", d.gpu.usage, "%", d.gpu.usage);
      }
    } catch (e) {}
  }
  return card;
}

// ── Gráfico geral: barras comparando a temperatura atual de cada PC ──
// Sempre desenha o quadro (eixos + linha do limite). Se não há dado para
// mostrar, avisa dentro do próprio gráfico em vez de escondê-lo.
function renderOverviewTempChart(list, errorMsg) {
  const box = $("overviewTempChart");
  if (!box) return;

  const has = v => v !== null && v !== undefined;
  const rows = list || [];

  let aviso = errorMsg || "";
  if (!aviso) {
    if (!rows.length) aviso = "Nenhum computador cadastrado.";
    else if (!rows.some(r => r.status !== "offline" && (has(r.cpu) || has(r.gpu))))
      aviso = "Nenhum coletor ativo — sem temperaturas em tempo real no momento.";
  }

  const W = Math.max(560, rows.length * 90), H = 236, padL = 34, padR = 12, padT = 14, padB = 46;
  const plotW = W - padL - padR, plotH = H - padT - padB;
  const maxVal = Math.max(100, ...rows.flatMap(r => [r.cpu, r.gpu]).filter(has));
  const yFor = v => padT + plotH - (v / maxVal) * plotH;
  const baseY = padT + plotH;

  const groupW = plotW / Math.max(1, rows.length);
  const barW = Math.min(22, groupW / 3);

  const bars = rows.map((r, i) => {
    const groupX = padL + i * groupW + groupW / 2;
    const off = r.status === "offline";
    const op = off ? 0.35 : 1;
    let out = "";
    const bar = (v, x, base) => {
      const alerta = v >= ALERT_THRESHOLD_C;
      return `<g opacity="${op}"><rect x="${x}" y="${yFor(v)}" width="${barW}" height="${baseY - yFor(v)}" fill="${alerta ? "var(--red)" : base}" rx="2"/>
              <text x="${x + barW / 2}" y="${yFor(v) - 4}" text-anchor="middle" font-size="9" fill="var(--text2)" font-family="var(--mono)">${v}°</text></g>`;
    };
    if (has(r.cpu)) out += bar(r.cpu, groupX - barW - 3, "var(--cyan)");
    if (has(r.gpu)) out += bar(r.gpu, groupX + 3, "#ff8a3d");
    if (!has(r.cpu) && !has(r.gpu))
      out += `<text x="${groupX}" y="${baseY - 8}" text-anchor="middle" font-size="9" fill="var(--text3)" font-family="var(--mono)">sem dados</text>`;
    out += `<text x="${groupX}" y="${baseY + 16}" text-anchor="middle" font-size="10" fill="var(--text3)" font-family="var(--mono)">${esc(r.code)}</text>`;
    if (off) {
      let quando = "";
      if (r.temp_em) {
        const d = new Date(String(r.temp_em).replace(" ", "T"));
        if (!isNaN(d)) quando = " · " + d.toLocaleDateString("pt-BR", {day:"2-digit", month:"2-digit"}) + " " + d.toLocaleTimeString("pt-BR", {hour:"2-digit", minute:"2-digit"});
      }
      out += `<text x="${groupX}" y="${baseY + 29}" text-anchor="middle" font-size="9" fill="var(--text3)" font-family="var(--mono)">offline${quando}</text>`;
    }
    return out;
  }).join("");

  const gridLines = [0, 0.5, 1].map(f => {
    const val = Math.round(maxVal * f);
    const y = yFor(val);
    return `<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" stroke="var(--border)" stroke-width="1"/>
            <text x="${padL - 6}" y="${y + 3}" text-anchor="end" font-size="9" fill="var(--text3)" font-family="var(--mono)">${val}°</text>`;
  }).join("");

  const thresholdY = yFor(ALERT_THRESHOLD_C);
  const thresholdLine = `<line x1="${padL}" y1="${thresholdY}" x2="${W - padR}" y2="${thresholdY}" stroke="var(--red)" stroke-width="1.2" stroke-dasharray="4,4" opacity="0.75"/>`;

  const avisoSvg = aviso
    ? `<text x="${W / 2}" y="${padT + plotH / 2}" text-anchor="middle" font-size="12" fill="var(--text3)" font-family="var(--mono)">${esc(aviso)}</text>`
    : "";

  // Poucos PCs: o gráfico encolhe junto com a tela. Muitos PCs: largura
  // fixa com rolagem horizontal, para as barras não ficarem espremidas.
  const sizeStyle = rows.length > 6 ? `width:${W}px;max-width:none;` : `width:${W}px;max-width:100%;`;
  box.innerHTML = `<div style="overflow-x:auto;"><svg viewBox="0 0 ${W} ${H}" style="${sizeStyle}height:auto;display:block;">
    ${gridLines}
    ${thresholdLine}
    ${bars}
    ${avisoSvg}
  </svg></div>`;
}


function clearRefresh() {
  if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
  if (monitorRefreshTimer) { clearInterval(monitorRefreshTimer); monitorRefreshTimer = null; }
}

// ── Alertas (computadores em warning/offline) ──────────
function timeAgo(isoLike) {
  if (!isoLike) return "nunca reportou dados";
  const diffMs = Date.now() - new Date(isoLike.replace(" ", "T")).getTime();
  const s = Math.max(0, Math.floor(diffMs / 1000));
  if (s < 60) return `há ${s}s sem contato`;
  if (s < 3600) return `há ${Math.floor(s / 60)}min sem contato`;
  if (s < 86400) return `há ${Math.floor(s / 3600)}h sem contato`;
  return `há ${Math.floor(s / 86400)}d sem contato`;
}

async function loadAlerts() {
  alertsGrid().innerHTML = "";
  alertsEmpty().classList.add("hidden");
  try {
    const list = await fetchJSON(API.search(""));
    const alerts = list.filter(c => c.status !== "online" && !c.sem_agente);
    if (!alerts.length) { alertsEmpty().classList.remove("hidden"); }
    else { alerts.forEach((comp, i) => alertsGrid().appendChild(makeAlertCard(comp, i))); }
  } catch (e) { console.error(e); }
  loadCritBanner();
}

// Banner de "muitos problemas vermelhos ao mesmo tempo" (aba Alertas).
async function loadCritBanner() {
  const banner = $("critBanner");
  if (!banner) return;
  try {
    const data = await fetchJSON(API.notifications);
    if (!data.ok) return;
    const crit = data.critico;
    if (!crit || crit.total < MANY_PROBLEMS_THRESHOLD) {
      banner.classList.add("hidden");
      banner.innerHTML = "";
      return;
    }
    const nomes = crit.itens.slice(0, 4).map(i => i.label).join(", ");
    const resto = crit.total > 4 ? ` e mais ${crit.total - 4}` : "";
    banner.innerHTML = `
      <svg class="crit-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17h.01"/></svg>
      <div class="crit-banner-body">
        <div class="crit-banner-title">${crit.total} problemas críticos acontecendo ao mesmo tempo</div>
        <div class="crit-banner-sub">${nomes}${resto} — veja os detalhes na aba Notificações.</div>
      </div>`;
    banner.classList.remove("hidden");
  } catch (e) {}
}

function alertsGrid() { return $("alertsGrid"); }
function alertsEmpty() { return $("alertsEmpty"); }

function makeAlertCard(comp, i) {
  const statusLabel = { warning: "Alerta", offline: "Offline" };
  const card = document.createElement("div");
  card.className = `computer-card ${comp.status === "warning" ? "warn" : "off"}`;
  card.style.animationDelay = `${i * 0.05}s`;
  card.innerHTML = `
    <div class="card-top">
      <span class="computer-code">${comp.code}</span>
      <span class="status-badge ${comp.status}"><span class="status-dot ${comp.status}"></span>${statusLabel[comp.status]}</span>
    </div>
    <div class="computer-label">${comp.label}</div>
    <div class="computer-loc">
      <svg width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 2a6 6 0 016 6c0 5-6 12-6 12S4 13 4 8a6 6 0 016-6z"/><circle cx="10" cy="8" r="2"/></svg>
      ${comp.location}
    </div>
    <div class="alert-reason">${timeAgo(comp.last_seen)}</div>`;
  card.addEventListener("click", () => openComputer(comp));
  return card;
}

// Contador de alertas no menu lateral (independe da tela aberta)
async function updateAlertBadge() {
  try {
    const list = await fetchJSON(API.search(""));
    const n = list.filter(c => c.status !== "online" && !c.sem_agente).length;
    $("alertBadge").textContent = n;
  } catch (e) {}
}

// ── Notificações (lista com lida/não lida + filtros + pop-up/toast) ──

// Guarda o maior id de evento já mostrado como pop-up, pra só avisar
// sobre eventos realmente novos (mesmo depois de recarregar a página).
let lastSeenNotifId = parseInt(localStorage.getItem("sysmon_last_notif_id"), 10) || 0;

// Último resultado buscado da API, pra filtrar na hora sem precisar
// pedir de novo pro servidor toda vez que troca o filtro.
let notifCache = [];
let notifFilter = "todos";

const notifIconByLevel = { offline: "offline", warning: "warning", critical: "critical", recuperado: "recuperado" };

async function loadNotifications() {
  const empty = $("notifEmpty");
  try {
    const data = await fetchJSON(API.notifications);
    if (!data.ok) return;
    $("notifBadge").textContent = data.unread;

    notifCache = data.events;
    renderNotifSummary(data);
    renderNotifList();
    checkForNewNotifToasts(data.events);
  } catch (e) { console.error(e); }
}

function renderNotifSummary(data) {
  const el = $("notifSummary");
  if (!el) return;
  const criticosAtivos = data.critico ? data.critico.total : 0;
  const vermelhosTotal = notifCache.filter(e => e.nivel === "critical" || e.nivel === "offline").length;
  el.textContent = `${vermelhosTotal} problema(s) vermelho(s) no histórico · ${criticosAtivos} ainda em andamento agora`;
}

function applyNotifFilter(events, filter) {
  switch (filter) {
    case "vermelho":   return events.filter(e => e.nivel === "critical" || e.nivel === "offline");
    case "ativos":     return events.filter(e => e.ativo);
    case "resolvidos": return events.filter(e => !e.ativo);
    case "nao_lidas":  return events.filter(e => !e.lida);
    default:           return events;
  }
}

function renderNotifList() {
  const list = $("notifList");
  const empty = $("notifEmpty");
  const filtered = applyNotifFilter(notifCache, notifFilter);

  list.innerHTML = "";
  if (!filtered.length) { empty.classList.remove("hidden"); return; }
  empty.classList.add("hidden");
  filtered.forEach(ev => list.appendChild(makeNotifRow(ev)));
}

document.querySelectorAll(".notif-filter-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    document.querySelectorAll(".notif-filter-btn").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    notifFilter = btn.dataset.filter;
    renderNotifList();
  });
});

function makeNotifRow(ev) {
  const row = document.createElement("div");
  row.className = `notif-row ${ev.lida ? "read" : "unread"}`;
  row.dataset.id = ev.id;
  row.innerHTML = `
    <div class="notif-dot ${notifIconByLevel[ev.nivel] || "warning"}"></div>
    <div class="notif-body">
      <div class="notif-msg">${ev.mensagem}</div>
      <div class="notif-meta">
        <span>${timeAgo(ev.criado_em)}</span>
        <span class="notif-tag ${ev.ativo ? "ativo" : "resolvido"}">${ev.ativo ? "Em andamento" : "Resolvido"}</span>
      </div>
    </div>
    ${!ev.lida ? '<div class="notif-unread-mark" title="Não lida"></div>' : ""}`;
  row.addEventListener("click", () => markNotifRead(ev.id, row));
  return row;
}

async function markNotifRead(id, row) {
  if (row && row.classList.contains("read")) return; // já lida, nada a fazer
  try {
    await fetch(API.notifications, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ acao: "marcar_lida", id }),
    });
    if (row) {
      row.classList.remove("unread");
      row.classList.add("read");
      const mark = row.querySelector(".notif-unread-mark");
      if (mark) mark.remove();
    }
    updateNotifBadgeCount();
  } catch (e) {}
}

const btnMarkAllRead = $("btnMarkAllRead");
if (btnMarkAllRead) {
  btnMarkAllRead.addEventListener("click", async () => {
    btnMarkAllRead.disabled = true;
    try {
      await fetch(API.notifications, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ acao: "marcar_todas_lidas" }),
      });
      await loadNotifications();
    } finally {
      btnMarkAllRead.disabled = false;
    }
  });
}

// Contador do sininho "Notificações" — roda sempre, em qualquer tela.
async function updateNotifBadgeCount() {
  try {
    const data = await fetchJSON(API.notifications);
    if (!data.ok) return;
    $("notifBadge").textContent = data.unread;
    checkForNewNotifToasts(data.events);
  } catch (e) {}
}

// Compara a lista recebida com o último id visto e mostra um pop-up
// (toast) pra cada evento novo — cobre tanto máquina entrando em
// alerta/offline quanto métrica/temperatura passando do limite.
function checkForNewNotifToasts(events) {
  if (!events || !events.length) return;
  const novos = events.filter(e => e.id > lastSeenNotifId).sort((a, b) => a.id - b.id);
  const maiorId = Math.max(lastSeenNotifId, ...events.map(e => e.id));

  // Na primeira carga da sessão (lastSeenNotifId ainda em 0), não
  // dispara um monte de pop-up de eventos antigos — só atualiza a
  // marca d'água e segue a vida.
  if (lastSeenNotifId > 0) {
    novos.slice(0, 5).forEach(showToast); // limite de 5 de uma vez, pra não lotar a tela
  }
  lastSeenNotifId = maiorId;
  localStorage.setItem("sysmon_last_notif_id", String(maiorId));
}

function showToast(ev) {
  const container = $("toastContainer");
  if (!container) return;
  const toast = document.createElement("div");
  toast.className = `toast ${notifIconByLevel[ev.nivel] || "warning"}`;
  const titleByLevel = { offline: "Máquina offline", warning: "Alerta", critical: "Limite ultrapassado", recuperado: "Normalizado" };
  toast.innerHTML = `<div class="toast-title">${titleByLevel[ev.nivel] || "Notificação"}</div>${ev.mensagem}`;
  toast.addEventListener("click", () => {
    toast.remove();
    (ev.onClick || (() => navBtns.notifications.click()))();
  });
  container.appendChild(toast);
  setTimeout(() => {
    toast.classList.add("fade-out");
    setTimeout(() => toast.remove(), 300);
  }, 7000);
}

// ── Configurações ───────────────────────────────────────
function openSettings() {
  $("settingsUsername").textContent = $("sidebarUsername").textContent;
  $("settingsAvatar").textContent   = $("sidebarUsername").textContent.charAt(0).toUpperCase();
  $("currentPasswordUsername").value = "";
  $("currentPassword").value = "";
  $("newUsername").value = "";
  $("newPassword").value = "";
  $("settingsMsg").className = "settings-msg hidden";
  $("usernameMsg").className = "settings-msg hidden";
  $("refreshIntervalSelect").value = String(REFRESH_MS);
  loadEmailSettings();
}

$("refreshIntervalSelect").addEventListener("change", e => {
  REFRESH_MS = parseInt(e.target.value, 10);
  localStorage.setItem("sysmon_refresh_ms", String(REFRESH_MS));
  // Reinicia o timer ativo (se houver) já com o novo intervalo.
  if (refreshTimer) {
    clearInterval(refreshTimer);
    refreshTimer = views.detail.classList.contains("active")
      ? setInterval(refreshDetail, REFRESH_MS)
      : setInterval(loadAlerts, REFRESH_MS);
  }
  if (monitorRefreshTimer) {
    clearInterval(monitorRefreshTimer);
    monitorRefreshTimer = setInterval(loadMonitorAll, REFRESH_MS);
  }
});

$("btnChangeUsername").addEventListener("click", async () => {
  const current_password = $("currentPasswordUsername").value;
  const new_username      = $("newUsername").value.trim();
  const msg = $("usernameMsg");
  const btn = $("btnChangeUsername");

  btn.disabled = true;
  try {
    const data = await fetch(API.changeUsername, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ current_password, new_username }),
    }).then(r => r.json());

    if (data.ok) {
      msg.textContent = "Usuário atualizado com sucesso.";
      msg.className = "settings-msg show-ok";
      $("sidebarUsername").textContent = data.usuario;
      $("settingsUsername").textContent = data.usuario;
      $("settingsAvatar").textContent = data.usuario.charAt(0).toUpperCase();
      $("currentPasswordUsername").value = "";
      $("newUsername").value = "";
    } else {
      msg.textContent = data.error || "Não foi possível alterar o usuário.";
      msg.className = "settings-msg show-error";
    }
  } catch (e) {
    msg.textContent = "Erro de conexão. Tente novamente.";
    msg.className = "settings-msg show-error";
  } finally {
    btn.disabled = false;
  }
});

$("btnChangePassword").addEventListener("click", async () => {
  const current_password = $("currentPassword").value;
  const new_password     = $("newPassword").value;
  const msg = $("settingsMsg");
  const btn = $("btnChangePassword");

  btn.disabled = true;
  try {
    const data = await fetch(API.changePassword, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ current_password, new_password }),
    }).then(r => r.json());

    if (data.ok) {
      msg.textContent = "Senha alterada com sucesso.";
      msg.className = "settings-msg show-ok";
      $("currentPassword").value = "";
      $("newPassword").value = "";
    } else {
      msg.textContent = data.error || "Não foi possível alterar a senha.";
      msg.className = "settings-msg show-error";
    }
  } catch (e) {
    msg.textContent = "Erro de conexão. Tente novamente.";
    msg.className = "settings-msg show-error";
  } finally {
    btn.disabled = false;
  }
});

// ── Usuários (tela restrita aos admins) ──────────────────
const usersList  = $("usersList");
const usersEmpty = $("usersEmpty");

function fmtDateTime(iso) {
  if (!iso) return "—";
  const d = new Date(iso.replace(" ", "T"));
  if (isNaN(d)) return iso;
  return d.toLocaleString("pt-BR", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

async function openUsers() {
  if (!usersList) return;
  $("newUserUsername").value = "";
  $("newUserEmail").value    = "";
  $("newUserPassword").value = "";
  $("newUserMsg").className  = "settings-msg hidden";
  await loadUsers();
}

async function loadUsers() {
  usersList.innerHTML = "";
  usersEmpty.classList.add("hidden");
  try {
    const data = await fetch(API.users).then(r => r.json());
    const list = (data.ok && data.users) || [];
    if (!list.length) { usersEmpty.classList.remove("hidden"); return; }
    list.forEach(u => usersList.appendChild(makeUserRow(u)));
  } catch (e) {
    console.error(e);
  }
}

function makeUserRow(u) {
  const row = document.createElement("div");
  row.className = "user-row m-card";
  row.dataset.usuario = u.usuario;
  row.innerHTML = `
    <div class="user-row-main">
      <div class="user-row-name">${u.usuario}</div>
      <div class="user-row-meta mono-text">Criado em ${fmtDateTime(u.criado_em)}${u.criado_por ? ` por ${esc(u.criado_por)}` : ""}</div>
      <div class="user-row-meta mono-text user-row-email-line">${u.email ? `E-mail: ${esc(u.email)}` : `<span class="email-missing">E-mail não cadastrado</span>`}</div>
    </div>
    <span class="status-badge ${u.ativo ? "online" : "offline"}">${u.ativo ? "Ativo" : "Inativo"}</span>
    <div class="user-row-actions">
      <input type="email" class="settings-input user-row-email-input hidden" placeholder="novo e-mail" autocomplete="off" maxlength="150"/>
      <button class="btn-row-action btn-edit-email">${u.email ? "Alterar e-mail" : "Cadastrar e-mail"}</button>
      <input type="password" class="settings-input user-row-pass-input hidden" placeholder="Nova senha" autocomplete="new-password"/>
      <button class="btn-row-action btn-reset-pass">Redefinir senha</button>
      <button class="btn-row-action ${u.ativo ? "danger" : ""} btn-toggle-active">${u.ativo ? "Desativar" : "Ativar"}</button>
    </div>
    <div class="settings-msg hidden user-row-msg"></div>
  `;

  const emailInput  = row.querySelector(".user-row-email-input");
  const btnEmail    = row.querySelector(".btn-edit-email");
  const passInput   = row.querySelector(".user-row-pass-input");
  const btnReset     = row.querySelector(".btn-reset-pass");
  const btnToggle     = row.querySelector(".btn-toggle-active");
  const msg          = row.querySelector(".user-row-msg");

  btnEmail.addEventListener("click", async () => {
    // Primeiro clique: revela o campo (já com o e-mail atual). Segundo: salva.
    if (emailInput.classList.contains("hidden")) {
      emailInput.value = u.email || "";
      emailInput.classList.remove("hidden");
      emailInput.focus();
      btnEmail.textContent = "Salvar e-mail";
      return;
    }
    btnEmail.disabled = true;
    try {
      const data = await fetch(API.userEmail, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ usuario: u.usuario, email: emailInput.value.trim() }),
      }).then(r => r.json());

      if (data.ok) {
        u.email = data.email;
        row.replaceWith(makeUserRow(u));
      } else {
        msg.textContent = data.error || "Não foi possível salvar o e-mail.";
        msg.className = "settings-msg show-error user-row-msg";
      }
    } catch (e) {
      msg.textContent = "Erro de conexão. Tente novamente.";
      msg.className = "settings-msg show-error user-row-msg";
    } finally {
      btnEmail.disabled = false;
    }
  });

  btnReset.addEventListener("click", async () => {
    // Primeiro clique: revela o campo de senha. Segundo clique (já com
    // senha digitada): confirma a redefinição.
    if (passInput.classList.contains("hidden")) {
      passInput.classList.remove("hidden");
      passInput.focus();
      btnReset.textContent = "Confirmar";
      return;
    }
    const senha = passInput.value;
    if (senha.length < 6) {
      msg.textContent = "A nova senha precisa ter pelo menos 6 caracteres.";
      msg.className = "settings-msg show-error user-row-msg";
      return;
    }
    btnReset.disabled = true;
    try {
      const data = await fetch(API.userPassword, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ usuario: u.usuario, senha }),
      }).then(r => r.json());

      if (data.ok) {
        msg.textContent = "Senha redefinida com sucesso.";
        msg.className = "settings-msg show-ok user-row-msg";
        passInput.value = "";
        passInput.classList.add("hidden");
        btnReset.textContent = "Redefinir senha";
      } else {
        msg.textContent = data.error || "Não foi possível redefinir a senha.";
        msg.className = "settings-msg show-error user-row-msg";
      }
    } catch (e) {
      msg.textContent = "Erro de conexão. Tente novamente.";
      msg.className = "settings-msg show-error user-row-msg";
    } finally {
      btnReset.disabled = false;
    }
  });

  btnToggle.addEventListener("click", async () => {
    btnToggle.disabled = true;
    try {
      const data = await fetch(API.userToggle, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ usuario: u.usuario }),
      }).then(r => r.json());

      if (data.ok) {
        u.ativo = data.ativo;
        row.replaceWith(makeUserRow(u));
      } else {
        msg.textContent = data.error || "Não foi possível atualizar o acesso.";
        msg.className = "settings-msg show-error user-row-msg";
      }
    } catch (e) {
      msg.textContent = "Erro de conexão. Tente novamente.";
      msg.className = "settings-msg show-error user-row-msg";
    } finally {
      btnToggle.disabled = false;
    }
  });

  return row;
}

if ($("btnCreateUser")) {
  $("btnCreateUser").addEventListener("click", async () => {
    const usuario = $("newUserUsername").value.trim();
    const senha   = $("newUserPassword").value;
    const email   = $("newUserEmail").value.trim();
    const msg = $("newUserMsg");
    const btn = $("btnCreateUser");

    btn.disabled = true;
    try {
      const data = await fetch(API.users, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ usuario, senha, email }),
      }).then(r => r.json());

      if (data.ok) {
        msg.textContent = "Usuário cadastrado com sucesso.";
        msg.className = "settings-msg show-ok";
        $("newUserUsername").value = "";
        $("newUserEmail").value = "";
        $("newUserPassword").value = "";
        await loadUsers();
      } else {
        msg.textContent = data.error || "Não foi possível cadastrar o usuário.";
        msg.className = "settings-msg show-error";
      }
    } catch (e) {
      msg.textContent = "Erro de conexão. Tente novamente.";
      msg.className = "settings-msg show-error";
    } finally {
      btn.disabled = false;
    }
  });
}

// ── Manutenção (aba do computador): quando foi feita e por quem ──────
function nowLocalInput() {
  // Valor para <input type="datetime-local">, no fuso do navegador.
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
}

function makeMaintenanceRow(m, isAdmin) {
  return `
    <div class="maint-row m-card" data-id="${m.id}">
      <div class="maint-row-top">
        <span class="maint-tag ${esc(m.tipo)}">${esc(m.tipo_label)}</span>
        <span class="maint-when mono-text">${fmtDateTime(m.realizado_em)}</span>
        ${isAdmin ? `<button class="btn-row-action danger btn-del-maint" data-id="${m.id}">Excluir</button>` : ""}
      </div>
      <div class="maint-desc">${esc(m.descricao)}</div>
      <div class="maint-meta mono-text">Registrado por <b>${esc(m.registrado_por)}</b> em ${fmtDateTime(m.criado_em)}</div>
    </div>`;
}

async function renderMaintenance(code, flashOk = "") {
  metricArea.innerHTML = `<div style="color:var(--text3);font-family:var(--mono);font-size:12px;padding:20px 0;">Carregando...</div>`;

  let data;
  try {
    data = await fetchJSON(API.maintenance(code));
  } catch (e) {
    metricArea.innerHTML = `<div class="temp-chart-empty">Não foi possível carregar o histórico de manutenção.</div>`;
    return;
  }
  // A pessoa pode ter trocado de aba/computador enquanto carregava.
  if (!currentComputer || currentComputer.code !== code || currentCat !== "maintenance") return;
  if (!data.ok) {
    metricArea.innerHTML = `<div class="temp-chart-empty">${esc(data.error || "Erro ao carregar manutenções.")}</div>`;
    return;
  }

  const options = Object.entries(data.tipos)
    .map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join("");
  const itens = data.itens.map(m => makeMaintenanceRow(m, data.admin)).join("");
  const agora = nowLocalInput();

  metricArea.innerHTML = `
    <div class="section-title">Registrar manutenção</div>
    <div class="m-card form-card maint-form">
      <div class="maint-form-grid">
        <div>
          <label class="settings-label" for="maintTipo">Tipo</label>
          <select class="settings-input" id="maintTipo">${options}</select>
        </div>
        <div>
          <label class="settings-label" for="maintQuando">Data e hora</label>
          <input class="settings-input" type="datetime-local" id="maintQuando" value="${agora}" max="${agora}"/>
        </div>
      </div>
      <label class="settings-label" for="maintDesc">O que foi feito</label>
      <textarea class="settings-input" id="maintDesc" maxlength="1000" placeholder="Ex.: limpeza interna e troca da pasta térmica"></textarea>
      <div class="settings-hint mono-text">Será registrado em nome de <b>${esc($("sidebarUsername").textContent)}</b>.</div>
      <div class="settings-msg ${flashOk ? "show-ok" : "hidden"}" id="maintMsg">${esc(flashOk)}</div>
      <button class="btn-settings-save" id="btnSaveMaint" style="margin-top:14px;">Registrar manutenção</button>
    </div>

    <div class="section-title" style="margin-top:26px;">Histórico de manutenção (${data.itens.length})</div>
    <div class="maint-list">
      ${itens || `<div class="temp-chart-empty">Nenhuma manutenção registrada para este computador.</div>`}
    </div>`;

  $("btnSaveMaint").addEventListener("click", async () => {
    const msg = $("maintMsg");
    const btn = $("btnSaveMaint");
    btn.disabled = true;
    try {
      const res = await fetch(API.maintenanceSave, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          acao: "criar",
          codigo: code,
          tipo: $("maintTipo").value,
          realizado_em: $("maintQuando").value,
          descricao: $("maintDesc").value.trim(),
        }),
      }).then(r => r.json());

      if (res.ok) {
        await renderMaintenance(code, "Manutenção registrada.");
      } else {
        msg.textContent = res.error || "Não foi possível registrar a manutenção.";
        msg.className = "settings-msg show-error";
        btn.disabled = false;
      }
    } catch (e) {
      msg.textContent = "Erro de conexão. Tente novamente.";
      msg.className = "settings-msg show-error";
      btn.disabled = false;
    }
  });

  metricArea.querySelectorAll(".btn-del-maint").forEach(b => {
    b.addEventListener("click", async () => {
      if (!confirm("Excluir este registro de manutenção? Não tem como desfazer.")) return;
      b.disabled = true;
      try {
        const res = await fetch(API.maintenanceSave, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ acao: "excluir", id: parseInt(b.dataset.id, 10) }),
        }).then(r => r.json());
        if (res.ok) await renderMaintenance(code);
        else { alert(res.error || "Não foi possível excluir."); b.disabled = false; }
      } catch (e) {
        alert("Erro de conexão. Tente novamente.");
        b.disabled = false;
      }
    });
  });
}

// ── E-mail do usuário logado (Configurações) ─────────────────────────
function emailMsg(text, ok) {
  const el = $("emailMsg");
  el.textContent = text;
  el.className = `settings-msg ${ok ? "show-ok" : "show-error"}`;
}

async function loadEmailSettings() {
  $("emailMsg").className = "settings-msg hidden";
  try {
    const d = await fetchJSON(API.emailSettings);
    if (!d.ok) return;
    $("emailInput").value = d.email || "";
    $("emailNotify").checked = !!d.notificar;
    $("emailHint").textContent = d.email
      ? "Você recebe um e-mail quando um PC fica offline ou passa do limite."
      : "Cadastre seu e-mail para receber os alertas do painel.";
  } catch (e) {}
}

$("btnSaveEmail").addEventListener("click", async () => {
  const btn = $("btnSaveEmail");
  btn.disabled = true;
  try {
    const d = await fetch(API.emailSettings, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ acao: "salvar", email: $("emailInput").value.trim(), notificar: $("emailNotify").checked }),
    }).then(r => r.json());
    if (d.ok) emailMsg("E-mail salvo.", true);
    else emailMsg(d.error || "Não foi possível salvar o e-mail.", false);
  } catch (e) {
    emailMsg("Erro de conexão. Tente novamente.", false);
  } finally {
    btn.disabled = false;
  }
});

$("btnTestEmail").addEventListener("click", async () => {
  const btn = $("btnTestEmail");
  btn.disabled = true;
  emailMsg("Enviando e-mail de teste...", true);
  try {
    const d = await fetch(API.emailSettings, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ acao: "testar" }),
    }).then(r => r.json());
    if (d.ok) emailMsg(`E-mail de teste enviado para ${d.email}. Confira a caixa de entrada (e o spam).`, true);
    else emailMsg(d.error || "Não foi possível enviar o e-mail de teste.", false);
  } catch (e) {
    emailMsg("Erro de conexão. Tente novamente.", false);
  } finally {
    btn.disabled = false;
  }
});

// Lembrete único por sessão se o usuário logado ainda não tem e-mail.
async function checkMyEmail() {
  try {
    const d = await fetchJSON(API.emailSettings);
    if (d.ok && !d.email) {
      showToast({
        nivel: "warning",
        mensagem: "Você ainda não cadastrou seu e-mail. Toque aqui e cadastre em Configurações para receber os alertas.",
        onClick: () => navBtns.settings.click(),
      });
    }
  } catch (e) {}
}


// ── Histórico de problemas (semanal / mensal) ─────────────────────────
let histPeriodo = "semana";

navBtns.history.addEventListener("click", () => {
  setNavActive(navBtns.history);
  showView(views.history, "Histórico de problemas");
  clearRefresh();
  loadHistory();
  refreshTimer = setInterval(loadHistory, Math.max(REFRESH_MS, 30000));
});

document.querySelectorAll(".hist-periodo").forEach(btn => {
  btn.addEventListener("click", () => {
    document.querySelectorAll(".hist-periodo").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    histPeriodo = btn.dataset.periodo;
    loadHistory();
  });
});

function fmtDuracao(min) {
  min = Math.max(0, Math.round(min || 0));
  if (min < 60) return `${min}min`;
  const h = Math.floor(min / 60), m = min % 60;
  if (h < 24) return m ? `${h}h ${m}min` : `${h}h`;
  const d = Math.floor(h / 24), hr = h % 24;
  return hr ? `${d}d ${hr}h` : `${d}d`;
}

function fmtDiaCurto(iso) {
  const [, m, d] = iso.split("-");
  return `${d}/${m}`;
}

async function loadHistory() {
  const box = $("histContent");
  try {
    const d = await fetchJSON(API.history(histPeriodo));
    if (!d.ok) { box.innerHTML = `<div class="temp-chart-empty">Não foi possível carregar o histórico.</div>`; return; }
    renderHistory(d);
  } catch (e) {
    console.error(e);
    box.innerHTML = `<div class="temp-chart-empty">Erro de conexão ao carregar o histórico.</div>`;
  }
}

function histBarChart(dias) {
  const max = Math.max(1, ...dias.map(x => x.total));
  const many = dias.length > 10;
  const cols = dias.map((x, i) => {
    const hTot = Math.round((x.total / max) * 100);
    const hCrit = x.total ? Math.round((x.criticos / x.total) * 100) : 0;
    const label = many ? (i % 5 === 0 ? fmtDiaCurto(x.dia) : "") : fmtDiaCurto(x.dia);
    return `<div class="hist-col" title="${fmtDiaCurto(x.dia)}: ${x.total} problema(s), ${x.criticos} vermelho(s)">
      <div class="hist-col-num">${x.total || ""}</div>
      <div class="hist-col-bar" style="height:${hTot}%">
        <div class="hist-col-crit" style="height:${hCrit}%"></div>
      </div>
      <div class="hist-col-label">${label}</div>
    </div>`;
  }).join("");
  return `<div class="hist-chart ${many ? "dense" : ""}">${cols}</div>
    <div class="hist-legend mono-text"><span><i class="lg lg-all"></i>Todos os problemas</span><span><i class="lg lg-crit"></i>Vermelhos (crítico/offline)</span></div>`;
}

function histTable(titulo, cols, linhas, vazio) {
  const head = cols.map(c => `<th>${c}</th>`).join("");
  const body = linhas.length ? linhas.join("") : `<tr><td colspan="${cols.length}" class="hist-empty-row">${vazio}</td></tr>`;
  return `<div class="hist-card"><div class="hist-card-title">${titulo}</div>
    <div class="hist-table-wrap"><table class="hist-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div></div>`;
}

function histStat(label, valor, sub, cor = "") {
  return `<div class="m-card hist-stat ${cor}"><div class="m-card-label">${esc(label)}</div>
    <div class="m-card-val">${esc(valor)}</div><div class="m-card-sub">${esc(sub)}</div></div>`;
}

function renderHistory(d) {
  const box = $("histContent");
  const t = d.totais;
  const rotuloPeriodo = d.periodo === "mes" ? "nos últimos 30 dias" : "nos últimos 7 dias";

  if (!t.total) {
    box.innerHTML = `<div class="hist-cards">${histStat("Problemas", 0, rotuloPeriodo)}</div>
      <div class="empty-state"><div>Nenhum problema registrado ${rotuloPeriodo}.</div></div>`;
    return;
  }

  const stats = `<div class="hist-cards">
    ${histStat("Problemas", t.total, rotuloPeriodo)}
    ${histStat("Vermelhos", t.criticos + t.offline, `${t.criticos} crítico(s) · ${t.offline} offline`, "red")}
    ${histStat("Computadores afetados", t.pcs_afetados, "com pelo menos 1 problema")}
    ${histStat("Em andamento", t.em_andamento, "ainda não resolvidos", t.em_andamento ? "yellow" : "")}
    ${histStat("Tempo em problema", fmtDuracao(t.minutos), "soma de todos os eventos")}
  </div>`;

  const porPc = histTable("Computadores com mais problemas",
    ["Computador", "Problemas", "Vermelhos", "Tempo"],
    d.por_pc.map(p => `<tr><td><span class="mono-text">${esc(p.codigo)}</span><div>${esc(p.label)}</div></td>
      <td>${p.total}</td><td class="${p.criticos ? "hist-red" : ""}">${p.criticos}</td><td>${fmtDuracao(p.minutos)}</td></tr>`),
    "Sem dados.");

  const porTipo = histTable("Tipos de problema",
    ["Tipo", "Ocorrências"],
    d.por_tipo.map(x => `<tr><td>${esc(x.rotulo)}</td><td>${x.total}</td></tr>`),
    "Sem dados.");

  const dot = { offline: "offline", warning: "warning", critical: "critical" };
  const lista = d.eventos.map(ev => `<div class="notif-row read hist-ev">
      <div class="notif-dot ${dot[ev.nivel] || "warning"}"></div>
      <div class="notif-body">
        <div class="notif-msg">${esc(ev.mensagem)}</div>
        <div class="notif-meta">
          <span>${esc(fmtDateTime(ev.criado_em))}</span>
          <span class="notif-tag ${ev.ativo ? "ativo" : "resolvido"}">${ev.ativo ? "Em andamento" : "Resolvido"}</span>
          <span>${fmtDuracao(ev.dur_min)}</span>
        </div>
      </div>
    </div>`).join("");

  box.innerHTML = `${stats}
    <div class="hist-card"><div class="hist-card-title">Problemas por dia</div>${histBarChart(d.por_dia)}</div>
    <div class="hist-grid">${porPc}${porTipo}</div>
    <div class="hist-card"><div class="hist-card-title">Ocorrências do período${d.eventos.length < t.total ? ` (mostrando as ${d.eventos.length} mais recentes)` : ""}</div>
      <div class="notif-list" style="max-width:none">${lista}</div></div>
    ${d.truncado ? `<div class="mono-text">Há mais de 3000 ocorrências no período; o resumo considera só as 3000 mais recentes.</div>` : ""}`;
}

// ══════════════════════════════════════════════════════════════════
// Plantas do campus — bolinhas de status por sala
// ══════════════════════════════════════════════════════════════════
// Cada bolinha fica numa posição (x/y em % da imagem) da planta e está
// ligada a uma ou mais salas do cadastro. Cor e números vêm do servidor
// (api/plantas.php), calculados ao vivo a partir do status dos PCs.
// Só administradores veem o botão "Editar bolinhas".

let voltarParaPlantas = false;   // true quando um computador foi aberto a partir de uma planta

const PL = {
  data: null,          // última resposta da API
  admin: false,
  current: null,       // id da planta aberta (ex.: "principal-terreo")
  selected: null,      // id do marcador selecionado
  edit: false,         // modo de edição ligado?
  draft: null,         // marcador sendo criado/editado {id, planta, x, y, nome, salas:Set, nomeTocado}
  pending: null,       // sala escolhida em "salas sem bolinha", esperando o clique na planta
  salaFiltro: "",
  claro: localStorage.getItem("sysmon_planta_claro") === "1",
  salvando: false,
};

const COR_ROTULO = { verde: "Tudo online e sem problemas", vermelho: "Com problemas / algum offline", cinza: "Offline ou sem agente" };
const CAT_ROTULO = { ok: "Sem problemas", problema: "Com problemas", offline: "Offline", sem_agente: "Sem agente" };

const plView   = () => $("viewPlantas");
const plPlanta = () => PL.data && PL.data.plantas.find(p => p.id === PL.current);
const plAcha   = id => { for (const p of PL.data.plantas) { const m = p.marcadores.find(x => x.id === id); if (m) return { planta: p, marcador: m }; } return null; };

function abrirPlantas() {
  voltarParaPlantas = false;
  setNavActive(navBtns.plantas);
  showView(views.plantas, "Plantas do campus");
  clearRefresh();
  loadPlantas();
  // Não redesenha enquanto o admin está editando/arrastando (perderia o que digitou).
  refreshTimer = setInterval(() => { if (!PL.draft && !PL.salvando) loadPlantas(true); }, REFRESH_MS);
}

navBtns.plantas.addEventListener("click", abrirPlantas);

// Ao usar qualquer item do menu, o "voltar" do detalhe deixa de apontar para as plantas.
document.querySelectorAll(".nav-btn").forEach(b => b.addEventListener("click", () => { if (b !== navBtns.plantas) voltarParaPlantas = false; }));

async function loadPlantas(silencioso = false) {
  try {
    const d = await fetch(API.plantas).then(r => r.json());
    if (!d.ok) throw new Error(d.error || "Erro ao carregar as plantas.");
    PL.data = d;
    PL.admin = !!d.admin;
    if (!PL.current || !plPlanta()) {
      const comMarcador = d.plantas.find(p => p.marcadores.length);
      PL.current = (comMarcador || d.plantas[0]).id;
    }
    if (PL.selected && !plAcha(PL.selected)) PL.selected = null;
    renderPlantas();
  } catch (e) {
    console.error(e);
    if (!silencioso) $("plantaStage").innerHTML = `<div class="temp-chart-empty">${esc(e.message || "Erro ao carregar as plantas.")}</div>`;
  }
}

function renderPlantas() {
  renderPlantaSummary();
  renderPlantaTabs();
  renderPlantaStage();
  renderPlantaSide();
  renderPlantaLivres();
  renderPlantaHint();
}

// ── Resumo geral ────────────────────────────────
function renderPlantaSummary() {
  const g = PL.data.geral;
  const chip = (cls, v, rot) => `<div class="planta-chip ${cls}"><span class="planta-chip-val">${v}</span><span class="planta-chip-rot">${rot}</span></div>`;
  $("plantaSummary").innerHTML =
    chip("", g.total, "Computadores cadastrados") +
    chip("verde", g.ok, "Online, sem problemas") +
    chip("vermelho", g.problema, "Com problemas") +
    chip("cinza", g.offline, "Offline") +
    chip("dim", g.sem_agente, "Sem agente") +
    chip("dim", PL.data.mapeados, "Já ligados a uma bolinha");
}

// ── Abas: prédio → andar ────────────────────────
function corDaPlanta(p) {
  if (!p.marcadores.length) return "";
  if (p.marcadores.some(m => m.cor === "vermelho")) return "vermelho";
  if (p.marcadores.some(m => m.cor === "verde")) return "verde";
  return "cinza";
}

function renderPlantaTabs() {
  const predios = [...new Set(PL.data.plantas.map(p => p.predio))];
  $("plantaTabs").innerHTML = predios.map(predio => {
    const abas = PL.data.plantas.filter(p => p.predio === predio).map(p => {
      const c = corDaPlanta(p);
      return `<button type="button" class="planta-tab ${p.id === PL.current ? "active" : ""}" data-planta="${esc(p.id)}">
        ${c ? `<span class="planta-tab-dot ${c}"></span>` : ""}${esc(p.andar)}
        <span class="planta-tab-n">${p.marcadores.length}</span></button>`;
    }).join("");
    return `<div class="planta-tab-group"><span class="planta-tab-label">Prédio ${esc(predio)}</span>${abas}</div>`;
  }).join("");

  $("plantaTabs").querySelectorAll(".planta-tab").forEach(b => b.addEventListener("click", () => {
    if (PL.draft && !confirm("Descartar o marcador que está sendo editado?")) return;
    PL.current = b.dataset.planta;
    PL.draft = null;
    PL.selected = null;
    renderPlantas();
  }));
}

// ── A planta + bolinhas ─────────────────────────
function textoTooltip(m) {
  const n = m.n;
  return `${m.nome} — ${n.total} PC(s): ${n.ok} sem problemas, ${n.problema} com problemas, ${n.offline} offline` +
         (n.sem_agente ? `, ${n.sem_agente} sem agente` : "");
}

function dotHTML(m, extra = "") {
  const n = m.n.total;
  return `<button type="button" class="planta-dot cor-${m.cor} ${m.id === PL.selected ? "sel" : ""} ${n > 99 ? "big" : ""} ${extra}"
      data-id="${m.id}" style="left:${m.x}%;top:${m.y}%" title="${esc(textoTooltip(m))}">
      <span class="planta-dot-num">${n}</span>
      <span class="planta-dot-label">${esc(m.nome)}</span>
    </button>`;
}

function renderPlantaStage() {
  const p = plPlanta();
  const stage = $("plantaStage");
  const ghost = PL.draft && PL.draft.planta === p.id && !PL.draft.id
    ? `<span class="planta-dot ghost" style="left:${PL.draft.x}%;top:${PL.draft.y}%"><span class="planta-dot-num">+</span></span>` : "";

  stage.innerHTML = `
    <div class="planta-canvas ${PL.claro ? "claro" : ""} ${PL.edit ? "editando" : ""}" id="plantaCanvas">
      <img src="${esc(p.arquivo)}" alt="Planta — Prédio ${esc(p.predio)}, ${esc(p.andar)}" draggable="false"/>
      <div class="planta-markers">${p.marcadores.map(m => dotHTML(m, PL.draft && PL.draft.id === m.id ? "sel" : "")).join("")}${ghost}</div>
    </div>
    <div class="planta-legenda">
      <span><i class="planta-lg verde"></i>${COR_ROTULO.verde}</span>
      <span><i class="planta-lg vermelho"></i>${COR_ROTULO.vermelho}</span>
      <span><i class="planta-lg cinza"></i>${COR_ROTULO.cinza}</span>
      <span class="planta-lg-note">Prédio ${esc(p.predio)} · ${esc(p.andar)}</span>
    </div>
    ${p.marcadores.length || PL.edit ? "" : `<div class="planta-vazia">Nenhuma sala marcada nesta planta ainda.${PL.admin ? " Use <b>✎ Editar bolinhas</b> para posicionar as salas." : ""}</div>`}`;

  const canvas = $("plantaCanvas");
  $("plantaTema").textContent = PL.claro ? "Fundo escuro" : "Fundo claro";

  canvas.querySelectorAll(".planta-dot[data-id]").forEach(dot => {
    dot.addEventListener("click", e => {
      e.stopPropagation();
      if (PL.edit) return;                       // no modo edição, o clique é tratado pelo pointerup (arrastar/abrir)
      selecionarMarcador(+dot.dataset.id);
    });
    dot.addEventListener("pointerdown", e => { if (PL.edit) iniciarArraste(e, dot); });
  });

  canvas.addEventListener("click", e => {
    if (!PL.edit || e.target.closest(".planta-dot")) return;
    const r = canvas.getBoundingClientRect();
    novoRascunho((e.clientX - r.left) / r.width * 100, (e.clientY - r.top) / r.height * 100);
  });
}

function selecionarMarcador(id) {
  PL.selected = PL.selected === id ? null : id;
  document.querySelectorAll("#plantaCanvas .planta-dot[data-id]").forEach(d => d.classList.toggle("sel", +d.dataset.id === PL.selected));
  renderPlantaSide();
}

// ── Arrastar uma bolinha (modo edição) ──────────
function iniciarArraste(e, dot) {
  e.preventDefault();
  const canvas = $("plantaCanvas");
  const rect = canvas.getBoundingClientRect();
  const id = +dot.dataset.id;
  const x0 = e.clientX, y0 = e.clientY;
  let moveu = false, nx = null, ny = null;
  try { dot.setPointerCapture(e.pointerId); } catch (_) { /* ok */ }

  const mover = ev => {
    if (!moveu && Math.hypot(ev.clientX - x0, ev.clientY - y0) < 5) return;
    moveu = true;
    nx = Math.min(100, Math.max(0, (ev.clientX - rect.left) / rect.width * 100));
    ny = Math.min(100, Math.max(0, (ev.clientY - rect.top) / rect.height * 100));
    dot.style.left = nx + "%";
    dot.style.top = ny + "%";
  };
  const soltar = async () => {
    dot.removeEventListener("pointermove", mover);
    dot.removeEventListener("pointerup", soltar);
    dot.removeEventListener("pointercancel", soltar);
    if (!moveu) { abrirRascunhoDe(id); return; }          // foi só um clique: abre a edição
    PL.salvando = true;
    try {
      const d = await postPlantas({ acao: "mover", id, x: +nx.toFixed(3), y: +ny.toFixed(3) });
      if (!d.ok) throw new Error(d.error);
      const f = plAcha(id); if (f) { f.marcador.x = nx; f.marcador.y = ny; }
      if (PL.draft && PL.draft.id === id) { PL.draft.x = +nx.toFixed(3); PL.draft.y = +ny.toFixed(3); }
    } catch (err) {
      alert(err.message || "Não foi possível mover a bolinha.");
      await loadPlantas(true);
    } finally { PL.salvando = false; }
  };
  dot.addEventListener("pointermove", mover);
  dot.addEventListener("pointerup", soltar);
  dot.addEventListener("pointercancel", soltar);
}

async function postPlantas(body) {
  const r = await fetch(API.plantas, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  return r.json();
}

// ── Painel lateral ──────────────────────────────
function boxNum(cls, v, rot) {
  return `<div class="planta-num ${cls}"><div class="planta-num-v">${v}</div><div class="planta-num-r">${rot}</div></div>`;
}

function renderPlantaSide() {
  const side = $("plantaSide");
  if (PL.edit && PL.draft) { side.innerHTML = editorHTML(); ligarEditor(); return; }

  const p = plPlanta();
  let html = "";

  const sel = PL.selected ? plAcha(PL.selected) : null;
  if (sel && sel.planta.id === p.id) {
    const m = sel.marcador, n = m.n;
    const nomesSalas = m.salas.length > 1 || (m.salas[0] && m.salas[0] !== m.nome) ? `<div class="planta-det-salas">${m.salas.map(esc).join(" · ")}</div>` : "";
    html += `<div class="m-card planta-det">
      <div class="planta-det-top">
        <span class="planta-dot-mini cor-${m.cor}"></span>
        <div class="planta-det-nome">${esc(m.nome)}</div>
        <button type="button" class="planta-x" id="plantaFechar" title="Fechar">×</button>
      </div>
      ${nomesSalas}
      <div class="planta-det-cor">${COR_ROTULO[m.cor]}</div>
      <div class="planta-nums">
        ${boxNum("", n.total, "Computadores")}
        ${boxNum("verde", n.ok, "Sem problemas")}
        ${boxNum("vermelho", n.problema, "Com problemas")}
        ${boxNum("cinza", n.offline, "Offline")}
        ${n.sem_agente ? boxNum("dim", n.sem_agente, "Sem agente") : ""}
      </div>
      <div class="planta-pcs">${m.pcs.map(pc => `<button type="button" class="planta-pc cat-${pc.cat}" data-code="${esc(pc.code)}" title="${esc(CAT_ROTULO[pc.cat])} — ${esc(pc.sala)}">${esc(pc.code)}</button>`).join("")}</div>
      ${PL.admin ? `<button type="button" class="btn-row-action" id="plantaEditarSel">Editar esta bolinha</button>` : ""}
    </div>`;
  }

  html += `<div class="section-title">Salas neste andar (${p.marcadores.length})</div>`;
  if (!p.marcadores.length) {
    html += `<div class="planta-side-vazio">Nenhuma sala marcada neste andar.</div>`;
  } else {
    html += `<div class="planta-lista">` + p.marcadores.slice().sort((a, b) => a.nome.localeCompare(b.nome, "pt-BR", { numeric: true })).map(m => {
      const n = m.n;
      return `<button type="button" class="planta-row ${m.id === PL.selected ? "sel" : ""}" data-id="${m.id}">
        <span class="planta-dot-mini cor-${m.cor}"></span>
        <span class="planta-row-main">
          <span class="planta-row-nome">${esc(m.nome)}</span>
          <span class="planta-row-sub">${n.total} PC${n.total === 1 ? "" : "s"} · ${n.ok} ok · ${n.problema} c/ problema · ${n.offline} offline${n.sem_agente ? ` · ${n.sem_agente} s/ agente` : ""}</span>
        </span>
      </button>`;
    }).join("") + `</div>`;
  }
  side.innerHTML = html;

  side.querySelectorAll(".planta-row").forEach(b => b.addEventListener("click", () => selecionarMarcador(+b.dataset.id)));
  const fechar = $("plantaFechar"); if (fechar) fechar.addEventListener("click", () => selecionarMarcador(PL.selected));
  const ed = $("plantaEditarSel"); if (ed) ed.addEventListener("click", () => { PL.edit = true; atualizarBotaoEdicao(); abrirRascunhoDe(PL.selected); });
  side.querySelectorAll(".planta-pc").forEach(b => b.addEventListener("click", () => abrirComputadorDaPlanta(b.dataset.code)));
}

async function abrirComputadorDaPlanta(code) {
  try {
    const comp = await fetch(API.computer(code)).then(r => r.json());
    if (comp.error) throw new Error(comp.error);
    voltarParaPlantas = true;
    clearRefresh();
    openComputer(comp);
  } catch (e) { console.error(e); alert("Não foi possível abrir o computador."); }
}

// ── Salas ainda sem bolinha ─────────────────────
function renderPlantaLivres() {
  const box = $("plantaLivres");
  const l = PL.data.salas_livres;
  if (!l.length && !PL.data.sem_sala) { box.innerHTML = ""; return; }
  const chips = l.map(s => `<button type="button" class="planta-livre ${PL.pending === s.sala ? "sel" : ""}" data-sala="${esc(s.sala)}" ${PL.edit ? "" : "disabled"}>${esc(s.sala)} <b>${s.total}</b></button>`).join("");
  box.innerHTML = `
    <div class="section-title">Salas ainda sem bolinha (${l.length})</div>
    <div class="planta-livres-chips">${chips || '<span class="mono-text">Todas as salas já têm bolinha.</span>'}</div>
    ${PL.edit && l.length ? `<div class="settings-hint">Clique numa sala e depois no ponto da planta onde ela fica.</div>` : (l.length && PL.admin ? `<div class="settings-hint">Ative <b>✎ Editar bolinhas</b> para posicionar estas salas nas plantas.</div>` : "")}
    ${PL.data.sem_sala ? `<div class="settings-hint">${PL.data.sem_sala} computador(es) estão sem sala cadastrada e não aparecem em nenhuma bolinha.</div>` : ""}`;
  box.querySelectorAll(".planta-livre").forEach(b => b.addEventListener("click", () => {
    if (!PL.edit) return;
    PL.pending = PL.pending === b.dataset.sala ? null : b.dataset.sala;
    renderPlantaLivres(); renderPlantaHint();
  }));
}

// ── Modo edição (só admin) ──────────────────────
function atualizarBotaoEdicao() {
  const b = $("plantaEditToggle");
  if (b) b.textContent = PL.edit ? "✓ Concluir edição" : "✎ Editar bolinhas";
  if (b) b.classList.toggle("ativo", PL.edit);
}

function renderPlantaHint() {
  const h = $("plantaEditHint");
  if (!PL.edit) { h.classList.add("hidden"); return; }
  h.classList.remove("hidden");
  h.innerHTML = PL.pending
    ? `Agora clique na planta onde fica <b>${esc(PL.pending)}</b>.`
    : `<b>Modo edição:</b> clique num ponto vazio da planta para criar uma bolinha · arraste uma bolinha para mover · clique nela para editar as salas ou excluir.`;
}

const editToggle = $("plantaEditToggle");
if (editToggle) {
  editToggle.addEventListener("click", () => {
    if (PL.edit && PL.draft && !confirm("Descartar o marcador que está sendo editado?")) return;
    PL.edit = !PL.edit;
    PL.draft = null; PL.pending = null;
    atualizarBotaoEdicao();
    renderPlantas();
  });
}

$("plantaTema").addEventListener("click", () => {
  PL.claro = !PL.claro;
  localStorage.setItem("sysmon_planta_claro", PL.claro ? "1" : "0");
  const c = $("plantaCanvas"); if (c) c.classList.toggle("claro", PL.claro);
  $("plantaTema").textContent = PL.claro ? "Fundo escuro" : "Fundo claro";
});

function novoRascunho(x, y) {
  const p = plPlanta();
  const sala = PL.pending;
  PL.draft = { id: null, planta: p.id, x: +x.toFixed(3), y: +y.toFixed(3), nome: sala || "", salas: new Set(sala ? [sala] : []), nomeTocado: false };
  PL.pending = null; PL.salaFiltro = ""; PL.selected = null;
  renderPlantas();
}

function abrirRascunhoDe(id) {
  const f = plAcha(id);
  if (!f) return;
  PL.draft = { id, planta: f.planta.id, x: f.marcador.x, y: f.marcador.y, nome: f.marcador.nome, salas: new Set(f.marcador.salas), nomeTocado: true };
  PL.salaFiltro = ""; PL.selected = id;
  if (PL.current !== f.planta.id) PL.current = f.planta.id;
  renderPlantas();
}

// Todas as salas conhecidas, com quantos PCs e em qual bolinha já estão.
function todasAsSalas() {
  const mapa = new Map();
  PL.data.salas_livres.forEach(s => mapa.set(s.sala, { sala: s.sala, total: s.total, em: null }));
  PL.data.plantas.forEach(p => p.marcadores.forEach(m => {
    const cont = {};
    m.pcs.forEach(pc => { cont[pc.sala] = (cont[pc.sala] || 0) + 1; });
    m.salas.forEach(s => mapa.set(s, { sala: s, total: cont[s] || 0, em: { id: m.id, nome: m.nome, planta: p } }));
  }));
  return [...mapa.values()].sort((a, b) => a.sala.localeCompare(b.sala, "pt-BR", { numeric: true }));
}

function editorHTML() {
  const d = PL.draft;
  return `<div class="m-card planta-editor">
    <div class="form-card-title">${d.id ? "Editar bolinha" : "Nova bolinha"} · ${esc(plPlanta().andar)} (${esc(plPlanta().predio)})</div>
    <label class="settings-label" for="pdNome">Nome mostrado na planta</label>
    <input class="settings-input" id="pdNome" maxlength="100" value="${esc(d.nome)}" placeholder="Ex.: Lab. de Informática 1" autocomplete="off"/>
    <label class="settings-label" for="pdFiltro" style="margin-top:14px;">Salas ligadas a esta bolinha</label>
    <input class="settings-input" id="pdFiltro" value="${esc(PL.salaFiltro)}" placeholder="Filtrar salas..." autocomplete="off"/>
    <div class="planta-sala-list" id="pdSalas"></div>
    <div class="settings-hint">Marque mais de uma sala quando a planilha usa nomes diferentes para o mesmo lugar (ex.: LAB. GAMES e LAB. JOGOS). Uma sala só pode estar em uma bolinha.</div>
    <div class="settings-msg" id="pdMsg"></div>
    <div class="planta-editor-btns">
      <button type="button" class="btn-settings-save" id="pdSalvar">Salvar</button>
      <button type="button" class="btn-row-action" id="pdCancelar">Cancelar</button>
      ${d.id ? `<button type="button" class="btn-row-action danger" id="pdExcluir">Excluir bolinha</button>` : ""}
    </div>
  </div>`;
}

function desenharListaSalas() {
  const d = PL.draft;
  const f = PL.salaFiltro.trim().toLowerCase();
  const box = $("pdSalas");
  const lista = todasAsSalas().filter(s => !f || s.sala.toLowerCase().includes(f));
  // marcadas primeiro, depois as livres, por fim as que já pertencem a outra bolinha
  const peso = s => d.salas.has(s.sala) ? 0 : (!s.em ? 1 : 2);
  lista.sort((a, b) => peso(a) - peso(b) || a.sala.localeCompare(b.sala, "pt-BR", { numeric: true }));
  box.innerHTML = lista.map(s => {
    const outra = s.em && s.em.id !== d.id;
    return `<label class="planta-sala-item ${outra ? "outra" : ""}">
      <input type="checkbox" value="${esc(s.sala)}" ${d.salas.has(s.sala) ? "checked" : ""}/>
      <span class="planta-sala-nome">${esc(s.sala)}</span>
      <span class="planta-sala-n">${s.total} PC${s.total === 1 ? "" : "s"}</span>
      ${outra ? `<span class="planta-sala-em" title="Ao marcar, a sala sai da outra bolinha">hoje em «${esc(s.em.nome)}» (${esc(s.em.planta.andar)}, ${esc(s.em.planta.predio)})</span>` : ""}
    </label>`;
  }).join("") || `<div class="planta-side-vazio">Nenhuma sala encontrada.</div>`;

  box.querySelectorAll("input[type=checkbox]").forEach(cb => cb.addEventListener("change", () => {
    if (cb.checked) d.salas.add(cb.value); else d.salas.delete(cb.value);
    const nomeEl = $("pdNome");
    if (!d.nomeTocado && d.salas.size) { d.nome = [...d.salas][0]; nomeEl.value = d.nome; }
  }));
}

function ligarEditor() {
  const d = PL.draft;
  desenharListaSalas();
  $("pdNome").addEventListener("input", e => { d.nome = e.target.value; d.nomeTocado = true; });
  $("pdFiltro").addEventListener("input", e => { PL.salaFiltro = e.target.value; desenharListaSalas(); });

  const msg = (t, ok = false) => { const m = $("pdMsg"); m.textContent = t; m.className = "settings-msg " + (t ? (ok ? "show-ok" : "show-error") : ""); };

  $("pdCancelar").addEventListener("click", () => { PL.draft = null; renderPlantas(); });

  $("pdSalvar").addEventListener("click", async () => {
    if (!d.salas.size) { msg("Marque ao menos uma sala."); return; }
    const btn = $("pdSalvar"); btn.disabled = true; PL.salvando = true;
    try {
      const r = await postPlantas({ acao: "salvar", id: d.id, planta: d.planta, nome: d.nome.trim(), x: d.x, y: d.y, salas: [...d.salas] });
      if (!r.ok) throw new Error(r.error || "Erro ao salvar.");
      PL.draft = null; PL.selected = r.id;
      await loadPlantas(true);
    } catch (e) { msg(e.message); btn.disabled = false; }
    finally { PL.salvando = false; }
  });

  const ex = $("pdExcluir");
  if (ex) ex.addEventListener("click", async () => {
    if (!confirm(`Excluir a bolinha "${d.nome}"? As salas voltam para a lista de salas sem bolinha.`)) return;
    ex.disabled = true; PL.salvando = true;
    try {
      const r = await postPlantas({ acao: "excluir", id: d.id });
      if (!r.ok) throw new Error(r.error || "Erro ao excluir.");
      PL.draft = null; PL.selected = null;
      await loadPlantas(true);
    } catch (e) { msg(e.message); ex.disabled = false; }
    finally { PL.salvando = false; }
  });
}

// Init
loadComputers();
updateAlertBadge();
updateNotifBadgeCount();
checkMyEmail();
badgeTimer = setInterval(() => { updateAlertBadge(); updateNotifBadgeCount(); }, REFRESH_MS);
