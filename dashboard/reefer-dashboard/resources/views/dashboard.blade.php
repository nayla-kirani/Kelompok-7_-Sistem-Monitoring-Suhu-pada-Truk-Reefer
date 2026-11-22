<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reefer Monitor - Monitoring Suhu</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <style>
    :root {
        --ink: #172f42;
        --ink-light: #254c65;
        --frost: #f2f6fa;
        --paper: #ffffff;
        --line: #e4ebf1;
        --mute: #748797;
        --cold: #2685b5;
        --cold-light: #eaf5fb;
        --cyan: #48b8d2;
        --ok: #27b58a;
        --warn: #f2b544;
        --crit: #e65d60;
        --ok-d: #16835f;
        --crit-d: #c23d3d;
        --shadow: 0 8px 28px rgba(25,55,75,.055);
        --sidebar-width: 260px;
    }

    * { box-sizing: border-box; }

    html {
        scroll-behavior: smooth;
        scroll-padding-top: 30px;
    }

    body {
        margin: 0;
        background: var(--frost);
        color: var(--ink);
        font: 400 15px/1.5 'Instrument Sans', system-ui, sans-serif;
    }

    h1, h2, h3 {
        font-family: 'Bricolage Grotesque', 'Instrument Sans', sans-serif;
        margin: 0;
    }

    button, input { font: inherit; }

    button:focus-visible,
    a:focus-visible {
        outline: 3px solid #7fb8d4;
        outline-offset: 3px;
    }

    /* =====================================
       SIDEBAR
    ===================================== */

    .sidebar {
        position: fixed;
        z-index: 100;
        inset: 0 auto 0 0;
        width: var(--sidebar-width);
        height: 100dvh;
        display: flex;
        flex-direction: column;
        padding: 24px 17px;
        background: #142f43;
        color: white;
        transition: width .25s ease, transform .25s ease;
        overflow: hidden;
    }

    .sidebar-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 0 5px 27px;
    }

    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 11px;
        min-width: 0;
    }

    .sidebar-logo {
        width: 42px;
        height: 42px;
        flex: none;
        display: grid;
        place-items: center;
        border-radius: 13px;
        background: linear-gradient(135deg, #1678a9, #48bfd5);
        font-size: 23px;
    }

    .sidebar-brand-text {
        min-width: 0;
        white-space: nowrap;
    }

    .sidebar-brand-text strong {
        display: block;
        font: 800 17px 'Bricolage Grotesque', sans-serif;
        letter-spacing: -.5px;
    }

    .sidebar-brand-text small {
        display: block;
        margin-top: 2px;
        color: #a8c1cf;
        font-size: 10px;
        letter-spacing: 1px;
    }

    .sidebar-toggle {
        width: 34px;
        height: 34px;
        flex: none;
        border: 1px solid rgba(255,255,255,.14);
        border-radius: 9px;
        color: white;
        background: rgba(255,255,255,.08);
        cursor: pointer;
        font-size: 18px;
        transition: .2s;
    }

    .sidebar-toggle:hover {
        background: rgba(255,255,255,.17);
    }

    .sidebar-label {
        padding: 0 12px;
        margin: 5px 0 10px;
        color: #7f9cad;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1.5px;
        white-space: nowrap;
    }

    .sidebar-menu {
        display: flex;
        flex-direction: column;
        gap: 7px;
    }

    .side-link {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 13px;
        padding: 13px 14px;
        border: 0;
        border-radius: 11px;
        background: transparent;
        color: #b8cbd6;
        text-align: left;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
        cursor: pointer;
        transition: .2s ease;
    }

    .side-link:hover {
        color: white;
        background: rgba(255,255,255,.08);
    }

    .side-link.active {
        color: white;
        background: linear-gradient(135deg, #167eae, #279fc0);
        box-shadow: 0 5px 15px rgba(0,0,0,.12);
    }

    .side-icon {
        width: 22px;
        flex: none;
        text-align: center;
        font-size: 18px;
    }

    .sidebar-bottom {
        margin-top: auto;
        padding: 16px 10px 4px;
        border-top: 1px solid rgba(255,255,255,.12);
        color: #91aab9;
        font-size: 11px;
        line-height: 1.7;
    }

    .sidebar-bottom strong {
        display: block;
        color: white;
        font-size: 12px;
    }

    /* SIDEBAR COLLAPSED DESKTOP */

    body.sidebar-collapsed .sidebar {
        width: 78px;
    }

    body.sidebar-collapsed .sidebar-brand-text,
    body.sidebar-collapsed .sidebar-label,
    body.sidebar-collapsed .side-text,
    body.sidebar-collapsed .sidebar-bottom {
        display: none;
    }

    body.sidebar-collapsed .sidebar-top {
        flex-direction: column;
        padding-bottom: 25px;
    }

    body.sidebar-collapsed .sidebar-brand {
        justify-content: center;
    }

    body.sidebar-collapsed .side-link {
        justify-content: center;
        padding: 13px 8px;
    }

    body.sidebar-collapsed .side-icon {
        font-size: 20px;
    }

    /* =====================================
       MAIN CONTENT
    ===================================== */

    .main-content {
        min-height: 100vh;
        margin-left: var(--sidebar-width);
        transition: margin-left .25s ease;
    }

    body.sidebar-collapsed .main-content {
        margin-left: 78px;
    }

    .mobile-topbar {
        display: none;
    }

    .page {
        display: block;
    }

    .page[hidden] {
        display: none !important;
    }

    /* =====================================
       LANDING PAGE
    ===================================== */

    .landing-page {
        min-height: 100vh;
        padding: 28px 35px 45px;
    }

    .landing-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 24px;
    }

    .landing-top-label {
        color: var(--mute);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1.5px;
    }

    .landing-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: var(--mute);
        font-size: 12px;
    }

    .landing-status span {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--ok);
    }

    .landing-hero {
        position: relative;
        min-height: 440px;
        display: flex;
        align-items: flex-end;
        overflow: hidden;
        padding: 45px;
        border-radius: 25px;
        isolation: isolate;
        background-color: #183d55;
        background-image:
            linear-gradient(
                0deg,
                #183d55 0%,
                rgba(24,61,85,.88) 15%,
                rgba(24,61,85,.35) 55%,
                rgba(24,61,85,.12) 100%
            ),
            url("{{ asset('images/truk-reefer.jpg') }}");
        background-position: center;
        background-size: cover;
        box-shadow: 0 15px 40px rgba(25,55,75,.12);
    }

    .landing-hero::before {
        content: "";
        position: absolute;
        inset: 0;
        z-index: -1;
        background:
            radial-gradient(circle at 80% 15%, rgba(72,184,210,.25), transparent 35%),
            linear-gradient(135deg, rgba(20,47,67,.08), rgba(20,47,67,.45));
    }

    .landing-hero-content {
        max-width: 700px;
        color: white;
    }

    .landing-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 13px;
        margin-bottom: 18px;
        border: 1px solid rgba(255,255,255,.25);
        border-radius: 30px;
        background: rgba(255,255,255,.1);
        color: #d9f3fa;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 1.4px;
        backdrop-filter: blur(8px);
    }

    .landing-hero h1 {
        max-width: 680px;
        font-size: clamp(36px, 5vw, 64px);
        line-height: 1.05;
        letter-spacing: -2px;
    }

    .landing-hero h1 span {
        color: #70d5e8;
    }

    .landing-hero p {
        max-width: 560px;
        margin: 18px 0 0;
        color: #d0e1e9;
        font-size: 15px;
        line-height: 1.8;
    }

    .landing-info {
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(240px, .8fr);
        gap: 22px;
        margin-top: 24px;
    }

    .landing-description,
    .landing-quick-card {
        padding: 26px;
        border: 1px solid var(--line);
        border-radius: 18px;
        background: white;
        box-shadow: var(--shadow);
    }

    .landing-description h2 {
        font-size: 21px;
        margin-bottom: 10px;
    }

    .landing-description p {
        max-width: 720px;
        margin: 0;
        color: var(--mute);
        font-size: 14px;
        line-height: 1.8;
    }

    .landing-quick-card {
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 13px;
        background: linear-gradient(145deg, #ffffff, #edf7fb);
    }

    .quick-icon {
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        background: var(--cold-light);
        color: var(--cold);
        font-size: 21px;
    }

    .landing-quick-card strong {
        font: 700 17px 'Bricolage Grotesque', sans-serif;
    }

    .landing-quick-card p {
        margin: -6px 0 2px;
        color: var(--mute);
        font-size: 12px;
        line-height: 1.6;
    }

    .landing-cta {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 9px;
        width: fit-content;
        padding: 12px 18px;
        border: 0;
        border-radius: 10px;
        background: var(--cold);
        color: white;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: .2s;
    }

    .landing-cta:hover {
        background: #176d98;
        transform: translateY(-1px);
    }

    .landing-footer {
        padding: 25px 5px 0;
        color: #91a0aa;
        text-align: center;
        font-size: 11px;
    }

    /* =====================================
       DASHBOARD LAMA
    ===================================== */

    .wrap {
        max-width: 1440px;
        margin: 0 auto;
        padding: 28px 30px 50px;
    }

    .main-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 22px;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 13px;
        min-width: 0;
    }

    .brand-icon {
        width: 48px;
        height: 48px;
        display: grid;
        place-items: center;
        flex: none;
        border-radius: 15px;
        color: white;
        font-size: 26px;
        background: linear-gradient(135deg, #1678a9, #48bfd5);
        box-shadow: 0 6px 16px rgba(38,133,181,.2);
    }

    .brand h1 {
        font-size: 23px;
        line-height: 1.2;
        letter-spacing: -.6px;
    }

    .brand h1 span {
        color: var(--cold);
        margin-left: 5px;
    }

    .brand .sub { margin-top: 3px; }

    .sub {
        color: var(--mute);
        font-size: 13px;
    }

    .pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 15px;
        border: 1px solid var(--line);
        border-radius: 12px;
        background: white;
        box-shadow: var(--shadow);
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .dot {
        width: 8px;
        height: 8px;
        flex: none;
        border-radius: 50%;
        background: var(--crit);
    }

    .pill.on {
        color: var(--ok-d);
        background: #edfaf5;
        border-color: #d2f1e5;
    }

    .pill.on .dot {
        background: var(--ok);
        box-shadow: 0 0 0 4px rgba(39,181,138,.12);
    }

    .banner {
        display: none;
        background: #fbe3de;
        border: 1px solid #e8a99e;
        color: var(--crit-d);
        padding: 12px 15px;
        border-radius: 12px;
        margin-bottom: 18px;
        font-size: 14px;
    }

    .banner.show { display: block; }

    .grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 20px;
    }

    .panel {
        min-width: 0;
        padding: 23px;
        background: var(--paper);
        border: 1px solid #e7edf2;
        border-radius: 18px;
        box-shadow: var(--shadow);
        transition: box-shadow .2s ease;
    }

    .panel:hover {
        box-shadow: 0 12px 32px rgba(25,55,75,.085);
    }

    .panel h2 {
        font-size: 17px;
        font-weight: 700;
        letter-spacing: -.2px;
        margin-bottom: 17px;
    }

    .hero {
        grid-column: span 7;
        position: relative;
        overflow: hidden;
        min-height: 290px;
        padding: 32px;
        color: white;
        border: 0;
        border-radius: 22px;
        background:
            radial-gradient(circle at 90% 10%, rgba(73,190,220,.24), transparent 32%),
            linear-gradient(135deg, #142f43, #1c506c);
        box-shadow: 0 14px 35px rgba(20,47,67,.16);
    }

    .hero::after {
        content: "❄";
        position: absolute;
        right: 25px;
        bottom: -55px;
        font-size: 220px;
        color: rgba(255,255,255,.045);
        pointer-events: none;
    }

    .hero > * {
        position: relative;
        z-index: 1;
    }

    .fans { grid-column: span 5; }
    .chartp { grid-column: span 8; }
    .side { grid-column: span 4; }

    .reading {
        display: flex;
        align-items: flex-end;
        gap: 16px;
        flex-wrap: wrap;
    }

    .temp {
        font: 800 88px/.95 'Bricolage Grotesque', sans-serif;
        letter-spacing: -4px;
    }

    .temp small {
        font-size: 32px;
        font-weight: 600;
        letter-spacing: 0;
        margin-left: 4px;
        opacity: .7;
    }

    .badge {
        padding: 8px 15px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 13px;
        background: #2a414b;
        margin-bottom: 13px;
    }

    [data-status=normal] .badge {
        background: var(--ok);
        color: #06251c;
    }

    [data-status=warning] .badge {
        background: var(--warn);
        color: #3a2800;
    }

    [data-status=critical] .badge {
        background: var(--crit);
        color: #3a0c05;
    }

    .hero .sub { color: #a8c1cf; }

    .strip {
        position: relative;
        margin: 36px 0 8px;
    }

    .bands {
        display: flex;
        height: 12px;
        border-radius: 6px;
        overflow: hidden;
    }

    .bands i { display: block; }
    .b-ok { flex: 4; background: var(--ok); }
    .b-warn { flex: 6; background: var(--warn); }
    .b-crit { flex: 4; background: var(--crit); }

    .marker {
        position: absolute;
        top: -8px;
        left: 0;
        width: 4px;
        height: 28px;
        margin-left: -2px;
        background: white;
        border-radius: 2px;
        box-shadow: 0 0 0 3px #18394d;
        transition: left .6s ease;
    }

    .ticks {
        position: relative;
        height: 18px;
        margin-top: 8px;
        font-size: 12px;
        color: #a8c1cf;
    }

    .ticks span {
        position: absolute;
        transform: translateX(-50%);
    }

    .seg {
        display: inline-flex;
        flex: none;
        border: 1px solid var(--line);
        border-radius: 10px;
        overflow: hidden;
        background: #f7f9fb;
    }

    .seg button {
        border: 0;
        background: transparent;
        padding: 8px 12px;
        font: 600 12px 'Instrument Sans', sans-serif;
        color: var(--ink);
        cursor: pointer;
        transition: .2s ease;
    }

    .seg button + button { border-left: 1px solid var(--line); }
    .seg button.active { background: var(--cold); color: white; }
    .seg button:disabled { opacity: .45; cursor: not-allowed; }

    .row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 15px 0;
        border-top: 1px solid #edf1f5;
    }

    .row.first {
        border-top: 0;
        padding-top: 0;
    }

    .row b {
        display: block;
        font-size: 14px;
        font-weight: 700;
    }

    .row .st {
        display: block;
        margin-top: 4px;
        font-size: 12px;
        line-height: 1.5;
        color: var(--mute);
    }

    .fault { color: var(--crit-d); font-weight: 600; }

    dl {
        margin: 0 0 18px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 8px 14px;
        font-size: 13px;
    }

    dt { color: var(--mute); }

    dd {
        margin: 0;
        font-weight: 600;
        text-align: right;
        word-break: break-word;
    }

    .transaction-status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 8px;
        background: #ddf4ea;
        color: #17745a;
        font-size: 12px;
        font-weight: 700;
    }

    .transaction-status.locked {
        background: #fbe3de;
        color: var(--crit-d);
    }

    .transaction-id {
        font-size: 12px;
        color: var(--mute);
        word-break: break-all;
    }

    .transaction-weight {
        font-size: 32px;
        font-weight: 800;
        font-family: 'Bricolage Grotesque', sans-serif;
        color: var(--cold);
        margin: 3px 0 12px;
    }

    .transaction-actions {
        display: flex;
        gap: 8px;
        margin: 14px 0 18px;
        flex-wrap: wrap;
    }

    .transaction-actions .btn { flex: 1; }

    .section-divider {
        border: 0;
        border-top: 1px solid var(--line);
        margin: 20px 0;
    }

    .ev {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: 260px;
        overflow: auto;
    }

    .ev li {
        display: flex;
        gap: 10px;
        padding: 10px 0;
        border-top: 1px solid var(--line);
        font-size: 13px;
    }

    .ev time {
        display: block;
        color: var(--mute);
        font-size: 11px;
        margin-bottom: 2px;
    }

    .ev .k {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-top: 7px;
        flex: none;
        background: var(--cold);
    }

    .ev .k.warning { background: var(--warn); }
    .ev .k.critical { background: var(--crit); }
    .ev .k.normal { background: var(--ok-d); }

    .empty {
        color: var(--mute);
        font-size: 13px;
        margin: 0;
    }

    .chartbox {
        position: relative;
        height: 310px;
        margin-top: 12px;
    }

    .btn {
        border: 1px solid var(--cold);
        background: var(--cold);
        color: white;
        padding: 10px 16px;
        border-radius: 10px;
        font: 700 13px 'Instrument Sans', sans-serif;
        cursor: pointer;
        transition: .2s ease;
    }

    .btn:not(:disabled):hover {
        transform: translateY(-1px);
        filter: brightness(1.06);
        box-shadow: 0 5px 12px rgba(38,133,181,.15);
    }

    .btn.ghost {
        background: white;
        color: var(--ink);
        border-color: var(--line);
    }

    .btn.ghost:hover { background: #f0f6fa; }

    .btn.danger {
        background: white;
        color: var(--crit-d);
        border-color: #e8a99e;
    }

    .btn:disabled { opacity: .5; cursor: not-allowed; }

    .in {
        width: 100%;
        min-width: 0;
        padding: 11px 13px;
        border: 1px solid var(--line);
        border-radius: 10px;
        font: inherit;
        background: white;
        color: var(--ink);
    }

    .in:focus {
        outline: 3px solid rgba(38,133,181,.12);
        border-color: var(--cold);
    }

    .field { margin-bottom: 14px; }

    .field label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 6px;
    }

    .field .in:disabled {
        background: #edf1f3;
        color: var(--mute);
    }

    .msg {
        color: var(--crit-d);
        font-size: 13px;
        margin: 8px 0 0;
        min-height: 1em;
    }

    .actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        flex-wrap: wrap;
        margin-top: 16px;
    }

    dialog {
        border: 1px solid var(--line);
        border-radius: 18px;
        padding: 25px;
        width: min(480px, calc(100% - 32px));
        max-height: 90vh;
        overflow: auto;
        background: var(--paper);
        color: var(--ink);
        box-shadow: 0 20px 70px rgba(15,35,50,.2);
    }

    dialog::backdrop {
        background: rgba(18,38,47,.55);
        backdrop-filter: blur(3px);
    }

    #reader {
        width: 100%;
        min-height: 220px;
        background: #0b171d;
        border-radius: 12px;
        overflow: hidden;
    }

    #reader video {
        width: 100%;
        object-fit: cover;
    }

    #reader__scan_region { min-height: 220px; }

    #reader__dashboard_section_csr button {
        padding: 7px 12px;
        margin: 5px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: white;
        cursor: pointer;
    }

    .toast {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--ink);
        color: white;
        padding: 12px 18px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 600;
        opacity: 0;
        pointer-events: none;
        transition: opacity .2s;
        z-index: 1000;
        max-width: calc(100% - 32px);
        text-align: center;
        box-shadow: 0 8px 25px rgba(0,0,0,.15);
    }

    .toast.show { opacity: 1; }
    .toast.err { background: var(--crit-d); }

    .sidebar-overlay {
        display: none;
    }

    /* =====================================
       RESPONSIVE
    ===================================== */

    @media (max-width: 1100px) {
        .landing-page { padding: 24px; }
        .landing-hero { min-height: 390px; }
        .wrap { padding: 24px 22px 40px; }

        .hero,
        .fans,
        .chartp,
        .side {
            grid-column: 1 / -1;
        }

        .hero { min-height: 260px; }
        .chartbox { height: 280px; }
    }

    @media (max-width: 700px) {
        :root { --sidebar-width: 260px; }

        .sidebar {
            width: var(--sidebar-width);
            transform: translateX(-100%);
            box-shadow: 12px 0 35px rgba(0,0,0,.15);
        }

        body.sidebar-mobile-open .sidebar {
            transform: translateX(0);
        }

        body.sidebar-collapsed .sidebar {
            width: var(--sidebar-width);
        }

        body.sidebar-collapsed .sidebar-brand-text,
        body.sidebar-collapsed .sidebar-label,
        body.sidebar-collapsed .side-text,
        body.sidebar-collapsed .sidebar-bottom {
            display: initial;
        }

        body.sidebar-collapsed .sidebar-top {
            flex-direction: row;
        }

        body.sidebar-collapsed .side-link {
            justify-content: flex-start;
            padding: 13px 14px;
        }

        .main-content,
        body.sidebar-collapsed .main-content {
            margin-left: 0;
        }

        .mobile-topbar {
            position: sticky;
            z-index: 80;
            top: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 62px;
            padding: 10px 16px;
            background: rgba(255,255,255,.94);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(12px);
        }

        .mobile-menu-button {
            width: 39px;
            height: 39px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: white;
            color: var(--ink);
            font-size: 20px;
            cursor: pointer;
        }

        .mobile-topbar strong {
            font: 700 16px 'Bricolage Grotesque', sans-serif;
        }

        .sidebar-overlay {
            position: fixed;
            z-index: 90;
            inset: 0;
            display: block;
            background: rgba(9,25,36,.48);
            opacity: 0;
            visibility: hidden;
            transition: .2s;
        }

        body.sidebar-mobile-open .sidebar-overlay {
            opacity: 1;
            visibility: visible;
        }

        .landing-page {
            padding: 19px 15px 30px;
        }

        .landing-top {
            margin-bottom: 16px;
        }

        .landing-top-label { font-size: 10px; }

        .landing-hero {
            min-height: 400px;
            padding: 25px 21px;
            border-radius: 18px;
        }

        .landing-hero h1 {
            font-size: clamp(34px, 9vw, 48px);
            letter-spacing: -1.5px;
        }

        .landing-hero p {
            font-size: 13px;
            line-height: 1.7;
        }

        .landing-info {
            grid-template-columns: 1fr;
            gap: 14px;
            margin-top: 15px;
        }

        .landing-description,
        .landing-quick-card {
            padding: 21px;
        }

        .landing-description h2 { font-size: 19px; }

        .wrap {
            padding: 18px 13px 32px;
        }

        .main-header {
            align-items: flex-start;
            gap: 10px;
        }

        .brand { gap: 9px; }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            font-size: 21px;
        }

        .brand h1 { font-size: 19px; }
        .brand .sub { font-size: 10px; }

        .main-header > .pill {
            font-size: 10px;
            padding: 7px 9px;
            gap: 5px;
            max-width: 145px;
            white-space: normal;
        }

        .grid { gap: 14px; }

        .hero {
            min-height: 235px;
            padding: 23px 19px;
            border-radius: 18px;
        }

        .hero::after {
            font-size: 160px;
            right: 5px;
            bottom: -40px;
        }

        .temp {
            font-size: 67px;
            letter-spacing: -3px;
        }

        .temp small { font-size: 25px; }

        .badge {
            font-size: 11px;
            padding: 7px 10px;
        }

        .hero .sub { font-size: 11px; }

        .panel {
            padding: 18px 15px;
            border-radius: 15px;
        }

        .panel h2 { font-size: 16px; }

        .row { gap: 8px; }

        .row b { font-size: 12px; }
        .row .st { font-size: 11px; }

        .seg button {
            padding: 8px 9px;
            font-size: 10px;
        }

        .chartp .row {
            align-items: flex-start;
            flex-direction: column;
        }

        .chartp .seg { width: 100%; }
        .chartp .seg button { flex: 1; }

        .chartbox { height: 250px; }

        .side dl {
            gap: 8px;
            font-size: 12px;
        }

        .transaction-weight { font-size: 27px; }

        .transaction-actions { flex-direction: column; }

        .actions { flex-direction: column-reverse; }
        .actions .btn { width: 100%; }

        #stepScan > div[style*="display:flex"] {
            flex-wrap: wrap;
        }

        #stepScan > div[style*="display:flex"] .in {
            flex: 1 1 100%;
        }

        #stepScan > div[style*="display:flex"] .btn {
            width: 100%;
        }

        dialog { padding: 19px; }

        .toast {
            width: calc(100% - 26px);
            bottom: 12px;
        }
    }

    @media (max-width: 380px) {
        .main-header { flex-wrap: wrap; }

        .main-header > .pill {
            margin-left: 49px;
        }

        .row { align-items: flex-start; }

        .row .seg { max-width: 58%; }

        .seg button {
            padding: 7px 6px;
            font-size: 10px;
        }

        .landing-hero { min-height: 360px; }
    }

    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            transition: none !important;
            animation: none !important;
        }

        html { scroll-behavior: auto; }
    }


      /* ==========================================
        RIWAYAT TRANSAKSI PENGIRIMAN
      ========================================== */

      .history-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 20px;
          margin-bottom: 24px;
      }

      .history-eyebrow {
          display: inline-block;
          color: var(--cold);
          font-size: 11px;
          font-weight: 700;
          letter-spacing: 1.5px;
          margin-bottom: 8px;
      }

      .history-header h1 {
          color: var(--ink);
          font-size: clamp(24px, 3vw, 32px);
          font-weight: 800;
          margin: 0 0 8px;
      }

      .history-header .sub {
          margin: 0;
      }

      .history-panel {
          padding: 22px;
          min-width: 0;
      }

      .history-toolbar {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 18px;
      }

      .history-toolbar h2 {
          margin: 0 0 5px;
          color: var(--ink);
          font-size: 18px;
      }

      .history-toolbar .sub {
          margin: 0;
      }

      .history-table-wrap {
          width: 100%;
          overflow-x: auto;
      }

      .history-table {
          width: 100%;
          border-collapse: collapse;
          min-width: 1050px;
          font-size: 13px;
      }

      .history-table th {
          padding: 14px 12px;
          text-align: left;
          color: var(--mute);
          background: var(--frost);
          font-weight: 700;
          white-space: nowrap;
          border-bottom: 1px solid var(--line);
      }

      .history-table td {
          padding: 14px 12px;
          color: var(--ink);
          border-bottom: 1px solid var(--line);
          vertical-align: middle;
      }

      .history-table tbody tr:hover {
          background: var(--frost);
      }

      .history-status {
          display: inline-block;
          padding: 5px 10px;
          border-radius: 20px;
          font-size: 11px;
          font-weight: 700;
          white-space: nowrap;
      }

      .history-status.new {
          color: #167447;
          background: #e7f7ee;
      }

      .history-status.edited {
          color: #946200;
          background: #fff4d6;
      }

      .history-empty {
          text-align: center;
          padding: 35px !important;
          color: var(--mute) !important;
      }

      @media (max-width: 700px) {
          .history-header {
              align-items: flex-start;
              flex-direction: column;
          }

          .history-panel {
              padding: 14px;
          }
      }

    </style>
