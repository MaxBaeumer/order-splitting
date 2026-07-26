import Plugin from 'src/plugin-system/plugin.class';

/**
 * Storefront plugin for the order split step.
 *
 * Client-side, progressive-enhancement:
 *  - add / remove address rows per line item
 *  - live-validate that quantities of a line item sum to its total
 *  - render one shipping-method selector per distinct delivery address
 *  - live-recalculate the cost summary (no full page reload) whenever the split
 *    or a delivery method changes
 *  - on submit, serialise everything into hidden fields:
 *      assignment[<lineItemId>][<addressId>] = <qty>
 *      method[<addressId>]                   = <shippingMethodId>
 */
export default class OrderSplitPlugin extends Plugin {
    init() {
        this._form = this.el.querySelector('.order-split-form');
        if (!this._form) {
            return;
        }

        this._recalculateUrl = this.el.dataset.recalculateUrl;
        this._availableMethods = this._parseJson(this.el.dataset.shippingMethods, []);
        this._methodChoices = this._parseJson(this.el.dataset.selectedMethods, {});
        this._methodsList = this.el.querySelector('.order-split-methods-list');
        this._methodsEmpty = this.el.querySelector('.order-split-methods-empty');
        this._recalcTimer = null;

        this._registerEvents();
        this._renderMethods();
        this._validateAll();
    }

    _parseJson(raw, fallback) {
        try {
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
            return fallback;
        }
    }

    _registerEvents() {
        this.el.querySelectorAll('.order-split-add-row').forEach((btn) => {
            btn.addEventListener('click', (e) => this._onAddRow(e));
        });

        this.el.addEventListener('click', (e) => {
            if (e.target.closest('.order-split-remove-row')) {
                this._onRemoveRow(e);
            }
        });

        // Address/quantity changes: re-render methods, validate, recalc.
        this.el.addEventListener('change', (e) => {
            if (e.target.classList.contains('order-split-method')) {
                this._onMethodChange(e);
                return;
            }
            this._renderMethods();
            this._afterChange();
        });
        this.el.addEventListener('input', (e) => {
            if (e.target.closest('.order-split-methods-list')) {
                return;
            }
            this._afterChange();
        });

        this._form.addEventListener('submit', (e) => this._onSubmit(e));
    }

    _afterChange() {
        // Only recalculate once the split is complete: every product's assigned
        // quantities (counting rows that actually have an address) sum to its
        // total. This avoids posting an intermediate state the backend rejects,
        // which would return the previous cart and flash a stale summary.
        if (this._validateAll() && this._isComplete()) {
            this._scheduleRecalculate();
        }
    }

    _isComplete() {
        let complete = true;
        this.el.querySelectorAll('.order-split-item').forEach((card) => {
            const total = parseInt(card.dataset.totalQuantity, 10);
            let sum = 0;
            card.querySelectorAll('.order-split-row').forEach((row) => {
                const addr = row.querySelector('.order-split-address').value;
                const qty = parseInt(row.querySelector('.order-split-quantity').value, 10) || 0;
                if (addr && qty > 0) {
                    sum += qty;
                }
            });
            if (sum !== total) {
                complete = false;
            }
        });
        return complete;
    }

    _onAddRow(event) {
        const lineItemId = event.currentTarget.dataset.lineItemId;
        const card = this.el.querySelector(`.order-split-item[data-line-item-id="${lineItemId}"]`);
        const rows = card.querySelector('.order-split-rows');
        const template = rows.querySelector('.order-split-row');
        if (!template) {
            return;
        }

        const clone = template.cloneNode(true);
        clone.querySelectorAll('select, input').forEach((field) => {
            if (field.tagName === 'SELECT') {
                field.selectedIndex = 0;
            } else {
                field.value = 1;
            }
        });
        rows.appendChild(clone);
        this._renderMethods();
        this._validateAll();
    }

    _onRemoveRow(event) {
        const row = event.target.closest('.order-split-row');
        const rows = row.parentElement;
        if (rows.querySelectorAll('.order-split-row').length > 1) {
            row.remove();
            this._renderMethods();
            this._afterChange();
        }
    }

    /* ------------------------------------------------------------------ *
     * Shipping method per address
     * ------------------------------------------------------------------ */

    _distinctAddresses() {
        const seen = new Map();
        this.el.querySelectorAll('.order-split-address').forEach((select) => {
            const id = select.value;
            if (!id || seen.has(id)) {
                return;
            }
            const label = select.options[select.selectedIndex]
                ? select.options[select.selectedIndex].text.replace(/\s+/g, ' ').trim()
                : id;
            seen.set(id, label);
        });
        return Array.from(seen, ([id, label]) => ({ id, label }));
    }

