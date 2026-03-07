</div> </div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/main.js"></script>
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
</script>
</body>
</html>