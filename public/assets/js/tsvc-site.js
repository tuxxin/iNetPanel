/* FILE: public/assets/js/tsvc-site.js
 * iNetPanel — "Site details" from Tuxxin Services, shared by the admin Accounts
 * page and the client portal. Only the modules that are on are rendered; the
 * page passes which those are. Opening the panel reads cached data — a request
 * to a service happens only when someone clicks a refresh or scan button.
 *
 *   TsvcSite.render(el, domain, {
 *       api: '/api/tuxxin',                 // or '/api/account'
 *       modules: {issued: true, webshot: true, whack: false},
 *   });
 */
(function () {
    'use strict';

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    function daysLeft(iso) {
        if (!iso) return null;
        const t = Date.parse(iso);
        return isNaN(t) ? null : Math.floor((t - Date.now()) / 86400000);
    }

    function dateCell(iso, label) {
        if (!iso) return '<span class="text-muted">not known</span>';
        const d = daysLeft(iso);
        const when = new Date(Date.parse(iso)).toLocaleDateString();
        let cls = 'text-success';
        if (d !== null && d < 14) cls = 'text-danger fw-semibold';
        else if (d !== null && d < 30) cls = 'text-warning fw-semibold';
        const rel = d === null ? '' : (d < 0 ? ' (' + label + ' ' + (-d) + ' days ago)' : ' (in ' + d + ' days)');
        return '<span class="' + cls + '">' + esc(when) + esc(rel) + '</span>';
    }

    const VERDICT = {
        clean: ['success', 'Clean'], suspicious: ['warning text-dark', 'Suspicious'], malware: ['danger', 'Malware'],
        phishing: ['danger', 'Phishing'], cloaking: ['danger', 'Cloaking'], unreachable: ['secondary', 'Unreachable'],
    };

    function post(api, action, domain) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('domain', domain);
        return fetch(api, {method: 'POST', body: fd}).then(r => r.json());
    }

    function render(el, domain, cfg) {
        const api = cfg.api, m = cfg.modules || {};
        if (!m.issued && !m.webshot && !m.whack) { el.innerHTML = ''; return; }
        el.innerHTML = '<div class="text-muted small">Loading site details…</div>';
        fetch(api + '?action=site_insights&domain=' + encodeURIComponent(domain))
            .then(r => r.json())
            .then(r => {
                if (!r.success) { el.innerHTML = '<div class="text-muted small">' + esc(r.error || 'Unavailable.') + '</div>'; return; }
                draw(el, domain, cfg, r.data || {});
            })
            .catch(() => { el.innerHTML = '<div class="text-muted small">Site details are unavailable right now.</div>'; });
    }

    function draw(el, domain, cfg, d) {
        const api = cfg.api, m = cfg.modules || {};
        let h = '<div class="row g-3">';

        if (m.webshot) {
            const w = d.webshot || {};
            h += '<div class="col-md-5"><div class="border rounded p-2 h-100">';
            h += '<div class="small text-muted mb-1"><i class="fas fa-camera me-1"></i>Screenshot</div>';
            if (w.has_image) {
                h += '<a href="https://' + esc(domain) + '/" target="_blank" rel="noopener"><img class="img-fluid rounded border" alt="Screenshot of '
                   + esc(domain) + '" src="' + api + '?action=' + (cfg.shotAction || 'shot') + '&domain=' + encodeURIComponent(domain)
                   + '&t=' + encodeURIComponent(w.captured_at || '') + '" loading="lazy"></a>';
                h += '<div class="small text-muted mt-1">Taken ' + (w.captured_at ? esc(new Date(w.captured_at * 1000).toLocaleString()) : '—') + '</div>';
            } else {
                h += '<div class="small text-muted py-3 text-center">' + (w.queued ? 'Queued — screenshots are taken a few at a time to stay within the free limit.' : 'No screenshot yet.') + '</div>';
            }
            if (w.error) h += '<div class="small text-warning">Last attempt: ' + esc(w.error)
                + (w.retry_at ? ' — retrying ' + esc(new Date(w.retry_at * 1000).toLocaleString()) : '') + '</div>';
            h += '<button class="btn btn-sm btn-outline-secondary mt-2 tsvc-shot-btn"' + (w.queued ? ' disabled' : '') + '>'
               + '<i class="fas fa-rotate me-1"></i>' + (w.queued ? 'Queued' : 'Refresh screenshot') + '</button>';
            h += '<div class="text-muted mt-1" style="font-size:.7rem;">by <a href="https://webshot.site" target="_blank" rel="noopener">webshot.site</a></div>';
            h += '</div></div>';
        }

        h += '<div class="col-md-' + (m.webshot ? '7' : '12') + '"><div class="d-flex flex-column gap-3 h-100">';

        if (m.issued) {
            const i = d.issued;
            h += '<div class="border rounded p-2">';
            h += '<div class="small text-muted mb-1"><i class="fas fa-certificate me-1"></i>Domain &amp; certificate</div>';
            if (!i) {
                h += '<div class="small text-muted">Not looked up yet.</div>';
            } else if (i.found === false) {
                h += '<div class="small text-muted">issued.live has no record of this domain yet.</div>';
            } else if (i.error) {
                h += '<div class="small text-muted">Lookup failed (' + esc(i.error) + '); it is retried within the hour.</div>';
            } else {
                const folded = i.domain && i.domain !== domain ? ' <span class="text-muted">(for ' + esc(i.domain) + ')</span>' : '';
                h += '<table class="table table-sm small mb-1"><tbody>'
                   + '<tr><td class="text-muted ps-0">Registration expires' + folded + '</td><td>' + dateCell(i.expires, 'expired') + '</td></tr>'
                   + '<tr><td class="text-muted ps-0">Certificate expires</td><td>' + dateCell(i.ssl_expires, 'expired') + '</td></tr>'
                   + '<tr><td class="text-muted ps-0">Certificate issuer</td><td>' + esc(i.ssl_issuer || 'not known') + '</td></tr>'
                   + (i.registered ? '<tr><td class="text-muted ps-0">Registered</td><td>' + esc(new Date(Date.parse(i.registered)).toLocaleDateString()) + '</td></tr>' : '')
                   + '</tbody></table>';
                const seen = i.last_update || i.ip_observed_at;
                h += '<div class="text-muted" style="font-size:.7rem;">Observed by <a href="https://issued.live/' + encodeURIComponent(i.domain || domain)
                   + '" target="_blank" rel="noopener">issued.live</a> from public certificate logs'
                   + (seen ? ', ' + esc(new Date(Date.parse(seen)).toLocaleString()) : '') + '.</div>';
            }
            h += '</div>';
        }

        if (m.whack) {
            const w = d.whack;
            h += '<div class="border rounded p-2">';
            h += '<div class="small text-muted mb-1"><i class="fas fa-bug me-1"></i>Malware &amp; phishing scan</div>';
            if (d.whack_has_key === false) {
                h += '<div class="small text-muted">Not available: whack.sh needs an API key, which ' + (cfg.portal
                    ? 'your host has not added.' : 'you can add in <a href="/admin/settings#tuxxin">Settings &rarr; Tuxxin Services</a> (a free account includes these scans).') + '</div>';
            } else {
                if (w && w.state === 'running') {
                    h += '<div class="small"><span class="spinner-border spinner-border-sm me-1"></span>Scanning…</div>';
                } else if (w && w.state === 'finished') {
                    const v = VERDICT[w.verdict] || ['secondary', w.status === 'failed' ? 'Scan failed' : (w.verdict || 'No verdict')];
                    h += '<div class="small"><span class="badge bg-' + v[0] + '">' + esc(v[1]) + '</span> '
                       + (w.finished_at ? '<span class="text-muted">' + esc(new Date(w.finished_at * 1000).toLocaleString()) + '</span>' : '')
                       + (w.error ? '<div class="text-muted">' + esc(w.error) + '</div>' : '') + '</div>';
                    if (['malware', 'phishing', 'cloaking'].includes(w.verdict)) {
                        h += '<div class="alert alert-danger small py-1 px-2 mt-2 mb-0">This site may be compromised. Check for files you did not '
                           + 'upload and change its passwords' + (cfg.portal ? ', and contact your host.' : '.') + '</div>';
                    }
                } else {
                    h += '<div class="small text-muted">Not scanned yet.</div>';
                }
                h += '<button class="btn btn-sm btn-outline-secondary mt-2 tsvc-whack-btn"' + (w && w.state === 'running' ? ' disabled' : '') + '>'
                   + '<i class="fas fa-magnifying-glass me-1"></i>Scan now</button>';
                h += '<div class="text-muted mt-1" style="font-size:.7rem;">Scanned from outside by <a href="https://whack.sh" target="_blank" rel="noopener">whack.sh</a>. '
                   + 'Files a scan confirms as malicious are shared by hash with malware databases.</div>';
            }
            h += '</div>';
        }

        h += '</div></div></div>';
        el.innerHTML = h;

        const shot = el.querySelector('.tsvc-shot-btn');
        if (shot) shot.addEventListener('click', () => {
            shot.disabled = true;
            post(api, cfg.shotRefreshAction || 'webshot_refresh', domain).then(r => {
                if (!r.success) { shot.disabled = false; alert(r.error || 'Could not queue a screenshot.'); return; }
                shot.innerHTML = '<i class="fas fa-clock me-1"></i>Queued';
                setTimeout(() => render(el, domain, cfg), 20000);
            });
        });
        const wb = el.querySelector('.tsvc-whack-btn');
        if (wb) wb.addEventListener('click', () => {
            wb.disabled = true;
            post(api, cfg.whackStartAction || 'whack_start', domain).then(r => {
                if (!r.success) { wb.disabled = false; alert(r.error || 'Could not start the scan.'); return; }
                pollWhack(el, domain, cfg, 0);
            });
        });
        if (m.whack && d.whack && d.whack.state === 'running') pollWhack(el, domain, cfg, 0);
    }

    function pollWhack(el, domain, cfg, n) {
        if (n > 60 || !el.isConnected) return;
        setTimeout(() => {
            fetch(cfg.api + '?action=' + (cfg.whackStatusAction || 'whack_status') + '&domain=' + encodeURIComponent(domain))
                .then(r => r.json())
                .then(r => {
                    if (r.success && r.data && r.data.state === 'running') pollWhack(el, domain, cfg, n + 1);
                    else render(el, domain, cfg);
                })
                .catch(() => pollWhack(el, domain, cfg, n + 1));
        }, n === 0 ? 2000 : 4000);
    }

    /** Small badges for a list row: an expiring domain or certificate, a flagged site. */
    function badges(s) {
        if (!s) return '';
        let h = '';
        const de = daysLeft(s.expires), ds = daysLeft(s.ssl_expires);
        if (de !== null && de < 30) h += '<span class="badge bg-' + (de < 14 ? 'danger' : 'warning text-dark') + ' ms-1" title="Domain registration">'
            + (de < 0 ? 'domain expired' : 'domain expires in ' + de + 'd') + '</span>';
        if (ds !== null && ds < 14) h += '<span class="badge bg-' + (ds < 7 ? 'danger' : 'warning text-dark') + ' ms-1" title="TLS certificate">'
            + (ds < 0 ? 'certificate expired' : 'certificate expires in ' + ds + 'd') + '</span>';
        if (['malware', 'phishing', 'cloaking'].includes(s.verdict)) h += '<span class="badge bg-danger ms-1" title="whack.sh">' + esc(s.verdict) + '</span>';
        return h;
    }

    window.TsvcSite = {render, badges};
})();
