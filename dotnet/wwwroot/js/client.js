const statusBadge = document.getElementById("connection-status");
const listAll = document.getElementById("list-all");
const listInbound = document.getElementById("list-inbound");
const listOutbound = document.getElementById("list-outbound");
const tabButtons = document.querySelectorAll(".tab-btn");
const tabPanes = document.querySelectorAll(".tab-pane");
const clearAllBtn = document.getElementById("clear-all-btn");
const themeToggle = document.getElementById("theme-toggle");
const lightIcon = document.getElementById("theme-icon-light");
const darkIcon = document.getElementById("theme-icon-dark");

const STORAGE_KEY = "paylabs_visualizer_data";
const THEME_KEY = "paylabs_visualizer_theme";
let dataStore = []; // Array of all items
let itemCount = 0;

// Theme Logic
function initTheme() {
  const savedTheme = localStorage.getItem(THEME_KEY) || "dark";
  if (savedTheme === "dark") {
    document.documentElement.classList.add("dark");
    lightIcon.classList.remove("hidden");
    darkIcon.classList.add("hidden");
  } else {
    document.documentElement.classList.remove("dark");
    lightIcon.classList.add("hidden");
    darkIcon.classList.remove("hidden");
  }
}

themeToggle.addEventListener("click", () => {
  const isDark = document.documentElement.classList.toggle("dark");
  localStorage.setItem(THEME_KEY, isDark ? "dark" : "light");
  lightIcon.classList.toggle("hidden");
  darkIcon.classList.toggle("hidden");
});

// Persistence Logic
function loadFromStorage() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored) {
      dataStore = JSON.parse(stored);
      itemCount = dataStore.length;
      renderAllItems();
    }
  } catch (e) {
    console.warn("Failed to load from localStorage", e);
  }
}

function saveToStorage() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(dataStore));
  } catch (e) {
    console.warn("Failed to save to localStorage", e);
  }
}

function clearAllData() {
  if (!confirm("Clear all history?")) return;
  dataStore = [];
  itemCount = 0;
  localStorage.removeItem(STORAGE_KEY);
  const emptyInbound =
    '<p class="empty-state text-center text-gray-400 dark:text-slate-500 text-sm py-8">No inbound callbacks yet.</p>';
  const emptyOutbound =
    '<p class="empty-state text-center text-gray-400 dark:text-slate-500 text-sm py-8">No outbound requests yet.</p>';
  listAll.innerHTML =
    '<div class="empty-state bg-white dark:bg-slate-800 rounded-lg shadow p-8 text-center text-gray-400 dark:text-slate-500 border border-transparent dark:border-slate-700"><p class="text-sm font-medium text-gray-600 dark:text-slate-400">No activity yet.</p></div>';
  listInbound.innerHTML = emptyInbound;
  listOutbound.innerHTML = emptyOutbound;
}

clearAllBtn.addEventListener("click", clearAllData);

// Tab Switching Logic
tabButtons.forEach((btn) => {
  btn.addEventListener("click", () => {
    const targetId = btn.dataset.tab;
    tabPanes.forEach((pane) => pane.classList.add("hidden"));
    document.getElementById(targetId).classList.remove("hidden");

    tabButtons.forEach((b) => {
      b.classList.remove(
        "bg-white",
        "dark:bg-slate-700",
        "text-indigo-600",
        "dark:text-indigo-300",
        "shadow-sm",
      );
      b.classList.add("text-gray-500", "dark:text-slate-400");
    });
    btn.classList.add(
      "bg-white",
      "dark:bg-slate-700",
      "text-indigo-600",
      "dark:text-indigo-300",
      "shadow-sm",
    );
    btn.classList.remove("text-gray-500", "dark:text-slate-400");
  });
});

function connect() {
  const eventSource = new EventSource("/events");

  eventSource.onopen = () => {
    statusBadge.className =
      "fixed bottom-4 right-4 z-50 px-3 py-1.5 rounded-full text-xs font-medium shadow-lg bg-green-500 text-white";
    statusBadge.textContent = "Connected";
  };

  eventSource.onmessage = (event) => {
    const data = JSON.parse(event.data);
    data.timestamp = new Date().toLocaleTimeString();
    dataStore.unshift(data);
    saveToStorage();
    addDataItem(data, true);
  };

  eventSource.onerror = () => {
    statusBadge.className =
      "fixed bottom-4 right-4 z-50 px-3 py-1.5 rounded-full text-xs font-medium shadow-lg bg-red-500 text-white";
    statusBadge.textContent = "Disconnected";
    eventSource.close();
    setTimeout(connect, 3000);
  };
}

function renderAllItems() {
  if (dataStore.length === 0) return;

  listAll.innerHTML = "";
  listInbound.innerHTML = "";
  listOutbound.innerHTML = "";

  dataStore.forEach((data, index) => {
    addDataItem(data, false, dataStore.length - index);
  });
}