</head>

<body>



<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-brand">
            <div class="sidebar-logo">❄</div>
            <div class="sidebar-brand-text">
                <strong>Reefer Monitor</strong>
                <small>SMART COLD CHAIN</small>
            </div>
        </div>

        <button class="sidebar-toggle" id="sidebarToggle" type="button"
                aria-label="Buka atau tutup sidebar" title="Buka/tutup sidebar">
            «
        </button>
    </div>

    <div class="sidebar-label">MENU UTAMA</div>

    <nav class="sidebar-menu" aria-label="Menu utama">
        <button type="button" class="side-link active" data-page="landing">
            <span class="side-icon">⌂</span>
            <span class="side-text">Dashboard Pembuka</span>
        </button>

        <button type="button" class="side-link" data-page="monitoring">
            <span class="side-icon">▦</span>
            <span class="side-text">Monitoring & Kontrol</span>
        </button>

        <button type="button" class="side-link" data-page="shipments">
            <span class="side-icon">▤</span>
            <span class="side-text">Riwayat Transaksi Pengiriman</span>
        </button>
    </nav>

    <div class="sidebar-bottom">
        <strong>REEFER MONITOR</strong>
        Sistem Monitoring Suhu Truk Reefer
        <br>Smart Cold Chain System
    </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- MOBILE TOPBAR -->
