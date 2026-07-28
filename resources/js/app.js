import Alpine from 'alpinejs';

/*
 * Komponenty se registrují tady, ne v inline <script> v šablonách.
 * Důvod je bezpečnostní: CSP hlavička nepovoluje 'unsafe-inline' pro skripty,
 * takže inline <script> v šabloně prohlížeč zablokuje a komponenta by nefungovala.
 */

/**
 * Náhled faktury (nastavení fakturace i formulář Nová/Upravit faktura) se
 * skládá výhradně na serveru — vrací se hotové HTML stejné Blade šablony,
 * jakou používá i skutečné PDF. Klient jen debounce-uje vstup a HTML vloží
 * do iframu přes srcdoc. Žádná duplicitní vykreslovací logika v JS.
 */
async function fetchPreviewHtml(url, params) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            Accept: 'text/html',
        },
        body: new URLSearchParams(params),
    });

    return response.ok ? response.text() : '';
}

// A4 při 96 DPI — přesná velikost, kterou šablona (viz pdf/invoices/*) fakticky
// vyplní. Iframe se renderuje v týhle skutečné velikosti (žádné zalamování,
// žádné vnitřní scrollbary) a zmenšuje se transformem na šířku sloupce.
const PREVIEW_WIDTH = 794;
const PREVIEW_HEIGHT = 1123;

/**
 * Náhled vykreslený v přirozené velikosti dokumentu a zmenšený transformem
 * na šířku dostupného sloupce — proto se nikdy neobjeví posuvníky, jen
 * zmenšená/zvětšená celá stránka. Sdílené oběma náhledovými komponentami.
 */
function setupPdfPreviewScaling(component) {
    const apply = () => {
        const wrapper = component.$refs.previewWrapper;
        const frame = component.$refs.previewFrame;

        if (!wrapper || !frame) return;

        const scale = wrapper.clientWidth > 0 ? wrapper.clientWidth / PREVIEW_WIDTH : 1;

        frame.style.width = `${PREVIEW_WIDTH}px`;
        frame.style.height = `${PREVIEW_HEIGHT}px`;
        frame.style.transform = `scale(${scale})`;
        frame.style.transformOrigin = 'top left';
        wrapper.style.height = `${PREVIEW_HEIGHT * scale}px`;
    };

    apply();
    new ResizeObserver(apply).observe(component.$refs.previewWrapper);
}

/** Formulář faktury — dynamické položky, živý přepočet a náhled vzhledu vpravo. */
Alpine.data('invoiceForm', (initialItems, vatPayer, meta, previewUrl) => ({
    items: initialItems,
    vatPayer,
    clientId: meta.clientId ?? '',
    bankAccountId: meta.bankAccountId ?? '',
    issueDate: meta.issueDate ?? '',
    dueDate: meta.dueDate ?? '',
    duzp: meta.duzp ?? '',
    paymentMethod: meta.paymentMethod ?? '',
    variableSymbol: meta.variableSymbol ?? '',
    number: meta.number ?? '',
    note: meta.note ?? '',
    type: meta.type ?? 'invoice',
    direction: meta.direction ?? 'issued',

    addItem() {
        this.items.push({ description: '', quantity: '1', unit: '', unit_price: '', vat_rate: '21' });
        this.refresh();
    },

    removeItem(index) {
        this.items.splice(index, 1);
        this.refresh();
    },

    parse(value) {
        const n = parseFloat(String(value ?? '').replace(/\s/g, '').replace(',', '.'));
        return isNaN(n) ? 0 : n;
    },

    lineTotal(item) {
        const base = this.parse(item.quantity) * this.parse(item.unit_price);
        const rate = this.vatPayer ? this.parse(item.vat_rate) : 0;
        return base * (1 + rate / 100);
    },

    subtotal() {
        return this.items.reduce((sum, i) => sum + this.parse(i.quantity) * this.parse(i.unit_price), 0);
    },

    vatTotal() {
        return this.grandTotal() - this.subtotal();
    },

    grandTotal() {
        return this.items.reduce((sum, i) => sum + this.lineTotal(i), 0);
    },

    // jen orientační náhled — závazný výpočet dělá server v bcmath
    formatMoney(value) {
        return value.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Kč';
    },

    init() {
        setupPdfPreviewScaling(this);
        this.refresh();
    },

    async refresh() {
        const params = {
            client_id: this.clientId,
            bank_account_id: this.bankAccountId,
            issue_date: this.issueDate,
            due_date: this.dueDate,
            duzp: this.duzp,
            payment_method: this.paymentMethod,
            variable_symbol: this.variableSymbol,
            number: this.number,
            note: this.note,
            type: this.type,
            direction: this.direction,
        };

        this.items.forEach((item, index) => {
            params[`items[${index}][description]`] = item.description ?? '';
            params[`items[${index}][quantity]`] = item.quantity ?? '';
            params[`items[${index}][unit]`] = item.unit ?? '';
            params[`items[${index}][unit_price]`] = item.unit_price ?? '';
            params[`items[${index}][vat_rate]`] = item.vat_rate ?? '';
        });

        const html = await fetchPreviewHtml(previewUrl, params);

        if (html && this.$refs.previewFrame) {
            this.$refs.previewFrame.srcdoc = html;
        }
    },
}));

