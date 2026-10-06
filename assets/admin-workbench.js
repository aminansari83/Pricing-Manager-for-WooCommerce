/* global APGAdmin, wp */
(() => {
  'use strict';
  const { __ } = wp.i18n;
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const number = value => Number(String(value).replace(/[۰-۹٠-٩]/g, char => '۰۱۲۳۴۵۶۷۸۹'.includes(char) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(char) : '٠١٢٣٤٥٦٧٨٩'.indexOf(char)).replace(/[٬,]/g, '').replace('٫', '.'));
  const count = value => new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 4 }).format(number(value));
  const fold = value => String(value).normalize('NFKC').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').toLocaleLowerCase('fa-IR');

  function symbol(currency = APGAdmin.currency) {
    const units = { IRR: __('ریال', 'andiya-price-guard'), IRT: __('تومان', 'andiya-price-guard'), IRHR: __('هزار ریال', 'andiya-price-guard'), IRHT: __('هزار تومان', 'andiya-price-guard') };
    if (units[currency]) return units[currency];
    try {
      return new Intl.NumberFormat('fa-IR', { style: 'currency', currency, currencyDisplay: 'narrowSymbol' }).formatToParts(0).find(part => part.type === 'currency').value;
    } catch (error) { return currency; }
  }
  function money(value, currency = APGAdmin.currency) {
    return value === '' || value === undefined || value === null ? __('بدون قیمت', 'andiya-price-guard') : count(value) + ' ' + symbol(currency);
  }
  function categoryLabel(category, categories) {
    const seen = new Set([String(category.id)]), names = [category.name];
    let parent = category.parent;
    while (parent && !seen.has(String(parent))) {
      seen.add(String(parent));
      const item = categories.find(candidate => Number(candidate.id) === Number(parent));
      if (!item) break;
      names.unshift(item.name); parent = item.parent;
    }
    return names.join(' / ');
  }
  function scope(filters = {}, bootstrap) {
    const parts = [];
    if (filters.categories?.length) {
      const names = filters.categories.map(id => {
        const term = bootstrap.categories.find(item => Number(item.id) === Number(id));
        return term ? categoryLabel(term, bootstrap.categories) : '#' + id;
      });
      parts.push(__('دسته‌ها: ', 'andiya-price-guard') + names.join('، '));
    }
    for (const [taxonomy, ids] of Object.entries(filters.terms || {})) {
      if (!ids.length) continue;
      const tax = bootstrap.taxonomies.find(item => item.name === taxonomy);
      const names = ids.map(id => tax?.terms.find(term => Number(term.id) === Number(id))?.name || '#' + id);
      parts.push((tax?.label || taxonomy) + ': ' + names.join('، '));
    }
    if (filters.search) parts.push(__('نام کالا شامل: ', 'andiya-price-guard') + filters.search);
    if (filters.source) {
      const source = bootstrap.sources.find(item => 'source:' + item.id === filters.source);
      parts.push(__('منبع: ', 'andiya-price-guard') + (source?.name || filters.source));
    }
    return parts.length ? parts.join(' · ') : __('تمام محصولات منتشرشده و تنوع‌های آنها', 'andiya-price-guard');
  }
  function operation(config = {}, currency = APGAdmin.currency) {
    const kinds = { state: __('تغییر وضعیت فروش', 'andiya-price-guard'), confirm: __('تأیید قیمت فعلی', 'andiya-price-guard'), import: __('قیمت‌های بررسی‌شده از فایل یا منبع', 'andiya-price-guard') };
    if (kinds[config.kind]) return kinds[config.kind];
    if (config.kind === 'set') return __('قیمت ثابت: ', 'andiya-price-guard') + money(config.value, currency);
    const change = number(config.value);
    const direction = change < 0 ? __('کاهش ', 'andiya-price-guard') : __('افزایش ', 'andiya-price-guard');
    const amount = config.kind === 'percent' ? count(Math.abs(change)) + '٪' : money(Math.abs(change), currency);
    const base = Number(config.base_id) ? __('از مبنای اولیه عملیات #', 'andiya-price-guard') + config.base_id : __('از قیمت فعلی هر کالا', 'andiya-price-guard');
    return direction + amount + ' · ' + base;
  }
  function badge(text, status) {
    return node('span', text, 'apg-badge apg-badge-' + status);
  }
  function metrics(items) {
    const grid = node('div', undefined, 'apg-summary-grid');
    for (const [title, value, tone] of items) {
      const card = node('div', undefined, 'apg-metric' + (tone ? ' apg-metric-' + tone : ''));
      card.append(node('span', title), node('strong', count(value)));
      grid.append(card);
    }
    return grid;
  }
  function difference(before, after, currency) {
    const old = before.regular_price, next = after.regular_price;
    if (old === '' && next !== '') return node('span', __('قیمت اولیه', 'andiya-price-guard'), 'apg-delta apg-delta-initial');
    if (old === '' || next === '') return node('span', '—', 'apg-delta');
    const delta = number(next) - number(old);
    if (!delta) return node('span', __('بدون تغییر مبلغ', 'andiya-price-guard'), 'apg-delta');
    return node('span', (delta > 0 ? '+' : '−') + money(Math.abs(delta), currency), 'apg-delta ' + (delta > 0 ? 'apg-delta-up' : 'apg-delta-down'));
  }

  /** A native select remains the canonical value; checkboxes need no Ctrl key. */
  function picker(select) {
    if (select._apgPicker) { select._apgPicker.rebuild(); return; }
    const label = select.closest('label');
    if (!label) return;
    const title = [...label.childNodes].filter(child => child.nodeType === Node.TEXT_NODE).map(child => child.textContent).join('').trim();
    const help = label.querySelector('small');
    const field = node('fieldset', undefined, 'apg-picker');
    const details = node('details'), summary = node('summary');
    const search = node('input'); search.type = 'search'; search.maxLength = 100;
    search.placeholder = __('جست‌وجوی گزینه‌ها', 'andiya-price-guard');
    search.setAttribute('aria-label', __('جست‌وجو در ', 'andiya-price-guard') + title);
    const list = node('div', undefined, 'apg-picker-list');
    const empty = node('p', __('گزینه‌ای با این نام پیدا نشد.', 'andiya-price-guard'), 'apg-help'); empty.hidden = true;
    const clear = node('button', __('پاک‌کردن انتخاب‌های این فیلتر', 'andiya-price-guard'), 'button apg-picker-clear'); clear.type = 'button';
    field.append(node('legend', title));
    label.replaceWith(field); select.hidden = true; field.append(select);
    details.append(summary, search, list, empty, clear); field.append(details);
    if (help) field.append(help);
    let choices = [];
    function filter() {
      const term = fold(search.value); let visible = 0;
      for (const item of choices) { item.label.hidden = !fold(item.option.textContent).includes(term); if (!item.label.hidden) visible++; }
      empty.hidden = visible > 0;
    }
    function refresh() {
      for (const item of choices) item.check.checked = item.option.selected;
      const selected = [...select.selectedOptions].map(option => option.textContent);
      summary.textContent = selected.length ? selected.slice(0, 2).join('، ') + (selected.length > 2 ? ' · ' + count(selected.length) + __(' گزینه', 'andiya-price-guard') : '') : __('همه؛ برای محدودکردن انتخاب کنید', 'andiya-price-guard');
      clear.disabled = !selected.length;
    }
    function rebuild() {
      choices = []; list.replaceChildren();
      const fragment = document.createDocumentFragment();
      for (const option of select.options) {
        const row = node('label', undefined, 'apg-picker-option'), check = node('input'); check.type = 'checkbox'; check.checked = option.selected;
        check.dataset.choice = option.value;
        check.addEventListener('change', () => { option.selected = check.checked; refresh(); select.dispatchEvent(new Event('change', { bubbles: true })); });
        row.append(check, node('span', option.textContent)); fragment.append(row); choices.push({ option, check, label: row });
      }
      list.append(fragment); filter(); refresh();
    }
    select.addEventListener('change', refresh);
    search.addEventListener('input', filter);
    clear.addEventListener('click', () => { for (const option of select.options) option.selected = false; refresh(); select.dispatchEvent(new Event('change', { bubbles: true })); });
    select._apgPicker = { refresh, rebuild }; rebuild();
  }
  function sync(root) {
    for (const select of root.querySelectorAll('select[multiple]')) select._apgPicker?.refresh();
  }
  window.APGWorkbench = Object.freeze({ node, count, number, money, symbol, scope, operation, metrics, badge, difference, picker, sync, categoryLabel });
})();
