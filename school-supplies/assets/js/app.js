// Live filter: <input data-filter="containerId"> shows only the product packages that match what's typed and the page's
// <select data-view="category|status|sort"> choices ('*' = all), sorts what is left (inside each category group when grouped),
// and hides empty category groups. Packs carry what they are sorted and filtered on as data-* attributes (see pack.php).
document.querySelectorAll('[data-filter]').forEach(input => {
    const box = document.getElementById(input.dataset.filter);
    const views = document.querySelectorAll('[data-view]');
    const packs = [...box.querySelectorAll('.pack')];
    const added = new Map(packs.map((pack, i) => [pack, i]));   // the order they came in: "order added", and the tie-break for every sort
    const text = (a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    const byName = (a, b) => text(a.name, b.name);
    const sorts = {
        name: byName,
        name_desc: (a, b) => byName(b, a),
        stock: (a, b) => a.stock - b.stock,
        stock_desc: (a, b) => b.stock - a.stock,
        category: (a, b) => (a.category === '') - (b.category === '') || text(a.category, b.category) || byName(a, b),   // no category last
        id: (a, b) => text(a.id, b.id),
    };

    const apply = () => {
        const view = Object.fromEntries([...views].map(v => [v.dataset.view, v.value]));
        const query = input.value.toLowerCase();
        const only = (key, value) => (view[key] ?? '*') === '*' || view[key] === value;
        packs.forEach(pack => {
            pack.hidden = !(pack.textContent.toLowerCase().includes(query) && only('category', pack.dataset.category) && only('status', pack.dataset.status));
        });
        box.querySelectorAll('.packs').forEach(list => {
            [...list.children].sort((a, b) => sorts[view.sort]?.(a.dataset, b.dataset) || added.get(a) - added.get(b)).forEach(pack => list.append(pack));
        });
        box.querySelectorAll('.group').forEach(group => {
            group.hidden = !group.querySelector('.pack:not([hidden])');
        });
        const none = box.querySelector('[data-none]');
        if (none) none.hidden = packs.some(pack => !pack.hidden);
    };
    input.addEventListener('input', apply);
    views.forEach(v => v.addEventListener('change', apply));
});

// Edit popup: <button data-edit> inside a .pack fills the dialog from the pack's data-* attributes.
const dialog = document.getElementById('product-dialog');
document.querySelectorAll('[data-edit]').forEach(button => {
    button.addEventListener('click', () => {
        const pack = button.closest('.pack').dataset;
        const fields = dialog.querySelector('form').elements;
        fields.product_id.value = pack.id;
        fields.name.value = pack.name;
        fields.category.value = pack.category;
        fields.reorder_level.value = pack.reorder;
        dialog.querySelector('[name=delete_id]').value = pack.id;
        dialog.showModal();
    });
});

// Ask before submitting any <form data-confirm="message">. Listens on the document, so it also covers panels that Live updates redraw.
document.addEventListener('submit', event => {
    const message = event.target.dataset?.confirm;
    if (message && !confirm(message)) event.preventDefault();
});

// Movement history tabs: clicking a day tab shows that day's panel.
document.querySelectorAll('.day-tabs').forEach(tabs => {
    tabs.addEventListener('click', event => {
        const tab = event.target.closest('[data-day]');
        if (!tab) return;
        tabs.querySelectorAll('[data-day]').forEach(t => t.classList.toggle('active', t === tab));
        document.querySelectorAll('[data-panel]').forEach(p => p.hidden = p.dataset.panel !== tab.dataset.day);
    });
});

// Stock in/out and Add product popups: open from their buttons, or on load when the server asks (data-open).
document.getElementById('open-stock')?.addEventListener('click', () => document.getElementById('stock-dialog').showModal());
document.getElementById('open-add')?.addEventListener('click', () => document.getElementById('add-dialog').showModal());
document.querySelectorAll('dialog[data-open]').forEach(d => d.showModal());

// Reset-password popup (account page, super admin): <button data-reset> opens it for that account. Listens on the document and looks the
// popup up each time, because Live updates redraw the Accounts panel it sits in.
document.addEventListener('click', event => {
    const button = event.target.closest('[data-reset]');
    if (!button) return;
    const resetDialog = document.getElementById('reset-dialog');
    const fields = resetDialog.querySelector('form').elements;
    fields.id.value = button.dataset.reset;
    fields.new.value = '';
    resetDialog.querySelector('[data-name]').textContent = button.dataset.name;
    resetDialog.showModal();
});

// QR popup (dashboard only, where the QR library is loaded): the products added so far go to qr.php, which returns them
// compressed and encrypted as text; the code is drawn and offered as a PNG.
const qrDialog = document.getElementById('qr-dialog');
document.getElementById('open-qr')?.addEventListener('click', () => qrDialog.showModal());
if (qrDialog) {
    const form = qrDialog.querySelector('form');
    const result = qrDialog.querySelector('.qr-result');
    const canvas = result.querySelector('canvas');
    const box = form.querySelector('.qr-list-box');
    const inputs = ['id', 'name', 'category', 'stock'].map(n => form.elements[n]);
    const item = document.getElementById('qr-item');
    const records = [];   // [code, name, category, stock] per product added; kept when the popup closes, so an accidental Esc loses nothing
    const show = done => { form.hidden = done; result.hidden = !done; };

    const render = () => {
        box.querySelector('.qr-list').replaceChildren(...records.map((r, i) => {
            const li = item.content.firstElementChild.cloneNode(true);
            const [name, code, more] = li.querySelectorAll('strong, .code, .more');
            [code.textContent, name.textContent] = r;
            more.textContent = [r[2], r[3] && `+${r[3]} stock`].filter(Boolean).map(s => ' · ' + s).join('');
            li.lastElementChild.onclick = () => { records.splice(i, 1); render(); };
            return li;
        }));
        box.querySelector('[data-clear]').hidden = !records.length;
        box.querySelector('[data-count]').textContent = `Queue (${records.length})`;
    };
    box.querySelector('[data-clear]').onclick = () => { records.length = 0; render(); };
    render();

    form.addEventListener('submit', async event => {
        if (event.submitter?.formMethod === 'dialog') return;   // Cancel
        event.preventDefault();
        const fields = inputs.map(i => i.value.trim());
        const single = form.elements.mode.value === 'single';
        let list = records;
        if (single) {   // the typed product alone; the queue is left as it is
            if (!form.reportValidity()) return;
            list = [fields];
        } else if (fields.some(Boolean) || event.submitter?.value === 'add') {   // a product is typed in: both buttons add it, so Create QR code never leaves it out
            if (!form.reportValidity()) return;
            if (records.some(r => r[0] === fields[0] || r[1].toLowerCase() === fields[1].toLowerCase())) return alert('This code or name is already in the queue.');
            records.push(fields);
            inputs.forEach(i => i.value = '');
            render();
            inputs[0].focus();
        }
        if (!single && event.submitter?.value !== 'make') return;
        if (!list.length) return alert('Add at least one product first.');

        const response = await fetch('qr.php', { method: 'POST', body: new URLSearchParams({ records: JSON.stringify(list) }) });
        const text = await response.text();
        if (!response.ok) return alert(text);

        let qr;
        for (const level of ['M', 'L']) {   // M reads more reliably; L only when the products don't fit in M
            try {
                qr = qrcode(0, level);
                qr.addData(text, 'Alphanumeric');
                qr.make();
                break;
            } catch { qr = null; }
        }
        if (!qr) return alert(`Too many products for one QR code (${list.length}). Remove some and make a second code for them.`);

        const cell = 10, quiet = 4 * cell, size = qr.getModuleCount() * cell + 2 * quiet;
        canvas.width = canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, size, size);
        ctx.translate(quiet, quiet);
        qr.renderTo2dContext(ctx, cell);
        ctx.setTransform(1, 0, 0, 1, 0, 0);

        const one = list.length === 1;
        result.querySelector('.hint').textContent = one ? `${list[0][0]} - ${list[0][1]}` : `${list.length} products`;
        const save = result.querySelector('a');
        save.href = canvas.toDataURL('image/png');
        save.download = `qr-${one ? list[0][0] : list.length + '-products'}.png`;
        show(true);
    });
    result.querySelector('[data-back]').addEventListener('click', () => show(false));
    qrDialog.addEventListener('close', () => { show(false); inputs.forEach(i => i.value = ''); });   // the mode and the queue stay
}

