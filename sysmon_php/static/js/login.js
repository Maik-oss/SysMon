/* SysMon — login.js */

const usernameInput = document.getElementById("username");
const passwordInput = document.getElementById("password");
const btnLogin      = document.getElementById("btnLogin");
const btnLoginText  = document.getElementById("btnLoginText");
const btnSpinner    = document.getElementById("btnSpinner");
const loginError    = document.getElementById("loginError");
const togglePw      = document.getElementById("togglePw");
const liveClock     = document.getElementById("liveClock");

if (liveClock) setInterval(() => liveClock.textContent = new Date().toLocaleTimeString("pt-BR"), 1000);

// Números reais de online/alerta/offline (não mais fixos no HTML)
async function loadStatusCounts() {
  try {
    const c = await fetch("api/status_public.php").then(r => r.json());
    document.getElementById("onlineCount").textContent = c.online;
    document.getElementById("warnCount").textContent   = c.warning;
    document.getElementById("offCount").textContent    = c.offline;
  } catch (e) {
    // Se falhar, deixa "—" em vez de mostrar um número errado.
  }
}
loadStatusCounts();

togglePw.addEventListener("click", () => {
  passwordInput.type = passwordInput.type === "password" ? "text" : "password";
});

async function doLogin() {
  const username = usernameInput.value.trim();
  const password = passwordInput.value;

  if (!username || !password) { showError("Preencha usuário e senha."); return; }

  setLoading(true);
  loginError.classList.add("hidden");

  try {
    const data = await fetch("api/login.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username, password }),
    }).then(r => r.json());

    if (data.ok) location.href = "dashboard.php";
    else showError(data.error || "Credenciais inválidas.");
  } catch (e) {
    showError("Erro de conexão. Tente novamente.");
  } finally {
    setLoading(false);
  }
}

function setLoading(on) {
  btnLogin.disabled = on;
  btnLoginText.classList.toggle("hidden", on);
  btnSpinner.classList.toggle("hidden", !on);
}

function showError(msg) {
  loginError.textContent = msg;
  loginError.classList.remove("hidden");
}

btnLogin.addEventListener("click", doLogin);
[usernameInput, passwordInput].forEach(el => el.addEventListener("keydown", e => e.key === "Enter" && doLogin()));
