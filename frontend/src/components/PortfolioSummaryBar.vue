<template>
  <div :class="compact ? 'grid grid-cols-[auto_auto_auto_auto_auto] items-center gap-x-1 gap-y-0.5 text-xs w-fit' : 'flex flex-col sm:flex-row gap-3 px-4 py-3 bg-base-100 border border-hairline rounded-md'">

    <!-- 환율 정보 -->
    <div :class="compact ? 'col-span-5 grid grid-cols-subgrid items-center' : 'flex items-center gap-2 shrink-0 sm:border-r sm:border-hairline sm:pr-3'">
      <span :class="compact ? 'text-xs font-medium font-mono text-base-content/70' : 'text-sm font-medium font-mono text-base-content/70'">
        환율<template v-if="!compact"> {{ exchangeRate ? Number(exchangeRate.USD_KRW).toFixed(2) : '—' }}</template>
      </span>
      <span v-if="compact" class="justify-self-end text-xs font-mono text-base-content/70 px-1 py-0.5">
        {{ exchangeRate ? Number(exchangeRate.USD_KRW).toFixed(2) : '—' }}
      </span>
      <!-- 환율 전일 대비 등락폭·등락률 (손익·등락률 열 재사용, prev_close 없으면 미표시 → subgrid로 레이아웃 유지) -->
      <span
        v-if="compact && fxDelta !== null"
        class="col-start-4 justify-self-end ml-2 text-xs font-semibold font-mono rounded-xs px-1 py-0.5"
        :class="profitColorClass(fxDelta)"
      >{{ (fxDelta >= 0 ? '+' : '-') + Math.abs(fxDelta).toFixed(2) }}</span>
      <span
        v-if="compact && fxDelta !== null"
        class="col-start-5 justify-self-end text-2xs font-medium font-mono px-2 py-0.5 rounded-xs"
        :class="profitColorClass(fxDelta)"
      >{{ formatProfitRate(fxRate) }}</span>
      <span v-if="exchangeRate && !compact" class="text-xs font-mono text-base-content/40">
        {{ fxSourceLabel }} · {{ formatRecordedAt(exchangeRate.recorded_at) }}
      </span>
      <span
        v-if="exchangeRate && !compact"
        class="text-xs text-base-content/40 cursor-help select-none"
        title="증권사(KIS)가 쓰는 KIS 고시환율 기준입니다. 미국 장중에는 증권사 평가손익과 거의 일치하고, 미국 휴장(주말·공휴일) 중에는 증권사가 마지막 마감 시점 환율로 평가를 고정해 보여주므로 기준 시각 차이로 평가손익이 미세하게 다를 수 있습니다."
      >ⓘ</span>
    </div>

    <!-- 보유 없음 -->
    <div
      v-if="!usSummary.hasHoldings && !krSummary.hasHoldings"
      :class="compact ? 'col-span-5' : 'flex items-center'"
    >
      <span :class="compact ? 'text-xs text-base-content/40 font-mono' : 'text-sm text-base-content/40 font-mono'">보유 종목 없음</span>
    </div>

    <!-- 미국주식 손익 (미국 = 중립 시장 라벨 → accent 배지) -->
    <div
      v-if="usSummary.hasHoldings"
      :class="compact ? 'col-span-5 grid grid-cols-subgrid items-center' : 'flex items-center gap-3'"
    >
      <FlagIcon market="US" class="shrink-0" />
      <span :class="[compact ? 'justify-self-end text-xs font-semibold font-mono text-base-content/80' : 'text-sm font-semibold font-mono text-base-content/80', 'transition-colors duration-260 rounded-xs px-1 py-0.5', flashTint(usFlash)]">
        {{ usSummary.marketValueUSD.toFixed(2) }}<template v-if="!compact">$</template>
      </span>
      <span v-if="compact" class="justify-self-start text-xs font-semibold font-mono text-base-content/80">$</span>
      <span
        :class="[compact ? 'justify-self-end ml-2 text-xs font-semibold font-mono' : 'text-sm font-semibold font-mono', 'transition-colors duration-260 rounded-xs px-1 py-0.5', profitColorClass(usSummary.profitUSD, 'us'), flashTint(usFlash)]"
      >{{ formatProfitUSD(usSummary.profitUSD) }}</span>
      <span
        class="text-2xs font-medium font-mono px-2 py-0.5 rounded-xs transition-colors duration-260"
        :class="[compact && 'justify-self-end', profitColorClass(usSummary.profitUSD, 'us'), flashTint(usFlash)]"
      >{{ formatProfitRate(usSummary.profitRate) }}</span>
    </div>

    <!-- 구분선 (둘 다 있을 때, compact 시 숨김) -->
    <div
      v-if="usSummary.hasHoldings && krSummary.hasHoldings && !compact"
      class="hidden sm:block w-px bg-hairline self-stretch shrink-0"
    ></div>

    <!-- 국내주식 손익 (국내 = 중립 시장 라벨 → accent 배지) -->
    <div
      v-if="krSummary.hasHoldings"
      :class="compact ? 'col-span-5 grid grid-cols-subgrid items-center' : 'flex items-center gap-3'"
    >
      <FlagIcon market="KR" class="shrink-0" />
      <span :class="[compact ? 'justify-self-end text-xs font-semibold font-mono text-base-content/80' : 'text-sm font-semibold font-mono text-base-content/80', 'transition-colors duration-260 rounded-xs px-1 py-0.5', flashTint(krFlash)]">
        {{ compact ? Math.round(krSummary.marketValueKRW).toLocaleString() : formatWon(krSummary.marketValueKRW) }}
      </span>
      <span v-if="compact" class="justify-self-start text-xs font-semibold font-mono text-base-content/80">원</span>
      <span
        :class="[compact ? 'justify-self-end ml-2 text-xs font-semibold font-mono' : 'text-sm font-semibold font-mono', 'transition-colors duration-260 rounded-xs px-1 py-0.5', profitColorClass(krSummary.profitKRW, 'kr'), flashTint(krFlash)]"
      >{{ formatProfitWon(krSummary.profitKRW) }}</span>
      <span
        class="text-2xs font-medium font-mono px-2 py-0.5 rounded-xs transition-colors duration-260"
        :class="[compact && 'justify-self-end', profitColorClass(krSummary.profitKRW, 'kr'), flashTint(krFlash)]"
      >{{ formatProfitRate(krSummary.profitRate) }}</span>
    </div>

  </div>
