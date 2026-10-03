<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    charts: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) },
    locationName: { type: String, default: '' },
});
const can = (permission) => Boolean(props.permissions[permission]);
const count = (value) => Number.isFinite(Number(value)) ? Math.max(0, Math.floor(Number(value))) : 0;
const number = (value) => count(value).toLocaleString('hy-AM');
const shortDate = (date) => `${date.slice(8, 10)}.${date.slice(5, 7)}`;
const fullDate = (date) => `${shortDate(date)}.${date.slice(0, 4)}`;
const seriesDefinitions = [
    { key: 'receipts', label: 'Մուտքեր', color: '#18a887', permission: 'receipts.view' },
    { key: 'issues', label: 'Ելքեր', color: '#5262f6', permission: 'movements.view' },
    { key: 'returns', label: 'Վերադարձներ', color: '#9a66dc', permission: 'returns.view' },
    { key: 'transfers', label: 'Տեղափոխումներ', color: '#e9a13b', permission: 'transfers.view' },
];
const dates = computed(() => (props.charts.activity_daily?.dates || []).filter((date) => typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date)));
const series = computed(() => seriesDefinitions.filter((item) => can(item.permission) && Array.isArray(props.charts.activity_daily?.series?.[item.key]))
    .map((item) => {
        const values = dates.value.map((_, index) => count(props.charts.activity_daily.series[item.key][index]));
        return { ...item, values, total: values.reduce((sum, value) => sum + value, 0) };
    }));
const hidden = ref([]);
const visibleSeries = computed(() => series.value.filter((item) => !hidden.value.includes(item.key)));
function toggleSeries(key) {
    if (hidden.value.includes(key)) hidden.value = hidden.value.filter((item) => item !== key);
    else if (visibleSeries.value.length > 1) hidden.value = [...hidden.value, key];
}
const selectedDate = ref(null);
const selectedIndex = computed(() => Math.max(0, dates.value.includes(selectedDate.value) ? dates.value.indexOf(selectedDate.value) : dates.value.length - 1));
const hasActivity = computed(() => series.value.some((item) => item.total > 0));
const maxValue = computed(() => Math.max(1, ...visibleSeries.value.flatMap((item) => item.values)));
const tickStep = computed(() => Math.max(1, Math.ceil(maxValue.value / 4)));
const ceiling = computed(() => tickStep.value * 4);
const plot = { left: 38, right: 632, top: 20, bottom: 208 };
const x = (index) => plot.left + index * (plot.right - plot.left) / Math.max(1, dates.value.length - 1);
const y = (value) => plot.bottom - count(value) / ceiling.value * (plot.bottom - plot.top);
const points = (item) => item.values.map((value, index) => `${x(index)},${y(value)}`).join(' ');
const dayDescription = (index) => `${fullDate(dates.value[index])}՝ ${visibleSeries.value.map((item) => `${item.label} ${number(item.values[index])}`).join(', ')}`;
const ticks = computed(() => Array.from({ length: 5 }, (_, index) => ({ value: tickStep.value * index, y: y(tickStep.value * index) })));

