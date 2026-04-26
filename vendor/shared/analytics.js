/**
 * WorkToGo — Analytics Helper (v2 Upgrade)
 * ──────────────────────────────────────────
 * Lightweight stats computed from existing order/booking arrays.
 * No external charting library. Pure DOM + inline SVG sparklines.
 */
const Analytics = (() => {

  /** Group items by date key YYYY-MM-DD */
  function _byDay(items, dateField) {
    const map = {};
    items.forEach(item => {
      const raw = item[dateField] || item.created_at || item.date || item.booking_date;
      if (!raw) return;
      const d = raw.split('T')[0];
      map[d] = (map[d] || 0) + 1;
    });
    return map;
  }

  /** Last N days keys */
  function _lastDays(n) {
    const days = [];
    for (let i = n - 1; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      days.push(d.toISOString().split('T')[0]);
    }
    return days;
  }

  /** Build a simple SVG sparkline */
  function sparkline(values, w = 80, h = 28) {
    if (!values.length) return '';
    const max = Math.max(...values, 1);
    const step = w / Math.max(values.length - 1, 1);
    const pts = values.map((v, i) => `${i * step},${h - (v / max) * (h - 4) - 2}`).join(' ');
    return `<svg viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" style="display:block;">
      <polyline points="${pts}" fill="none" stroke="#f5a623" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`;
  }

  /**
   * Compute summary stats from a list of orders/bookings.
   * Returns: { today, week, totalRevenue, pending, completed, trend }
   */
  function compute(items, amountField = 'total_amount') {
    const today = new Date().toISOString().split('T')[0];
    const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().split('T')[0];
    const days7 = _lastDays(7);
    const days14 = _lastDays(14);

    const byDay = _byDay(items, 'created_at');

    const todayCount = byDay[today] || 0;
    const weekCount  = days7.reduce((s, d) => s + (byDay[d] || 0), 0);
    const prevWeek   = days14.slice(0, 7).reduce((s, d) => s + (byDay[d] || 0), 0);

    const trend = prevWeek === 0 ? null : Math.round(((weekCount - prevWeek) / prevWeek) * 100);

    const pending   = items.filter(i => i.status === 'pending').length;
    const completed = items.filter(i => ['delivered', 'completed'].includes(i.status)).length;

    const totalRevenue = items
      .filter(i => ['delivered', 'completed'].includes(i.status))
      .reduce((s, i) => s + parseFloat(i[amountField] || i.total || i.amount || 0), 0);

    const sparkValues = days7.map(d => byDay[d] || 0);

    return { todayCount, weekCount, trend, pending, completed, totalRevenue, sparkValues, days7 };
  }

  /**
   * Render analytics section HTML.
   * Inject into any container.
   */
  function renderHTML(stats, isService = false) {
    const trendHtml = stats.trend !== null
      ? `<span class="analytics-trend ${stats.trend >= 0 ? 'up' : 'down'}">${stats.trend >= 0 ? '↑' : '↓'} ${Math.abs(stats.trend)}% vs last week</span>`
      : `<span class="analytics-trend">—</span>`;

    return `
      <div class="analytics-row">
        <div class="analytics-card">
          <div class="analytics-label">Today's ${isService ? 'Bookings' : 'Orders'}</div>
          <div class="analytics-value">${stats.todayCount}</div>
          ${trendHtml}
        </div>
        <div class="analytics-card">
          <div class="analytics-label">This Week</div>
          <div class="analytics-value">${stats.weekCount}</div>
          <div style="margin-top:6px;">${sparkline(stats.sparkValues)}</div>
        </div>
        <div class="analytics-card">
          <div class="analytics-label">Pending</div>
          <div class="analytics-value" style="color:${stats.pending > 0 ? 'var(--warning)' : 'var(--success)'};">${stats.pending}</div>
          <span class="analytics-trend ${stats.pending > 0 ? '' : 'up'}">${stats.pending > 0 ? 'Needs attention' : 'All clear!'}</span>
        </div>
        <div class="analytics-card">
          <div class="analytics-label">Earnings (completed)</div>
          <div class="analytics-value" style="font-size:1.3rem;">₹${Number(stats.totalRevenue).toLocaleString('en-IN', {maximumFractionDigits:0})}</div>
          <span class="analytics-trend up">${stats.completed} completed</span>
        </div>
      </div>`;
  }

  return { compute, renderHTML, sparkline };
})();