</template>

<script setup>
import { computed, ref, watch, onUnmounted } from 'vue';
import FlagIcon from './FlagIcon.vue';
import {
  formatWon,
  formatProfitWon,
  formatProfitUSD,
  formatProfitRate,
  formatRecordedAt,
  profitColorClass,
} from '../utils/format.js';

const props = defineProps({
  holdings: {
    type: Array,
    default: () => [],
  },
  exchangeRate: {
    // { USD_KRW: number, recorded_at: string, source: string } | null
    type: Object,
    default: null,
  },
  compact: {
    type: Boolean,
    default: false,
  },
});

// ── computed ──────────────────────────────────────────────────

const fxSourceLabel = computed(() => {
  const src = props.exchangeRate?.source || '';
  if (src.includes('KIS')) return 'KIS 고시환율';
  if (src.toLowerCase().includes('yahoo')) return 'Yahoo 환율';
  if (src.includes('db_fallback')) return '최근 환율';
  return '환율';
});

// 환율 전일 대비 등락폭 (USD_KRW - prev_close). prev_close null/미제공 → null
const fxDelta = computed(() => {
  const r = props.exchangeRate;
  if (!r || r.prev_close == null || r.USD_KRW == null) return null;
  return Number(r.USD_KRW) - Number(r.prev_close);
});
// 환율 등락률(소수 비율) — formatProfitRate 가 ×100 처리. prev_close 0/null → null
const fxRate = computed(() => {
  const r = props.exchangeRate;
  if (!r || !Number(r.prev_close) || r.USD_KRW == null) return null;
  return (Number(r.USD_KRW) - Number(r.prev_close)) / Number(r.prev_close);
});