// Scan popup (dashboard only, where ZXing is loaded): reads a QR code from the camera or a picture, scan.php checks it,
// and the popup lists every product in it so they can be verified before any stock is added.
const scanDialog = document.getElementById('scan-dialog');
document.getElementById('open-scan')?.addEventListener('click', () => scanDialog.showModal());
if (scanDialog) {
    const source = scanDialog.querySelector('[data-source]');
    const problem = source.querySelector('.alert');
    const video = source.querySelector('video');
    const file = source.querySelector('input[type=file]');
    const verify = scanDialog.querySelector('[data-verify]');
    const items = verify.querySelector('[data-items]');
    const item = document.getElementById('scan-item');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const cat = c => c ? `“${c}”` : 'no category';
    let stream;
    ZXingWASM.setZXingModuleOverrides({ locateFile: (path, prefix) => path.endsWith('.wasm') ? `lib/zxing/${path}` : prefix + path });   // the .wasm is served from here, not a CDN

    const fail = message => { problem.textContent = message; problem.hidden = false; };
    const stopCamera = () => { stream?.getTracks().forEach(t => t.stop()); stream = null; video.hidden = true; };

    // The text of the QR code in a picture or video frame, or undefined. ZXing copes with the tilt, blur, screen moire and noise of a
    // camera photo at full size; only a photo over 4096 px on its longest side is shrunk (a 50 MP one would need ~200 MB of pixels).
    const read = async (picture, width, height) => {
        const scale = Math.min(1, 4096 / Math.max(width, height));
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        ctx.drawImage(picture, 0, 0, canvas.width, canvas.height);
        const codes = await ZXingWASM.readBarcodes(ctx.getImageData(0, 0, canvas.width, canvas.height), { formats: ['QRCode'], maxNumberOfSymbols: 1 });
        return codes.find(code => code.isValid)?.text;
    };

    // Fills the verify step with one block per product. What the QR code holds is shown as text; only what it leaves out
    // (and a new product's optional restock level) is a field. Fields are named item[position][field] for scan.php.
    const show = (list, text) => {
        verify.elements.text.value = text;
        items.replaceChildren(...list.map((s, i) => {
            const el = item.content.firstElementChild.cloneNode(true);
            const q = selector => el.querySelector(selector);
            const isNew = s.state === 'new', clash = s.state === 'category', critical = s.state === 'critical';

            q('[data-name]').textContent = s.name;
            const tag = { new: ['New', 'ok'], category: ['Category differs', 'low'], critical: ['Problem', 'out'] }[s.state];
            const badge = q('[data-badge]');
            badge.hidden = !tag;
            if (tag) {
                badge.textContent = tag[0];
                badge.classList.add(`st-${tag[1]}`);
            }
            q('[data-info]').textContent = [s.id, s.category, s.stock !== null && `+${s.stock} to add`, s.inventory && `${s.inventory.quantity} in stock now`]
                .filter(Boolean).join(' · ');
            q('[data-problem]').hidden = !critical;
            q('[data-problem]').textContent = s.problem;
            el.disabled = critical;   // a disabled fieldset submits nothing

            const need = { stock: !critical && s.stock === null, category: isNew && !s.category, reorder: isNew };
            el.querySelectorAll('[data-for]').forEach(label => {
                const input = label.querySelector('input');
                input.name = `item[${i}][${label.dataset.for}]`;
                label.hidden = input.disabled = !need[label.dataset.for];
            });

            const conflict = q('[data-conflict]');
            conflict.hidden = !clash;
            conflict.querySelectorAll('input').forEach(radio => { radio.name = `item[${i}][choice]`; radio.disabled = !clash; });
            if (clash) {
                const [keep, overwrite] = conflict.querySelectorAll('span');
                conflict.querySelector('p').textContent = `The category on the QR code (${cat(s.category)}) is not the one in the inventory (${cat(s.inventory.category)}). What should happen?`;
                keep.textContent = `Keep the inventory's category (${cat(s.inventory.category)}).`;
                overwrite.textContent = `Overwrite it with the QR code's category (${cat(s.category)}).`;
            }
            return el;
        }));

        const count = state => list.filter(s => s.state === state).length;
        const skipped = count('critical');
        verify.querySelector('[data-summary]').textContent = `${list.length} ${list.length === 1 ? 'product' : 'products'} in this code: `
            + `${count('new')} new, ${count('match') + count('category')} already in the inventory${skipped ? `, ${skipped} skipped` : ''}.`;
        verify.querySelector('[data-critical]').hidden = !skipped;
        verify.querySelector('[data-accept]').hidden = skipped === list.length;
        source.hidden = true;
        verify.hidden = false;
    };

    // A code was read: scan.php accepts only untouched codes made by this app.
    const found = async text => {
        stopCamera();
        const response = await fetch('scan.php', { method: 'POST', body: new URLSearchParams({ text }) });
        const body = await response.text();
        if (!response.ok) return fail(body);
        show(JSON.parse(body), text);
    };

    source.querySelector('[data-camera]').addEventListener('click', async () => {
        problem.hidden = true;
        stopCamera();
        if (!window.isSecureContext) {   // browsers hide the camera from plain http:// pages (localhost excepted), which is what a phone gets from a PC's address
            return fail('Browsers only allow the live camera on https:// pages (or localhost), and this page is neither. Take a photo or choose an image instead, or open this site over https.');
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } } });
        } catch {   // no camera, permission refused or camera busy
            return fail('Could not open the camera. Check that one is connected, that this site may use it and that no other app is using it, or use a picture instead.');
        }
        video.srcObject = stream;
        video.hidden = false;
        video.play().catch(() => {});   // some phones ignore autoplay

        const tick = async () => {
            if (!stream) return;   // stopped: popup closed, picture chosen, or a code was read
            const text = video.videoWidth ? await read(video, video.videoWidth, video.videoHeight) : undefined;
            if (!stream) return;
            text ? found(text) : setTimeout(tick, 100);
        };
        tick();
    });

    // "Take photo" opens the phone's camera app straight away (capture); "Choose image" opens the picture chooser.
    const pick = capture => {
        capture ? file.setAttribute('capture', 'environment') : file.removeAttribute('capture');
        file.click();
    };
    source.querySelector('[data-photo]').addEventListener('click', () => pick(true));
    source.querySelector('[data-image]').addEventListener('click', () => pick(false));
    file.addEventListener('change', async () => {
        if (!file.files[0]) return;   // the chooser was cancelled
        problem.hidden = true;
        stopCamera();
        const picture = await createImageBitmap(file.files[0]).catch(() => null);
        file.value = '';   // so picking the same file again still counts
        if (!picture) return fail('That file could not be read as an image.');
        const text = await read(picture, picture.width, picture.height);
        picture.close();
        text ? found(text) : fail('No QR code found in that picture. Move closer, hold still and fill the frame with the code.');
    });

    scanDialog.addEventListener('close', () => {
        stopCamera();
        problem.hidden = true;
        source.hidden = false;
        verify.hidden = true;
        verify.reset();
        items.replaceChildren();
    });
}