const statusCharts = computed(() => [
    { key: 'stock', source: 'stock_status', permission: 'stock.view', eyebrow: 'ԱՊՐԱՆՔՆԵՐԻ ՄՆԱՑՈՐԴ', title: 'Պաշարի վիճակ', unit: 'ակտիվ ապրանք', empty: 'Ակտիվ ապրանքներ չկան։',
        note: 'Ցածր մնացորդը՝ զրոյից բարձր, բայց MIN շեմից ցածր։',
        buckets: [{ key: 'healthy', label: 'Բավարար', color: '#18a887' }, { key: 'low', label: 'MIN-ից ցածր', color: '#e9a13b' }, { key: 'zero', label: 'Զրոյական', color: '#ed7085' }] },
    { key: 'expiry', source: 'expiry_status', permission: 'expiry.view', eyebrow: 'ԺԱՄԿԵՏՆԵՐԻ ՎԵՐԱՀՍԿՈՒՄ', title: 'LOT-երի ժամկետներ', unit: 'մնացորդով LOT', empty: 'Մնացորդով LOT-եր չկան։',
        note: 'Միայն դրական մնացորդով LOT-երը՝ ընտրված պահեստում։',
        buckets: [{ key: 'safe', label: '90 օրից ավելի', color: '#5262f6' }, { key: 'expiring', label: 'Մինչև 90 օր', color: '#e9a13b' }, { key: 'expired', label: 'Ժամկետանց', color: '#ed7085' }, { key: 'undated', label: 'Ժամկետը նշված չէ', color: '#a5b1c5' }] },
].filter((chart) => can(chart.permission) && props.charts[chart.source]).map((chart) => {
    const buckets = chart.buckets.map((bucket) => ({ ...bucket, value: count(props.charts[chart.source][bucket.key]) }));
    const total = buckets.reduce((sum, bucket) => sum + bucket.value, 0);
    let offset = 0;
    const segments = buckets.map((bucket) => {
        const percent = total ? bucket.value / total * 100 : 0;
        const segment = { ...bucket, percent, offset };
        offset += percent;
        return segment;
    });
    return { ...chart, total, segments, description: `${chart.title}՝ ${segments.map((bucket) => `${bucket.label} ${number(bucket.value)}`).join(', ')}` };
}));
</script>

<template>
    <section v-if="(dates.length && series.length) || statusCharts.length" class="dashboard-charts" aria-label="Պահեստի գրաֆիկներ">
        <article v-if="dates.length && series.length" class="dashboard-panel chart-activity" data-testid="charts-activity">
            <header class="dashboard-panel-header">
                <div><p class="eyebrow">ՊԱՀԵՍՏԻ ՇԱՐԺ</p><h2>Վերջին 14 օրվա շարժը</h2><p class="chart-location">{{ locationName }}</p></div>
                <span class="chart-period">{{ shortDate(dates[0]) }} — {{ shortDate(dates.at(-1)) }}</span>
            </header>
            <div class="chart-series-controls" aria-label="Գրաֆիկի ցուցանիշներ">
                <button v-for="item in series" :key="item.key" type="button" :data-testid="`charts-series-${item.key}`"
                    :aria-pressed="!hidden.includes(item.key)" :disabled="!hidden.includes(item.key) && visibleSeries.length === 1"
                    :class="{ 'series-hidden': hidden.includes(item.key) }" :style="{ '--series-color': item.color }" @click="toggleSeries(item.key)">
                    <span class="chart-color-dot"></span><span>{{ item.label }}</span><strong>{{ number(item.total) }}</strong>
                </button>
            </div>
            <p v-if="!hasActivity" class="chart-empty-state">Այս ժամանակահատվածում շարժի գրանցումներ չկան։</p>
            <div class="chart-plot">
                <svg viewBox="0 0 650 242" role="group" aria-label="Շարժի գրանցումներ՝ ըստ օրվա">
                    <g aria-hidden="true">
                        <g v-for="tick in ticks" :key="tick.value"><line :x1="plot.left" :x2="plot.right" :y1="tick.y" :y2="tick.y" class="chart-grid-line" /><text x="26" :y="tick.y + 4" text-anchor="end" class="chart-axis-label">{{ tick.value }}</text></g>
                        <line :x1="x(selectedIndex)" :x2="x(selectedIndex)" :y1="plot.top" :y2="plot.bottom" class="chart-selected-line" />
                        <polyline v-for="item in visibleSeries" :key="item.key" :data-series="item.key" :points="points(item)" :stroke="item.color" fill="none" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                        <circle v-for="item in visibleSeries" :key="`${item.key}-point`" :cx="x(selectedIndex)" :cy="y(item.values[selectedIndex])" r="4" :fill="item.color" stroke="white" stroke-width="2" />
                        <template v-for="(date, index) in dates" :key="date"><text v-if="index % 3 === 0 || index === dates.length - 1" :x="x(index)" y="233" text-anchor="middle" class="chart-axis-label">{{ shortDate(date) }}</text></template>
                    </g>
                    <rect v-for="(date, index) in dates" :key="`target-${date}`" :data-testid="`charts-day-${date}`" role="button" tabindex="0"
                        :x="x(index) - (plot.right - plot.left) / Math.max(1, dates.length - 1) / 2" :y="plot.top - 8"
                        :width="(plot.right - plot.left) / Math.max(1, dates.length - 1)" :height="plot.bottom - plot.top + 14"
                        :aria-label="dayDescription(index)" class="chart-day-target" @mouseenter="selectedDate = date" @focus="selectedDate = date"
                        @click="selectedDate = date" @keydown.enter.prevent="selectedDate = date" @keydown.space.prevent="selectedDate = date" />
                </svg>
            </div>
            <div class="chart-day-summary" aria-live="polite" aria-atomic="true">
                <strong>{{ fullDate(dates[selectedIndex]) }}</strong>
                <span v-for="item in visibleSeries" :key="item.key" :style="{ '--series-color': item.color }"><i class="chart-color-dot"></i>{{ item.label }} <b>{{ number(item.values[selectedIndex]) }}</b></span>
            </div>
            <p class="chart-note">LOT-երի շարժի գրանցումների քանակը՝ առանց հակադարձված շարժերի։ Սեղմեք ցուցանիշը՝ այն թաքցնելու համար։</p>
        </article>

        <div v-if="statusCharts.length" class="chart-status-grid">
            <article v-for="chart in statusCharts" :key="chart.key" class="dashboard-panel chart-status" :data-testid="`charts-${chart.key}`">
                <header class="dashboard-panel-header"><div><p class="eyebrow">{{ chart.eyebrow }}</p><h2>{{ chart.title }}</h2></div></header>
                <div class="chart-status-body">
                    <div class="chart-donut">
                        <svg viewBox="0 0 180 180" role="img" :aria-label="chart.description">
                            <circle cx="90" cy="90" r="64" fill="none" stroke="#eef1f7" stroke-width="19" />
                            <circle v-for="segment in chart.segments.filter((item) => item.value > 0)" :key="segment.key"
                                cx="90" cy="90" r="64" pathLength="100" fill="none" :stroke="segment.color" stroke-width="19"
                                :stroke-dasharray="`${segment.percent} ${100 - segment.percent}`" :stroke-dashoffset="-segment.offset" transform="rotate(-90 90 90)" />
                        </svg>
                        <div class="chart-donut-center" aria-hidden="true"><strong>{{ number(chart.total) }}</strong><small>{{ chart.unit }}</small></div>
                    </div>
                    <dl class="chart-status-legend">
                        <div v-for="segment in chart.segments" :key="segment.key" :data-bucket="segment.key" :style="{ '--series-color': segment.color }">
                            <dt><i class="chart-color-dot"></i>{{ segment.label }}</dt><dd>{{ number(segment.value) }}<small>{{ chart.total ? Math.round(segment.percent) : 0 }}%</small></dd>
                        </div>
                    </dl>
                </div>
                <p v-if="!chart.total" class="chart-empty-state">{{ chart.empty }}</p>
                <p class="chart-note">{{ chart.note }}</p>
            </article>
        </div>
    </section>
