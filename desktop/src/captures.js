'use strict';

/**
 * Rules for what can be captured without connection. They mirror the server's checks that matter offline (a journal
 * entry must balance, amounts must be positive); the server validates everything again when the capture is uploaded.
 */
const KINDS = ['sale', 'purchase', 'journal'];

const MONEY = /^\d{1,10}(\.\d{1,2})?$/;

function cents(value) {
    if (typeof value !== 'string' || !MONEY.test(value.trim())) {
        return null;
    }
    const [whole, fraction = ''] = value.trim().split('.');

    return Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
}

/**
 * @returns {string[]} the problems found, empty when the capture can be saved
 */
function validate(capture, company) {
    const errors = [];
    if (!KINDS.includes(capture.kind)) {
        return ['Tipo de movimiento no válido.'];
    }
    if (!company) {
        return ['Elige la empresa.'];
    }
    if (company.can && company.can[capture.kind] === false) {
        return ['No tienes permiso para registrar este movimiento en ' + company.name + '.'];
    }
    const payload = capture.payload || {};
    if (!/^\d{4}-\d{2}-\d{2}$/.test(payload.date || '')) {
        errors.push('Indica la fecha.');
    }
    const lines = Array.isArray(payload.lines) ? payload.lines : [];
    if (capture.kind === 'journal') {
        if (lines.length < 2) {
            errors.push('El asiento necesita al menos dos líneas.');
        }
        let debit = 0;
        let credit = 0;
        lines.forEach((line, index) => {
            const d = cents(String(line.debit || '0'));
            const c = cents(String(line.credit || '0'));
            if (!line.account_id) {
                errors.push('Línea ' + (index + 1) + ': elige la cuenta.');
            }
            if (d === null || c === null || (d > 0 && c > 0) || d + c === 0) {
                errors.push('Línea ' + (index + 1) + ': escribe un monto en el debe o en el haber.');
            }
            debit += d || 0;
            credit += c || 0;
        });
        if (debit !== credit) {
            errors.push('El asiento no cuadra: el debe suma ' + (debit / 100).toFixed(2) + ' y el haber ' + (credit / 100).toFixed(2) + '.');
        }
        if (!payload.description || !String(payload.description).trim()) {
            errors.push('Escribe la descripción del asiento.');
        }
    } else {
        if (capture.kind === 'sale' && !payload.customer_id) {
            errors.push('Elige el cliente.');
        }
        if (capture.kind === 'purchase' && !payload.supplier_id) {
            errors.push('Elige el proveedor.');
        }
        if (lines.length === 0) {
            errors.push('Agrega al menos una línea.');
        }
        lines.forEach((line, index) => {
            if (!line.description || !String(line.description).trim()) {
                errors.push('Línea ' + (index + 1) + ': escribe la descripción.');
            }
            if (!Number.isInteger(Number(line.quantity)) || Number(line.quantity) < 1) {
                errors.push('Línea ' + (index + 1) + ': la cantidad debe ser un número entero mayor que cero.');
            }
            const price = cents(String(line.unit_price || ''));
            if (price === null || price === 0) {
                errors.push('Línea ' + (index + 1) + ': escribe el precio unitario, con IVA incluido.');
            }
            if (capture.kind === 'purchase' && !line.account_id) {
                errors.push('Línea ' + (index + 1) + ': elige la cuenta de gasto o inventario.');
            }
        });
    }

    return errors;
}

module.exports = { KINDS, validate, cents };