// 미국주식 합계 (달러 기준) — PortfolioDashboard.computed.usSummary 와 동일 로직
const usSummary = computed(() => {
  const usHoldings = props.holdings.filter(h => h.market === 'US');
  if (usHoldings.length === 0) {
    return { hasHoldings: false, profitUSD: 0, marketValueUSD: 0, costUSD: 0, profitRate: 0 };
  }
  let profitUSD = 0;
  let marketValueUSD = 0;
  let costUSD = 0;
  let hasPrice = false;
  usHoldings.forEach(h => {
    if (h.price_available && h.current_price !== null && h.average_price !== null) {
      const qty = Number(h.quantity);
      const cur = Number(h.current_price);
      const avg = Number(h.average_price);
      const mv = cur * qty;
      const cv = avg * qty;
      marketValueUSD += mv;
      costUSD += cv;
      profitUSD += mv - cv;
      hasPrice = true;
    } else {
      const qty = Number(h.quantity);
      const avg = Number(h.average_price);
      costUSD += avg * qty;
    }
  });
  const profitRate = costUSD > 0 ? (profitUSD / costUSD) : 0;
  return {
    hasHoldings: true,
    profitUSD: hasPrice ? profitUSD : null,
    marketValueUSD,
    costUSD,
    profitRate: hasPrice ? profitRate : null,
  };
});

// 국내주식 합계 (원화 기준) — PortfolioDashboard.computed.krSummary 와 동일 로직
const krSummary = computed(() => {
  const krHoldings = props.holdings.filter(h => h.market === 'KR');
  if (krHoldings.length === 0) {
    return { hasHoldings: false, profitKRW: 0, marketValueKRW: 0, costKRW: 0, profitRate: 0 };
  }
  let profitKRW = 0;
  let marketValueKRW = 0;
  let costKRW = 0;
  let hasPrice = false;
  krHoldings.forEach(h => {
    if (h.price_available && h.profitKRW !== null) {
      profitKRW += Number(h.profitKRW);
      marketValueKRW += Number(h.marketValueKRW || 0);
      costKRW += Number(h.costKRW || 0);
      hasPrice = true;
    } else {
      costKRW += Number(h.costKRW || 0);
    }
  });
  const profitRate = costKRW > 0 ? (profitKRW / costKRW) : 0;
  return {
    hasHoldings: true,
    profitKRW: hasPrice ? profitKRW : null,
    marketValueKRW,
    costKRW,
    profitRate: hasPrice ? profitRate : null,
  };
});

// ── 틱 플래시 (다른 컴포넌트와 동일: 260ms, 상승=up-weak / 하락=down-weak) ──
// 한 시장(US/KR)의 평가액·손익액·손익률은 전부 같은 시세에서 파생돼 항상 같은 방향으로 움직이므로
// 시장별 1개 플래시 상태로 3항목을 함께 점등한다(평가액 델타 기준). US↔KR 은 독립.
// prefers-reduced-motion 은 전역 CSS(style.css)가 transition-duration 을 무효화해 자연 존중.
const usFlash = ref('');
const krFlash = ref('');
const flashTimers = {};
function triggerFlash(which, oldV, newV) {
  if (oldV == null || newV == null || newV === oldV) return;
  const dir = newV > oldV ? 'up' : 'down';
  if (which === 'us') usFlash.value = dir;
  else krFlash.value = dir;
  clearTimeout(flashTimers[which]);
  flashTimers[which] = setTimeout(() => {
    if (which === 'us') usFlash.value = '';
    else krFlash.value = '';
  }, 260); // 설계서 §6: 가격 틱 플래시 260ms 통일
}
function flashTint(dir) {
  return dir === 'up' ? 'bg-up-weak' : dir === 'down' ? 'bg-down-weak' : '';
}
watch(() => usSummary.value.marketValueUSD, (n, o) => triggerFlash('us', o, n));
watch(() => krSummary.value.marketValueKRW, (n, o) => triggerFlash('kr', o, n));
onUnmounted(() => Object.values(flashTimers).forEach(clearTimeout));

</script>
