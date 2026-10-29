{{-- Auto-height for sandboxed interactive graphics (interactive-block) --}}
<script>
    (function () {
        var MIN_HEIGHT = 120;
        var MAX_HEIGHT = 5000;

        window.addEventListener('message', function (event) {
            var payload = event.data;

            // Eigene Grafiken melden motionbase:resize, Seiten von eifach.online eifach:resize
            if (!payload || (payload.type !== 'motionbase:resize' && payload.type !== 'eifach:resize')) {
                return;
            }

            var height = Number(payload.height);

            if (!isFinite(height) || height <= 0) {
                return;
            }

            height = Math.round(Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, height)));

            // Sandboxed frames have an opaque origin, so event.origin is always
            // "null" and useless for verification - identify the sender by window.
            var frames = document.querySelectorAll('iframe.interactive-frame');

            for (var i = 0; i < frames.length; i++) {
                if (frames[i].contentWindow === event.source) {
                    frames[i].style.height = height + 'px';
                    return;
                }
            }
        });
    })();
</script>
