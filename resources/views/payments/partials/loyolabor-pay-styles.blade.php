<style>
    :root {
        --ll-navy: #071A3D;
        --ll-royal: #1345A3;
        --ll-secondary: #1F4E6D;
        --ll-white: #FFFFFF;
        --ll-bg: #F5F8FC;
        --ll-border: #E2E8F0;
        --ll-success: #16A34A;
        --ll-error: #DC2626;
        --ll-warning: #D97706;
        --ll-muted: #64748B;
        --ll-text: #0F172A;
        --ll-radius: 16px;
        --ll-shadow: 0 12px 40px rgba(7, 26, 61, 0.10);
    }
    * { box-sizing: border-box; }
    html, body {
        margin: 0;
        padding: 0;
        min-height: 100%;
        background:
            radial-gradient(1200px 500px at 10% -10%, rgba(19, 69, 163, 0.18), transparent 55%),
            radial-gradient(900px 420px at 100% 0%, rgba(31, 78, 109, 0.14), transparent 50%),
            var(--ll-bg);
        color: var(--ll-text);
        font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
    }
    .ll-shell {
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        padding: 16px 16px calc(24px + env(safe-area-inset-bottom, 0px));
        max-width: 460px;
        margin: 0 auto;
    }
    .ll-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;
        padding-top: env(safe-area-inset-top, 0px);
    }
    .ll-back {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        border: 1px solid var(--ll-border);
        background: var(--ll-white);
        color: var(--ll-navy);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 20px;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(7, 26, 61, 0.05);
    }
    .ll-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .ll-logo {
        width: 40px;
        height: 40px;
        object-fit: contain;
        border-radius: 10px;
        background: var(--ll-white);
        border: 1px solid var(--ll-border);
        padding: 4px;
    }
    .ll-brand-text strong {
        display: block;
        font-size: 15px;
        color: var(--ll-navy);
        letter-spacing: 0.01em;
    }
    .ll-brand-text span {
        display: block;
        font-size: 12px;
        color: var(--ll-muted);
        margin-top: 2px;
    }
    .ll-hero {
        background: linear-gradient(145deg, var(--ll-navy) 0%, var(--ll-royal) 62%, var(--ll-secondary) 100%);
        color: var(--ll-white);
        border-radius: 20px;
        padding: 22px 20px;
        box-shadow: var(--ll-shadow);
        margin-bottom: 16px;
    }
    .ll-hero h1 {
        margin: 0 0 8px;
        font-size: 22px;
        line-height: 1.25;
        font-weight: 700;
    }
    .ll-hero p {
        margin: 0;
        font-size: 13px;
        line-height: 1.5;
        opacity: 0.9;
    }
    .ll-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.22);
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        margin-bottom: 12px;
    }
    .ll-card {
        background: var(--ll-white);
        border: 1px solid var(--ll-border);
        border-radius: var(--ll-radius);
        box-shadow: var(--ll-shadow);
        padding: 18px;
        margin-bottom: 14px;
    }
    .ll-card h2 {
        margin: 0 0 14px;
        font-size: 15px;
        color: var(--ll-navy);
        font-weight: 700;
    }
    .ll-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: baseline;
        padding: 8px 0;
        font-size: 14px;
    }
    .ll-row + .ll-row { border-top: 1px solid #F1F5F9; }
    .ll-row .label { color: var(--ll-muted); }
    .ll-row .value { color: var(--ll-text); font-weight: 600; text-align: right; }
    .ll-row.total .label,
    .ll-row.total .value {
        color: var(--ll-navy);
        font-size: 16px;
        font-weight: 700;
    }
    .ll-plan-name {
        font-size: 18px;
        font-weight: 700;
        color: var(--ll-navy);
        margin: 0 0 4px;
    }
    .ll-plan-meta {
        font-size: 13px;
        color: var(--ll-muted);
        margin: 0 0 12px;
    }
    .ll-note {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        background: #EFF6FF;
        border: 1px solid #DBEAFE;
        color: var(--ll-secondary);
        border-radius: 12px;
        padding: 12px;
        font-size: 12px;
        line-height: 1.45;
        margin-bottom: 14px;
    }
    .ll-methods {
        display: grid;
        gap: 10px;
    }
    .ll-method {
        display: flex;
        gap: 12px;
        align-items: center;
        border: 1px solid var(--ll-border);
        border-radius: 14px;
        padding: 12px;
        background: #FAFCFF;
    }
    .ll-method-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(145deg, #E8EEF9, #F8FAFC);
        color: var(--ll-royal);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
        flex-shrink: 0;
    }
    .ll-method strong {
        display: block;
        font-size: 14px;
        color: var(--ll-navy);
    }
    .ll-method span {
        display: block;
        font-size: 12px;
        color: var(--ll-muted);
        margin-top: 2px;
    }
    .ll-cta {
        width: 100%;
        min-height: 52px;
        border: 0;
        border-radius: 14px;
        background: linear-gradient(135deg, var(--ll-navy), var(--ll-royal));
        color: var(--ll-white);
        font-size: 16px;
        font-weight: 700;
        padding: 14px 18px;
        box-shadow: 0 10px 24px rgba(19, 69, 163, 0.28);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        text-decoration: none;
    }
    .ll-cta[disabled],
    .ll-cta.is-loading {
        opacity: 0.75;
        cursor: wait;
        pointer-events: none;
    }
    .ll-cta-secondary {
        width: 100%;
        min-height: 48px;
        margin-top: 10px;
        border-radius: 14px;
        border: 1px solid var(--ll-border);
        background: var(--ll-white);
        color: var(--ll-navy);
        font-size: 15px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    .ll-spinner {
        width: 18px;
        height: 18px;
        border: 2px solid rgba(255,255,255,0.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: llspin .7s linear infinite;
        flex-shrink: 0;
    }
    @keyframes llspin { to { transform: rotate(360deg); } }
    .ll-status {
        text-align: center;
        padding: 12px 4px 4px;
    }
    .ll-status-icon {
        width: 72px;
        height: 72px;
        margin: 8px auto 16px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 34px;
        font-weight: 700;
    }
    .ll-status-icon.ok { background: #DCFCE7; color: var(--ll-success); }
    .ll-status-icon.pending { background: #FEF3C7; color: var(--ll-warning); }
    .ll-status-icon.fail { background: #FEE2E2; color: var(--ll-error); }
    .ll-status-icon.process {
        background: #DBEAFE;
        color: var(--ll-royal);
    }
    .ll-status h1 {
        margin: 0 0 8px;
        font-size: 22px;
        color: var(--ll-navy);
    }
    .ll-status p {
        margin: 0 auto;
        max-width: 320px;
        color: var(--ll-muted);
        font-size: 14px;
        line-height: 1.55;
    }
    .ll-meta {
        margin-top: 16px;
        text-align: left;
        background: #F8FAFC;
        border: 1px solid var(--ll-border);
        border-radius: 12px;
        padding: 12px;
        font-size: 13px;
        color: var(--ll-muted);
        word-break: break-word;
    }
    .ll-meta strong { color: var(--ll-navy); }
    .ll-foot {
        margin-top: auto;
        padding-top: 18px;
        text-align: center;
        font-size: 11px;
        color: var(--ll-muted);
    }
    .ll-foot strong { color: var(--ll-secondary); }
    @media (max-width: 360px) {
        .ll-hero h1 { font-size: 20px; }
        .ll-cta { font-size: 15px; }
    }
</style>