function addDataItem(data, prepend = true, forceId = null) {
  const id = forceId || ++itemCount;
  const timestamp = data.timestamp || new Date().toLocaleTimeString();
  const isOutbound = data.type === "outbound";
  const typeLabel = isOutbound ? "OUT" : "IN";
  const badgeColor = isOutbound
    ? "bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300"
    : "bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300";
  const endpoint = data.endpoint || "/callback";

  let statusBadgeHtml = "";
  if (!isOutbound) {
    const isSuccess = data.verificationStatus === "Valid";
    const statusColor = isSuccess
      ? "bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300"
      : "bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300";
    statusBadgeHtml = `<span class="px-1.5 py-0.5 rounded text-xs font-medium ${statusColor}">${data.verificationStatus}</span>`;
  }

  const createCard = () => {
    const card = document.createElement("div");
    card.className =
      "bg-white dark:bg-slate-800 rounded-lg shadow dark:shadow-indigo-900/10 border border-transparent dark:border-slate-700 fade-in overflow-hidden";
    card.innerHTML = `
      <div class="flex items-center px-4 py-2.5 border-b border-gray-100 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer transition-colors group" onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('.chevron').classList.toggle('rotate-180')">
        <div class="flex items-center gap-2" style="width: 40%;">
          <svg class="chevron w-4 h-4 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
          <span class="px-2 py-0.5 rounded text-xs font-bold ${badgeColor}">${typeLabel}</span>
          <code class="text-xs text-gray-600 dark:text-slate-300 font-mono">${endpoint}</code>
          ${statusBadgeHtml}
        </div>
        <div class="text-right" style="width: 60%;">
          <span class="text-xs text-gray-400 dark:text-slate-500 font-medium">${timestamp}</span>
        </div>
      </div>
      <div class="p-4 flex flex-col gap-4 hidden border-t border-gray-50 dark:border-slate-700/50">
        ${isOutbound ? renderOutbound(data) : renderInbound(data)}
      </div>
    `;
    return card;
  };

  const removeEmpty = (listEl, type) => {
    const placeholder = listEl.querySelector(".empty-state");
    if (placeholder) {
      placeholder.remove();
    }
  };

  if (prepend) {
    removeEmpty(listAll, "all");
    listAll.prepend(createCard());
    if (isOutbound) {
      removeEmpty(listOutbound, "outbound");
      listOutbound.prepend(createCard());
    } else {
      removeEmpty(listInbound, "inbound");
      listInbound.prepend(createCard());
    }
  } else {
    listAll.appendChild(createCard());
    if (isOutbound) {
      listOutbound.appendChild(createCard());
    } else {
      listInbound.appendChild(createCard());
    }
  }
}

function renderInbound(data) {
  return `
    <div class="w-full">
      <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase mb-2">Request from Paylabs</p>
      <div class="bg-gray-50 dark:bg-slate-900/50 rounded border border-gray-200 dark:border-slate-700 p-2 space-y-2 h-full flex flex-col grow">
        <div>
          <p class="text-[10px] text-gray-400 dark:text-slate-500 mb-1">Headers:</p>
          <pre class="text-[11px] bg-white dark:bg-black text-slate-700 dark:text-green-400 p-2 rounded max-h-40 overflow-auto border border-gray-100 dark:border-slate-800">${JSON.stringify(data.headers, null, 2)}</pre>
        </div>
        <div class="flex-1 flex flex-col">
          <p class="text-[10px] text-gray-400 dark:text-slate-500 mb-1">Body:</p>
          <pre class="text-[11px] bg-white dark:bg-black text-slate-700 dark:text-emerald-400 p-2 rounded grow max-h-80 overflow-auto border border-gray-100 dark:border-slate-800">${JSON.stringify(data.body, null, 2)}</pre>
        </div>
      </div>
    </div>
    <div class="w-full flex flex-col">
      <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase mb-2">Response sent to Paylabs</p>
      <div class="bg-gray-50 dark:bg-slate-900/50 rounded border border-gray-200 dark:border-slate-700 p-2 grow flex flex-col">
        <pre class="text-[11px] bg-white dark:bg-black text-slate-700 dark:text-cyan-400 p-2 rounded grow max-h-80 overflow-auto border border-gray-100 dark:border-slate-800">${JSON.stringify(data.responseBody || { message: "N/A" }, null, 2)}</pre>
      </div>
    </div>
  `;
}

function renderOutbound(data) {
  return `
    <div class="w-full">
      <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase mb-2">Outbound Request to Paylabs</p>
      <div class="bg-gray-50 dark:bg-slate-900/50 rounded border border-gray-200 dark:border-slate-700 p-2 space-y-2 h-full flex flex-col grow">
        <div>
          <p class="text-[10px] text-gray-400 dark:text-slate-500 mb-1">Headers:</p>
          <pre class="text-[11px] bg-white dark:bg-black text-slate-700 dark:text-slate-200 p-2 rounded border border-gray-200 dark:border-slate-800 max-h-40 overflow-auto font-mono">${JSON.stringify(data.requestHeaders, null, 2)}</pre>
        </div>
        <div class="flex-1 flex flex-col">
          <p class="text-[10px] text-gray-400 dark:text-slate-500 mb-1">Body:</p>
          <pre class="text-[11px] bg-white dark:bg-black text-slate-700 dark:text-slate-200 p-2 rounded grow border border-gray-200 dark:border-slate-800 max-h-80 overflow-auto font-mono">${JSON.stringify(data.requestBody, null, 2)}</pre>
        </div>
      </div>
    </div>
    <div class="w-full flex flex-col text-slate-700 dark:text-cyan-400">
      <p class="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase mb-2">Immediate Response from Paylabs</p>
      <div class="bg-gray-50 dark:bg-slate-900/50 rounded border border-gray-200 dark:border-slate-700 p-2 grow flex flex-col">
        <pre class="text-[11px] bg-white dark:bg-black text-slate-700 dark:text-cyan-400 p-2 rounded grow max-h-80 overflow-auto border border-gray-100 dark:border-slate-800 font-mono">${JSON.stringify(data.responseBody, null, 2)}</pre>
      </div>
    </div>
  `;
}

// Initialize
initTheme();
loadFromStorage();
connect();
