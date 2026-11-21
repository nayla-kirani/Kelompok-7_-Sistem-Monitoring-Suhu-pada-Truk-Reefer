<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Monitoring Suhu Reefer</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <style>
    :root {
        --ink:#12262F;
        --frost:#EAF0F3;
        --paper:#F8FAFB;
        --line:#CBD8DE;
        --mute:#5B707A;
        --cold:#2F6F8F;
        --ok:#3DBE9A;
        --warn:#F0B429;
        --crit:#E8604C;
        --ok-d:#17745A;
        --crit-d:#B3321F;
    }

    * { box-sizing:border-box; }

    body {
        margin:0;
        background:var(--frost);
        color:var(--ink);
        font:400 15px/1.5 'Instrument Sans',system-ui,sans-serif;
    }

    h1,h2 {
        font-family:'Bricolage Grotesque','Instrument Sans',sans-serif;
        margin:0;
    }

    .wrap {
        max-width:1180px;
        margin:0 auto;
        padding:20px 16px 40px;
    }

    header {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:16px;
    }

    h1 { font-size:22px; font-weight:700; }
    .sub { color:var(--mute); font-size:13px; }

    .pill {
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:5px 12px;
        border:1px solid var(--line);
        border-radius:999px;
        background:var(--paper);
        font-size:13px;
        font-weight:500;
    }

    .dot {
        width:8px;
        height:8px;
        border-radius:50%;
        background:var(--crit);
    }

    .pill.on .dot { background:var(--ok-d); }

    .grid {
        display:grid;
        grid-template-columns:repeat(12,1fr);
        gap:14px;
    }

    .panel {
        background:var(--paper);
        border:1px solid var(--line);
        border-radius:6px;
        padding:18px;
        min-width:0;
    }

    .panel h2 {
        font-size:16px;
        font-weight:600;
        margin-bottom:12px;
    }

    .hero {
        grid-column:span 7;
        background:var(--ink);
        color:#fff;
        border-radius:20px;
        padding:26px 28px;
    }

    .fans { grid-column:span 5; }
    .chartp { grid-column:span 8; }
    .side { grid-column:span 4; }

    @media(max-width:900px) {
        .hero,.fans,.chartp,.side { grid-column:1/-1; }
        .temp { font-size:68px; }
    }

    .reading {
        display:flex;
        align-items:flex-end;
        gap:16px;
        flex-wrap:wrap;
    }

    .temp {
        font:700 88px/.95 'Bricolage Grotesque',sans-serif;
        letter-spacing:-2px;
    }

    .temp small {
        font-size:32px;
        font-weight:600;
        letter-spacing:0;
        margin-left:4px;
        opacity:.7;
    }

    .badge {
        padding:6px 14px;
        border-radius:999px;
        font-weight:600;
        font-size:14px;
        background:#2a414b;
        margin-bottom:10px;
    }

    [data-status=normal] .badge {
        background:var(--ok);
        color:#06251c;
    }

    [data-status=warning] .badge {
        background:var(--warn);
        color:#3a2800;
    }

    [data-status=critical] .badge {
        background:var(--crit);
        color:#3a0c05;
    }

    .strip { position:relative; margin:36px 0 8px; }
    .bands { display:flex; height:12px; border-radius:6px; overflow:hidden; }
    .bands i { display:block; }
    .b-ok { flex:4; background:var(--ok); }
    .b-warn { flex:6; background:var(--warn); }
    .b-crit { flex:4; background:var(--crit); }

    .marker {
        position:absolute;
        top:-8px;
        left:0;
        width:4px;
        height:28px;
        margin-left:-2px;
        background:#fff;
        border-radius:2px;
        box-shadow:0 0 0 3px var(--ink);
        transition:left .6s ease;
    }

    .ticks {
        position:relative;
        height:18px;
        margin-top:8px;
        font-size:12px;
        color:#9FB3BC;
    }

    .ticks span {
        position:absolute;
        transform:translateX(-50%);
    }

    .hero .sub { color:#9FB3BC; }

    .seg {
        display:inline-flex;
        border:1px solid var(--line);
        border-radius:6px;
        overflow:hidden;
        flex:none;
    }

    .seg button {
        border:0;
        background:#fff;
        padding:7px 12px;
        font:500 13px 'Instrument Sans',sans-serif;
        color:var(--ink);
        cursor:pointer;
    }

    .seg button+button { border-left:1px solid var(--line); }
    .seg button.active { background:var(--cold); color:#fff; }
    .seg button:disabled { opacity:.45; cursor:not-allowed; }

    button:focus-visible {
        outline:3px solid #7FB8D4;
        outline-offset:2px;
    }

    .row {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        padding:12px 0;
        border-top:1px solid var(--line);
    }

    .row.first { border-top:0; padding-top:0; }
    .row b { display:block; font-weight:600; }
    .row .st { font-size:13px; color:var(--mute); }
    .fault { color:var(--crit-d); font-weight:600; }

    dl {
        margin:0 0 18px;
        display:grid;
        grid-template-columns:auto 1fr;
        gap:6px 14px;
    }

    dt { color:var(--mute); }

    dd {
        margin:0;
        font-weight:500;
        text-align:right;
        word-break:break-word;
    }

    .ev {
        list-style:none;
        margin:0;
        padding:0;
        max-height:240px;
        overflow:auto;
    }

    .ev li {
        display:flex;
        gap:10px;
        padding:8px 0;
        border-top:1px solid var(--line);
        font-size:14px;
    }

    .ev time {
        display:block;
        color:var(--mute);
        font-size:12px;
    }

    .ev .k {
        width:8px;
        height:8px;
        border-radius:50%;
        margin-top:7px;
        flex:none;
        background:var(--cold);
    }

    .ev .k.warning { background:var(--warn); }
    .ev .k.critical { background:var(--crit); }
    .ev .k.normal { background:var(--ok-d); }

    .empty { color:var(--mute); font-size:14px; margin:0; }

    .chartbox {
        position:relative;
        height:280px;
    }

    .toast {
        position:fixed;
        bottom:18px;
        left:50%;
        transform:translateX(-50%);
        background:var(--ink);
        color:#fff;
        padding:10px 16px;
        border-radius:6px;
        font-size:14px;
        opacity:0;
        pointer-events:none;
        transition:opacity .2s;
        z-index:1000;
        max-width:calc(100% - 32px);
    }

    .toast.show { opacity:1; }
    .toast.err { background:var(--crit-d); }

    .banner {
        display:none;
        background:#FBE3DE;
        border:1px solid #E8A99E;
        color:var(--crit-d);
        padding:10px 14px;
        border-radius:6px;
        margin-bottom:14px;
        font-size:14px;
    }

    .banner.show { display:block; }

    .btn {
        border:1px solid var(--cold);
        background:var(--cold);
        color:#fff;
        padding:8px 14px;
        border-radius:6px;
        font:600 14px 'Instrument Sans',sans-serif;
        cursor:pointer;
    }

    .btn.ghost {
        background:#fff;
        color:var(--ink);
        border-color:var(--line);
    }

    .btn.danger {
        background:#fff;
        color:var(--crit-d);
        border-color:#E8A99E;
    }

    .btn:disabled { opacity:.5; cursor:not-allowed; }

    .in {
        width:100%;
        padding:9px 10px;
        border:1px solid var(--line);
        border-radius:6px;
        font:inherit;
        background:#fff;
        color:var(--ink);
    }

    .field { margin-bottom:13px; }

    .field label {
        display:block;
        font-size:13px;
        font-weight:600;
        margin-bottom:5px;
    }

    .field .in:disabled {
        background:#edf1f3;
        color:var(--mute);
    }

    dialog {
        border:1px solid var(--line);
        border-radius:12px;
        padding:20px;
        width:min(480px,calc(100% - 32px));
        max-height:90vh;
        overflow:auto;
        background:var(--paper);
        color:var(--ink);
    }

    dialog::backdrop { background:rgba(18,38,47,.55); }

    #reader {
        width:100%;
        min-height:220px;
        background:#0b171d;
        border-radius:6px;
        overflow:hidden;
    }

    #reader video {
        width:100%;
        object-fit:cover;
    }

    #reader__scan_region { min-height:220px; }

    #reader__dashboard_section_csr button {
        padding:7px 12px;
        margin:5px;
        border:1px solid var(--line);
        border-radius:5px;
        background:#fff;
        cursor:pointer;
    }

    .actions {
        display:flex;
        gap:8px;
        justify-content:flex-end;
        flex-wrap:wrap;
        margin-top:14px;
    }

    .msg {
        color:var(--crit-d);
        font-size:13px;
        margin:8px 0 0;
        min-height:1em;
    }

    .transaction-status {
        display:inline-block;
        padding:4px 9px;
        border-radius:999px;
        background:#DDF4EA;
        color:#17745A;
        font-size:12px;
        font-weight:600;
    }

    .transaction-status.locked {
        background:#FBE3DE;
        color:var(--crit-d);
    }

    .transaction-id {
        font-size:12px;
        color:var(--mute);
        word-break:break-all;
    }

    .transaction-weight {
        font-size:25px;
        font-weight:700;
        font-family:'Bricolage Grotesque',sans-serif;
        margin:3px 0 12px;
    }

    .transaction-actions {
        display:flex;
        gap:8px;
        margin:14px 0 18px;
        flex-wrap:wrap;
    }

    .transaction-actions .btn { flex:1; }

    .section-divider {
        border:0;
        border-top:1px solid var(--line);
        margin:18px 0;
    }

    @media(prefers-reduced-motion:reduce) {
        * { transition:none!important; }
    }
    </style>