// Month/year chooser shared by both calendars: draws into `picker`; picking a month calls done('Y-m').
// `active` lists the 'Y-m' months that have movements, `current` is the 'Y-m' now shown.
function chooseMonth(picker, active, current, done) {
    const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const now = Number(current.slice(0, 4));
    let view = 'months', year = now, page = 0;  // page = first year of the year grid

    const draw = () => {
        const months = view === 'months';
        const items = Array.from({ length: 12 }, (_, i) => {
            const key = months ? `${year}-${String(i + 1).padStart(2, '0')}` : page + i;
            return {
                key,
                label: months ? names[i] : key,
                on: months ? active.includes(key) : active.some(m => m.startsWith(key + '-')),
                cur: months ? key === current : key === now,
            };
        });
        picker.innerHTML =
            `<div class="cal-head"><button type="button" class="cal-nav" data-step="-1">‹</button>` +
            `<button type="button" class="cal-title" data-up>${months ? year : `${page}–${page + 11}`}</button>` +
            `<button type="button" class="cal-nav" data-step="1">›</button></div>` +
            `<div class="cal-choices">${items.map(i =>
                `<button type="button" class="cal-choice${i.on ? ' on' : ''}${i.cur ? ' cur' : ''}" data-pick="${i.key}">${i.label}</button>`
            ).join('')}</div>`;
    };

    picker.onclick = event => {
        const button = event.target.closest('button');
        if (!button) return;
        if ('step' in button.dataset) {
            if (view === 'months') year += Number(button.dataset.step); else page += 12 * button.dataset.step;
        } else if ('up' in button.dataset) {
            if (view === 'months') { view = 'years'; page = year - (year % 12); }
        } else if (view === 'months') {
            return done(button.dataset.pick);
        } else {
            year = Number(button.dataset.pick);
            view = 'months';
        }
        draw();
    };
    draw();
}

