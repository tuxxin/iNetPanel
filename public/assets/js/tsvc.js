/* FILE: public/assets/js/tsvc.js
 * iNetPanel — Tuxxin Services helpers for admin pages.
 * Loaded only while the IP Intelligence (worldip.io) module is on; nothing here
 * contacts a service until someone clicks an address.
 */
(function () {
    'use strict';

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    // Addresses with no public profile: looking them up would only spend quota.
    function isPrivateV4(ip) {
        const p = ip.split('.').map(Number);
        if (p.length !== 4 || p.some(n => !(n >= 0 && n <= 255))) return true;
        return p[0] === 10 || p[0] === 127 || p[0] === 0 || p[0] >= 224 ||
               (p[0] === 172 && p[1] >= 16 && p[1] <= 31) ||
               (p[0] === 192 && p[1] === 168) ||
               (p[0] === 169 && p[1] === 254) ||
               (p[0] === 100 && p[1] >= 64 && p[1] <= 127);
    }

    function flag(cc) {
        if (!/^[A-Z]{2}$/.test(cc || '')) return '';
        return String.fromCodePoint(...[...cc].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
    }

    function render(d) {
        if (d.private) {
            return '<div class="small">A private or reserved address — it has no public profile.</div>';
        }
        const place = [d.city, d.region, d.country].filter(Boolean).join(', ');
        let h = '<div class="small">';
        h += '<div class="fw-semibold mb-1">' + esc(d.ip) + ' ' + flag(d.country_code) + '</div>';
        if (place) h += '<div>' + esc(place) + '</div>';
        if (d.asn) h += '<div>AS' + esc(d.asn) + ' ' + esc(d.asn_name || d.org) + '</div>';
        if (d.cidr) h += '<div class="text-muted">' + esc(d.cidr) + '</div>';
        if (d.ptr) {
            h += '<div class="mt-1">rDNS: <code>' + esc(d.ptr) + '</code>';
            if (d.forward_confirmed === true) h += ' <span class="text-success" title="The name resolves back to this address">&#10003; forward-confirmed</span>';
            else if (d.forward_confirmed === false) h += ' <span class="text-muted">(not forward-confirmed)</span>';
            h += '</div>';
        }
        if (d.classification) {
            const c = d.classification, tags = [];
            if (c.is_vpn) tags.push('VPN');
            if (c.is_proxy) tags.push('Proxy');
            if (c.is_tor) tags.push('Tor');
            if (c.is_relay) tags.push('Relay');
            if (c.is_datacenter) tags.push('Datacenter');
            if (tags.length) h += '<div class="mt-1">' + tags.map(t => '<span class="badge bg-warning text-dark me-1">' + esc(t) + '</span>').join('') + '</div>';
        }
        if (d.trust && d.trust.score !== undefined && d.trust.score !== null) {
            h += '<div>Trust score: ' + esc(d.trust.score) + (d.trust.band ? ' (' + esc(d.trust.band) + ')' : '') + '</div>';
        }
        h += '<div class="text-muted mt-2" style="font-size:.7rem;">Data: <a href="https://worldip.io/' + encodeURIComponent(d.ip)
           + '" target="_blank" rel="noopener">worldip.io</a>'
           + (d.attribution ? ' — ' + esc(d.attribution.replace(/ License details:.*$/, '')) : '')
           + ' <a href="https://worldip.io/data-sources" target="_blank" rel="noopener">Sources</a></div>';
        return h + '</div>';
    }

    let open = null;

    function show(el) {
        const ip = el.dataset.ip;
        if (open && open.el === el) { open.pop.dispose(); open = null; return; }
        if (open) { open.pop.dispose(); open = null; }
        const pop = new bootstrap.Popover(el, {
            html: true, sanitize: false, trigger: 'manual', placement: 'auto', container: 'body',
            title: 'IP lookup', content: '<div class="small text-muted">Looking up ' + esc(ip) + '…</div>',
        });
        pop.show();
        open = {el, pop};
        fetch('/api/tuxxin?action=worldip&ip=' + encodeURIComponent(ip))
            .then(r => r.json())
            .then(d => {
                if (!open || open.el !== el) return;
                pop.setContent({'.popover-body': d.success ? render(d.data) : '<div class="small text-danger">' + esc(d.error || 'Lookup failed.') + '</div>'});
            })
            .catch(() => { if (open && open.el === el) pop.setContent({'.popover-body': '<div class="small text-danger">Lookup failed.</div>'}); });
    }

    document.addEventListener('click', e => {
        const el = e.target.closest('.tsvc-ip');
        if (el) { e.preventDefault(); show(el); return; }
        if (open && !e.target.closest('.popover')) { open.pop.dispose(); open = null; }
    });

    /** Markup for one address: a link that opens the lookup. */
    window.tsvcIp = function (ip) {
        if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(ip) || isPrivateV4(ip)) return esc(ip);
        return '<a href="#" class="tsvc-ip text-reset text-decoration-underline" style="text-decoration-style:dotted!important;" data-ip="'
             + esc(ip) + '" title="Look up ' + esc(ip) + '">' + esc(ip) + '</a>';
    };

    /** Make every public IPv4 address inside a <pre> clickable. */
    window.tsvcLinkifyIps = function (pre) {
        if (!pre) return;
        const text = pre.textContent;
        if (text.length > 2000000) return;          // a huge log stays plain text
        const re = /\b(?:\d{1,3}\.){3}\d{1,3}\b/g;
        let out = '', last = 0, m;
        while ((m = re.exec(text)) !== null) {
            out += esc(text.slice(last, m.index)) + window.tsvcIp(m[0]);
            last = m.index + m[0].length;
        }
        pre.innerHTML = out + esc(text.slice(last));
    };
})();