</head>

<body>
<div class="wrap" id="app" data-status="">

    <header>
        <div>
            <h1>Monitoring suhu truk reefer</h1>
            <div class="sub">Perangkat {{ $deviceId }}</div>
        </div>

        <span class="pill" id="online">
            <span class="dot"></span>
            <span id="onlineText">Menghubungkan</span>
        </span>
    </header>

    <div class="banner" id="banner">
        Tidak bisa terhubung ke API Go. Dashboard mencoba lagi otomatis.
    </div>

    <div class="grid">

        <section class="hero" aria-live="polite">
            <div class="reading">
                <div class="temp">
                    <span id="temp">--</span><small>°C</small>
                </div>
                <span class="badge" id="badge">Menunggu data</span>
            </div>

            <div class="strip">
                <div class="bands">
                    <i class="b-ok"></i>
                    <i class="b-warn"></i>
                    <i class="b-crit"></i>
                </div>
                <div class="marker" id="marker"></div>
                <div class="ticks">
                    <span style="left:28.57%">2°C</span>
                    <span style="left:71.43%">8°C</span>
                </div>
            </div>

            <div class="sub" id="updated">Belum ada data</div>
        </section>

        <section class="panel fans">
            <h2>Kipas dan kontrol</h2>

            <div class="row first">
                <span class="sub">Mode</span>
                <div class="seg" id="modeSeg">
                    <button data-mode="auto">Auto</button>
                    <button data-mode="manual">Manual</button>
                </div>
            </div>

            <div class="row">
                <div>
                    <b>Kipas 1, penjaga suhu</b>
                    <span class="st" id="fan1st">-</span>
                </div>
                <div class="seg" data-fan="fan1">
                    <button data-value="off">Mati</button>
                    <button data-value="normal">Normal</button>
                    <button data-value="high">Tinggi</button>
                </div>
            </div>

            <div class="row">
                <div>
                    <b>Kipas 2, pendingin es</b>
                    <span class="st" id="fan2st">-</span>
                </div>
                <div class="seg" data-fan="fan2">
                    <button data-value="off">Mati</button>
                    <button data-value="on">Nyala</button>
                </div>
            </div>

            <p class="sub" id="ctlHint" style="margin:8px 0 0"></p>
        </section>

        <section class="panel chartp">
            <div class="row first" style="padding-bottom:6px">
                <h2 style="margin:0">Riwayat suhu</h2>

                <div class="seg" id="rangeSeg">
                    <button data-range="1h" class="active">1 jam</button>
                    <button data-range="6h">6 jam</button>
                    <button data-range="24h">24 jam</button>
                </div>
            </div>

            <div class="chartbox">
                <canvas id="chart"></canvas>
            </div>
        </section>

        <section class="panel side">
            <h2>Transaksi pengiriman terakhir</h2>

            <div id="shipmentEmpty">
                <p class="empty">Belum ada transaksi pengiriman.</p>
            </div>

            <div id="shipmentDetail" hidden>
                <div class="transaction-id" id="trxId">-</div>

                <div style="margin:9px 0">
                    <span class="transaction-status" id="editStatus">Belum diedit</span>
                </div>

                <dl>
                    <dt>UID Box</dt>
                    <dd id="trxBox">-</dd>

                    <dt>Pengemudi / Distributor</dt>
                    <dd id="trxDriver">-</dd>

                    <dt>Nama barang</dt>
                    <dd id="trxItem">-</dd>
                </dl>

                <div class="sub">Berat barang</div>
                <div class="transaction-weight">
                    <span id="trxWeight">-</span> kg
                </div>

                <dl>
                    <dt>Waktu transaksi</dt>
                    <dd id="trxCreated">-</dd>

                    <dt>Terakhir diedit</dt>
                    <dd id="trxEdited">-</dd>
                </dl>

                <div class="transaction-actions">
                    <button class="btn ghost" id="editShipment">
                        Edit transaksi
                    </button>
                </div>
            </div>

            <button class="btn" id="openScan" style="width:100%">
                + Scan Box Baru
            </button>

            <hr class="section-divider">

            <h2>Riwayat event</h2>
            <ul class="ev" id="events"></ul>
        </section>
    </div>
