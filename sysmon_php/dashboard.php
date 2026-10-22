<?php
session_start();
if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/data.php';
$user = htmlspecialchars($_SESSION['user']);
$userInitial = strtoupper($user[0]);
$isAdmin = is_admin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SysMon — Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="static/favicon.svg"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Barlow:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= asset_v('static/css/base.css') ?>"/>
  <link rel="stylesheet" href="<?= asset_v('static/css/dashboard.css') ?>"/>
</head>
<body class="dash-body">

  <div class="scan-lines"></div>

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="brand-icon-sm">
        <svg viewBox="0 0 40 40" fill="none"><rect x="2" y="2" width="36" height="36" rx="4" stroke="currentColor" stroke-width="1.5"/><path d="M8 20h4l3-8 4 16 3-10 3 5 3-3h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <div class="brand-text">
        <div class="brand-name">SysMon</div>
        <div class="brand-sub">NOC Panel</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Ferramentas</div>

      <button class="nav-btn active" id="btnListComputers" data-action="list-computers">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="16" height="13" rx="2"/><path d="M6 17h8M10 16v1"/></svg>
        Listar Computadores
      </button>

      <button class="nav-btn" id="btnMonitorAll" data-action="monitor-all">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 10h4l2-6 4 12 2-8 2 4 2-2"/></svg>
        Monitoramento Geral
      </button>

      <button class="nav-btn" id="btnAlerts" data-action="alerts">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 2a6 6 0 016 6c0 4 1 5 2 6H2c1-1 2-2 2-6a6 6 0 016-6z"/><path d="M8 16a2 2 0 004 0"/></svg>
        Alertas
        <span class="badge-warn" id="alertBadge">0</span>
      </button>

      <button class="nav-btn" id="btnNotifications" data-action="notifications">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 2a6 6 0 016 6c0 4 1 5 2 6H2c1-1 2-2 2-6a6 6 0 016-6z"/><circle cx="15" cy="5" r="3" fill="currentColor" stroke="none"/></svg>
        Notificações
        <span class="badge-warn" id="notifBadge">0</span>
      </button>

      <div class="nav-section-label" style="margin-top:20px;">Sistema</div>

      <button class="nav-btn" id="btnSettings" data-action="settings">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="10" cy="10" r="3"/><path d="M10 2v2M10 16v2M2 10h2M16 10h2M4.22 4.22l1.42 1.42M14.36 14.36l1.42 1.42M4.22 15.78l1.42-1.42M14.36 5.64l1.42-1.42"/></svg>
        Configurações
      </button>

      <?php if ($isAdmin): ?>
      <button class="nav-btn" id="btnUsers" data-action="users">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="7" r="3"/><path d="M2 17c0-3 2.5-5 6-5s6 2 6 5"/><path d="M15 4v5M17.5 6.5h-5"/></svg>
        Usuários
      </button>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="user-chip">
        <div class="user-avatar"><?= $userInitial ?></div>
        <div class="user-info">
          <div class="user-name" id="sidebarUsername"><?= $user ?></div>
          <div class="user-role mono-text"><?= $isAdmin ? 'Administrador' : 'Operador' ?></div>
        </div>
      </div>
      <button class="btn-logout" id="btnLogout" title="Sair">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 15l4-5-4-5"/><path d="M17 10H7"/><path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3"/></svg>
      </button>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">

    <div class="topbar">
      <button class="sidebar-toggle" id="sidebarToggle">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
      </button>
      <div class="topbar-title" id="topbarTitle">Listar Computadores</div>
      <div class="topbar-right">
        <div class="clock mono-text" id="dashClock"></div>
        <div class="status-dot online" title="Sistema Online"></div>
      </div>
    </div>

    <!-- VIEW: LISTA DE COMPUTADORES -->
    <div class="view active" id="viewListComputers">
      <div class="view-header">
        <h2 class="view-title">Computadores</h2>
        <p class="view-sub">Digite o código CM para buscar um computador</p>
      </div>
      <div class="search-bar-wrap">
        <div class="search-bar">
          <svg class="search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M16 16l-3.5-3.5"/></svg>
          <input class="search-input" id="computerSearch" type="text" placeholder="Buscar: CM000001..." maxlength="8" autocomplete="off"/>
          <span class="search-hint mono-text" id="searchHint">CM + 6 dígitos</span>
        </div>
      </div>
      <div class="computer-grid" id="computerGrid"></div>
      <div class="empty-state hidden" id="emptyState">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="6" y="10" width="36" height="26" rx="3"/><path d="M16 36v4M32 36v4M12 40h24"/><circle cx="24" cy="23" r="6"/><path d="M21 23l2 2 4-4"/></svg>
        <div>Nenhum computador encontrado</div>
      </div>
    </div>

    <!-- VIEW: DETALHE DO COMPUTADOR -->
    <div class="view" id="viewComputer">
      <div class="view-header">
        <button class="btn-back" id="btnBack">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 4l-6 6 6 6"/></svg>
          Voltar
        </button>
        <div>
          <h2 class="view-title" id="computerTitle">—</h2>
          <p class="view-sub mono-text" id="computerSubtitle">—</p>
        </div>
        <div class="status-badge" id="computerStatus"></div>
        <?php if ($isAdmin): ?>
        <button class="btn-danger-outline" id="btnDeleteComputer" title="Remover este computador do painel">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10"/></svg>
          Remover
        </button>
        <?php endif; ?>
      </div>

      <div class="category-tabs" id="categoryTabs">
        <button class="cat-btn active" data-cat="overview">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="7" height="7" rx="1"/><rect x="11" y="2" width="7" height="7" rx="1"/><rect x="2" y="11" width="7" height="7" rx="1"/><rect x="11" y="11" width="7" height="7" rx="1"/></svg>
          Visão Geral
        </button>
        <button class="cat-btn" data-cat="cpu">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="5" width="10" height="10" rx="1"/><path d="M8 2v3M12 2v3M8 15v3M12 15v3M2 8h3M2 12h3M15 8h3M15 12h3"/></svg>
          CPU
        </button>
        <button class="cat-btn" data-cat="gpu">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="6" width="18" height="10" rx="2"/><path d="M5 6V4M9 6V4M13 6V4M17 6V4M6 16v2M14 16v2"/></svg>
          GPU
        </button>
        <button class="cat-btn" data-cat="temp">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 13.5V4a2 2 0 00-4 0v9.5a4 4 0 104 0z"/><path d="M10 10v4"/></svg>
          Temperatura
        </button>
        <button class="cat-btn" data-cat="memory">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="7" width="16" height="7" rx="1"/><path d="M6 7V5M10 7V5M14 7V5M6 14v2M10 14v2M14 14v2"/></svg>
          Memória
        </button>
        <button class="cat-btn" data-cat="maintenance">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13.5 3a3.5 3.5 0 00-3.3 4.7L3.5 14.4a1.4 1.4 0 002 2l6.7-6.7A3.5 3.5 0 0017 6.5l-2 2-2-.5-.5-2 2-2a3.5 3.5 0 00-1-1z"/></svg>
          Manutenção
        </button>
      </div>

      <div id="categoryContent"></div>

      <div class="temp-submenu hidden" id="tempSubmenu">
        <div class="sub-label mono-text">Temperatura de qual componente?</div>
        <div class="temp-btns">
          <button class="temp-btn active" data-temp="all">Todos</button>
          <button class="temp-btn" data-temp="cpu">CPU</button>
          <button class="temp-btn" data-temp="gpu">GPU</button>
          <button class="temp-btn" data-temp="storage">Armazenamento</button>
          <button class="temp-btn" data-temp="board">Placa-Mãe</button>
        </div>
      </div>

      <div class="metric-area" id="metricArea"></div>

      <div class="offline-state hidden" id="offlineState">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1"><circle cx="24" cy="24" r="20"/><path d="M24 14v12M24 34v1"/></svg>
        <div>Computador Offline</div>
        <div class="mono-text" style="font-size:12px;margin-top:4px;">Não é possível coletar métricas</div>
      </div>
    </div>

    <!-- VIEW: MONITORAMENTO GERAL -->
    <div class="view" id="viewMonitorAll">
      <div class="view-header">
        <h2 class="view-title">Monitoramento Geral</h2>
        <p class="view-sub">Status em tempo real de todos os computadores</p>
      </div>

      <div class="section-title">Temperatura atual — todos os PCs</div>
      <div class="m-card temp-chart-wrap" style="margin-bottom:26px;">
        <div class="temp-chart-legend">
          <span><span class="temp-chart-dot" style="background:var(--cyan);"></span>CPU</span>
          <span><span class="temp-chart-dot" style="background:#ff8a3d;"></span>GPU</span>
          <span><span class="temp-chart-dot" style="background:var(--red);"></span>Acima do limite (85°C)</span>
          <span><span class="temp-chart-dot" style="background:var(--text3);opacity:.5;"></span>PC offline (último valor registrado)</span>
        </div>
        <div id="overviewTempChart"><div class="temp-chart-empty">Carregando...</div></div>
      </div>

      <div class="monitor-grid" id="monitorGrid"></div>
    </div>

    <!-- VIEW: ALERTAS -->
    <div class="view" id="viewAlerts">
      <div class="view-header">
        <h2 class="view-title">Alertas</h2>
        <p class="view-sub">Computadores em alerta ou sem contato no momento</p>
      </div>
      <div class="crit-banner hidden" id="critBanner"></div>
      <div class="computer-grid" id="alertsGrid"></div>
      <div class="empty-state hidden" id="alertsEmpty">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M24 8v16M24 34v2"/><circle cx="24" cy="24" r="20"/></svg>
        <div>Nenhum alerta no momento — tudo online</div>
      </div>
    </div>

    <!-- VIEW: NOTIFICAÇÕES (histórico de problemas + lidas/não lidas) -->
    <div class="view" id="viewNotifications">
      <div class="view-header">
        <h2 class="view-title">Notificações</h2>
        <p class="view-sub">Histórico de alertas e problemas — clique para marcar como lida</p>
        <button class="btn-danger-outline" id="btnMarkAllRead" style="margin-left:auto;">Marcar todas como lidas</button>
      </div>
      <div class="notif-summary" id="notifSummary"></div>
      <div class="notif-filters">
        <button class="notif-filter-btn active" data-filter="todos">Todos</button>
        <button class="notif-filter-btn" data-filter="vermelho">Só problemas vermelhos</button>
        <button class="notif-filter-btn" data-filter="ativos">Em andamento</button>
        <button class="notif-filter-btn" data-filter="resolvidos">Resolvidos</button>
        <button class="notif-filter-btn" data-filter="nao_lidas">Não lidas</button>
      </div>
      <div class="notif-list" id="notifList"></div>
      <div class="empty-state hidden" id="notifEmpty">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M24 8v16M24 34v2"/><circle cx="24" cy="24" r="20"/></svg>
        <div>Nenhuma notificação com esse filtro</div>
      </div>
    </div>

    <!-- VIEW: CONFIGURAÇÕES -->

    <div class="view" id="viewSettings">
      <div class="view-header">
        <h2 class="view-title">Configurações</h2>
        <p class="view-sub">Conta e preferências do painel</p>
      </div>

      <div class="section-title">Atualização automática</div>
      <div class="m-card" style="max-width:420px;">
        <label class="settings-label" for="refreshIntervalSelect">Intervalo de atualização das telas</label>
        <select class="settings-input" id="refreshIntervalSelect">
          <option value="15000" selected>A cada 15 segundos</option>
          <option value="30000">A cada 30 segundos</option>
          <option value="60000">A cada 1 minuto</option>
          <option value="120000">A cada 2 minutos</option>
          <option value="300000">A cada 5 minutos</option>
        </select>
        <div class="settings-hint mono-text">Vale para a tela de detalhes e o Monitoramento Geral.</div>
      </div>

      <div class="section-title" style="margin-top:24px;">Conta</div>

      <div class="account-chip m-card">
        <div class="user-avatar" id="settingsAvatar">?</div>
        <div>
          <div class="settings-label" style="margin-bottom:3px;">Usuário logado</div>
          <div class="account-chip-name" id="settingsUsername">—</div>
        </div>
      </div>

      <div class="account-cards">
        <div class="m-card form-card">
          <div class="form-card-title">Nome de usuário</div>

          <label class="settings-label" for="currentPasswordUsername">Senha atual</label>
          <input class="settings-input" type="password" id="currentPasswordUsername" autocomplete="current-password"/>

          <label class="settings-label" for="newUsername" style="margin-top:14px;">Novo nome de usuário</label>
          <input class="settings-input" type="text" id="newUsername" autocomplete="username"/>

          <div class="settings-msg hidden" id="usernameMsg"></div>
          <button class="btn-settings-save" id="btnChangeUsername" style="margin-top:14px;">Salvar usuário</button>
        </div>

        <div class="m-card form-card">
          <div class="form-card-title">Senha</div>

          <label class="settings-label" for="currentPassword">Senha atual</label>
          <input class="settings-input" type="password" id="currentPassword" autocomplete="current-password"/>

          <label class="settings-label" for="newPassword" style="margin-top:14px;">Nova senha</label>
          <input class="settings-input" type="password" id="newPassword" autocomplete="new-password"/>

          <div class="settings-msg hidden" id="settingsMsg"></div>
          <button class="btn-settings-save" id="btnChangePassword" style="margin-top:14px;">Alterar senha</button>
        </div>

        <div class="m-card form-card">
          <div class="form-card-title">E-mail e notificações</div>

          <label class="settings-label" for="emailInput">Seu e-mail</label>
          <input class="settings-input" type="email" id="emailInput" autocomplete="email" placeholder="voce@exemplo.com" maxlength="150"/>

          <label class="check-row" for="emailNotify">
            <input type="checkbox" id="emailNotify" checked/>
            <span>Receber os alertas do painel por e-mail</span>
          </label>

          <div class="settings-hint mono-text" id="emailHint">Você recebe um e-mail quando um PC fica offline ou passa do limite.</div>
          <div class="settings-msg hidden" id="emailMsg"></div>
          <div class="btn-row">
            <button class="btn-settings-save" id="btnSaveEmail">Salvar e-mail</button>
            <button class="btn-row-action" id="btnTestEmail" type="button">Enviar e-mail de teste</button>
          </div>
        </div>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- VIEW: USUÁRIOS (somente admins: ver ADMIN_USERNAMES em includes/data.php) -->
    <div class="view" id="viewUsers">
      <div class="view-header">
        <h2 class="view-title">Usuários</h2>
        <p class="view-sub">Cadastre acessos para outras pessoas e gerencie as senhas delas</p>
      </div>

      <div class="section-title">Novo usuário</div>
      <div class="m-card form-card">
        <label class="settings-label" for="newUserUsername">Nome de usuário</label>
        <input class="settings-input" type="text" id="newUserUsername" autocomplete="off" placeholder="ex.: joao.silva"/>

        <label class="settings-label" for="newUserEmail" style="margin-top:14px;">E-mail (obrigatório)</label>
        <input class="settings-input" type="email" id="newUserEmail" autocomplete="off" placeholder="ex.: joao.silva@escolar.ifrn.edu.br" maxlength="150"/>

        <label class="settings-label" for="newUserPassword" style="margin-top:14px;">Senha</label>
        <input class="settings-input" type="password" id="newUserPassword" autocomplete="new-password"/>

        <div class="settings-msg hidden" id="newUserMsg"></div>
        <button class="btn-settings-save" id="btnCreateUser" style="margin-top:14px;">Cadastrar usuário</button>
      </div>

      <div class="section-title" style="margin-top:28px;">Usuários cadastrados</div>
      <div class="users-list" id="usersList"></div>
      <div class="empty-state hidden" id="usersEmpty">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="24" cy="18" r="8"/><path d="M8 42c0-9 7-14 16-14s16 5 16 14"/></svg>
        <div>Nenhum outro usuário cadastrado ainda</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- VIEW: PLACEHOLDER -->
    <div class="view" id="viewPlaceholder">
      <div class="placeholder-state">
        <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1"><path d="M24 4v8M24 36v8M4 24h8M36 24h8M9.86 9.86l5.66 5.66M32.48 32.48l5.66 5.66M9.86 38.14l5.66-5.66M32.48 15.52l5.66-5.66"/><circle cx="24" cy="24" r="6"/></svg>
        <div>Em desenvolvimento</div>
        <div class="mono-text" style="font-size:12px;margin-top:4px;">Funcionalidade em breve</div>
      </div>
    </div>

  </div>

  <!-- Pop-up de notificação (toast) -->
  <div class="toast-container" id="toastContainer"></div>

  <script src="<?= asset_v('static/js/dashboard.js') ?>"></script>
</body>
</html>
