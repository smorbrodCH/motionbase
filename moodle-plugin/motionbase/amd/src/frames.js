// This file is part of the MotionBase plugin for Moodle.

/**
 * Sizes embedded MotionBase graphics to their content. Each graphic reports
 * its height as {type: 'motionbase:resize'} or, on eifach.online, {type: 'eifach:resize'};
 * without this the frame
 * keeps the author's height and scrolls inside.
 *
 * @module     filter_motionbase/frames
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const MIN = 120;
const MAX = 5000;
let listening = false;

export const init = () => {
    if (listening) {
        return;
    }
    listening = true;

    window.addEventListener('message', event => {
        const payload = event.data;
        // Eigene Grafiken melden motionbase:resize, Seiten von eifach.online eifach:resize
        if (!payload || (payload.type !== 'motionbase:resize' && payload.type !== 'eifach:resize')) {
            return;
        }

        const height = Number(payload.height);
        if (!Number.isFinite(height) || height <= 0) {
            return;
        }

        // Sandboxed frames have an opaque origin, so event.origin is "null":
        // the sender is told apart by its window.
        const frame = [...document.querySelectorAll('iframe.motionbase-frame')]
            .find(f => f.contentWindow === event.source);
        if (frame) {
            frame.style.height = Math.round(Math.min(MAX, Math.max(MIN, height))) + 'px';
        }
    });
};