</div>

<dialog id="scanDlg" aria-labelledby="scanTitle">

    <div id="stepScan">
        <h2 id="scanTitle" style="font-size:18px;margin-bottom:12px">
            Scan barcode UID Box
        </h2>

        <p class="sub" style="margin:0 0 10px">
            Arahkan barcode ke kamera. Pastikan seluruh garis barcode terlihat.
        </p>

        <div id="reader"></div>

        <p class="msg" id="camMsg"></p>

        <label class="sub" for="manualUid" style="display:block;margin-top:10px">
            Atau masukkan UID Box secara manual
        </label>

        <div style="display:flex;gap:8px;margin-top:4px">
            <input
                class="in"
                id="manualUid"
                placeholder="Contoh: BOX-001"
                autocomplete="off"
                maxlength="64"
            >
            <button class="btn" id="manualGo">Periksa</button>
        </div>

        <div class="actions">
            <button class="btn ghost" id="cancelScan">Batal</button>
        </div>
    </div>

    <div id="stepForm" hidden>
        <h2 id="formTitle" style="font-size:18px;margin-bottom:6px">
            Data pengiriman
        </h2>

        <p class="sub" id="formDescription" style="margin:0 0 14px">
            Lengkapi informasi pengiriman.
        </p>

        <div class="field">
            <label for="formBox">UID Box</label>
            <input class="in" id="formBox" disabled>
        </div>

        <div class="field">
            <label for="formDriver">Nama Pengemudi / Distributor</label>
            <input
                class="in"
                id="formDriver"
                placeholder="Masukkan nama pengemudi atau distributor"
                maxlength="128"
            >
        </div>

        <div class="field">
            <label for="formItem">Nama Barang</label>
            <input
                class="in"
                id="formItem"
                placeholder="Contoh: Sayuran Segar"
                maxlength="128"
            >
        </div>

        <div class="field">
            <label for="formWeight">Berat Barang (kg)</label>
            <input
                class="in"
                id="formWeight"
                type="number"
                min="0.01"
                max="100000"
                step="0.01"
                placeholder="Contoh: 250"
            >
        </div>

        <p class="msg" id="formMsg"></p>

        <div class="actions">
            <button class="btn ghost" id="backForm">Kembali</button>
            <button class="btn" id="saveShipment">Simpan transaksi</button>
        </div>
    </div>
