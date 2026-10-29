import type {
    API,
    BlockAPI,
    BlockTool,
    BlockToolConstructorOptions,
    BlockToolData,
} from '@editorjs/editorjs';
import { sandboxFor } from '@/lib/interactive';

interface InteractiveBlockData extends BlockToolData {
    url?: string;
    caption?: string;
    height?: number;
}

interface InteractiveBlockConfig {
    placeholder?: string;
}

const DEFAULT_HEIGHT = 480;
const MIN_HEIGHT = 120;
const MAX_HEIGHT = 5000;

export default class InteractiveBlock implements BlockTool {
    private api: API;
    private block?: BlockAPI;
    private data: InteractiveBlockData;
    private readOnly: boolean;
    private config: InteractiveBlockConfig;
    private wrapper: HTMLDivElement | null = null;
    private iframe: HTMLIFrameElement | null = null;
    private messageHandler: ((event: MessageEvent) => void) | null = null;

    constructor({
        api,
        block,
        data,
        readOnly,
        config = {},
    }: BlockToolConstructorOptions<InteractiveBlockData, InteractiveBlockConfig>) {
        this.api = api;
        this.block = block;
        this.readOnly = Boolean(readOnly);
        this.config = config;

        this.data = {
            url: data?.url ?? '',
            caption: data?.caption ?? '',
            height: data?.height ?? DEFAULT_HEIGHT,
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    static get toolbox() {
        return {
            title: 'Interaktiv',
            icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none"><rect x="2.5" y="4" width="19" height="14" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 14.5c2-5.5 4-8 5.5-8s1.5 4 5.5 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        };
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.className = 'interactive-block';

        if (this.data.url) {
            this.renderPreview();
        } else {
            this.renderInput();
        }

        return this.wrapper;
    }

    private renderInput() {
        if (!this.wrapper) return;
        this.teardownResizeListener();
        this.wrapper.innerHTML = '';

        const inputWrapper = document.createElement('div');
        inputWrapper.className = 'interactive-block__input-wrapper';

        const icon = document.createElement('div');
        icon.className = 'interactive-block__icon';
        icon.innerHTML = `
            <svg viewBox="0 0 24 24" width="32" height="32" fill="none">
                <rect x="2.5" y="4" width="19" height="14" rx="2.5" stroke="currentColor" stroke-width="1.5"/>
                <path d="M6.5 14.5c2-5.5 4-8 5.5-8s1.5 4 5.5 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        `;
        inputWrapper.appendChild(icon);

        // Erstellt und verwaltet werden interaktive Elemente auf eifach.online:
        // dort gibt es Versionen, Dateien und das Design der Schule. MotionBase
        // bettet sie nur ein.
        const hint = document.createElement('p');
        hint.className = 'interactive-block__hint';
        hint.innerHTML =
            'Interaktive Elemente entstehen auf <a href="https://eifach.online" target="_blank" rel="noopener">eifach.online</a>. ' +
            'Dort die Seite bauen und hier ihre Adresse einfügen.';
        inputWrapper.appendChild(hint);

        // URL input
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'interactive-block__input';
        input.placeholder = this.config.placeholder ?? 'https://…eifach.online/meine-seite/';
        input.value = this.data.url ?? '';
        input.disabled = this.readOnly;

        input.addEventListener('paste', () => {
            setTimeout(() => {
                this.handleUrlChange(input.value);
            }, 0);
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.handleUrlChange(input.value);
            }
        });

        input.addEventListener('blur', () => {
            if (input.value) {
                this.handleUrlChange(input.value);
            }
        });

        inputWrapper.appendChild(input);

        this.wrapper.appendChild(inputWrapper);
    }

    // Mirrors InteractiveAssets on the server. Checked here as well because
    private handleUrlChange(url: string) {
        const trimmed = url.trim();

        // Accept absolute http(s) URLs and app-relative paths (e.g. /storage/…)
        if (/^https?:\/\//i.test(trimmed) || trimmed.startsWith('/')) {
            this.data.url = trimmed;
            this.renderPreview();
            this.block?.dispatchChange();
        }
    }

    private renderPreview() {
        if (!this.wrapper || !this.data.url) return;
        this.teardownResizeListener();
        this.wrapper.innerHTML = '';

        const previewWrapper = document.createElement('div');
        previewWrapper.className = 'interactive-block__preview';

        const frameContainer = document.createElement('div');
        frameContainer.className = 'interactive-block__frame';

        const iframe = document.createElement('iframe');
        iframe.src = this.data.url;
        iframe.className = 'interactive-block__iframe';
        iframe.style.height = `${this.clampHeight(this.data.height)}px`;
        iframe.setAttribute('sandbox', sandboxFor(this.data.url));
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('title', this.data.caption || 'Interaktive Grafik');

        this.iframe = iframe;
        this.setupResizeListener();

        frameContainer.appendChild(iframe);
        previewWrapper.appendChild(frameContainer);

        if (!this.readOnly) {
            const controls = document.createElement('div');
            controls.className = 'interactive-block__controls';

            const heightLabel = document.createElement('label');
            heightLabel.className = 'interactive-block__height';
            heightLabel.appendChild(document.createTextNode('Höhe (px)'));

            const heightInput = document.createElement('input');
            heightInput.type = 'number';
            heightInput.min = String(MIN_HEIGHT);
            heightInput.max = String(MAX_HEIGHT);
            heightInput.step = '20';
            heightInput.value = String(this.clampHeight(this.data.height));
            heightInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    heightInput.blur();
                }
            });
            heightInput.addEventListener('change', () => {
                this.data.height = this.clampHeight(Number(heightInput.value));
                heightInput.value = String(this.data.height);
                if (this.iframe) {
                    this.iframe.style.height = `${this.data.height}px`;
                }
                this.block?.dispatchChange();
            });
            heightLabel.appendChild(heightInput);
            controls.appendChild(heightLabel);

            const autoHint = document.createElement('span');
            autoHint.className = 'interactive-block__auto-hint';
            autoHint.textContent = 'Meldet die Grafik ihre Höhe selbst, wird dieser Wert überschrieben.';
            controls.appendChild(autoHint);

            previewWrapper.appendChild(controls);

            // Caption input
            const captionInput = document.createElement('input');
            captionInput.type = 'text';
            captionInput.className = 'interactive-block__caption';
            captionInput.placeholder = 'Beschreibung hinzufügen (optional)…';
            captionInput.value = this.data.caption ?? '';

            const saveCaption = () => {
                this.data.caption = captionInput.value;
                this.block?.dispatchChange();
            };

            captionInput.addEventListener('input', (e) => {
                this.data.caption = (e.target as HTMLInputElement).value;
            });
            captionInput.addEventListener('blur', saveCaption);
            captionInput.addEventListener('change', saveCaption);
            captionInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    captionInput.blur();
                }
            });
            previewWrapper.appendChild(captionInput);

            // Change button
            const changeBtn = document.createElement('button');
            changeBtn.type = 'button';
            changeBtn.className = 'interactive-block__change-btn';
            changeBtn.textContent = 'Grafik ändern';
            changeBtn.addEventListener('click', () => {
                this.data.url = '';
                this.renderInput();
            });
            previewWrapper.appendChild(changeBtn);
        } else if (this.data.caption) {
            const captionText = document.createElement('p');
            captionText.className = 'interactive-block__caption-text';
            captionText.textContent = this.data.caption;
            previewWrapper.appendChild(captionText);
        }

        this.wrapper.appendChild(previewWrapper);
    }

    private clampHeight(value: unknown): number {
        const height = Number(value);

        if (!Number.isFinite(height) || height <= 0) {
            return DEFAULT_HEIGHT;
        }

        return Math.round(Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, height)));
    }

    /**
     * Sandboxed frames have an opaque origin, so event.origin is always "null"
     * and useless for verification — identify the sender by its window instead.
     */
    private setupResizeListener() {
        this.messageHandler = (event: MessageEvent) => {
            if (!this.iframe || event.source !== this.iframe.contentWindow) {
                return;
            }

            const payload = event.data;
            // Eigene Grafiken melden motionbase:resize, Seiten von eifach.online eifach:resize
            if (!payload || (payload.type !== 'motionbase:resize' && payload.type !== 'eifach:resize')) {
                return;
            }

            this.iframe.style.height = `${this.clampHeight(payload.height)}px`;
        };

        window.addEventListener('message', this.messageHandler);
    }

    private teardownResizeListener() {
        if (this.messageHandler) {
            window.removeEventListener('message', this.messageHandler);
            this.messageHandler = null;
        }
        this.iframe = null;
    }

    destroy() {
        this.teardownResizeListener();
    }

    /**
     * Normalises on the way out. Editor.js persists whatever this returns, so a
     * null caption or a lost height would be written to the section as-is and
     * then rendered from there - the block has to be the last line of defence.
     */
    async save(): Promise<InteractiveBlockData> {
        return {
            url: typeof this.data.url === 'string' ? this.data.url.trim() : '',
            caption: typeof this.data.caption === 'string' ? this.data.caption : '',
            height: this.clampHeight(this.data.height),
        };
    }

    validate(data: InteractiveBlockData): boolean {
        return Boolean(data.url);
    }
}
