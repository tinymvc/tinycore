<style>
    :root {
        --bg: #0a0a0a;
        --surface: #101010;
        --surface-2: #171717;
        --code-bg: #0c0c0c;
        --border: #222222;
        --border-strong: #333333;
        --text: #ededed;
        --text-2: #a1a1a1;
        --text-3: #6b6b6b;
        --danger: #ff5c60;
        --danger-bg: rgba(255, 92, 96, 0.10);
        --danger-line: rgba(255, 92, 96, 0.18);
        --accent: #52a8ff;
        --accent-bg: rgba(82, 168, 255, 0.12);
        --hover: rgba(255, 255, 255, 0.035);
        --selected: rgba(255, 255, 255, 0.06);
        --shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
        color-scheme: dark;
    }

    :root {
        --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
        --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    body {
        background: var(--bg) !important;
        color: var(--text) !important;
        font-family: var(--sans);
        font-size: 13px;
        line-height: 1.5;
        margin: 0;
        padding: 0;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    button {
        font-family: inherit;
    }

    .tr {
        max-width: 1040px;
        margin: 0 auto;
        padding: 0 20px 40px;
    }

    .stack {
        display: grid;
        gap: 12px;
    }

    /* ---------- Top bar ---------- */
    .tr-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        height: 44px;
    }

    .tr-bar-l,
    .tr-bar-r {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .tr-brand {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-weight: 600;
        font-size: 13px;
        letter-spacing: -0.01em;
    }

    .tr-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--danger);
    }

    .tr-meta {
        color: var(--text-3);
        font-family: var(--mono);
        font-size: 11.5px;
        white-space: nowrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 26px;
        padding: 0 9px;
        background: transparent;
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text-2);
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        transition: background 0.12s, color 0.12s, border-color 0.12s;
    }

    .btn:hover {
        background: var(--hover);
        color: var(--text);
        border-color: var(--border-strong);
    }

    .btn:focus-visible,
    .tab:focus-visible,
    .frame:focus-visible,
    .icon-btn:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 1px;
    }

    .btn-icon {
        width: 26px;
        padding: 0;
        justify-content: center;
    }

    .icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        padding: 0;
        background: none;
        border: none;
        border-radius: 5px;
        color: var(--text-3);
        cursor: pointer;
        text-decoration: none;
        transition: color 0.12s, background 0.12s;
        flex-shrink: 0;
    }

    .icon-btn:hover {
        color: var(--text);
        background: var(--hover);
    }

    /* ---------- Error header ---------- */
    .tr-err {
        padding: 20px 0 18px;
    }

    .tr-err-head {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 8px;
    }

    .tag {
        display: inline-flex;
        align-items: center;
        height: 20px;
        padding: 0 7px;
        border-radius: 5px;
        background: var(--danger-bg);
        color: var(--danger);
        font-family: var(--mono);
        font-size: 11.5px;
        font-weight: 600;
        border: 1px solid var(--danger-line);
    }

    .tr-req {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
        color: var(--text-3);
        font-family: var(--mono);
        font-size: 12px;
    }

    .tr-req b {
        color: var(--text-2);
        font-weight: 600;
    }

    .tr-req span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 560px;
    }

    .tr-msg {
        margin: 0 0 10px;
        font-size: 18px;
        font-weight: 600;
        line-height: 1.4;
        letter-spacing: -0.012em;
        color: var(--text);
        word-break: break-word;
    }

    .tr-loc {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        max-width: 100%;
        font-family: var(--mono);
        font-size: 12px;
        color: var(--text-2);
    }

    .tr-loc .path {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .tr-loc .ln {
        color: var(--danger);
        font-weight: 600;
    }

    /* ---------- Cards ---------- */
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        overflow: hidden;
    }

    .card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        min-height: 36px;
        padding: 0 8px 0 14px;
        border-bottom: 1px solid var(--border);
    }

    .card-title {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        font-weight: 600;
        color: var(--text);
    }

    .count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 18px;
        height: 17px;
        padding: 0 5px;
        border-radius: 9px;
        background: var(--surface-2);
        border: 1px solid var(--border);
        color: var(--text-2);
        font-family: var(--mono);
        font-size: 10.5px;
        font-weight: 500;
    }

    .head-actions {
        display: inline-flex;
        align-items: center;
        gap: 2px;
    }

    /* ---------- Source viewer ---------- */
    .viewer {
        background: var(--code-bg);
    }

    .viewer .card-head {
        background: var(--surface);
    }

    .viewer-loc {
        display: flex;
        align-items: center;
        min-width: 0;
        font-family: var(--mono);
        font-size: 12px;
    }

    .viewer-file {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--text);
        font-weight: 500;
    }

    .viewer-line {
        flex-shrink: 0;
        color: var(--danger);
        font-weight: 600;
    }

    .code {
        position: relative;
        max-height: 440px;
        overflow: auto;
        padding: 8px 0;
        font-family: var(--mono);
        font-size: 12.5px;
        line-height: 1.6;
        scrollbar-width: thin;
        scrollbar-color: var(--border-strong) transparent;
    }

    .code::-webkit-scrollbar,
    .frames::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .code::-webkit-scrollbar-thumb,
    .frames::-webkit-scrollbar-thumb {
        background: var(--border-strong);
        border-radius: 4px;
    }

    .syntax-highlight-line {
        display: flex;
        align-items: stretch;
        min-width: max-content;
        min-height: 20px;
        padding: 0 16px 0 0;
        border-left: 2px solid transparent;
    }

    .syntax-highlight-line.active {
        background: var(--danger-bg) !important;
        border-left-color: var(--danger);
    }

    .syntax-highlight-line code {
        display: flex;
        width: 100%;
        font-family: inherit;
        background: none;
        padding: 0;
    }

    .syntax-highlight-no {
        position: sticky;
        left: 0;
        width: 50px;
        flex-shrink: 0;
        padding-right: 14px;
        background: var(--code-bg);
        text-align: right;
        color: var(--text-3);
        user-select: none;
        opacity: 0.85;
    }

    .syntax-highlight-line.active .syntax-highlight-no {
        background: linear-gradient(var(--danger-bg), var(--danger-bg)), var(--code-bg);
        color: var(--danger);
        font-weight: 600;
        opacity: 1;
    }

    .syntax-highlight-code {
        flex: 1;
        white-space: pre;
        color: var(--text);
    }

    .syntax-highlight-empty {
        padding: 40px 24px;
        text-align: center;
        color: var(--text-3);
        font-family: var(--sans);
        font-size: 12px;
    }

    .syntax-highlight-empty .t {
        margin: 0 0 4px;
        color: var(--text);
        font-size: 13px;
        font-weight: 600;
    }

    .syntax-highlight-empty .s {
        margin: 0;
        font-family: var(--mono);
        word-break: break-all;
    }

    /* ---------- Call stack ---------- */
    .frames {
        position: relative;
        max-height: 300px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--border-strong) transparent;
    }

    .frame {
        display: grid;
        grid-template-columns: 22px minmax(0, max-content) minmax(0, 1fr);
        align-items: center;
        column-gap: 10px;
        width: 100%;
        min-height: 30px;
        padding: 0 14px 0 12px;
        text-align: left;
        background: transparent;
        border: none;
        border-bottom: 1px solid var(--border);
        border-left: 2px solid transparent;
        color: inherit;
        font-family: var(--mono);
        font-size: 12px;
        cursor: pointer;
        transition: background 0.1s;
    }

    .frame:last-child {
        border-bottom: none;
    }

    .frame:hover {
        background: var(--hover);
    }

    .frame.active {
        background: var(--selected);
        border-left-color: var(--text);
    }

    .frame.hidden {
        display: none !important;
    }

    .frame-idx {
        color: var(--text-3);
        font-size: 11px;
    }

    .frame-func {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100%;
        color: var(--text);
        font-weight: 600;
    }

    .frame.vendor .frame-func {
        color: var(--text-2);
        font-weight: 500;
    }

    .frame-path {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        direction: rtl;
        text-align: right;
        color: var(--text-3);
        font-size: 11.5px;
    }

    .frame-path bdi {
        direction: ltr;
        unicode-bidi: isolate;
    }

    .frame.active .frame-path {
        color: var(--text-2);
    }

    /* ---------- Diagnostics ---------- */
    .tabs {
        display: flex;
        gap: 2px;
        padding: 0 8px;
        border-bottom: 1px solid var(--border);
    }

    .tab {
        position: relative;
        height: 36px;
        padding: 0 10px;
        background: transparent;
        border: none;
        color: var(--text-3);
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: color 0.12s;
    }

    .tab:hover,
    .tab.active {
        color: var(--text);
    }

    .tab.active::after {
        content: "";
        position: absolute;
        left: 8px;
        right: 8px;
        bottom: -1px;
        height: 2px;
        background: var(--text);
        border-radius: 2px 2px 0 0;
    }

    .pane {
        display: none;
    }

    .pane.active {
        display: block;
    }

    .kv {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    .kv tr {
        border-bottom: 1px solid var(--border);
    }

    .kv tr:last-child {
        border-bottom: none;
    }

    .kv td {
        padding: 6px 14px;
        vertical-align: top;
    }

    .kv .k {
        width: 160px;
        color: var(--text-3);
        font-family: var(--mono);
        white-space: nowrap;
    }

    .kv .v {
        color: var(--text);
        font-family: var(--mono);
        word-break: break-all;
    }

    .kv pre {
        margin: 0;
        font-family: inherit;
        font-size: 12px;
        white-space: pre-wrap;
        color: var(--text-2);
    }

    .method {
        display: inline-block;
        margin-right: 8px;
        padding: 0 6px;
        border-radius: 4px;
        background: var(--accent-bg);
        color: var(--accent);
        font-size: 11px;
        font-weight: 600;
        line-height: 18px;
    }

    .muted {
        color: var(--text-3) !important;
    }

    /* ---------- Toast ---------- */
    .toast {
        position: fixed;
        right: 20px;
        bottom: 20px;
        z-index: 9999;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        background: var(--surface);
        border: 1px solid var(--border-strong);
        border-radius: 8px;
        box-shadow: var(--shadow);
        color: var(--text);
        font-size: 12px;
        font-weight: 500;
        opacity: 0;
        transform: translateY(8px);
        pointer-events: none;
        transition: opacity 0.18s ease, transform 0.18s ease;
    }

    .toast svg {
        color: #3fb950;
    }

    .toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    @media (prefers-reduced-motion: reduce) {
        * {
            transition: none !important;
        }
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 640px) {
        .tr {
            padding: 0 12px 32px;
        }

        .tr-meta,
        .btn .lbl {
            display: none;
        }

        .tr-msg {
            font-size: 16px;
        }

        .frame {
            grid-template-columns: 20px minmax(0, 1fr);
        }

        .frame-path {
            grid-column: 2;
            text-align: left;
            padding-bottom: 6px;
        }

        .kv .k {
            width: 100px;
        }
    }
</style>