</dialog>

<div class="toast" id="toast" role="status"></div>

<script>
(() => {
    const $ = selector => document.querySelector(selector);

    const U = {
        latest: @json(route('dashboard.latest')),
        history: @json(route('dashboard.history')),
        events: @json(route('dashboard.events')),
        control: @json(route('dashboard.control')),

        validateBox: @json(route('dashboard.boxes.validate', ['uid' => '__UID__'])),
        storeShipment: @json(route('dashboard.shipments.store')),
        latestShipment: @json(route('dashboard.shipments.latest')),
        updateShipment: @json(route('dashboard.shipments.update', ['id' => '__ID__']))
    };

    const csrf = $('meta[name=csrf-token]').content;

    const LBL = {
        normal: 'Normal',
        warning: 'Warning',
        critical: 'Kritis'
    };

    const FAN1 = {
        off: 'Mati',
        normal: 'Menyala, kecepatan normal',
        high: 'Menyala, kecepatan tinggi'
    };

    let range = '1h';
    let scanner = null;
    let scanning = false;
    let currentShipment = null;
    let shipmentMode = 'create';
    let validatedBoxUid = '';

    const jsonHeaders = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf
    };

    async function requestJson(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                ...(options.headers || {})
            }
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(
                data.message || data.error ||
                'Permintaan gagal (' + response.status + ')'
            );
        }

        return data;
    }

    const getJson = url => requestJson(url);

    function ago(iso) {
        if (!iso) return '-';

        const seconds = Math.max(
            0,
            Math.round((Date.now() - new Date(iso)) / 1000)
        );

        if (seconds < 60) return seconds + ' detik lalu';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' menit lalu';

        return Math.floor(seconds / 3600) + ' jam lalu';
    }

    function hhmm(iso) {
        if (!iso) return '-';

        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return '-';

        return date.toLocaleTimeString('id-ID', {
            timeZone: 'Asia/Jakarta',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function dateTime(iso) {
        if (!iso) return '-';

        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return '-';

        return date.toLocaleString('id-ID', {
            timeZone: 'Asia/Jakarta',
            day: '2-digit',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function toast(message, error = false) {
        const element = $('#toast');

        element.textContent = message;
        element.className = 'toast show' + (error ? ' err' : '');

        clearTimeout(toast.timer);

        toast.timer = setTimeout(() => {
            element.className = 'toast';
        }, 3000);
    }

    // ==========================================
    // GRAFIK SUHU
    // ==========================================

    const chart = new Chart($('#chart'), {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                {
                    label: 'Suhu',
                    data: [],
                    borderColor: '#2F6F8F',
                    backgroundColor: 'rgba(47,111,143,.12)',
                    fill: true,
                    tension: .3,
                    pointRadius: 0,
                    borderWidth: 2
                },
                {
                    label: 'Batas warning (2°C)',
                    data: [],
                    borderColor: '#B97A00',
                    borderDash: [6, 4],
                    pointRadius: 0,
                    borderWidth: 1.5
                },
                {
                    label: 'Batas kritis (8°C)',
                    data: [],
                    borderColor: '#B3321F',
                    borderDash: [6, 4],
                    pointRadius: 0,
                    borderWidth: 1.5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            scales: {
                y: {
                    title: {
                        display: true,
                        text: '°C'
                    }
                },
                x: {
                    ticks: {
                        maxTicksLimit: 8
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8
                    }
                }
            }
        }
    });

    // ==========================================
    // MONITORING SUHU DAN KIPAS
    // ==========================================

    function fanLine(selector, text, fan = {}) {
        const element = $(selector);

        element.textContent = text + (
            fan.rpm != null ? ', ' + fan.rpm + ' RPM' : ''
        );

        if (fan.fault) {
            const span = document.createElement('span');
            span.className = 'fault';
            span.textContent = ' Bermasalah, kipas tidak berputar';
            element.append(span);
        }
    }

    function renderLatest(data) {
        $('#banner').classList.remove('show');
        $('#app').dataset.status = data.status || '';

        const temperature = Number(data.temperature);

        $('#temp').textContent = Number.isFinite(temperature)
            ? temperature.toFixed(1)
            : '--';

        $('#badge').textContent =
            LBL[data.status] || data.status || '-';

        if (Number.isFinite(temperature)) {
            $('#marker').style.left =
                ((Math.min(12, Math.max(-2, temperature)) + 2) / 14 * 100) + '%';
        }

        $('#updated').textContent = 'Data terakhir ' + ago(data.updated_at);

        $('#online').classList.toggle('on', !!data.online);

        $('#onlineText').textContent = data.online
            ? 'Perangkat online'
            : 'Perangkat offline, terakhir terlihat ' + ago(data.last_seen);

        const fan1 = data.fan1 || {};
        const fan2 = data.fan2 || {};

        fanLine('#fan1st', FAN1[fan1.state] || fan1.state || '-', fan1);

        fanLine(
            '#fan2st',
            fan2.state === 'on' ? 'Menyala' : 'Mati',
            fan2
        );

        document.querySelectorAll('#modeSeg button').forEach(button => {
            button.classList.toggle(
                'active',
                button.dataset.mode === data.mode
            );

            button.disabled = !data.online;
        });

        const locked = data.mode !== 'manual' || !data.online;

        document.querySelectorAll('[data-fan]').forEach(group => {
            const fan = data[group.dataset.fan] || {};

            group.querySelectorAll('button').forEach(button => {
                button.classList.toggle(
                    'active',
                    button.dataset.value === fan.state
                );

                button.disabled = locked;
            });
        });

        $('#ctlHint').textContent = !data.online
            ? 'Perangkat offline, kontrol kipas dinonaktifkan.'
            : data.mode === 'manual'
                ? 'Mode manual: kamu mengatur kipas sendiri.'
                : 'Mode auto: ESP32 mengatur kipas sesuai suhu. Pilih Manual untuk mengontrol sendiri.';
    }

    async function loadLatest() {
        try {
            const data = await getJson(U.latest);
            renderLatest(data);
        } catch (error) {
            $('#banner').classList.add('show');
            $('#online').classList.remove('on');
            $('#onlineText').textContent = 'Tidak terhubung';

            console.error('Gagal memuat data monitoring:', error);
        }
    }

    async function loadHistory() {
        try {
            const points = await getJson(U.history + '?range=' + range);

            chart.data.labels = points.map(point => hhmm(point.t));
            chart.data.datasets[0].data = points.map(point => point.temp);
            chart.data.datasets[1].data = points.map(() => 2);
            chart.data.datasets[2].data = points.map(() => 8);

            chart.update();
        } catch (error) {
            console.error('Gagal memuat grafik:', error);
        }
    }

    async function loadEvents() {
        const listElement = $('#events');

        try {
            const list = await getJson(U.events);

            listElement.replaceChildren();

            if (!Array.isArray(list) || !list.length) {
                const li = document.createElement('li');
                const p = document.createElement('p');

                p.className = 'empty';
                p.textContent = 'Belum ada event.';

                li.append(p);
                listElement.append(li);

                return;
            }

            list.forEach(event => {
                const li = document.createElement('li');
                const dot = document.createElement('span');
                const box = document.createElement('div');
                const time = document.createElement('time');
                const message = document.createElement('span');

                dot.className = 'k ' + (event.type || '');
                time.textContent = hhmm(event.time);
                message.textContent = event.message || '-';

                box.append(time, message);
                li.append(dot, box);
                listElement.append(li);
            });
        } catch (error) {
            console.error('Gagal memuat event:', error);
        }
    }

    async function sendControl(payload) {
        document.querySelectorAll('.seg button').forEach(button => {
            button.disabled = true;
        });

        try {
            await requestJson(U.control, {
                method: 'POST',
                headers: jsonHeaders,
                body: JSON.stringify(payload)
            });

            toast('Perintah terkirim');
        } catch (error) {
            toast(error.message, true);
        }

        await loadLatest();
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('button');

        if (!button || button.disabled) return;

        if (button.dataset.range) {
            range = button.dataset.range;

            document.querySelectorAll('#rangeSeg button').forEach(element => {
                element.classList.toggle('active', element === button);
            });

            loadHistory();
            return;
        }

        if (button.dataset.mode) {
            sendControl({ mode: button.dataset.mode });
            return;
        }

        const group = button.closest('[data-fan]');

        if (group) {
            sendControl({
                [group.dataset.fan]: button.dataset.value
            });
        }
    });

    // ==========================================
    // TRANSAKSI PENGIRIMAN
    // ==========================================

    function renderShipment(shipment) {
        currentShipment = shipment || null;

        if (!shipment) {
            $('#shipmentEmpty').hidden = false;
            $('#shipmentDetail').hidden = true;
            return;
        }

        $('#shipmentEmpty').hidden = true;
        $('#shipmentDetail').hidden = false;

        $('#trxId').textContent = shipment.transaction_id || '-';
        $('#trxBox').textContent = shipment.box_uid || '-';

        $('#trxDriver').textContent =
            shipment.driver_distributor_name || '-';

        $('#trxItem').textContent = shipment.item_name || '-';

        const weight = Number(shipment.weight_kg);

        $('#trxWeight').textContent = Number.isFinite(weight)
            ? weight.toLocaleString('id-ID')
            : '-';

        $('#trxCreated').textContent = dateTime(shipment.created_at);

        $('#trxEdited').textContent = shipment.edited_at
            ? dateTime(shipment.edited_at)
            : 'Belum pernah diedit';

        const edited = Number(shipment.edit_count || 0) >= 1;

        const status = $('#editStatus');
        const editButton = $('#editShipment');

        status.textContent = edited
            ? 'Sudah diedit, transaksi terkunci'
            : 'Belum pernah diedit';

        status.classList.toggle('locked', edited);

        editButton.disabled = edited;
        editButton.textContent = edited
            ? 'Transaksi sudah terkunci'
            : 'Edit transaksi';
    }

    async function loadLatestShipment() {
        try {
            const response = await getJson(U.latestShipment);
            renderShipment(response.data || null);
        } catch (error) {
            console.error('Gagal memuat transaksi:', error);
        }
    }

    // ==========================================
    // SCANNER BARCODE
    // ==========================================

    const dialog = $('#scanDlg');

    async function stopScanner() {
        if (!scanner) return;

        const oldScanner = scanner;
        scanner = null;

        try {
            if (oldScanner.isScanning) {
                await oldScanner.stop();
            }
        } catch (error) {
            console.warn('Scanner stop:', error);
        }

        try {
            oldScanner.clear();
        } catch (error) {
            console.warn('Scanner clear:', error);
        }
    }

    async function startScanner() {
        $('#camMsg').textContent = '';

        if (!window.isSecureContext) {
            $('#camMsg').textContent =
                'Kamera hanya bisa dipakai melalui HTTPS atau localhost. Gunakan input manual.';
            return;
        }

        if (!window.Html5Qrcode || !window.Html5QrcodeSupportedFormats) {
            $('#camMsg').textContent =
                'Library scanner belum berhasil dimuat. Periksa koneksi internet atau gunakan input manual.';
            return;
        }

        await stopScanner();

        try {
            const formats = window.Html5QrcodeSupportedFormats;

            const supportedFormats = [
                formats.CODE_128,
                formats.CODE_39,
                formats.CODE_93,
                formats.CODABAR,
                formats.EAN_13,
                formats.EAN_8,
                formats.UPC_A,
                formats.UPC_E,
                formats.ITF,
                formats.QR_CODE
            ].filter(format => format !== undefined);

            if (supportedFormats.length === 0) {
                throw new Error('Format barcode tidak tersedia.');
            }

            scanner = new Html5Qrcode('reader', {
                formatsToSupport: supportedFormats,
                verbose: false
            });

            await scanner.start(
                { facingMode: 'environment' },
                {
                    fps: 10,
                    aspectRatio: 1.7777778

                    // Tidak menggunakan qrbox agar barcode 1D
                    // yang panjang bisa dibaca di seluruh area kamera.
                },
                handleCode,
                () => {
                    // Tidak menampilkan error setiap frame.
                }
            );

            $('#camMsg').textContent =
                'Kamera aktif. Arahkan seluruh garis barcode ke kamera.';

        } catch (error) {
            console.error('Scanner error:', error);

            await stopScanner();

            $('#camMsg').textContent =
                'Scanner gagal dimulai. Periksa izin kamera atau gunakan input UID manual.';
        }
    }

    function showScanStep() {
        $('#stepScan').hidden = false;
        $('#stepForm').hidden = true;

        $('#manualUid').value = '';
        $('#camMsg').textContent = '';
        $('#formMsg').textContent = '';
    }

    function showFormStep() {
        $('#stepScan').hidden = true;
        $('#stepForm').hidden = false;
    }

    function openScan() {
        shipmentMode = 'create';
        validatedBoxUid = '';

        $('#formTitle').textContent = 'Data pengiriman';

        $('#formDescription').textContent =
            'Lengkapi informasi pengiriman untuk box yang sudah divalidasi.';

        $('#formBox').value = '';
        $('#formDriver').value = '';
        $('#formItem').value = '';
        $('#formWeight').value = '';

        $('#saveShipment').textContent = 'Simpan transaksi';

        showScanStep();
        dialog.showModal();

        startScanner();
    }

    async function closeDialog() {
        scanning = false;
        await stopScanner();

        if (dialog.open) {
            dialog.close();
        }
    }

    // Dipanggil ketika barcode berhasil dibaca.
    async function handleCode(raw) {
        const uid = String(raw || '').trim();

        if (!uid || scanning) return;

        scanning = true;

        await stopScanner();

        const valid = await validateBox(uid);

        scanning = false;

        if (!valid && dialog.open && !$('#stepScan').hidden) {
            startScanner();
        }
    }

    async function validateBox(uid) {
        uid = String(uid || '').trim();

        if (!uid) {
            $('#camMsg').textContent = 'UID Box wajib diisi.';
            return false;
        }

        const manualButton = $('#manualGo');

        manualButton.disabled = true;
        $('#camMsg').textContent = 'Memeriksa UID Box...';

        try {
            const url = U.validateBox.replace(
                '__UID__',
                encodeURIComponent(uid)
            );

            const result = await getJson(url);

            if (!result.valid) {
                throw new Error(
                    result.message || 'Box tidak dapat digunakan.'
                );
            }

            if (result.status !== 'available') {
                throw new Error('Box sudah pernah digunakan.');
            }

            validatedBoxUid = result.uid || uid;

            await stopScanner();

            $('#formBox').value = validatedBoxUid;
            $('#camMsg').textContent = '';

            showFormStep();

            toast('UID Box valid dan tersedia');

            $('#formDriver').focus();

            return true;

        } catch (error) {
            $('#camMsg').textContent = error.message;
            toast(error.message, true);

            return false;

        } finally {
            manualButton.disabled = false;
        }
    }

    // ==========================================
    // SIMPAN TRANSAKSI BARU / EDIT
    // ==========================================

    async function saveShipment() {
        const driver = $('#formDriver').value.trim();
        const item = $('#formItem').value.trim();
        const weight = Number($('#formWeight').value);

        if (!validatedBoxUid && shipmentMode === 'create') {
            $('#formMsg').textContent = 'UID Box belum divalidasi.';
            return;
        }

        if (!driver) {
            $('#formMsg').textContent =
                'Nama pengemudi atau distributor wajib diisi.';
            $('#formDriver').focus();
            return;
        }

        if (!item) {
            $('#formMsg').textContent = 'Nama barang wajib diisi.';
            $('#formItem').focus();
            return;
        }

        if (!Number.isFinite(weight) || weight <= 0 || weight > 100000) {
            $('#formMsg').textContent =
                'Berat barang harus lebih dari 0 kg.';
            $('#formWeight').focus();
            return;
        }

        const payload = {
            driver_distributor_name: driver,
            item_name: item,
            weight_kg: weight
        };

        if (shipmentMode === 'create') {
            payload.box_uid = validatedBoxUid;
        }

        const button = $('#saveShipment');

        button.disabled = true;
        button.textContent = 'Menyimpan...';
        $('#formMsg').textContent = '';

        try {
            let response;

            if (shipmentMode === 'create') {
                response = await requestJson(U.storeShipment, {
                    method: 'POST',
                    headers: jsonHeaders,
                    body: JSON.stringify(payload)
                });
            } else {
                if (!currentShipment) {
                    throw new Error('Data transaksi tidak ditemukan.');
                }

                const url = U.updateShipment.replace(
                    '__ID__',
                    encodeURIComponent(currentShipment.transaction_id)
                );

                response = await requestJson(url, {
                    method: 'PUT',
                    headers: jsonHeaders,
                    body: JSON.stringify(payload)
                });
            }

            toast(
                shipmentMode === 'create'
                    ? 'Transaksi berhasil disimpan'
                    : 'Transaksi berhasil diperbarui'
            );

            if (response.data) {
                renderShipment(response.data);
            }

            await loadLatestShipment();
            await closeDialog();

        } catch (error) {
            $('#formMsg').textContent = error.message;
            toast(error.message, true);

        } finally {
            button.disabled = false;

            button.textContent = shipmentMode === 'create'
                ? 'Simpan transaksi'
                : 'Simpan perubahan';
        }
    }

    // ==========================================
    // EDIT TRANSAKSI
    // ==========================================

    async function openEditShipment() {
        if (!currentShipment) {
            toast('Belum ada transaksi untuk diedit.', true);
            return;
        }

        if (Number(currentShipment.edit_count || 0) >= 1) {
            toast('Transaksi sudah pernah diedit dan terkunci.', true);
            return;
        }

        shipmentMode = 'edit';

        await stopScanner();

        $('#formTitle').textContent = 'Edit transaksi pengiriman';

        $('#formDescription').textContent =
            'Transaksi hanya dapat diedit satu kali. UID Box dan ID transaksi tidak dapat diubah.';

        $('#formBox').value = currentShipment.box_uid || '';

        $('#formDriver').value =
            currentShipment.driver_distributor_name || '';

        $('#formItem').value =
            currentShipment.item_name || '';

        $('#formWeight').value =
            currentShipment.weight_kg || '';

        $('#saveShipment').textContent = 'Simpan perubahan';
        $('#formMsg').textContent = '';

        showFormStep();

        dialog.showModal();

        $('#formDriver').focus();
    }

    // ==========================================
    // EVENT TOMBOL
    // ==========================================

    $('#openScan').addEventListener('click', openScan);

    $('#cancelScan').addEventListener('click', closeDialog);

    $('#manualGo').addEventListener('click', async () => {
        if (scanning) return;

        const uid = $('#manualUid').value.trim();

        await stopScanner();

        const valid = await validateBox(uid);

        if (!valid && dialog.open && !$('#stepScan').hidden) {
            startScanner();
        }
    });

    $('#manualUid').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#manualGo').click();
        }
    });

    $('#backForm').addEventListener('click', async () => {
        if (shipmentMode === 'edit') {
            await closeDialog();
            return;
        }

        validatedBoxUid = '';

        showScanStep();
        startScanner();
    });

    $('#saveShipment').addEventListener('click', saveShipment);

    $('#editShipment').addEventListener('click', openEditShipment);

    $('#formDriver').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#formItem').focus();
        }
    });

    $('#formItem').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#formWeight').focus();
        }
    });

    $('#formWeight').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            saveShipment();
        }
    });

    dialog.addEventListener('close', () => {
        stopScanner();
        scanning = false;
    });

    // ==========================================
    // INISIALISASI DASHBOARD
    // ==========================================

    loadLatest();
    loadHistory();
    loadEvents();
    loadLatestShipment();

    setInterval(loadLatest, 3000);
    setInterval(loadEvents, 10000);
    setInterval(loadHistory, 30000);
    setInterval(loadLatestShipment, 10000);

})();
</script>

</body>
</html>
