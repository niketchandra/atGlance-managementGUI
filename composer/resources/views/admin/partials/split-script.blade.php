{{-- Behaviour for every two-pane block ([data-split] with .ag-split-item / .ag-split-open / .ag-split-panel, all keyed by data-key).
     data-split names the URL parameter that remembers the open item, e.g. ?provider=github. --}}
<script>
(function () {
    document.querySelectorAll('[data-split]').forEach(function (root) {
        var param = root.dataset.split;

        function open(key) {
            root.querySelectorAll('.ag-split-item').forEach(function (item) { item.classList.toggle('is-open', item.dataset.key === key); });
            root.querySelectorAll('.ag-split-open').forEach(function (btn) { btn.setAttribute('aria-expanded', btn.dataset.key === key ? 'true' : 'false'); });
            root.querySelectorAll('.ag-split-panel').forEach(function (panel) { panel.style.display = panel.dataset.key === key ? 'block' : 'none'; });
            var url = new URL(window.location.href);
            url.searchParams.set(param, key);
            window.history.replaceState({}, '', url.toString());
        }

        root.addEventListener('click', function (event) {
            var btn = event.target.closest('.ag-split-open');
            if (btn && root.contains(btn)) {
                open(btn.dataset.key);
            }
        });

        root.splitOpen = open;
    });
})();
</script>