/**
 * Náhled na stránce Nastavení → Fakturace — organizace ještě není uložená,
 * náhled běží nad ukázkovým klientem a položkami.
 */
Alpine.data('organizationPreview', (initial, previewUrl) => ({
    name: initial.name ?? '',
    ico: initial.ico ?? '',
    dic: initial.dic ?? '',
    vatPayer: initial.vatPayer ?? false,
    street: initial.street ?? '',
    city: initial.city ?? '',
    zip: initial.zip ?? '',
    email: initial.email ?? '',
    registrationNote: initial.registrationNote ?? '',
    invoiceFooter: initial.invoiceFooter ?? '',
    template: initial.template ?? 'klasik',

    init() {
        setupPdfPreviewScaling(this);
        this.refresh();
    },

    async refresh() {
        const html = await fetchPreviewHtml(previewUrl, {
            name: this.name,
            ico: this.ico,
            dic: this.dic,
            vat_payer: this.vatPayer ? '1' : '0',
            street: this.street,
            city: this.city,
            zip: this.zip,
            email: this.email,
            registration_note: this.registrationNote,
            invoice_footer: this.invoiceFooter,
            invoice_template: this.template,
        });

        if (html && this.$refs.previewFrame) {
            this.$refs.previewFrame.srcdoc = html;
        }
    },
}));

