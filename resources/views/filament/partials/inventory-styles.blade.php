@once
    <style>
        .he-inv { font-size: .85rem; }
        .he-inv-bar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: .75rem; }
        .he-inv-bar select, .he-inv-bar input[type=search] { padding: .35rem .6rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: transparent; font-size: .85rem; }
        .he-inv-tabs { display: inline-flex; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; overflow: hidden; }
        .he-inv-tabs button { padding: .35rem .8rem; font-size: .85rem; }
        .he-inv-tabs button.on { background: rgba(245,158,11,.18); font-weight: 600; }
        .he-inv-wrap { overflow: auto; max-height: 70vh; border: 1px solid rgba(128,128,128,.25); border-radius: .5rem; }
        .he-inv table { border-collapse: separate; border-spacing: 0; width: max-content; min-width: 100%; }
        .he-inv th, .he-inv td { padding: .4rem .6rem; text-align: left; vertical-align: top; border-bottom: 1px solid rgba(128,128,128,.15); white-space: nowrap; }
        .he-inv thead th { position: sticky; top: 0; z-index: 2; background: var(--gray-50, #f9fafb); font-weight: 600; }
        .he-inv .fix { position: sticky; left: 0; z-index: 1; background: var(--color-white, #fff); border-right: 1px solid rgba(128,128,128,.2); }
        .he-inv thead .fix { z-index: 3; background: var(--gray-50, #f9fafb); }
        .dark .he-inv thead th, .dark .he-inv thead .fix { background: var(--gray-800, #1f2937); }
        .dark .he-inv .fix { background: var(--gray-900, #111827); }
        .he-inv tbody tr { cursor: pointer; }
        .he-inv tbody tr:hover td { background-color: rgba(128,128,128,.07); }
        .he-inv .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; }
        .he-inv .sub { opacity: .65; font-size: .75rem; }
        .he-inv .bad { background: rgba(239,68,68,.12); color: rgb(220,38,38); }
        .he-inv .o-global { color: rgb(107,114,128); }
        .he-inv .o-modul { color: rgb(37,99,235); }
        .dark .he-inv .o-modul { color: rgb(96,165,250); }
        .he-inv .o-curs { color: rgb(124,58,237); }
        .dark .he-inv .o-curs { color: rgb(167,139,250); }
        .he-inv .grp { border-left: 2px solid rgba(128,128,128,.3); }
        .he-inv .grp-row td { background: rgba(128,128,128,.08); font-weight: 600; font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; }
        .he-inv .o-propia { color: rgb(217,119,6); font-weight: 600; }
        .he-inv .legend { display: flex; gap: 1rem; font-size: .8rem; margin-top: .5rem; flex-wrap: wrap; }
        .he-inv .chip { display: inline-block; padding: .1rem .5rem; border-radius: 999px; border: 1px solid rgba(128,128,128,.3); margin-right: .5rem; }
        .he-inv .he-conn { display: inline-flex; align-items: center; gap: .3rem; margin-left: .35rem; vertical-align: middle; }
        .he-inv .he-dot { width: .6rem; height: .6rem; border-radius: 999px; display: inline-block; }
        .he-inv .he-pending { animation: he-pulse 1s ease-in-out infinite; }
        @keyframes he-pulse { 50% { opacity: .3; } }
        .he-inv .he-try { font-size: .6rem; line-height: 1; padding: .2rem .3rem; border-radius: .3rem; border: 1px solid rgba(128,128,128,.3); opacity: .55; cursor: pointer; }
        .he-inv .he-try:hover { opacity: 1; background: rgba(245,158,11,.18); }
        .he-inv .he-msg { font-size: .7rem; max-width: 16rem; white-space: normal; }
    </style>
@endonce
