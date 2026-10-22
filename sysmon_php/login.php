<?php
session_start();
if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
require_once __DIR__ . '/includes/data.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SysMon — Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Barlow:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= asset_v('static/css/base.css') ?>"/>
  <link rel="stylesheet" href="<?= asset_v('static/css/login.css') ?>"/>
</head>
<body class="login-body">

  <div class="scan-lines"></div>

  <div class="login-wrap">

    <div class="login-left">
      <div class="brand-block">
        <div class="brand-icon">
          <svg viewBox="0 0 40 40" fill="none">
            <rect x="2" y="2" width="36" height="36" rx="4" stroke="currentColor" stroke-width="1.5"/>
            <path d="M8 20h4l3-8 4 16 3-10 3 5 3-3h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div>
          <div class="brand-name">SysMon</div>
          <div class="brand-sub">Network Operations Center</div>
        </div>
      </div>

      <div class="left-stats">
        <div class="stat-item">
          <span class="stat-num" id="onlineCount">—</span>
          <span class="stat-lbl">Online</span>
        </div>
        <div class="stat-sep"></div>
        <div class="stat-item">
          <span class="stat-num warn" id="warnCount">—</span>
          <span class="stat-lbl">Alerta</span>
        </div>
        <div class="stat-sep"></div>
        <div class="stat-item">
          <span class="stat-num off" id="offCount">—</span>
          <span class="stat-lbl">Offline</span>
        </div>
      </div>

      <div class="left-footer">
        <div class="mono-text">v2.4.1 · PT-BR</div>
        <div class="mono-text" id="liveClock"></div>
      </div>
    </div>

    <div class="login-right">
      <div class="login-box">
        <div class="login-title">Acesso ao Sistema</div>
        <div class="login-subtitle">Insira suas credenciais de operador</div>

        <div class="form-group">
          <label class="form-label" for="username">Usuário</label>
          <div class="input-wrap">
            <svg class="input-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path d="M2 18a8 8 0 0116 0"/></svg>
            <input class="form-input" type="text" id="username" placeholder="seu.usuario" autocomplete="username"/>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Senha</label>
          <div class="input-wrap">
            <svg class="input-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="9" width="14" height="10" rx="2"/><path d="M7 9V6a3 3 0 016 0v3"/></svg>
            <input class="form-input" type="password" id="password" placeholder="••••••••" autocomplete="current-password"/>
            <button class="toggle-pw" id="togglePw" tabindex="-1">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 10s3.6-6 9-6 9 6 9 6-3.6 6-9 6-9-6-9-6z"/><circle cx="10" cy="10" r="2.5"/></svg>
            </button>
          </div>
        </div>

        <div class="error-msg hidden" id="loginError"></div>

        <button class="btn-login" id="btnLogin">
          <span id="btnLoginText">Entrar</span>
          <div class="btn-spinner hidden" id="btnSpinner"></div>
        </button>
      </div>
    </div>

  </div>

  <script src="<?= asset_v('static/js/login.js') ?>"></script>
</body>
</html>