/** Načtení údajů firmy z ARES podle IČO. */
Alpine.data('aresForm', (initialIco, lookupUrl) => ({
    ico: initialIco,
    loading: false,
    error: null,

    async lookup() {
        this.error = null;

        if (!/^\d{8}$/.test(this.ico ?? '')) {
            this.error = 'Zadejte osmimístné IČO.';
            return;
        }

        this.loading = true;

        try {
            const response = await fetch(`${lookupUrl}/${this.ico}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error((await response.json()).message ?? 'Nenalezeno.');
            }

            const data = await response.json();
            this.$refs.name.value = data.name ?? '';
            this.$refs.dic.value = data.dic ?? '';
            this.$refs.street.value = data.street ?? '';
            this.$refs.city.value = data.city ?? '';
            this.$refs.zip.value = data.zip ?? '';
        } catch (e) {
            this.error = e.message;
        } finally {
            this.loading = false;
        }
    },
}));

/**
 * Zadávání záložní fráze do 12 očíslovaných políček.
 * Vložení celé fráze (Ctrl+V) do kteréhokoli políčka ji rozdělí do všech —
 * bere mezery i čárky.
 */
Alpine.data('phraseInput', (wordCount) => ({
    words: Array(wordCount).fill(''),

    get phrase() {
        return this.words.map((w) => w.trim().toLowerCase()).join(' ').trim();
    },

    get filled() {
        return this.words.filter((w) => w.trim() !== '').length;
    },

    get complete() {
        return this.filled === wordCount;
    },

    handlePaste(event, index) {
        const text = (event.clipboardData || window.clipboardData)?.getData('text') ?? '';
        const parts = text.split(/[\s,;]+/).map((w) => w.trim().toLowerCase()).filter(Boolean);

        if (parts.length < 2) {
            return; // jedno slovo → necháme normální vložení
        }

        event.preventDefault();

        parts.slice(0, wordCount - index).forEach((word, i) => {
            this.words[index + i] = word;
        });
    },

    clear() {
        this.words = Array(wordCount).fill('');
    },
}));

/** Zkopírování záložní fráze do schránky. */
Alpine.data('recoveryPhrase', (phrase) => ({
    copied: false,

    copy() {
        navigator.clipboard.writeText(phrase);
        this.copied = true;
        setTimeout(() => { this.copied = false; }, 2000);
    },

    print() {
        window.print();
    },
}));

/**
 * Přepínač scénářů slev na stránce Daně.
 *
 * Nepočítá nic — všechny kombinace spočítal server v bcmath a jen se mezi nimi
 * přepíná. Kdyby daňovou logiku uměl i JavaScript, měli bychom dvě verze
 * pravdy, které se dřív nebo později rozejdou.
 */
Alpine.data('taxScenarios', (scenarios, initial) => ({
    children: initial.children,
    spouse: initial.spouse,

    get key() {
        if (this.children && this.spouse) return 'both';
        if (this.children) return 'children';
        if (this.spouse) return 'spouse';
        return 'none';
    },

    get current() {
        return scenarios[this.key].result;
    },

    money(value) {
        return Math.round(value).toLocaleString('cs-CZ') + ' Kč';
    },
}));

/**
 * Trvalé smazání faktury — potvrzení opsáním čísla dokladu, aby nešlo
 * omylem smazat platný doklad jedním kliknutím. Tlačítko se odemkne, až
 * se opsané číslo přesně shoduje (server to při odeslání ověří znovu).
 */
Alpine.data('forceDeleteInvoice', (expected, startOpen) => ({
    value: '',
    open: startOpen,

    get matches() {
        return this.value.trim() === expected;
    },
}));

/**
 * Globální vyhledávání v horní liště — faktury podle čísla/VS, klienti podle
 * názvu/IČO/e-mailu/města. Debounce 250 ms, ať se nefetchuje na každý úhoz.
 */
Alpine.data('globalSearch', (searchUrl) => ({
    query: '',
    open: false,
    loading: false,
    results: { invoices: [], clients: [] },
    timer: null,

    get hasResults() {
        return this.results.invoices.length > 0 || this.results.clients.length > 0;
    },

    onInput() {
        clearTimeout(this.timer);

        if (this.query.trim().length < 2) {
            this.results = { invoices: [], clients: [] };
            this.open = false;
            return;
        }

        this.timer = setTimeout(() => this.run(), 250);
    },

    async run() {
        this.loading = true;
        this.open = true;

        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(this.query.trim())}`, {
                headers: { Accept: 'application/json' },
            });
            this.results = response.ok ? await response.json() : { invoices: [], clients: [] };
        } finally {
            this.loading = false;
        }
    },

    close() {
        this.open = false;
    },
}));

/**
 * Ruční přepínač světlý/tmavý režim. Počáteční třídu na <html> nastavuje
 * public/theme-init.js (běží ještě před vykreslením, ať motiv nebliká) —
 * tahle komponenta jen doplňuje přepínání a ukládání volby.
 */
Alpine.data('themeToggle', () => ({
    dark: document.documentElement.classList.contains('dark'),

    toggle() {
        this.dark = !this.dark;
        document.documentElement.classList.toggle('dark', this.dark);
        localStorage.setItem('ucetni-prehled-theme', this.dark ? 'dark' : 'light');
    },
}));

/*
 * Potvrzení u destruktivních akcí.
 *
 * Dřív to řešil inline onsubmit="return confirm(…)", jenže ten CSP blokuje —
 * handler se nespustil a formulář se odeslal BEZ zeptání. Řešíme jedním
 * delegovaným posluchačem; šablona jen přidá data-confirm="Otázka?".
 */
document.addEventListener('submit', (event) => {
    const message = event.target?.dataset?.confirm;

    if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

window.Alpine = Alpine;
Alpine.start();
