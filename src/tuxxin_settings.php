<?php
// FILE: src/tuxxin_settings.php
// iNetPanel — Settings → Tuxxin Services tab (included by src/settings.php).
// Data comes from /api/tuxxin?action=status; nothing on this tab contacts a
// service except "Send test".
?>
<div class="tab-pane fade" id="tab-tuxxin">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div style="max-width:70ch;">
            <h6 class="text-muted text-uppercase small fw-bold mb-1">Tuxxin Services</h6>
            <p class="small text-muted mb-0">
                Optional integrations with services from <a href="https://tuxxin.com" target="_blank" rel="noopener">Tuxxin</a>,
                the company behind iNetPanel. Each module works on its free tier with no account; a key adds
                coverage or raises its limits. <strong>A module that is off does not run</strong> — nothing is sent
                anywhere for it, and nothing it would add is shown.
            </p>
        </div>
        <div class="form-check form-switch fs-6">
            <input class="form-check-input" type="checkbox" id="tsvc-master">
            <label class="form-check-label fw-semibold" for="tsvc-master">Enable Tuxxin Services</label>
        </div>
    </div>

    <div class="alert alert-secondary small d-none" id="tsvc-master-off">
        <i class="fas fa-power-off me-1"></i>
        Tuxxin Services are off. No module runs and none appears in the admin panel or the client portal.
    </div>

    <div class="row g-3" id="tsvc-modules">
        <div class="col-12 text-muted small">Loading…</div>
    </div>

    <div class="card border mt-3">
        <div class="card-body">
            <h6 class="mb-1"><i class="fas fa-bell me-2 text-muted"></i>Alerts</h6>
            <p class="small text-muted mb-2">
                iNetPanel does not run a mail server, so alerts go to a webhook. Used when the External Security
                Audit sees your exposure change, and when the Malware &amp; Phishing Scan flags a site. Paste a
                Discord or Slack incoming webhook, an <code>https://ntfy.sh/&lt;topic&gt;</code> URL, or any
                <code>https://</code> endpoint that accepts a JSON POST.
            </p>
            <div class="input-group input-group-sm" style="max-width:640px;">
                <input type="password" class="form-control" id="tsvc-webhook" autocomplete="off"
                       placeholder="https://discord.com/api/webhooks/…">
                <button class="btn btn-outline-primary" type="button" id="tsvc-webhook-save">Save</button>
                <button class="btn btn-outline-secondary" type="button" id="tsvc-webhook-test">Send test</button>
                <button class="btn btn-outline-danger" type="button" id="tsvc-webhook-clear" title="Remove the saved webhook">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <div class="small text-muted mt-1" id="tsvc-webhook-state"></div>
        </div>
    </div>

    <div class="card border mt-3">
        <div class="card-body">
            <h6 class="mb-2"><i class="fas fa-cubes me-2 text-muted"></i>More from Tuxxin</h6>
            <div class="row g-2" id="tsvc-links"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let state = null;

    function quotaText(q) {
        if (!q || q.remaining === null || q.remaining === undefined) return 'No requests made yet.';
        let t = q.remaining.toLocaleString() + (q.limit ? ' of ' + q.limit.toLocaleString() : '') + ' left';
        if (q.reset) {
            const d = new Date(q.reset * 1000);
            t += ' · resets ' + d.toLocaleString([], {hour: '2-digit', minute: '2-digit', month: 'short', day: 'numeric'});
        }
        return t;
    }

    function render() {
        const box = document.getElementById('tsvc-modules');
        document.getElementById('tsvc-master').checked = !!state.master;
        document.getElementById('tsvc-master-off').classList.toggle('d-none', !!state.master);
        box.innerHTML = '';
        Object.entries(state.modules).forEach(([key, m]) => {
            const col = document.createElement('div');
            col.className = 'col-lg-6';
            const keyState = m.has_key
                ? '<span class="text-success"><i class="fas fa-key me-1"></i>Key saved</span>'
                : (m.key_required ? '<span class="text-warning">No key yet — this module cannot run until one is saved.</span>'
                                  : '<span class="text-muted">Free tier — ' + esc(m.free) + '</span>');
            let extra = '';
            if (key === 'qsa') {
                const tier = state.qsa_tier ? ' (' + (state.qsa_tier === 'deep' ? 'Deep, one-time credits' : 'Full subscription') + ')' : '';
                extra = '<div class="small mt-1"><a href="/admin/firewall#tab-qsa">Open Firewall &rarr; Exposure Scan</a>'
                      + (m.has_key ? ' · token' + esc(tier) : '') + '</div>';
            } else {
                extra = '<div class="small text-muted mt-1"><i class="fas fa-gauge-high me-1"></i>' + esc(quotaText(m.quota)) + '</div>';
            }
            if (key === 'whack') {
                extra += '<div class="small text-muted mt-1">Per whack.sh\'s terms, files a scan confirms as malicious are shared '
                       + '(by hash and type, not your identity) with VirusTotal, MalwareBazaar and OTX.</div>';
            }
            col.innerHTML = `
              <div class="card border h-100 ${m.enabled ? '' : 'bg-light'}">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                      <h6 class="mb-0"><i class="fas ${esc(m.icon)} me-2 text-primary"></i>${esc(m.name)}</h6>
                      <a class="small text-muted" href="${esc(m.url)}" target="_blank" rel="noopener">${esc(m.service)}</a>
                    </div>
                    <div class="form-check form-switch m-0">
                      <input class="form-check-input tsvc-switch" type="checkbox" data-module="${esc(key)}"
                             ${m.switch ? 'checked' : ''} ${state.master ? '' : 'disabled'} aria-label="Enable ${esc(m.name)}">
                    </div>
                  </div>
                  <p class="small mt-2 mb-1">${esc(m.summary)}</p>
                  <div class="small text-muted"><strong>Sends:</strong> ${esc(m.sends)}</div>
                  <div class="small text-muted"><strong>Appears in:</strong> ${m.where}</div>
                  ${extra}
                  <hr class="my-2">
                  <label class="form-label small fw-semibold mb-1">${esc(m.key_label)} <span class="fw-normal text-muted">(${m.key_required ? 'required' : 'optional'})</span></label>
                  <div class="input-group input-group-sm">
                    <input type="password" class="form-control tsvc-key" data-name="${esc(m.key)}" autocomplete="off"
                           placeholder="${m.has_key ? '•••••••• saved — paste to replace' : 'Paste a key'}">
                    <button class="btn btn-outline-primary tsvc-key-save" data-name="${esc(m.key)}" type="button">Save</button>
                    ${m.has_key ? `<button class="btn btn-outline-danger tsvc-key-clear" data-name="${esc(m.key)}" type="button" title="Remove the key"><i class="fas fa-trash"></i></button>` : ''}
                  </div>
                  <div class="small text-muted mt-1">${keyState} · ${esc(m.key_hint)}
                    <a href="${esc(m.pricing)}" target="_blank" rel="noopener">Get a key</a></div>
                </div>
              </div>`;
            box.appendChild(col);
        });

        const wh = document.getElementById('tsvc-webhook');
        wh.value = '';
        wh.placeholder = state.webhook_set ? '•••••••• ' + (state.webhook_kind || '') + ' webhook saved — paste to replace'
                                           : 'https://discord.com/api/webhooks/…';
        document.getElementById('tsvc-webhook-state').textContent = state.webhook_set
            ? 'Alerts go to your ' + (state.webhook_kind || '') + ' webhook.'
            : 'No webhook saved — alerts appear in the panel only.';

        const links = document.getElementById('tsvc-links');
        links.innerHTML = state.links.map(l => `
            <div class="col-md-6 col-xl-3"><div class="border rounded p-2 h-100 small">
              <a class="fw-semibold" href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.name)}</a>
              <div class="text-muted">${esc(l.text)}</div></div></div>`).join('');
    }

    function load() {
        fetch('/api/tuxxin?action=status').then(r => r.json()).then(d => {
            if (!d.success) { showAlert(esc(d.error || 'Could not load Tuxxin Services.'), 'danger'); return; }
            state = d;
            render();
        }).catch(() => showAlert('Could not load Tuxxin Services.', 'danger'));
    }

    function saveSwitches() {
        const fd = new FormData();
        fd.append('action', 'save_switches');
        fd.append('master', document.getElementById('tsvc-master').checked ? '1' : '0');
        document.querySelectorAll('.tsvc-switch').forEach(el => fd.append(el.dataset.module, el.checked ? '1' : '0'));
        fetch('/api/tuxxin', {method: 'POST', body: fd}).then(r => r.json()).then(d => {
            showAlert(d.success ? 'Tuxxin Services updated.' : esc(d.error || 'Could not save.'), d.success ? 'success' : 'danger');
            load();
        }).catch(() => showAlert('Request failed.', 'danger'));
    }

    function saveKey(name, value, done) {
        const fd = new FormData();
        fd.append('action', value === null ? 'clear_key' : 'set_key');
        fd.append('name', name);
        if (value !== null) fd.append('value', value);
        fetch('/api/tuxxin', {method: 'POST', body: fd}).then(r => r.json()).then(d => {
            showAlert(d.success ? (value === null ? 'Removed.' : 'Saved.') : esc(d.error || 'Could not save.'), d.success ? 'success' : 'danger');
            if (done) done(d);
            load();
        }).catch(() => showAlert('Request failed.', 'danger'));
    }

    document.addEventListener('change', e => {
        if (e.target.id === 'tsvc-master' || e.target.classList.contains('tsvc-switch')) saveSwitches();
    });
    document.addEventListener('click', e => {
        const save = e.target.closest('.tsvc-key-save');
        if (save) {
            const inp = document.querySelector(`.tsvc-key[data-name="${save.dataset.name}"]`);
            if (!inp.value.trim()) { showAlert('Paste a key first.', 'danger'); return; }
            saveKey(save.dataset.name, inp.value.trim());
            return;
        }
        const clear = e.target.closest('.tsvc-key-clear');
        if (clear && confirm('Remove this key? The module falls back to its free tier.')) saveKey(clear.dataset.name, null);
    });
    document.getElementById('tsvc-webhook-save').addEventListener('click', () => {
        const v = document.getElementById('tsvc-webhook').value.trim();
        if (!v) { showAlert('Paste a webhook URL first.', 'danger'); return; }
        saveKey('webhook_url', v);
    });
    document.getElementById('tsvc-webhook-clear').addEventListener('click', () => {
        if (confirm('Remove the saved webhook? Alerts will appear in the panel only.')) saveKey('webhook_url', null);
    });
    document.getElementById('tsvc-webhook-test').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'notify_test');
        fetch('/api/tuxxin', {method: 'POST', body: fd}).then(r => r.json()).then(d => {
            showAlert(d.success ? 'Test message delivered.' : esc(d.error || 'Delivery failed.'), d.success ? 'success' : 'danger');
        }).catch(() => showAlert('Request failed.', 'danger')).finally(() => { btn.disabled = false; });
    });

    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.querySelector('[data-bs-target="#tab-tuxxin"]');
        if (btn) btn.addEventListener('shown.bs.tab', () => { if (!state) load(); });
        if (location.hash === '#tuxxin' || location.hash === '#tab-tuxxin') load();
    });
})();
</script>
