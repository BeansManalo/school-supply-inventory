// Live filter: <input data-filter="containerId"> hides product packages (and empty category groups) that don't match what's typed.
document.querySelectorAll('[data-filter]').forEach(input => {
    input.addEventListener('input', () => {
        const box = document.getElementById(input.dataset.filter);
        const query = input.value.toLowerCase();
        box.querySelectorAll('.pack').forEach(pack => {
            pack.hidden = !pack.textContent.toLowerCase().includes(query);
        });
        box.querySelectorAll('.group').forEach(group => {
            group.hidden = !group.querySelector('.pack:not([hidden])');
        });
    });
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

// Ask before submitting any <form data-confirm="message">.
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
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

// Stock in/out popup: opens from its toolbar button, or on load when the server asks (data-open).
document.getElementById('open-stock')?.addEventListener('click', () => document.getElementById('stock-dialog').showModal());
document.querySelectorAll('dialog[data-open]').forEach(d => d.showModal());

// Manager popup (sidebar button on every page).
document.getElementById('open-manager').addEventListener('click', () => document.getElementById('manager-dialog').showModal());

// QR popup (dashboard only, where the QR library is loaded): qr.php signs the fields, then the code is drawn and offered as a PNG.
const qrDialog = document.getElementById('qr-dialog');
document.getElementById('open-qr')?.addEventListener('click', () => qrDialog.showModal());
if (qrDialog) {
    qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];   // names may hold non-ASCII text
    const form = qrDialog.querySelector('form');
    const result = qrDialog.querySelector('.qr-result');
    const canvas = result.querySelector('canvas');
    const show = done => { form.hidden = done; result.hidden = !done; };

    form.addEventListener('submit', async event => {
        if (event.submitter?.formMethod === 'dialog') return;   // Cancel
        event.preventDefault();
        const response = await fetch('qr.php', { method: 'POST', body: new FormData(form) });
        const text = await response.text();
        if (!response.ok) return alert(text);

        const qr = qrcode(0, 'M');
        qr.addData(text);
        qr.make();
        const cell = 10, quiet = 4 * cell, size = qr.getModuleCount() * cell + 2 * quiet;
        canvas.width = canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, size, size);
        ctx.translate(quiet, quiet);
        qr.renderTo2dContext(ctx, cell);
        ctx.setTransform(1, 0, 0, 1, 0, 0);

        const { id, name } = form.elements;
        result.querySelector('.hint').textContent = `${id.value.trim()} - ${name.value.trim()}`;
        const save = result.querySelector('a');
        save.href = canvas.toDataURL('image/png');
        save.download = `qr-${id.value.trim()}.png`;
        show(true);
    });
    result.querySelector('[data-back]').addEventListener('click', () => show(false));
    qrDialog.addEventListener('close', () => { show(false); form.reset(); });
}

// Scan popup (dashboard only, where jsQR is loaded): reads a QR code from the camera or an image, scan.php checks it,
// and the popup shows what was read so it can be verified before its stock is added.
const scanDialog = document.getElementById('scan-dialog');
document.getElementById('open-scan')?.addEventListener('click', () => scanDialog.showModal());
if (scanDialog) {
    const source = scanDialog.querySelector('[data-source]');
    const problem = source.querySelector('.alert');
    const video = source.querySelector('video');
    const file = source.querySelector('input[type=file]');
    const verify = scanDialog.querySelector('[data-verify]');
    const conflict = verify.querySelector('[data-conflict]');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const cat = c => c ? `“${c}”` : 'no category';
    let stream, scan;

    const fail = message => { problem.textContent = message; problem.hidden = false; };
    const stopCamera = () => { stream?.getTracks().forEach(t => t.stop()); stream = null; video.hidden = true; };

    // The text of the QR code in a picture, or undefined. Big pictures are shrunk to `max` pixels so reading stays quick.
    const read = (picture, width, height, max) => {
        const scale = Math.min(1, max / Math.max(width, height));
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        ctx.drawImage(picture, 0, 0, canvas.width, canvas.height);
        const { data } = ctx.getImageData(0, 0, canvas.width, canvas.height);
        return jsQR(data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' })?.data;
    };

    // Fills the verify step. Whatever the QR code holds is locked; the rest is for the user to fill in.
    const show = (s, text) => {
        scan = s;
        verify.reset();
        const isNew = s.state === 'new', critical = s.state === 'critical', isConflict = s.state === 'category';
        const qr = 'from the QR code';
        const set = (name, value, locked, hint) => {
            const field = verify.elements[name];
            field.value = value ?? '';
            field.readOnly = locked;
            field.labels[0].querySelector('.hint').textContent = `(${hint})`;
        };
        verify.elements.text.value = text;
        set('id', s.id, true, qr);
        set('name', s.name, true, qr);
        set('category', s.category || (isNew ? '' : s.inventory.category), s.category !== '' || !isNew,
            s.category ? qr : isNew ? 'not on the QR code; optional' : 'from the inventory');
        set('stock', s.stock, s.stock !== null, s.stock === null ? 'not on the QR code' : qr);
        verify.elements.reorder_level.disabled = verify.querySelector('[data-reorder]').hidden = !isNew;   // only a new product needs one

        verify.querySelector('[data-status]').textContent = critical ? ''
            : isNew ? 'New product: it will be added to the inventory.'
            : `In the inventory now: ${s.inventory.quantity} in stock. The stock below will be added.`;
        verify.querySelector('[data-critical]').hidden = !critical;
        verify.querySelector('[data-problem]').textContent = s.problem;
        verify.querySelector('[data-fields]').disabled = critical;
        verify.querySelector('[data-accept]').hidden = critical;
        conflict.hidden = conflict.disabled = !isConflict;
        if (isConflict) {
            const [keep, overwrite] = conflict.querySelectorAll('span');
            conflict.querySelector('p').textContent = `The category on the QR code (${cat(s.category)}) is not the one in the inventory (${cat(s.inventory.category)}). What should happen?`;
            keep.textContent = `Keep the inventory's category (${cat(s.inventory.category)}).`;
            overwrite.textContent = `Overwrite it with the QR code's category (${cat(s.category)}).`;
        }
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
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        } catch {   // no camera, permission refused, camera busy, or the page is not on localhost/https
            return fail('Could not open the camera. Check that one is connected, that this site may use it and that no other app is using it, or scan an image instead.');
        }
        video.srcObject = stream;
        video.hidden = false;
        const tick = () => {
            if (!stream) return;   // stopped: popup closed, image chosen, or a code was read
            const text = video.videoWidth ? read(video, video.videoWidth, video.videoHeight, 800) : undefined;
            text ? found(text) : requestAnimationFrame(tick);
        };
        tick();
    });

    source.querySelector('[data-image]').addEventListener('click', () => file.click());
    file.addEventListener('change', async () => {
        problem.hidden = true;
        stopCamera();
        const picture = await createImageBitmap(file.files[0]).catch(() => null);
        file.value = '';   // so picking the same file again still counts
        if (!picture) return fail('That file could not be read as an image.');
        const text = read(picture, picture.width, picture.height, 1600);
        text ? found(text) : fail('No QR code found in that image. Try a sharper, closer picture.');
    });

    conflict.addEventListener('change', event => {   // the category field shows what will be kept
        verify.elements.category.value = event.target.value === 'keep' ? scan.inventory.category : scan.category;
    });
    scanDialog.addEventListener('close', () => {
        stopCamera();
        problem.hidden = true;
        source.hidden = false;
        verify.hidden = true;
        verify.reset();
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