// History calendar: the month title opens a month grid, its year opens a year grid; picking a month loads it.
const cal = document.querySelector('.cal');
cal?.querySelector('.cal-title').addEventListener('click', () => {
    const picker = cal.querySelector('.cal-picker');
    cal.querySelector('.cal-view').hidden = true;
    picker.hidden = false;
    chooseMonth(picker, cal.dataset.active.split(','), cal.dataset.month, m => location.href = `history.php?m=${m}&d=${cal.dataset.day}`);
});

// Report popup: opens from the Reports button; "specific days" swaps the date range for a multi-select calendar.
const reportDialog = document.getElementById('report-dialog');
document.getElementById('open-report')?.addEventListener('click', () => reportDialog.showModal());
if (reportDialog) {
    const form = reportDialog.querySelector('form');
    const advanced = form.querySelector('details');
    const byDays = form.querySelector('#by-days');
    const range = form.querySelector('.row2');
    const pick = form.querySelector('.day-pick');
    const view = pick.querySelector('.dp-view');
    const chooser = pick.querySelector('.cal-picker');
    const grid = pick.querySelector('.cal-grid');
    const note = pick.querySelector('.dp-count');
    const counts = JSON.parse(pick.dataset.days);   // day => number of movements, newest first
    const active = Object.keys(counts);
    const chosen = new Set();
    const now = new Date();
    const start = active.length ? active[0].split('-').map(Number) : [now.getFullYear(), now.getMonth() + 1];
    let [year, month] = [start[0], start[1] - 1];
    const pad = n => String(n).padStart(2, '0');

    const draw = () => {
        const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        const cells = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => `<b>${d}</b>`);
        for (let i = new Date(year, month, 1).getDay(); i > 0; i--) cells.push('<span></span>');
        for (let d = 1, last = new Date(year, month + 1, 0).getDate(); d <= last; d++) {
            const key = `${year}-${pad(month + 1)}-${pad(d)}`;
            const cls = `cal-day${key === today ? ' today' : ''}`;
            cells.push(counts[key]
                ? `<button type="button" class="${cls} has${chosen.has(key) ? ' on' : ''}" data-key="${key}" title="${counts[key]} movements">${d}<small>${counts[key]}</small></button>`
                : `<span class="${cls}">${d}</span>`);
        }
        grid.innerHTML = cells.join('');
        pick.querySelector('.cal-title').textContent = new Date(year, month, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
        note.textContent = chosen.size ? `${chosen.size} ${chosen.size === 1 ? 'day' : 'days'} selected` : 'Click the highlighted days to include them';
    };

    view.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.classList.contains('cal-title')) {
            view.hidden = true;
            chooser.hidden = false;
            chooseMonth(chooser, [...new Set(active.map(d => d.slice(0, 7)))], `${year}-${pad(month + 1)}`, m => {
                [year, month] = [Number(m.slice(0, 4)), Number(m.slice(5)) - 1];
                chooser.hidden = true;
                view.hidden = false;
                draw();
            });
            return;
        }
        if (button.dataset.nav) {
            const d = new Date(year, month + Number(button.dataset.nav), 1);
            [year, month] = [d.getFullYear(), d.getMonth()];
        } else if ('today' in button.dataset) {
            [year, month] = [now.getFullYear(), now.getMonth()];
        } else if (chosen.has(button.dataset.key)) {
            chosen.delete(button.dataset.key);
        } else {
            chosen.add(button.dataset.key);
        }
        draw();
    });
    byDays.addEventListener('change', () => {
        range.hidden = byDays.checked;
        pick.hidden = !byDays.checked;
        range.querySelectorAll('input').forEach(input => input.disabled = byDays.checked);
        chooser.hidden = true;
        view.hidden = false;
        draw();
    });

    // Product search: hides the products whose name or code doesn't contain what's typed (same rule as the Products page).
    // Ticked products stay ticked and are still included, so the hint counts them.
    const prodSearch = form.querySelector('#prod-search');
    const prodList = form.querySelector('#prod-list');
    const prodRows = [...prodList.querySelectorAll('label')];
    prodSearch.addEventListener('input', () => {
        const query = prodSearch.value.toLowerCase();
        prodRows.forEach(row => row.hidden = !row.textContent.toLowerCase().includes(query));
        prodList.querySelector('[data-none]').hidden = prodRows.some(row => !row.hidden);
    });
    prodSearch.addEventListener('keydown', event => event.key === 'Enter' && event.preventDefault());   // Enter would otherwise submit the form
    prodList.addEventListener('change', () => {
        const n = prodList.querySelectorAll(':checked').length;
        form.querySelector('#prod-hint').textContent = n ? `Products (${n} selected)` : 'Products (none selected = all)';
    });

    // Paper size: "Custom size" shows width, height and unit. Each side is held between the limits (in mm), which are shown in the chosen unit.
    const paper = form.querySelector('[name=paper]');
    const custom = form.querySelector('[data-custom]');
    const unit = custom.querySelector('[name=unit]');
    const limits = JSON.parse(custom.dataset.limits);
    const syncPaper = () => {
        const [lo, hi] = limits.map(mm => mm / unit.selectedOptions[0].dataset.mm);
        custom.hidden = paper.value !== 'custom';
        custom.querySelectorAll('input, select').forEach(field => field.disabled = custom.hidden);
        custom.querySelectorAll('input').forEach(input => {
            input.min = Math.ceil(lo * 100) / 100;
            input.max = Math.floor(hi * 100) / 100;
        });
        custom.querySelector('.hint').textContent = `Each side: ${Math.ceil(lo * 100) / 100} to ${Math.floor(hi * 100) / 100} ${unit.value}`;
    };
    paper.addEventListener('change', syncPaper);
    unit.addEventListener('change', syncPaper);
    syncPaper();

    // Advanced options only count while open: closing ignores them (they are kept for next time) and drops back to the date range.
    const sync = () => {
        advanced.querySelectorAll('input, select').forEach(field => field.disabled = !advanced.open);
        if (!advanced.open && byDays.checked) {
            byDays.checked = false;
            byDays.dispatchEvent(new Event('change'));
        }
    };
    advanced.addEventListener('toggle', sync);
    sync();

    form.addEventListener('submit', async event => {
        if (event.submitter?.formMethod === 'dialog') return;   // Cancel
        if (byDays.checked && !chosen.size) {
            event.preventDefault();
            note.textContent = 'Pick at least one day';
            return;
        }
        pick.querySelector('[name=days]').value = byDays.checked ? [...chosen].sort().join(',') : '';
        if (!window.showSaveFilePicker) {   // Firefox/Safari can't tell Save from Cancel: the form just opens the PDF in a new tab
            setTimeout(() => reportDialog.close());
            return;
        }

        // Chrome/Edge: build the PDF, ask where to save it, and open it in a new tab only if it was saved.
        event.preventDefault();
        const response = await fetch('report.php?' + new URLSearchParams(new FormData(form)));
        if (!response.ok) return alert('Could not create the PDF.');
        const pdf = await response.blob();
        let file;
        try {
            file = await showSaveFilePicker({
                suggestedName: `stock-report-${new Date().toLocaleDateString('en-CA')}.pdf`,
                types: [{ description: 'PDF', accept: { 'application/pdf': ['.pdf'] } }],
            });
        } catch (error) {   // Cancel just closes the popup
            if (error.name !== 'AbortError') alert(error.message);
            return reportDialog.close();
        }
        const out = await file.createWritable();
        await out.write(pdf);
        await out.close();
        reportDialog.close();
        window.open(URL.createObjectURL(pdf)) || alert('Saved. Allow pop-ups for this site so the PDF opens in a new tab.');
    });
}

