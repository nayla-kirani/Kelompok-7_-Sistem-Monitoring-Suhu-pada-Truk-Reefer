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
    :root{--ink:#12262F;--frost:#EAF0F3;--paper:#F8FAFB;--line:#CBD8DE;--mute:#5B707A;--cold:#2F6F8F;
    --ok:#3DBE9A;--warn:#F0B429;--crit:#E8604C;--ok-d:#17745A;--crit-d:#B3321F}
    *{box-sizing:border-box}
    body{margin:0;background:var(--frost);color:var(--ink);font:400 15px/1.5 'Instrument Sans',system-ui,sans-serif}
    h1,h2{font-family:'Bricolage Grotesque','Instrument Sans',sans-serif;margin:0}
    .wrap{max-width:1180px;margin:0 auto;padding:20px 16px 40px}
    header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
    h1{font-size:22px;font-weight:700}
    .sub{color:var(--mute);font-size:13px}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border:1px solid var(--line);border-radius:999px;background:var(--paper);font-size:13px;font-weight:500}
    .dot{width:8px;height:8px;border-radius:50%;background:var(--crit)}
    .pill.on .dot{background:var(--ok-d)}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
    .panel{background:var(--paper);border:1px solid var(--line);border-radius:6px;padding:18px}
    .panel h2{font-size:16px;font-weight:600;margin-bottom:12px}
    .hero{grid-column:span 7;background:var(--ink);color:#fff;border-radius:20px;padding:26px 28px}
    .fans{grid-column:span 5}
    .chartp{grid-column:span 8}
    .side{grid-column:span 4}
    @media(max-width:900px){.hero,.fans,.chartp,.side{grid-column:1/-1}.temp{font-size:68px}}
    .reading{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap}
    .temp{font:700 88px/.95 'Bricolage Grotesque',sans-serif;letter-spacing:-2px}
    .temp small{font-size:32px;font-weight:600;letter-spacing:0;margin-left:4px;opacity:.7}
    .badge{padding:6px 14px;border-radius:999px;font-weight:600;font-size:14px;background:#2a414b;margin-bottom:10px}
    [data-status=normal] .badge{background:var(--ok);color:#06251c}
    [data-status=warning] .badge{background:var(--warn);color:#3a2800}
    [data-status=critical] .badge{background:var(--crit);color:#3a0c05}
    .strip{position:relative;margin:36px 0 8px}
    .bands{display:flex;height:12px;border-radius:6px;overflow:hidden}
    .bands i{display:block}
    .b-ok{flex:4;background:var(--ok)}.b-warn{flex:6;background:var(--warn)}.b-crit{flex:4;background:var(--crit)}
    .marker{position:absolute;top:-8px;left:0;width:4px;height:28px;margin-left:-2px;background:#fff;border-radius:2px;box-shadow:0 0 0 3px var(--ink);transition:left .6s ease}
    .ticks{position:relative;height:18px;margin-top:8px;font-size:12px;color:#9FB3BC}
    .ticks span{position:absolute;transform:translateX(-50%)}
    .hero .sub{color:#9FB3BC}
    .seg{display:inline-flex;border:1px solid var(--line);border-radius:6px;overflow:hidden;flex:none}
    .seg button{border:0;background:#fff;padding:7px 12px;font:500 13px 'Instrument Sans',sans-serif;color:var(--ink);cursor:pointer}
    .seg button+button{border-left:1px solid var(--line)}
    .seg button.active{background:var(--cold);color:#fff}
    .seg button:disabled{opacity:.45;cursor:not-allowed}
    button:focus-visible{outline:3px solid #7FB8D4;outline-offset:2px}
    .row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--line)}
    .row.first{border-top:0;padding-top:0}
    .row b{display:block;font-weight:600}
    .row .st{font-size:13px;color:var(--mute)}
    .fault{color:var(--crit-d);font-weight:600}
    dl{margin:0 0 18px;display:grid;grid-template-columns:auto 1fr;gap:6px 14px}
    dt{color:var(--mute)}dd{margin:0;font-weight:500;text-align:right;word-break:break-all}
    .ev{list-style:none;margin:0;padding:0;max-height:240px;overflow:auto}
    .ev li{display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--line);font-size:14px}
    .ev time{display:block;color:var(--mute);font-size:12px}
    .ev .k{width:8px;height:8px;border-radius:50%;margin-top:7px;flex:none;background:var(--cold)}
    .ev .k.warning{background:var(--warn)}.ev .k.critical{background:var(--crit)}.ev .k.normal{background:var(--ok-d)}
    .empty{color:var(--mute);font-size:14px;margin:0}
    .chartbox{position:relative;height:280px}
    .toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;padding:10px 16px;border-radius:6px;font-size:14px;opacity:0;pointer-events:none;transition:opacity .2s}
    .toast.show{opacity:1}.toast.err{background:var(--crit-d)}
    .banner{display:none;background:#FBE3DE;border:1px solid #E8A99E;color:var(--crit-d);padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:14px}
    .banner.show{display:block}
    .btn{border:1px solid var(--cold);background:var(--cold);color:#fff;padding:8px 14px;border-radius:6px;font:600 14px 'Instrument Sans',sans-serif;cursor:pointer}
    .btn.ghost{background:#fff;color:var(--ink);border-color:var(--line)}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .in{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:6px;font:inherit;background:#fff;color:var(--ink)}
    dialog{border:1px solid var(--line);border-radius:12px;padding:20px;width:min(440px,calc(100% - 32px));background:var(--paper);color:var(--ink)}
    dialog::backdrop{background:rgba(18,38,47,.55)}
    #reader{width:100%;min-height:180px;background:#0b171d;border-radius:6px;overflow:hidden}
    .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
    .msg{color:var(--crit-d);font-size:13px;margin:8px 0 0;min-height:1em}
    @media(prefers-reduced-motion:reduce){*{transition:none!important}}
    </style>
  </head>
  <body>
    <div class="wrap" id="app" data-status="">
      <header>
        <div>
          <h1>Monitoring suhu truk reefer</h1>
          <div class="sub">Perangkat {{ $deviceId }}</div>
        </div>
        <span class="pill" id="online"><span class="dot"></span><span id="onlineText">Menghubungkan</span></span>
      </header>

      <div class="banner" id="banner">Tidak bisa terhubung ke API Go. Dashboard mencoba lagi otomatis.</div>

      <div class="grid">
        <section class="hero" aria-live="polite">
          <div class="reading">
            <div class="temp"><span id="temp">--</span><small>°C</small></div>
            <span class="badge" id="badge">Menunggu data</span>
          </div>
          <div class="strip">
            <div class="bands"><i class="b-ok"></i><i class="b-warn"></i><i class="b-crit"></i></div>
            <div class="marker" id="marker"></div>
            <div class="ticks"><span style="left:28.57%">2°C</span><span style="left:71.43%">8°C</span></div>
          </div>
          <div class="sub" id="updated">Belum ada data</div>
        </section>

        <section class="panel fans">
          <h2>Kipas dan kontrol</h2>
          <div class="row first">
            <span class="sub">Mode</span>
            <div class="seg" id="modeSeg"><button data-mode="auto">Auto</button><button data-mode="manual">Manual</button></div>
          </div>
          <div class="row">
            <div><b>Kipas 1, penjaga suhu</b><span class="st" id="fan1st">-</span></div>
            <div class="seg" data-fan="fan1"><button data-value="off">Mati</button><button data-value="normal">Normal</button><button data-value="high">Tinggi</button></div>
          </div>
          <div class="row">
            <div><b>Kipas 2, pendingin es</b><span class="st" id="fan2st">-</span></div>
            <div class="seg" data-fan="fan2"><button data-value="off">Mati</button><button data-value="on">Nyala</button></div>
          </div>
          <p class="sub" id="ctlHint" style="margin:8px 0 0"></p>
        </section>

        <section class="panel chartp">
          <div class="row first" style="padding-bottom:6px">
            <h2 style="margin:0">Riwayat suhu</h2>
            <div class="seg" id="rangeSeg">
              <button data-range="1h" class="active">1 jam</button><button data-range="6h">6 jam</button><button data-range="24h">24 jam</button>
            </div>
          </div>
          <div class="chartbox"><canvas id="chart"></canvas></div>
        </section>

        <section class="panel side">
          <h2>Barang di box</h2>
          <dl>
            <dt>Box</dt><dd id="box">-</dd>
            <dt>ID barang</dt><dd id="uid">-</dd>
            <dt>Nama barang</dt><dd id="item">-</dd>
          </dl>
          <button class="btn" id="openScan" style="width:100%;margin:-4px 0 18px">Scan barcode</button>
          <h2>Riwayat event</h2>
          <ul class="ev" id="events"></ul>
        </section>
      </div>
    </div>
    <dialog id="scanDlg" aria-labelledby="scanTitle">
      <div id="stepScan">
        <h2 id="scanTitle" style="font-size:18px;margin-bottom:12px">Scan barcode barang</h2>
        <div id="reader"></div>
        <p class="msg" id="camMsg"></p>
        <label class="sub" for="manualUid" style="display:block;margin-top:10px">Atau ketik / scan dengan alat scanner USB</label>
        <div style="display:flex;gap:8px;margin-top:4px">
          <input class="in" id="manualUid" placeholder="UID atau barcode" autocomplete="off">
          <button class="btn" id="manualGo">Simpan</button>
        </div>
        <div class="actions"><button class="btn ghost" id="cancelScan">Batal</button></div>
      </div>
      <div id="stepName" hidden>
        <h2 style="font-size:18px;margin-bottom:6px">Barang baru</h2>
        <p class="sub" style="margin:0 0 12px">UID <b id="uidShown"></b> belum punya nama. Beri nama supaya mudah dikenali.</p>
        <input class="in" id="itemName" placeholder="Contoh: Ikan tongkol" maxlength="128">
        <div class="actions">
          <button class="btn ghost" id="skipName">Lewati</button>
          <button class="btn" id="saveName">Simpan nama</button>
        </div>
      </div>
    </dialog>
    <div class="toast" id="toast" role="status"></div>

    <script>
    (() => {
      const $ = s => document.querySelector(s);
      const U = {
        latest: "{{ route('dashboard.latest') }}",
        history: "{{ route('dashboard.history') }}",
        events: "{{ route('dashboard.events') }}",
        control: "{{ route('dashboard.control') }}",
        scan: "{{ route('dashboard.scan') }}",
        items: "{{ route('dashboard.items') }}",
      };
      const csrf = $('meta[name=csrf-token]').content;
      const LBL = { normal: 'Normal', warning: 'Warning', critical: 'Kritis' };
      const FAN1 = { off: 'Mati', normal: 'Menyala, kecepatan normal', high: 'Menyala, kecepatan tinggi' };
      let range = '1h';

      const getJson = async url => {
        const r = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error(r.status);
        return r.json();
      };
      const ago = iso => {
        if (!iso) return '-';
        const s = Math.max(0, Math.round((Date.now() - new Date(iso)) / 1000));
        if (s < 60) return s + ' detik lalu';
        if (s < 3600) return Math.floor(s / 60) + ' menit lalu';
        return Math.floor(s / 3600) + ' jam lalu';
      };
      const hhmm = iso => new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
      const toast = (msg, err) => {
        const t = $('#toast');
        t.textContent = msg;
        t.className = 'toast show' + (err ? ' err' : '');
        clearTimeout(toast.t);
        toast.t = setTimeout(() => (t.className = 'toast'), 2800);
      };

      const chart = new Chart($('#chart'), {
        type: 'line',
        data: { labels: [], datasets: [
          { label: 'Suhu', data: [], borderColor: '#2F6F8F', backgroundColor: 'rgba(47,111,143,.12)', fill: true, tension: .3, pointRadius: 0, borderWidth: 2 },
          { label: 'Batas warning (2°C)', data: [], borderColor: '#B97A00', borderDash: [6, 4], pointRadius: 0, borderWidth: 1.5 },
          { label: 'Batas kritis (8°C)', data: [], borderColor: '#B3321F', borderDash: [6, 4], pointRadius: 0, borderWidth: 1.5 },
        ] },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false,
          interaction: { mode: 'index', intersect: false },
          scales: { y: { title: { display: true, text: '°C' } }, x: { ticks: { maxTicksLimit: 8 } } },
          plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
        },
      });

      function fanLine(sel, text, f) {
        const el = $(sel);
        el.textContent = text + (f.rpm != null ? ', ' + f.rpm + ' RPM' : '');
        if (f.fault) {
          const s = document.createElement('span');
          s.className = 'fault';
          s.textContent = ' Bermasalah, kipas tidak berputar';
          el.append(s);
        }
      }

      function renderLatest(d) {
        $('#banner').classList.remove('show');
        $('#app').dataset.status = d.status;
        $('#temp').textContent = Number(d.temperature).toFixed(1);
        $('#badge').textContent = LBL[d.status] || d.status;
        $('#marker').style.left = ((Math.min(12, Math.max(-2, d.temperature)) + 2) / 14 * 100) + '%';
        $('#updated').textContent = 'Data terakhir ' + ago(d.updated_at);
        $('#online').classList.toggle('on', !!d.online);
        $('#onlineText').textContent = d.online ? 'Perangkat online' : 'Perangkat offline, terakhir terlihat ' + ago(d.last_seen);
        $('#box').textContent = d.box_id || '-';
        $('#uid').textContent = d.item_uid || '-';
        $('#item').textContent = d.item_name || '-';
        fanLine('#fan1st', FAN1[d.fan1.state] || d.fan1.state, d.fan1);
        fanLine('#fan2st', d.fan2.state === 'on' ? 'Menyala' : 'Mati', d.fan2);

        document.querySelectorAll('#modeSeg button').forEach(b => b.classList.toggle('active', b.dataset.mode === d.mode));
        const locked = d.mode !== 'manual' || !d.online;
        document.querySelectorAll('[data-fan]').forEach(g => g.querySelectorAll('button').forEach(b => {
          b.classList.toggle('active', b.dataset.value === d[g.dataset.fan].state);
          b.disabled = locked;
        }));
        $('#ctlHint').textContent = !d.online
          ? 'Perangkat offline, kontrol kipas dinonaktifkan.'
          : d.mode === 'manual'
            ? 'Mode manual: kamu mengatur kipas sendiri.'
            : 'Mode auto: ESP32 mengatur kipas sesuai suhu. Pilih Manual untuk mengontrol sendiri.';
      }

      async function loadLatest() {
        try { renderLatest(await getJson(U.latest)); }
        catch { $('#banner').classList.add('show'); $('#online').classList.remove('on'); $('#onlineText').textContent = 'Tidak terhubung'; }
      }

      async function loadHistory() {
        try {
          const pts = await getJson(U.history + '?range=' + range);
          chart.data.labels = pts.map(p => hhmm(p.t));
          chart.data.datasets[0].data = pts.map(p => p.temp);
          chart.data.datasets[1].data = pts.map(() => 2);
          chart.data.datasets[2].data = pts.map(() => 8);
          chart.update();
        } catch {}
      }

      async function loadEvents() {
        const ul = $('#events');
        try {
          const list = await getJson(U.events);
          ul.replaceChildren();
          if (!list.length) {
            const li = document.createElement('li');
            li.innerHTML = '<p class="empty">Belum ada event. Event muncul saat kondisi suhu atau kipas berubah.</p>';
            ul.append(li);
            return;
          }
          list.forEach(e => {
            const li = document.createElement('li');
            const k = document.createElement('span'); k.className = 'k ' + (e.type || '');
            const box = document.createElement('div');
            const time = document.createElement('time'); time.textContent = hhmm(e.time);
            const msg = document.createElement('span'); msg.textContent = e.message;
            box.append(time, msg);
            li.append(k, box);
            ul.append(li);
          });
        } catch {}
      }

      async function send(payload) {
        document.querySelectorAll('.seg button').forEach(b => (b.disabled = true));
        try {
          const r = await fetch(U.control, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload),
          });
          if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || 'Perintah gagal dikirim');
          toast('Perintah terkirim');
        } catch (e) { toast(e.message, true); }
        await loadLatest();
        document.querySelectorAll('#rangeSeg button').forEach(b => (b.disabled = false));
      }

      document.addEventListener('click', e => {
        const b = e.target.closest('button');
        if (!b || b.disabled) return;
        if (b.dataset.range) {
          range = b.dataset.range;
          document.querySelectorAll('#rangeSeg button').forEach(x => x.classList.toggle('active', x === b));
          return loadHistory();
        }
        if (b.dataset.mode) return send({ mode: b.dataset.mode });
        const g = b.closest('[data-fan]');
        if (g) return send({ [g.dataset.fan]: b.dataset.value });
      });

      // ---- Scan barcode lewat kamera ----
      const dlg = $('#scanDlg');
      const jsonHeaders = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
      let scanner = null, scanning = false, scannedUid = '';

      async function stopScanner() {
        if (!scanner) return;
        try { await scanner.stop(); } catch {}
        try { scanner.clear(); } catch {}
        scanner = null;
      }

      async function startScanner() {
        $('#camMsg').textContent = '';
        if (!window.isSecureContext) {
          $('#camMsg').textContent = 'Kamera hanya bisa dipakai lewat HTTPS atau localhost. Pakai kolom ketik di bawah.';
          return;
        }
        try {
          const f = Html5QrcodeSupportedFormats;
          scanner = new Html5Qrcode('reader', {
            formatsToSupport: [f.QR_CODE, f.CODE_128, f.CODE_39, f.EAN_13, f.EAN_8, f.UPC_A, f.UPC_E, f.ITF],
            useBarCodeDetectorIfSupported: true,
            verbose: false,
          });
          await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 160 } }, handleCode, () => {});
        } catch {
          scanner = null;
          $('#camMsg').textContent = 'Kamera tidak bisa dibuka. Izinkan akses kamera di browser, atau ketik UID manual.';
        }
      }

      function openScan() {
        $('#stepScan').hidden = false;
        $('#stepName').hidden = true;
        $('#manualUid').value = '';
        $('#itemName').value = '';
        dlg.showModal();
        startScanner();
      }
      function closeScan() { stopScanner(); if (dlg.open) dlg.close(); }

      async function handleCode(raw) {
        const uid = String(raw || '').trim();
        if (!uid || scanning) return;
        scanning = true;
        await stopScanner();
        try {
          const r = await fetch(U.scan, { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ item_uid: uid }) });
          if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || 'Scan gagal disimpan');
          const d = await getJson(U.latest);
          renderLatest(d);
          if (d.item_name) {
            toast('Barang terbaca: ' + d.item_name);
            closeScan();
          } else {
            scannedUid = uid;
            $('#uidShown').textContent = uid;
            $('#stepScan').hidden = true;
            $('#stepName').hidden = false;
            $('#itemName').focus();
          }
        } catch (e) {
          toast(e.message, true);
          closeScan();
        }
        scanning = false;
      }

      async function saveName() {
        const name = $('#itemName').value.trim();
        if (!name) return $('#itemName').focus();
        try {
          const r = await fetch(U.items, { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ uid: scannedUid, name }) });
          if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || 'Nama gagal disimpan');
          toast('Nama barang disimpan');
          await loadLatest();
        } catch (e) { toast(e.message, true); }
        closeScan();
      }

      $('#openScan').addEventListener('click', openScan);
      $('#cancelScan').addEventListener('click', closeScan);
      $('#skipName').addEventListener('click', closeScan);
      $('#saveName').addEventListener('click', saveName);
      $('#manualGo').addEventListener('click', () => handleCode($('#manualUid').value));
      $('#manualUid').addEventListener('keydown', e => { if (e.key === 'Enter') handleCode(e.target.value); });
      $('#itemName').addEventListener('keydown', e => { if (e.key === 'Enter') saveName(); });
      dlg.addEventListener('close', stopScanner);

      loadLatest(); loadHistory(); loadEvents();
      setInterval(loadLatest, 3000);
      setInterval(loadEvents, 10000);
      setInterval(loadHistory, 30000);
    })();
    </script>
  </body>
</html>