'use strict';

/* Offline screens: read the encrypted copy through the bridge and capture sales, purchases and journal entries. Every
   value from the copy is written with textContent, never as HTML. */
(function () {
    const api = window.atlantiaDesktop;
    const view = document.getElementById('view');
    const notice = document.getElementById('notice');
    const toolbar = document.getElementById('toolbar');
    const companySelect = document.getElementById('company');
    const dialog = document.getElementById('form-dialog');
    const formFields = document.getElementById('form-fields');
    const formErrors = document.getElementById('form-errors');
    let snapshot = null;
    let captures = [];
    let tab = 'summary';
    let formKind = null;

    const money = (cents) => 'Q ' + (Number(cents || 0) / 100).toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const date = (value) => (value ? new Date(String(value).length === 10 ? value + 'T12:00:00' : value).toLocaleDateString('es-GT') : '—');
    const dateTime = (value) => new Date(value).toLocaleString('es-GT', { dateStyle: 'medium', timeStyle: 'short' });
    const today = () => new Date().toLocaleDateString('en-CA');

    function h(tag, attributes, children) {
        const element = document.createElement(tag);
        Object.entries(attributes || {}).forEach(([key, value]) => {
            if (value === undefined || value === null || value === false) {
                return;
            }
            if (key === 'text') {
                element.textContent = String(value);
            } else if (key === 'className') {
                element.className = value;
            } else if (key.startsWith('on')) {
                element.addEventListener(key.slice(2), value);
            } else {
                element.setAttribute(key, value === true ? '' : String(value));
            }
        });
        (children || []).forEach((child) => child && element.append(child));

        return element;
    }

    function table(headers, rows, empty) {
        if (rows.length === 0) {
            return h('p', { className: 'empty', text: empty });
        }

        return h('div', { className: 'table-wrap' }, [h('table', {}, [
            h('thead', {}, [h('tr', {}, headers.map(([label, amount]) => h('th', { className: amount ? 'amount' : '', text: label })))]),
            h('tbody', {}, rows.map((cells) => h('tr', {}, cells.map((cell, index) => (cell instanceof Node ? h('td', {}, [cell]) : h('td', { className: headers[index][1] ? 'amount' : '', text: cell })))))),
        ])]);
    }

    function company() {
        return snapshot ? snapshot.companies.find((item) => item.public_id === companySelect.value) || snapshot.companies[0] : null;
    }

    function hidden(section) {
        return h('p', { className: 'empty', text: 'Tu rol no incluye ' + section + ' de esta empresa.' });
    }

    function renderSummary(c) {
        const totals = { asset: 0, liability: 0, equity: 0, income: 0, expense: 0 };
        (c.balances || []).forEach((row) => { totals[row.type] = (totals[row.type] || 0) + row.cents; });
        const receivable = (c.receivables || []).reduce((sum, row) => sum + row.outstanding_cents, 0);
        const overdue = (c.receivables || []).filter((row) => row.overdue).reduce((sum, row) => sum + row.outstanding_cents, 0);
        const payable = (c.payables || []).reduce((sum, row) => sum + row.outstanding_cents, 0);
        const tiles = [];
        if (c.balances) {
            tiles.push(['Ingresos del ejercicio', totals.income], ['Gastos del ejercicio', totals.expense], ['Utilidad del ejercicio', totals.income - totals.expense]);
        }
        if (c.receivables) {
            tiles.push(['Por cobrar', receivable], ['Por cobrar vencido', overdue]);
        }
        if (c.payables) {
            tiles.push(['Por pagar', payable]);
        }
        const parts = [h('div', { className: 'grid' }, tiles.map(([label, value]) => h('div', { className: 'tile' }, [h('span', { text: label }), h('strong', { text: money(value) })])))];
        parts.push(h('div', { className: 'panel' }, [
            h('h3', { text: 'Saldos por cuenta (desde ' + date(c.balances_from) + ')' }),
            c.balances ? table([['Cuenta'], ['Nombre'], ['Saldo', true]], c.balances.map((row) => [row.code, row.name, money(row.cents)]), 'Sin movimientos contabilizados en el ejercicio.') : hidden('el libro mayor'),
        ]));
        parts.push(h('div', { className: 'panel' }, [
            h('h3', { text: 'Obligaciones fiscales próximas' }),
            c.obligations ? table([['Obligación'], ['Período'], ['Vence']], c.obligations.map((row) => [row.name, row.reference_period, date(row.due_on)]), 'No hay obligaciones en los próximos 60 días.') : hidden('lo fiscal'),
        ]));

        return parts;
    }

    function render() {
        view.replaceChildren();
        document.querySelectorAll('[data-tab]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.tab === tab)));
        document.getElementById('pending-count').textContent = String(captures.length);
        const c = company();
        if (!c) {
            return;
        }
        document.querySelectorAll('[data-new]').forEach((button) => { button.hidden = !(c.can && c.can[button.dataset.new]); });
        if (tab === 'summary') {
            view.append(...renderSummary(c));
        } else if (tab === 'receivables') {
            view.append(h('div', { className: 'panel' }, [h('h3', { text: 'Documentos por cobrar' }),
                c.receivables ? table([['Documento'], ['Cliente'], ['Vence'], ['Saldo', true]], c.receivables.map((row) => [row.document, row.customer,
                    h('span', { className: row.overdue ? 'pill late' : 'pill', text: date(row.due_date) }), money(row.outstanding_cents)]), 'No hay saldos por cobrar.') : hidden('ventas')]));
        } else if (tab === 'payables') {
            view.append(h('div', { className: 'panel' }, [h('h3', { text: 'Documentos por pagar' }),
                c.payables ? table([['Documento'], ['Proveedor'], ['Vence'], ['Saldo', true]], c.payables.map((row) => [row.document, row.supplier, date(row.due_date), money(row.outstanding_cents)]), 'No hay saldos por pagar.') : hidden('compras')]));
        } else if (tab === 'activity') {
            view.append(
                h('div', { className: 'panel' }, [h('h3', { text: 'Ventas de los últimos 90 días' }), c.sales ? table([['Fecha'], ['Cliente'], ['Estado'], ['Total', true]], c.sales.map((row) => [date(row.issued_on), row.customer, row.status, money(row.total_cents)]), 'Sin ventas recientes.') : hidden('ventas')]),
                h('div', { className: 'panel' }, [h('h3', { text: 'Compras de los últimos 90 días' }), c.purchases ? table([['Fecha'], ['Proveedor'], ['Documento'], ['Total', true]], c.purchases.map((row) => [date(row.document_date), row.supplier, row.external_reference || ('#' + (row.number || '')), money(row.total_cents)]), 'Sin compras recientes.') : hidden('compras')]),
                h('div', { className: 'panel' }, [h('h3', { text: 'Asientos de los últimos 90 días' }), c.entries ? table([['Fecha'], ['Descripción'], ['Estado']], c.entries.map((row) => [date(row.entry_date), row.description, row.status]), 'Sin asientos recientes.') : hidden('el libro diario')]),
            );
        } else {
            const labels = { sale: 'Venta', purchase: 'Compra o gasto', journal: 'Asiento' };
            view.append(h('div', { className: 'panel' }, [
                h('h3', { text: 'Capturas pendientes de subir' }),
                h('p', { className: 'muted', text: 'Se suben solas cuando vuelve la conexión y quedan como borradores en Atlantia Finance.' }),
                table([['Capturada'], ['Empresa'], ['Tipo'], ['Detalle'], ['']], captures.map((capture) => [
                    dateTime(capture.captured_at), capture.company_name, labels[capture.kind], describe(capture),
                    h('button', { type: 'button', className: 'link', text: 'Eliminar', onclick: async () => { await api.removeCapture(capture.id); await reload(); } }),
                ]), 'No hay capturas pendientes.'),
            ]));
        }
    }

    function describe(capture) {
        const p = capture.payload || {};
        if (capture.kind === 'journal') {
            return (p.description || '') + ' · ' + date(p.date);
        }
        const total = (p.lines || []).reduce((sum, line) => sum + Number(line.quantity || 0) * Number(line.unit_price || 0), 0);

        return date(p.date) + ' · Q ' + total.toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /* ---------- Capture forms ---------- */

    function field(label, control) {
        return h('label', { className: 'field' }, [h('span', { text: label }), control]);
    }

    function select(name, options, placeholder) {
        return h('select', { name, required: true }, [h('option', { value: '', text: placeholder }), ...options.map(([value, label]) => h('option', { value, text: label }))]);
    }

    function accountOptions(c, types) {
        return (c.accounts || []).filter((account) => !types || types.includes(account.type)).map((account) => [account.id, account.code + ' · ' + account.name]);
    }

    function addLine(container, kind, c) {
        const remove = h('button', { type: 'button', className: 'link', text: 'Quitar', onclick: () => { row.remove(); updateTotals(); } });
        const row = kind === 'journal'
            ? h('div', { className: 'line journal' }, [
                field('Cuenta', select('account_id', accountOptions(c), 'Elige la cuenta')),
                field('Debe', h('input', { name: 'debit', inputmode: 'decimal', placeholder: '0.00', oninput: updateTotals })),
                field('Haber', h('input', { name: 'credit', inputmode: 'decimal', placeholder: '0.00', oninput: updateTotals })),
                field('Detalle', h('input', { name: 'description', maxlength: 250 })),
                remove,
            ])
            : h('div', { className: 'line' }, [
                field('Descripción', h('input', { name: 'description', required: true, maxlength: 250 })),
                field('Cantidad', h('input', { name: 'quantity', type: 'number', min: 1, step: 1, value: 1, required: true })),
                field('Precio unitario (con IVA)', h('input', { name: 'unit_price', inputmode: 'decimal', placeholder: '0.00', required: true })),
                kind === 'purchase'
                    ? field('Cuenta', select('account_id', accountOptions(c, ['expense', 'asset']), 'Elige la cuenta'))
                    : field('Tipo', h('select', { name: 'tax_treatment' }, [h('option', { value: 'goods', text: 'Bienes' }), h('option', { value: 'services', text: 'Servicios' }), h('option', { value: 'exempt', text: 'Exento' })])),
                remove,
            ]);
        container.append(row);
    }

    function updateTotals() {
        const totals = document.getElementById('journal-totals');
        if (!totals) {
            return;
        }
        let debit = 0;
        let credit = 0;
        formFields.querySelectorAll('.line').forEach((line) => {
            debit += Number(line.querySelector('[name=debit]').value || 0);
            credit += Number(line.querySelector('[name=credit]').value || 0);
        });
        totals.textContent = 'Debe Q ' + debit.toFixed(2) + ' · Haber Q ' + credit.toFixed(2) + (Math.abs(debit - credit) < 0.005 ? ' · Cuadra' : ' · No cuadra');
    }

    function openForm(kind) {
        const c = company();
        formKind = kind;
        formErrors.hidden = true;
        document.getElementById('form-title').textContent = { sale: 'Nueva venta', purchase: 'Nueva compra o gasto', journal: 'Nuevo asiento' }[kind] + ' · ' + c.name;
        const lines = h('div', { className: 'lines', id: 'lines' });
        const head = [];
        if (kind === 'sale') {
            head.push(field('Cliente', select('customer_id', (c.customers || []).map((row) => [row.id, row.name + (row.tax_id ? ' · ' + row.tax_id : '')]), 'Elige el cliente')),
                field('Fecha', h('input', { name: 'date', type: 'date', value: today(), required: true })),
                field('Vencimiento (opcional)', h('input', { name: 'due_on', type: 'date' })));
        } else if (kind === 'purchase') {
            head.push(field('Proveedor', select('supplier_id', (c.suppliers || []).map((row) => [row.id, row.name + (row.tax_id ? ' · ' + row.tax_id : '')]), 'Elige el proveedor')),
                field('Tipo', h('select', { name: 'document_type' }, [h('option', { value: 'purchase', text: 'Compra' }), h('option', { value: 'expense', text: 'Gasto' })])),
                field('No. de factura', h('input', { name: 'reference', maxlength: 100 })),
                field('Fecha', h('input', { name: 'date', type: 'date', value: today(), required: true })),
                field('Vencimiento (opcional)', h('input', { name: 'due_on', type: 'date' })));
        } else {
            head.push(field('Fecha', h('input', { name: 'date', type: 'date', value: today(), required: true })),
                field('Descripción', h('input', { name: 'description', required: true, maxlength: 250 })));
        }
        formFields.replaceChildren(
            h('div', { className: 'fields' }, head),
            lines,
            h('div', { className: 'form-actions' }, [
                kind === 'journal' ? h('span', { className: 'totals', id: 'journal-totals' }) : null,
                h('button', { type: 'button', className: 'ghost', text: 'Agregar línea', onclick: () => addLine(lines, kind, c) }),
            ]),
        );
        addLine(lines, kind, c);
        if (kind === 'journal') {
            addLine(lines, kind, c);
            updateTotals();
        }
        dialog.showModal();
    }

    function collect() {
        const value = (name) => { const input = formFields.querySelector('.fields [name=' + name + ']'); return input ? input.value.trim() : ''; };
        const lines = [...formFields.querySelectorAll('.line')].map((line) => {
            const get = (name) => { const input = line.querySelector('[name=' + name + ']'); return input ? input.value.trim() : undefined; };

            return formKind === 'journal'
                ? { account_id: Number(get('account_id')) || null, debit: get('debit') || '0', credit: get('credit') || '0', description: get('description') }
                : { description: get('description'), quantity: Number(get('quantity')), unit_price: get('unit_price'), tax_treatment: get('tax_treatment') || 'services', account_id: get('account_id') ? Number(get('account_id')) : undefined };
        });
        const payload = { date: value('date'), lines };
        if (formKind === 'sale') {
            Object.assign(payload, { customer_id: Number(value('customer_id')) || null, due_on: value('due_on') || null });
        } else if (formKind === 'purchase') {
            Object.assign(payload, { supplier_id: Number(value('supplier_id')) || null, document_type: value('document_type'), reference: value('reference') || null, due_on: value('due_on') || null });
        } else {
            payload.description = value('description');
        }

        return { company: company().public_id, kind: formKind, payload };
    }

    document.getElementById('capture-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const result = await api.addCapture(collect());
        if (!result.saved) {
            formErrors.replaceChildren(...result.errors.map((error) => h('li', { text: error })));
            formErrors.hidden = false;

            return;
        }
        dialog.close();
        tab = 'captures';
        await reload();
    });
    document.getElementById('form-cancel').addEventListener('click', () => dialog.close());

    /* ---------- State ---------- */

    function showStatus(status) {
        const box = document.getElementById('status');
        box.classList.toggle('online', Boolean(status && status.online));
        document.getElementById('status-text').textContent = status && status.online
            ? 'Conexión recuperada · volviendo a Atlantia…'
            : 'Sin conexión con el servidor' + (snapshot ? ' · copia del ' + dateTime(snapshot.generated_at) : '');
        if (status && status.owner) {
            document.getElementById('firm').textContent = status.owner.organization.name + ' · ' + status.owner.user.name;
        }
    }

    function showNotice(title, text) {
        notice.replaceChildren(h('h2', { text: title }), h('p', { text }));
        notice.hidden = false;
    }

    async function reload() {
        const result = await api.snapshot();
        captures = await api.captures();
        notice.hidden = true;
        if (!result || result.status === 'missing') {
            snapshot = null;
            toolbar.hidden = true;
            showNotice('Todavía no hay copia sin conexión', 'La copia se crea sola la primera vez que inicias sesión con internet. Cuando vuelva la conexión, entra a Atlantia y la app la guardará en este equipo.');
            view.replaceChildren();

            return;
        }
        if (result.status === 'expired') {
            snapshot = null;
            toolbar.hidden = true;
            showNotice('La copia sin conexión se bloqueó', 'Tiene más de ' + result.max_offline_days + ' días (es del ' + dateTime(result.generated_at) + '). Por seguridad se desbloquea cuando el equipo vuelva a conectarse.');
            view.replaceChildren();

            return;
        }
        const selected = companySelect.value;
        snapshot = result.snapshot;
        companySelect.replaceChildren(...snapshot.companies.map((item) => h('option', { value: item.public_id, text: item.name })));
        if (selected && snapshot.companies.some((item) => item.public_id === selected)) {
            companySelect.value = selected;
        }
        toolbar.hidden = false;
        if (snapshot.companies.length === 0) {
            showNotice('Sin empresas', 'No tienes empresas asignadas en la copia sin conexión.');
        }
        showStatus(await api.state());
        render();
    }

    document.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => { tab = button.dataset.tab; render(); }));
    document.querySelectorAll('[data-new]').forEach((button) => button.addEventListener('click', () => openForm(button.dataset.new)));
    companySelect.addEventListener('change', render);
    document.getElementById('retry').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        button.textContent = 'Comprobando…';
        const online = await api.retry();
        button.disabled = false;
        button.textContent = online ? 'Conectado' : 'Reintentar conexión';
    });
    api.onStatus((status) => { showStatus(status); api.captures().then((list) => { captures = list; render(); }); });

    reload();
})();