// Live updates (every signed-in page). poll.php is asked what changed for this account; the answer is acted on:
//  - its role changed: the page is drawn again with the new buttons;  - its session ended (banned, deleted): off to the sign-in page;
//  - super admin: the number of waiting requests on the account button and in the tab title;
//  - account page: the [data-live] panels are swapped for fresh HTML when their version (data-v) is out of date. A panel is left alone
//    while someone is typing or choosing in it or a popup is open, and is swapped on a later poll.
// When to ask: at once when the push server sends a message over the WebSocket (core/Push.php, ws-server.php), and otherwise every 5
// seconds (15 in a background tab). With the WebSocket connected that timer only backs it up, once a minute. If /ws cannot be reached
// (push server down, Apache without the proxy), the page just keeps polling, and tries the WebSocket again with growing pauses.
if (document.body.dataset.role) {
    const baseTitle = document.title.replace(/^\(\d+\) /, '');
    const account = document.querySelector('.sidebar .account');
    const badge = account?.querySelector('.count');
    let token = document.body.dataset.push, socket = null, timer, running = false, again = false;

    const poll = async () => {
        if (running) return void (again = true);   // a push arrived while asking: ask once more afterwards
        running = true;
        clearTimeout(timer);
        try {
            const query = new URLSearchParams();
            document.querySelectorAll('[data-live]').forEach(panel => query.set(`v[${panel.dataset.live}]`, panel.dataset.v));
            const response = await fetch('poll.php?' + query, { cache: 'no-store' });
            if (response.status === 401) return location.replace('login.php?ended');
            const data = await response.json();
            if (data.role !== document.body.dataset.role) return location.replace(location.pathname + location.search);
            token = data.push ?? token;
            if (badge) {
                badge.hidden = !data.pending;
                badge.textContent = data.pending;
                account.href = data.pending ? 'account.php#mailbox' : 'account.php';
                account.title = data.pending ? `${data.pending} access request(s) waiting` : 'Your account';
                document.title = (data.pending ? `(${data.pending}) ` : '') + baseTitle;
            }
            for (const [name, html] of Object.entries(data.panels ?? {})) {
                const panel = document.querySelector(`[data-live="${name}"]`);
                const field = document.activeElement;
                const typing = panel?.contains(field) && /^(INPUT|SELECT|TEXTAREA)$/.test(field.tagName);
                if (panel && !typing && !document.querySelector('dialog[open]')) panel.outerHTML = html;
            }
        } catch {}   // offline or the server restarting: try again next time
        running = false;
        timer = setTimeout(poll, again ? 1000 : socket ? 60000 : document.hidden ? 15000 : 5000);   // pushes are answered at most once a second
        again = false;
    };

    let pause = 5000;
    const connect = () => {
        if (!token || !('WebSocket' in window)) return;
        const ws = new WebSocket(new URL('ws', location.href).href.replace(/^http/, 'ws'));
        ws.onopen = () => ws.send(JSON.stringify({ t: token }));
        ws.onmessage = event => {
            if (event.data === 'ok') {   // the server knows who we are: from now on pushes arrive, and the timer is only a backup
                socket = ws;
                pause = 5000;
            }
            poll();
        };
        ws.onclose = () => {
            if (socket === ws) {
                socket = null;
                poll();   // back to the 5 second timer at once
            }
            setTimeout(connect, pause = Math.min(pause * 2, 60000));
        };
    };

    timer = setTimeout(poll, 5000);
    connect();
}