<div class="main-content">

    <div class="mobile-topbar">
        <button class="mobile-menu-button" id="mobileMenuButton"
                type="button" aria-label="Buka menu">
            ☰
        </button>
        <strong>Reefer Monitor</strong>
    </div>

    <!-- =================================
         HALAMAN PEMBUKA / LANDING PAGE
    ================================== -->
    <section class="page landing-page" id="landingPage">

        <div class="landing-top">
            <div class="landing-top-label">SMART COLD CHAIN SYSTEM</div>

            <div class="landing-status">
                <span></span>
                Temperature Monitoring System
            </div>
        </div>

        <div class="landing-hero">
            <div class="landing-hero-content">
                <div class="landing-eyebrow">
                    ❄ SMART REEFER MONITORING
                </div>

                <h1>
                    Keep It Cool.<br>
                    <span>Keep It Safe.</span>
                </h1>

                <p>
                    Pantau dan kendalikan suhu penyimpanan selama proses
                    pengiriman untuk membantu menjaga kualitas barang tetap
                    optimal.
                </p>
            </div>
        </div>

        <div class="landing-info">

            <div class="landing-description">
                <h2>Tentang Reefer Monitor</h2>

                <p>
                    Reefer Monitor merupakan dashboard sistem monitoring
                    suhu pada truk reefer yang dirancang untuk memantau
                    kondisi suhu secara real-time selama proses pengiriman.
                    Sistem ini membantu pengguna mengetahui kondisi
                    perangkat, memantau perubahan suhu, serta mengatur
                    kontrol kipas melalui satu dashboard.
                    <br><br>
                    Dengan adanya pemantauan ini, kondisi penyimpanan barang
                    dapat diawasi dengan lebih mudah sehingga kualitas barang
                    selama perjalanan dapat tetap terjaga.
                </p>
            </div>

            <div class="landing-quick-card">
                <div class="quick-icon">▦</div>

                <strong>Monitoring dalam Satu Dashboard</strong>

                <p>
                    Akses data suhu, status perangkat, kontrol kipas,
                    grafik riwayat, dan informasi pengiriman.
                </p>

                <button class="landing-cta" type="button" data-page="monitoring">
                    Buka Dashboard Monitoring
                    <span>→</span>
                </button>
            </div>

        </div>

        <div class="landing-footer">
            © 2026 Reefer Monitor · Smart Cold Chain Monitoring System
        </div>

    </section>

    <!-- =================================
         DASHBOARD MONITORING LAMA
         ISI DASHBOARD DIPERTAHANKAN
    ================================== -->
    <section class="page dashboard-page" id="dashboardPage" hidden>

        <div class="wrap" id="app" data-status="">

            <!-- HEADER -->
            <header class="main-header">
                <div class="brand">
                    <div class="brand-icon">❄</div>
                    <div>
                        <h1>Reefer<span>Monitor</span></h1>
                        <div class="sub">Smart Cold Chain Monitoring</div>
                        <div class="sub">Perangkat {{ $deviceId }}</div>
                    </div>
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

                <!-- MONITORING SUHU -->
                <section class="hero" id="overview" aria-live="polite">
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

                <!-- KONTROL KIPAS -->
                <section class="panel fans" id="controls">
                    <h2>Kipas dan kontrol</h2>

                    <div class="row first">
                        <span class="sub">Mode operasi</span>
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

                <!-- GRAFIK SUHU -->
                <section class="panel chartp" id="history">
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

                <!-- TRANSAKSI DAN EVENT -->
                <section class="panel side" id="shipment">
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

                    <h2 id="events">Riwayat event</h2>
                    <ul class="ev" id="eventsList"></ul>
                </section>

            </div>
        </div>
    </section>

    <!-- =================================
         HALAMAN RIWAYAT TRANSAKSI
    ================================== -->
    <section class="page dashboard-page" id="shipmentHistoryPage" hidden>

        <div class="wrap">

            <div class="history-header">
                <div>
                    <span class="history-eyebrow">DATA PENGIRIMAN</span>
                    <h1>Riwayat Transaksi Pengiriman</h1>
                    <p class="sub">
                        Daftar seluruh transaksi pengiriman yang tersimpan di sistem.
                    </p>
                </div>

                <button type="button" class="btn" id="refreshShipmentHistory">
                    ↻ Muat Ulang
                </button>
            </div>

            <div class="panel history-panel">
                <div class="history-toolbar">
                    <div>
                        <h2>Seluruh Transaksi</h2>
                        <p class="sub" id="shipmentHistoryCount">
                            Memuat data transaksi...
                        </p>
                    </div>
                </div>

                <div class="history-table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>ID Transaksi</th>
                                <th>UID Box</th>
                                <th>Nama Barang</th>
                                <th>Berat (Kg)</th>
                                <th>Driver / Distributor</th>
                                <th>Tanggal Transaksi</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="shipmentHistoryBody">
                            <tr>
                                <td colspan="8" class="history-empty">
                                    Memuat data transaksi...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- DIALOG SCAN DAN FORM -->
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

    // ==========================================
    // NAVIGASI SIDEBAR DAN HALAMAN
    // ==========================================

    const pageButtons = document.querySelectorAll('[data-page]');
    const landingPage = $('#landingPage');
    const dashboardPage = $('#dashboardPage');
    const shipmentHistoryPage = $('#shipmentHistoryPage');

    function showPage(page) {
        const isLanding = page === 'landing';
        const isMonitoring = page === 'monitoring';
        const isShipments = page === 'shipments';

        landingPage.hidden = !isLanding;
        dashboardPage.hidden = !isMonitoring;
        shipmentHistoryPage.hidden = !isShipments;

        pageButtons.forEach(button => {
            button.classList.toggle(
                'active',
                button.dataset.page === page
            );
        });

        document.body.classList.remove('sidebar-mobile-open');

        if (isMonitoring) {
            // Chart perlu menghitung ulang ukuran setelah halaman ditampilkan.
            requestAnimationFrame(() => {
                if (chart) {
                    chart.resize();
                }
            });

            loadLatest();
            loadHistory();
            loadEvents();
            loadLatestShipment();
        }

        if (isShipments) {
            loadShipmentHistory();
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    pageButtons.forEach(button => {
        button.addEventListener('click', () => {
            showPage(button.dataset.page);
        });
    });

    $('#sidebarToggle').addEventListener('click', () => {
        if (window.innerWidth <= 700) {
            document.body.classList.remove('sidebar-mobile-open');
        } else {
            document.body.classList.toggle('sidebar-collapsed');

            $('#sidebarToggle').textContent =
                document.body.classList.contains('sidebar-collapsed')
                    ? '»'
                    : '«';
        }
    });

    $('#mobileMenuButton').addEventListener('click', () => {
        document.body.classList.toggle('sidebar-mobile-open');
    });

    $('#sidebarOverlay').addEventListener('click', () => {
        document.body.classList.remove('sidebar-mobile-open');
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 700) {
            document.body.classList.remove('sidebar-mobile-open');
        }
    });

    // ==========================================
    // URL API
    // ==========================================

    const U = {
        latest: @json(route('dashboard.latest')),
        history: @json(route('dashboard.history')),
        events: @json(route('dashboard.events')),
        control: @json(route('dashboard.control')),

        validateBox: @json(route('dashboard.boxes.validate', ['uid' => '__UID__'])),
        storeShipment: @json(route('dashboard.shipments.store')),
        latestShipment: @json(route('dashboard.shipments.latest')),
        shipmentHistory: @json(route('dashboard.shipments.index')),
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
    let boxAlreadyUsed = false;

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
        const listElement = $('#eventsList');

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
    // RIWAYAT TRANSAKSI PENGIRIMAN
    // ==========================================

    function escapeHistoryHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[character]);
    }

    async function loadShipmentHistory() {
        const body = $('#shipmentHistoryBody');
        const count = $('#shipmentHistoryCount');

        if (!body || !count) return;

        body.innerHTML = `
            <tr>
                <td colspan="8" class="history-empty">
                    Memuat data transaksi...
                </td>
            </tr>
        `;

        count.textContent = 'Sedang mengambil data dari database...';

        try {
            const response = await getJson(U.shipmentHistory);
            const shipments = Array.isArray(response.data)
                ? response.data
                : [];

            count.textContent =
                `${Number(response.total ?? shipments.length).toLocaleString('id-ID')} transaksi tersimpan`;

            if (!shipments.length) {
                body.innerHTML = `
                    <tr>
                        <td colspan="8" class="history-empty">
                            Belum ada transaksi pengiriman yang tersimpan.
                        </td>
                    </tr>
                `;
                return;
            }

            shipments.sort((a, b) =>
                new Date(b.created_at || 0) - new Date(a.created_at || 0)
            );

            body.innerHTML = shipments.map((item, index) => {
                const edited = Number(item.edit_count || 0) > 0;
                const weight = Number(item.weight_kg);

                return `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${escapeHistoryHtml(item.transaction_id || '-')}</td>
                        <td>${escapeHistoryHtml(item.box_uid || '-')}</td>
                        <td>${escapeHistoryHtml(item.item_name || '-')}</td>
                        <td>${Number.isFinite(weight) ? weight.toLocaleString('id-ID') : '-'}</td>
                        <td>${escapeHistoryHtml(item.driver_distributor_name || '-')}</td>
                        <td>${escapeHistoryHtml(dateTime(item.created_at))}</td>
                        <td>
                            <span class="history-status ${edited ? 'edited' : 'new'}">
                                ${edited ? 'Sudah Diedit' : 'Baru'}
                            </span>
                        </td>
                    </tr>
                `;
            }).join('');

        } catch (error) {
            console.error('Gagal memuat riwayat transaksi:', error);
            count.textContent = 'Riwayat transaksi gagal dimuat.';

            body.innerHTML = `
                <tr>
                    <td colspan="8" class="history-empty">
                        ${escapeHistoryHtml(error.message || 'Terjadi kesalahan saat mengambil data.')}
                    </td>
                </tr>
            `;
        }
    }

    $('#refreshShipmentHistory').addEventListener(
        'click',
        loadShipmentHistory
    );

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

            const detail = error && error.message
                ? ' (' + error.message + ')'
                : '';

            $('#camMsg').textContent =
                'Scanner gagal dimulai' + detail + '. Periksa izin kamera atau gunakan input UID manual.';
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
        boxAlreadyUsed = false;
        $('#saveShipment').disabled = false;
        $('#saveShipment').textContent = 'Simpan transaksi';

        $('#formTitle').textContent = 'Data pengiriman';
        $('#formDescription').textContent =
            'Nama barang dan berat otomatis diambil dari data registrasi box.';

        $('#formBox').value = '';
        $('#formDriver').value = '';
        $('#formItem').value = '';
        $('#formWeight').value = '';

        // Saat membuat transaksi, barang dan berat hanya dibaca dari database.
        $('#formItem').readOnly = true;
        $('#formWeight').readOnly = true;

        $('#saveShipment').textContent = 'Simpan transaksi';

        showScanStep();

        if (!dialog.open) {
            dialog.showModal();
        }

        // Tunggu dialog tampil sebelum library memasang elemen video.
        requestAnimationFrame(() => {
            startScanner();
        });
    }

    async function closeDialog() {
        scanning = false;
        await stopScanner();

        if (dialog.open) {
            dialog.close();
        }
    }

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

        // Reset hasil pemeriksaan sebelumnya.
        validatedBoxUid = '';
        boxAlreadyUsed = false;

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

            const itemName = String(result.item_name || '').trim();
            const weightKg = Number(result.weight_kg);

            if (!itemName) {
                throw new Error('Nama barang belum terdaftar pada box.');
            }

            if (!Number.isFinite(weightKg) || weightKg <= 0) {
                throw new Error('Berat barang belum terdaftar dengan benar.');
            }

            // Box used tetap menampilkan detail, tetapi tidak bisa dipakai
            // untuk membuat transaksi baru.
            boxAlreadyUsed = result.status !== 'available';
            validatedBoxUid = result.uid || uid;

            await stopScanner();

            $('#formBox').value = validatedBoxUid;
            $('#formItem').value = itemName;
            $('#formWeight').value = weightKg;

            // Data barang berasal dari database.
            $('#formItem').readOnly = true;
            $('#formWeight').readOnly = true;

            $('#camMsg').textContent = '';

            if (boxAlreadyUsed) {
                $('#formTitle').textContent = 'Detail Box';
                $('#formDescription').textContent =
                    'Box ini sudah pernah digunakan. Detail barang tetap dapat dilihat.';

                $('#formMsg').textContent =
                    'Barang ini sudah di-scan. Transaksi baru tidak dapat dibuat menggunakan box ini.';

                $('#saveShipment').disabled = true;
                $('#saveShipment').textContent = 'Box sudah digunakan';

                showFormStep();
                toast('Barang ini sudah di-scan', true);
            } else {
                $('#formTitle').textContent = 'Data pengiriman';
                $('#formDescription').textContent =
                    'Nama barang dan berat otomatis diambil dari data registrasi box.';

                $('#formMsg').textContent = '';
                $('#saveShipment').disabled = false;
                $('#saveShipment').textContent = 'Simpan transaksi';

                showFormStep();
                toast('UID Box valid dan data barang berhasil dimuat');
                $('#formDriver').focus();
            }

            return true;

        } catch (error) {
            validatedBoxUid = '';
            boxAlreadyUsed = false;

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

        // Pengaman frontend; backend tetap wajib memeriksa status box.
        if (shipmentMode === 'create' && boxAlreadyUsed) {
            $('#formMsg').textContent =
                'Barang ini sudah di-scan. Transaksi baru tidak dapat dibuat.';
            return;
        }

        if (shipmentMode === 'create' && !validatedBoxUid) {
            $('#formMsg').textContent = 'UID Box belum divalidasi.';
            return;
        }

        if (!driver) {
            $('#formMsg').textContent =
                'Nama pengemudi atau distributor wajib diisi.';
            $('#formDriver').focus();
            return;
        }

        // Data barang hanya perlu divalidasi saat mengedit transaksi lama.
        let payload = {
            driver_distributor_name: driver
        };

        if (shipmentMode === 'create') {
            // Backend mengambil nama barang dan berat dari tabel boxes.
            payload.box_uid = validatedBoxUid;
        } else {
            const item = $('#formItem').value.trim();
            const weight = Number($('#formWeight').value);

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

            payload.item_name = item;
            payload.weight_kg = weight;
        }

        const button = $('#saveShipment');
        const modeSaatSimpan = shipmentMode;

        button.disabled = true;
        button.textContent = 'Menyimpan...';
        $('#formMsg').textContent = '';

        try {
            let response;

            if (modeSaatSimpan === 'create') {
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
                modeSaatSimpan === 'create'
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
            button.textContent = modeSaatSimpan === 'create'
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

        boxAlreadyUsed = false;
        $('#saveShipment').disabled = false;

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

        // Mode edit mengizinkan perubahan snapshot barang sesuai aturan edit satu kali.
        $('#formItem').readOnly = false;
        $('#formWeight').readOnly = false;

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
            requestAnimationFrame(() => startScanner());
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
        $('#formBox').value = '';
        $('#formDriver').value = '';
        $('#formItem').value = '';
        $('#formWeight').value = '';

        showScanStep();
        requestAnimationFrame(() => startScanner());
    });

    $('#saveShipment').addEventListener('click', saveShipment);

    $('#editShipment').addEventListener('click', openEditShipment);

    $('#formDriver').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();

            if (shipmentMode === 'create') {
                saveShipment();
            } else {
                $('#formItem').focus();
            }
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