</div> </div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../../public/assets/js/main.js') ?: time() ?>"></script>
<script src="/assets/js/tablekit.js?v=<?= @filemtime(__DIR__ . '/../../public/assets/js/tablekit.js') ?: time() ?>"></script>
<script>
(function () {
    var menu = document.querySelector('.sidebar-menu');
    if (!menu) return;
    var saved = sessionStorage.getItem('sidebar_scroll');
    if (saved) menu.scrollTop = parseInt(saved, 10);
    document.querySelectorAll('.sidebar-menu a').forEach(function (a) {
        a.addEventListener('click', function () {
            sessionStorage.setItem('sidebar_scroll', menu.scrollTop);
        });
    });
})();
(function () {
    var el = document.getElementById('admin-live-clock');
    if (!el) return;
    setInterval(function () {
        var d = new Date();
        var h = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        var tz = d.toLocaleTimeString('en-US', { timeZoneName: 'short' }).split(' ').pop();
        el.innerHTML = '<i class="fas fa-clock"></i> ' + h + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0') + ' ' + ampm + ' ' + tz;
    }, 1000);
})();
</script>
</body>
</html>