</template>

<style scoped>
.dashboard-charts { display: grid; gap: 15px; margin: 18px 0; }
.chart-location { margin: 6px 0 0; color: #8a97ad; font-size: 10px; }
.chart-period { flex: none; padding: 7px 10px; border: 1px solid #e5eaf5; border-radius: 8px; color: #75829b; background: #fafbff; font-size: 10px; font-variant-numeric: tabular-nums; }
.chart-series-controls { display: flex; flex-wrap: wrap; gap: 10px; padding: 18px 20px 4px; }
.chart-series-controls button { display: flex; align-items: center; gap: 8px; min-height: 38px; padding: 7px 12px; border: 1px solid #e4e9f4; border-radius: 10px; background: white; color: #52617b; font-size: 11px; cursor: pointer; transition: opacity .15s, background .15s; }
.chart-series-controls button:hover { background: #f7f9fe; }
.chart-series-controls button:focus-visible, .chart-day-target:focus-visible { outline: 2px solid #6879ff; outline-offset: 3px; }
.chart-series-controls button:disabled { cursor: default; }
.chart-series-controls button.series-hidden { opacity: .5; background: #f6f7fb; }
.chart-series-controls strong { margin-left: 8px; font-size: 14px; color: #243653; font-variant-numeric: tabular-nums; }
.chart-color-dot { display: inline-block; flex: none; width: 8px; height: 8px; border-radius: 50%; background: var(--series-color); }
.chart-plot { padding: 12px 20px 0; }
.chart-plot svg { display: block; width: 100%; height: auto; max-height: 310px; overflow: visible; }
.chart-grid-line { stroke: #edf0f7; stroke-dasharray: 3 5; }
.chart-axis-label { font-size: 10px; fill: #8d9bb1; font-family: inherit; }
.chart-selected-line { stroke: #d9e0ee; stroke-dasharray: 4 4; }
.chart-day-target { fill: transparent; cursor: pointer; }
.chart-day-target:focus-visible { fill: #6879ff08; }
.chart-day-summary { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 18px; margin: 9px 20px 13px; padding: 12px 14px; background: #f8faff; border: 1px solid #edf0f7; border-radius: 10px; color: #687791; font-size: 10px; }
.chart-day-summary > strong { margin-right: auto; color: #354664; font-variant-numeric: tabular-nums; }
.chart-day-summary span { display: inline-flex; align-items: center; gap: 6px; }
.chart-day-summary b { color: #354664; font-size: 12px; }
.chart-note { margin: 0; padding: 0 20px 18px; color: #8d9bb1; font-size: 10px; line-height: 1.7; }
.chart-empty-state { margin: 12px 20px 0; padding: 10px 12px; border-radius: 8px; background: #f8faff; color: #7b8ba5; font-size: 11px; }
.chart-status-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; }
.chart-status-body { display: flex; align-items: center; gap: 22px; padding: 17px 20px 12px; }
.chart-donut { position: relative; flex: 0 0 170px; width: 170px; height: 170px; }
.chart-donut svg { display: block; width: 100%; height: 100%; }
.chart-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; }
.chart-donut-center strong { color: #243653; font-size: 30px; letter-spacing: -.04em; font-variant-numeric: tabular-nums; }
.chart-donut-center small { color: #8d9bb1; font-size: 9px; }
.chart-status-legend { flex: 1; min-width: 0; margin: 0; }
.chart-status-legend > div { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 0; border-bottom: 1px solid #f0f3f8; }
.chart-status-legend > div:last-child { border-bottom: 0; }
.chart-status-legend dt { display: flex; align-items: center; gap: 8px; color: #687791; font-size: 11px; }
.chart-status-legend dd { display: flex; align-items: center; gap: 8px; margin: 0; color: #354664; font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; }
.chart-status-legend dd small { width: 30px; color: #a0adbe; font-size: 9px; font-weight: 400; text-align: right; }
@media (max-width: 1100px) { .chart-status-body { gap: 12px; padding: 15px; } .chart-donut { flex-basis: 140px; width: 140px; height: 140px; } }
@media (max-width: 800px) { .chart-status-grid { grid-template-columns: 1fr; } .chart-status-body { gap: 20px; } .chart-donut { flex-basis: 160px; width: 160px; height: 160px; } .chart-axis-label { font-size: 15px; } }
@media (max-width: 480px) {
    .chart-period { font-size: 9px; padding: 6px; }
    .chart-series-controls { gap: 7px; padding: 14px 12px 4px; }
    .chart-series-controls button { flex: 1 1 130px; justify-content: space-between; gap: 6px; font-size: 10px; }
    .chart-series-controls button > span:nth-child(2) { margin-right: auto; }
    .chart-series-controls strong { margin-left: 0; }
    .chart-plot { padding: 12px 10px 0; }
    .chart-axis-label { font-size: 20px; }
    .chart-day-summary { margin: 7px 12px 12px; gap: 9px 12px; }
    .chart-day-summary > strong { flex-basis: 100%; }
    .chart-status-body { gap: 10px; padding: 12px; }
    .chart-donut { flex-basis: 120px; width: 120px; height: 120px; }
    .chart-donut-center strong { font-size: 26px; }
    .chart-status-legend dt { font-size: 10px; gap: 6px; }
    .chart-status-legend dd { font-size: 12px; gap: 5px; }
    .chart-status-legend dd small { width: 25px; }
    .chart-note { padding: 0 12px 14px; font-size: 9px; }
}
</style>
