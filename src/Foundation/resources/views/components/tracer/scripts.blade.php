@props(['hideVendor', 'frameCount'])

<script>
    let currentFrameIndex = 0;
    let vendorHidden = {{ $hideVendor ? 'true' : 'false' }};
    const totalFrames = {{ $frameCount }};
    const editorBase = 'vscode://file/';

    function isVisible(el) {
        return el && !el.classList.contains('hidden');
    }

    // Scroll a container so `el` is inside it, without moving the page
    function revealIn(container, el) {
        if (!container || !el) return;
        const top = el.offsetTop;
        const bottom = top + el.offsetHeight;
        if (top < container.scrollTop) {
            container.scrollTop = top;
        } else if (bottom > container.scrollTop + container.clientHeight) {
            container.scrollTop = bottom - container.clientHeight;
        }
    }

    function selectFrame(index, focus) {
        if (index < 0 || index >= totalFrames) return;

        const prevItem = document.getElementById('frame-item-' + currentFrameIndex);
        if (prevItem) prevItem.classList.remove('active');
        const prevSnippet = document.getElementById('code-snippet-' + currentFrameIndex);
        if (prevSnippet) prevSnippet.style.display = 'none';

        currentFrameIndex = index;

        const item = document.getElementById('frame-item-' + index);
        if (item) {
            item.classList.add('active');
            revealIn(document.getElementById('framesList'), item);
            if (focus) item.focus({
                preventScroll: true
            });

            const line = item.getAttribute('data-line');
            const raw = item.getAttribute('data-raw-file');
            document.getElementById('viewerFile').textContent = item.getAttribute('data-file');
            document.getElementById('viewerLine').textContent = line ? ':' + line : '';

            const link = document.getElementById('editorLink');
            if (link) {
                if (raw) {
                    link.style.display = '';
                    link.href = editorBase + raw.replace(/\\/g, '/') + (line ? ':' + line : '');
                } else {
                    link.style.display = 'none';
                }
            }
        }

        const snippet = document.getElementById('code-snippet-' + index);
        if (snippet) {
            snippet.style.display = 'block';
            const box = document.getElementById('codeBox');
            const activeLine = snippet.querySelector('.syntax-highlight-line.active');
            box.scrollLeft = 0;
            box.scrollTop = activeLine ?
                Math.max(0, activeLine.offsetTop - box.clientHeight / 2 + activeLine.offsetHeight / 2) :
                0;
        }
    }

    function toggleVendor() {
        vendorHidden = !vendorHidden;
        document.querySelectorAll('.frame.vendor').forEach(function(el) {
            el.classList.toggle('hidden', vendorHidden);
        });
        const label = document.getElementById('vendorToggleLabel');
        if (label) label.textContent = (vendorHidden ? 'Show' : 'Hide') + ' vendor frames';

        const current = document.getElementById('frame-item-' + currentFrameIndex);
        if (!isVisible(current)) {
            const firstVisible = document.querySelector('.frame:not(.hidden)');
            if (firstVisible) selectFrame(parseInt(firstVisible.id.replace('frame-item-', ''), 10));
        }
    }

    function switchTab(tabId, btn) {
        document.querySelectorAll('.tab').forEach(function(b) {
            b.classList.remove('active');
        });
        document.querySelectorAll('.pane').forEach(function(p) {
            p.classList.remove('active');
        });
        btn.classList.add('active');
        const target = document.getElementById(tabId);
        if (target) target.classList.add('active');
    }

    let toastTimer = null;

    function showToast(msg) {
        const toast = document.getElementById('tracerToast');
        document.getElementById('toastMessage').textContent = msg;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function() {
            toast.classList.remove('show');
        }, 1600);
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('Copied to clipboard');
            });
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('Copied to clipboard');
        }
    }

    function copyCurrentPath() {
        const item = document.getElementById('frame-item-' + currentFrameIndex);
        if (item) copyText(item.getAttribute('data-raw-file') || item.getAttribute('data-file'));
    }

    // Any element with data-copy copies its value
    document.addEventListener('click', function(e) {
        const el = e.target.closest('[data-copy]');
        if (el) copyText(el.getAttribute('data-copy'));
    });

    // Keyboard: ↑ / ↓ while the call stack has focus, or j / k anywhere
    document.addEventListener('keydown', function(e) {
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        const tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA') return;

        const inList = !!e.target.closest('#framesList');
        let step = 0;
        if (inList && e.key === 'ArrowDown') step = 1;
        else if (inList && e.key === 'ArrowUp') step = -1;
        else if (e.key === 'j') step = 1;
        else if (e.key === 'k') step = -1;
        if (!step) return;
        e.preventDefault();

        let i = currentFrameIndex + step;
        while (i >= 0 && i < totalFrames) {
            const el = document.getElementById('frame-item-' + i);
            if (isVisible(el)) {
                selectFrame(i, inList);
                break;
            }
            i += step;
        }
    });

    // Center the error line on first paint
    selectFrame(0);
</script>