    _renderMethods() {
        if (!this._methodsList) {
            return;
        }

        const addresses = this._distinctAddresses();
        this._methodsList.innerHTML = '';

        if (addresses.length === 0 || this._availableMethods.length === 0) {
            if (this._methodsEmpty) {
                this._methodsEmpty.style.display = 'block';
            }
            return;
        }
        if (this._methodsEmpty) {
            this._methodsEmpty.style.display = 'none';
        }

        addresses.forEach((address) => {
            const chosen = this._methodChoices[address.id] || this._availableMethods[0].id;

            const row = document.createElement('div');
            row.className = 'order-split-method-row row align-items-center mb-2';

            const labelCol = document.createElement('div');
            labelCol.className = 'col-7 order-split-method-address';
            labelCol.textContent = address.label;

            const selectCol = document.createElement('div');
            selectCol.className = 'col-5';

            const select = document.createElement('select');
            select.className = 'form-select order-split-method';
            select.dataset.addressId = address.id;
            this._availableMethods.forEach((method) => {
                const opt = document.createElement('option');
                opt.value = method.id;
                opt.textContent = method.name;
                if (method.id === chosen) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
            // Remember the effective choice so it survives the next rebuild.
            this._methodChoices[address.id] = chosen;

            selectCol.appendChild(select);
            row.appendChild(labelCol);
            row.appendChild(selectCol);
            this._methodsList.appendChild(row);
        });
    }

    _onMethodChange(event) {
        const select = event.target;
        this._methodChoices[select.dataset.addressId] = select.value;
        this._scheduleRecalculate();
    }

    /* ------------------------------------------------------------------ *
     * Validation
     * ------------------------------------------------------------------ */

    _validateAll() {
        let valid = true;

        this.el.querySelectorAll('.order-split-item').forEach((card) => {
            const total = parseInt(card.dataset.totalQuantity, 10);
            const lineItemId = card.dataset.lineItemId;
            const errorEl = this.el.querySelector(`[data-error-for="${lineItemId}"]`);

            let sum = 0;
            const usedAddresses = new Set();
            let duplicate = false;

            card.querySelectorAll('.order-split-row').forEach((row) => {
                const qty = parseInt(row.querySelector('.order-split-quantity').value, 10) || 0;
                const addr = row.querySelector('.order-split-address').value;
                sum += qty;
                if (addr) {
                    if (usedAddresses.has(addr)) {
                        duplicate = true;
                    }
                    usedAddresses.add(addr);
                }
            });

            let message = '';
            if (duplicate) {
                message = this._errorText(card, 'duplicate');
            } else if (sum !== total) {
                message = `${this._errorText(card, 'sum')} (${sum} / ${total})`;
            }

            if (errorEl) {
                errorEl.textContent = message;
                errorEl.style.display = message ? 'block' : 'none';
            }
            if (message) {
                valid = false;
            }
        });

        const submit = this.el.querySelector('.order-split-submit');
        if (submit) {
            submit.disabled = !valid;
        }
        return valid;
    }

    _errorText(card, type) {
        return type === 'duplicate'
            ? 'Each address may be used once per product.'
            : 'Quantities must sum to the product total.';
    }

    /* ------------------------------------------------------------------ *
     * Live cost recalculation (no full page reload)
     * ------------------------------------------------------------------ */

    _scheduleRecalculate() {
        if (!this._recalculateUrl) {
            return;
        }
        window.clearTimeout(this._recalcTimer);
        this._recalcTimer = window.setTimeout(() => this._recalculate(), 400);
    }

    _recalculate() {
        const data = new FormData();
        this._collectAssignments((lineItemId, addr, qty) => {
            data.append(`assignment[${lineItemId}][${addr}]`, qty);
        });
        Object.keys(this._methodChoices).forEach((addressId) => {
            data.append(`method[${addressId}]`, this._methodChoices[addressId]);
        });

        // Sequence guard + abort so a slower earlier request can never overwrite
        // the summary produced by a later one (out-of-order responses).
        this._recalcSeq = (this._recalcSeq || 0) + 1;
        const seq = this._recalcSeq;
        if (this._abort) {
            this._abort.abort();
        }
        this._abort = new AbortController();

        this._setSummaryLoading(true);
        fetch(this._recalculateUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: this._abort.signal,
        })
            .then((response) => response.text())
            .then((html) => {
                if (seq === this._recalcSeq) {
                    this._swapSummary(html);
                }
            })
            .catch(() => { /* aborted or network error: keep current summary */ })
            .finally(() => {
                if (seq === this._recalcSeq) {
                    this._setSummaryLoading(false);
                }
            });
    }

    _swapSummary(html) {
        try {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.querySelector('.checkout-aside-summary');
            const current = document.querySelector('.checkout-aside-summary');
            if (next && current) {
                current.replaceWith(next);
            }
        } catch (e) {
            // Leave the current summary in place on parse errors.
        }
    }

    _setSummaryLoading(isLoading) {
        const summary = document.querySelector('.checkout-aside-summary');
        if (summary) {
            summary.style.opacity = isLoading ? '0.5' : '';
        }
    }

    /* ------------------------------------------------------------------ *
     * Submit
     * ------------------------------------------------------------------ */

    _collectAssignments(callback) {
        this.el.querySelectorAll('.order-split-item').forEach((card) => {
            const lineItemId = card.dataset.lineItemId;
            card.querySelectorAll('.order-split-row').forEach((row) => {
                const addr = row.querySelector('.order-split-address').value;
                const qty = parseInt(row.querySelector('.order-split-quantity').value, 10) || 0;
                if (!addr || qty <= 0) {
                    return;
                }
                callback(lineItemId, addr, qty);
            });
        });
    }

    _onSubmit(event) {
        if (!this._validateAll()) {
            event.preventDefault();
            return;
        }

        this._form.querySelectorAll('input[data-generated="true"]').forEach((el) => el.remove());

        this._collectAssignments((lineItemId, addr, qty) => {
            this._appendHidden(`assignment[${lineItemId}][${addr}]`, qty);
        });

        const usedAddresses = new Set();
        this.el.querySelectorAll('.order-split-address').forEach((s) => {
            if (s.value) {
                usedAddresses.add(s.value);
            }
        });
        usedAddresses.forEach((addressId) => {
            if (this._methodChoices[addressId]) {
                this._appendHidden(`method[${addressId}]`, this._methodChoices[addressId]);
            }
        });
    }

    _appendHidden(name, value) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = name;
        hidden.value = value;
        hidden.dataset.generated = 'true';
        this._form.appendChild(hidden);
    }
}
