<template>
  <div class="card bg-base-100/45 backdrop-blur-md border border-base-content/8 rounded-2xl overflow-hidden">

    <!-- 토스트 알림 -->
    <Transition name="toast-slide">
      <div
        v-if="toast.show"
        class="fixed top-16 right-4 z-50 flex items-center gap-2.5 px-4 py-3 rounded-xl border shadow-lg text-xs font-bold font-mono transition-all duration-300"
        :class="toast.type === 'success'
          ? 'bg-emerald-900/90 border-emerald-500/30 text-emerald-300'
          : toast.type === 'warn'
            ? 'bg-amber-900/90 border-amber-500/30 text-amber-300'
            : 'bg-error/15 border-error/30 text-error'"
        role="alert"
        aria-live="polite"
      >
        <span v-if="toast.type === 'success'">✓</span>
        <span v-else-if="toast.type === 'warn'">!</span>
        <span v-else>✕</span>
        {{ toast.message }}
      </div>
    </Transition>

    <!-- 섹션 헤더 -->
    <div class="flex items-center justify-between px-4 md:px-5 py-3.5 border-b border-base-content/8">
      <div class="flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </svg>
        <h2 class="text-sm font-black text-white tracking-tight leading-none">보유 종목</h2>
        <span class="px-2 py-0.5 rounded-full text-xs font-extrabold font-mono text-indigo-400 bg-indigo-500/10 border border-indigo-500/20">
          {{ holdings.length }}
        </span>
      </div>
      <!-- 보유 추가 버튼 -->
      <button
        @click="openHoldingModal()"
        class="btn btn-xs bg-indigo-600/80 hover:bg-indigo-500 border-indigo-500/30 text-white font-bold gap-1 rounded-lg cursor-pointer transition-all duration-200"
        aria-label="보유 종목 추가"
      >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        추가
      </button>
    </div>

    <!-- 빈 상태 -->
    <div v-if="holdings.length === 0" class="flex flex-col items-center justify-center py-14 gap-3 select-none">
      <div class="w-12 h-12 rounded-xl border-2 border-dashed border-base-content/12 flex items-center justify-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
      </div>
      <div class="text-center">
        <p class="text-sm font-bold text-base-content/35">보유 종목을 추가하세요</p>
        <p class="text-xs text-base-content/22 mt-0.5">우측 상단 '추가' 버튼으로 종목을 등록하세요</p>
      </div>
    </div>

    <!-- 보유 종목 테이블 -->
    <div v-else class="overflow-x-auto custom-scrollbar">
      <table class="w-full min-w-200" role="table" aria-label="보유 종목 목록">
        <thead>
          <tr class="text-xs font-extrabold text-base-content/35 tracking-wider uppercase border-b border-base-content/6">
            <th class="text-left px-4 md:px-5 py-3 font-extrabold">종목</th>
            <th class="text-right px-3 py-3 font-extrabold">수량</th>
            <th class="text-right px-3 py-3 font-extrabold">평단가</th>
            <th class="text-right px-3 py-3 font-extrabold">현재가</th>
            <th class="text-right px-3 py-3 font-extrabold">미실현손익</th>
            <th class="text-right px-3 py-3 font-extrabold">손익률</th>
            <th class="text-center px-4 md:px-5 py-3 font-extrabold">관리</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in holdings"
            :key="item.portfolio_id"
            class="border-b border-base-content/4 last:border-b-0 hover:bg-base-200/20 transition-colors duration-150 cursor-pointer"
            role="row"
            :title="`${item.name || item.symbol} 차트 보기`"
            @click.stop="openChartModal(item)"
          >
            <!-- 종목명·심볼·마켓 -->
            <td class="px-4 md:px-5 py-3.5">
              <div class="flex flex-col gap-0.5">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="text-sm font-black text-white leading-tight">{{ displayName(item) }}</span>
                  <span class="px-1.5 py-0.5 rounded text-xs font-bold font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/15 leading-tight">{{ item.symbol }}</span>
                </div>
                <span
                  class="text-xs font-bold font-mono px-1.5 py-0.5 rounded self-start"
                  :class="item.market === 'KR'
                    ? 'text-rose-400/70 bg-rose-500/6'
                    : 'text-emerald-400/70 bg-emerald-500/6'"
                >{{ item.market }}</span>
              </div>
            </td>

            <!-- 수량 -->
            <td class="px-3 py-3.5 text-right">
              <span class="text-sm font-bold font-mono text-white/80">{{ formatQuantity(item.quantity) }}</span>
            </td>

            <!-- 평단가 -->
            <td class="px-3 py-3.5 text-right">
              <span class="text-sm font-bold font-mono text-base-content/60">
                {{ formatPrice(item.currency, item.average_price) }}
              </span>
            </td>

            <!-- 현재가 -->
            <td class="px-3 py-3.5 text-right">
              <span
                v-if="item.price_available && item.current_price !== null"
                class="text-sm font-extrabold font-mono"
                :class="item.market === 'US'
                  ? profitColorClass(calcUSDProfit(item), 'us')
                  : profitColorClass(item.profitKRW, 'kr')"
              >
                {{ formatPrice(item.currency, item.current_price) }}
              </span>
              <span v-else class="text-sm font-mono text-base-content/20">—</span>
            </td>

            <!-- 미실현손익 -->
            <td class="px-3 py-3.5 text-right">
              <template v-if="item.market === 'US'">
                <span
                  v-if="item.price_available && item.current_price !== null && item.average_price !== null"
                  class="text-sm font-black font-mono"
                  :class="profitColorClass(calcUSDProfit(item), 'us')"
                >{{ formatProfitUSD(calcUSDProfit(item)) }}</span>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
              <template v-else>
                <span
                  v-if="item.price_available && item.profitKRW !== null"
                  class="text-sm font-black font-mono"
                  :class="profitColorClass(item.profitKRW, 'kr')"
                >{{ formatProfitWon(item.profitKRW) }}</span>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
            </td>

            <!-- 손익률 -->
            <td class="px-3 py-3.5 text-right">
              <template v-if="item.market === 'US'">
                <span
                  v-if="item.price_available && item.current_price !== null && item.average_price !== null"
                  class="text-sm font-extrabold font-mono"
                  :class="profitColorClass(calcUSDProfit(item), 'us')"
                >{{ formatProfitRate(item.profitRate) }}</span>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
              <template v-else>
                <span
                  v-if="item.price_available && item.profitKRW !== null"
                  class="text-sm font-extrabold font-mono"
                  :class="profitColorClass(item.profitKRW, 'kr')"
                >{{ formatProfitRate(item.profitRate) }}</span>
                <span v-else class="text-sm font-mono text-base-content/20">—</span>
              </template>
            </td>

            <!-- 관리 버튼 -->
            <td class="px-4 md:px-5 py-3.5 text-center">
              <div class="flex items-center justify-center gap-1.5">
                <button
                  @click.stop="openHoldingModal(item)"
                  class="btn btn-xs btn-ghost border border-base-content/10 hover:border-indigo-500/40 hover:bg-indigo-500/8 text-base-content/40 hover:text-indigo-400 rounded-lg cursor-pointer transition-all duration-150"
                  :aria-label="`${item.symbol} 수정`"
                  title="수정"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </button>
                <button
                  @click.stop="deleteHolding(item)"
                  :disabled="actionLoading"
                  class="btn btn-xs btn-ghost border border-base-content/10 hover:border-error/40 hover:bg-error/8 text-base-content/40 hover:text-error rounded-lg cursor-pointer transition-all duration-150 disabled:opacity-40"
                  :aria-label="`${item.symbol} 삭제`"
                  title="삭제"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ══════════════════════════════════════════════════════
         차트 모달 (행 클릭 시)
    ══════════════════════════════════════════════════════ -->
    <Transition name="modal-fade">
      <div
        v-if="showChartModal"
        class="fixed inset-0 z-60 flex flex-col"
        role="dialog"
        aria-modal="true"
        :aria-label="chartModalItem ? `${chartModalItem.name || chartModalItem.symbol} 차트` : '종목 차트'"
      >
        <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="closeChartModal"></div>

        <div class="relative z-10 m-auto w-full max-w-5xl h-[90vh] sm:h-[80vh] min-h-0 sm:min-h-120 mx-2 sm:mx-auto bg-base-100 border border-base-content/12 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
          <!-- 모달 헤더 -->
          <div class="flex items-center justify-between px-5 py-3 border-b border-base-content/8 shrink-0">
            <div class="flex items-center gap-2">
              <span class="px-2 py-0.5 rounded-md text-xs font-extrabold font-mono text-indigo-300 bg-indigo-500/12 border border-indigo-500/20 tracking-wider">
                {{ chartModalItem ? chartModalItem.symbol : '' }}
              </span>
              <span class="text-sm font-black text-white">{{ chartModalItem ? (chartModalItem.name || chartModalItem.symbol) : '' }}</span>
              <span class="px-1.5 py-0.5 rounded text-xs font-extrabold font-mono border leading-tight text-indigo-400 bg-indigo-500/10 border-indigo-500/20">보유</span>
            </div>

            <div class="flex items-center gap-2">
              <span v-if="chartLoading" class="loading loading-spinner loading-xs text-indigo-400"></span>
              <span v-if="chartError" class="text-xs text-error font-bold font-mono">데이터 오류</span>

              <button
                @click="closeChartModal"
                class="w-7 h-7 flex items-center justify-center rounded-lg text-base-content/40 hover:text-base-content/80 hover:bg-base-200/60 transition-all cursor-pointer"
                aria-label="차트 닫기"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <!-- 차트 본체 -->
          <div class="flex-1 min-h-0 p-3">
            <div v-if="chartLoading" class="h-full flex items-center justify-center gap-3">
              <span class="loading loading-ring loading-md text-indigo-500"></span>
              <span class="text-xs font-bold text-base-content/50 font-mono">차트 데이터 불러오는 중...</span>
            </div>

            <div v-else-if="chartError" class="h-full flex flex-col items-center justify-center gap-3">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-error/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="text-xs font-bold text-error/70 font-mono">{{ chartErrorMessage }}</p>
              <button @click="fetchChartCandles" class="btn btn-xs btn-outline btn-error font-bold rounded-lg cursor-pointer">재시도</button>
            </div>

            <StockChart
              v-else-if="chartCandles.length > 0 && chartModalItem"
              :key="`holdings_chart_${chartModalItem.symbol}_${chartSelectedTimeframe}`"
              :ticker="chartModalItem.symbol"
              :name="chartModalItem.name || chartModalItem.symbol"
              :current-price="chartModalItem.current_price ?? null"
              :change-amount="chartModalItem.change_amount ?? null"
              :change-percent="chartModalItem.change_percent ?? null"
              :candles="chartCandles"
              :session="chartModalItem.session_badge ? sessionBadgeToLabel(chartModalItem.session_badge) : ''"
              :timeframe="chartSelectedTimeframe"
              :average-price="chartModalAveragePrice"
              @timeframe-change="onChartTimeframeChange"
            />

            <div v-else-if="!chartLoading" class="h-full flex flex-col items-center justify-center gap-3">
              <p class="text-xs font-bold text-base-content/40 font-mono">차트 데이터가 없습니다 (장외 시간 또는 휴장일)</p>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ══════════════════════════════════════════════════════
         보유 종목 추가/수정 모달
    ══════════════════════════════════════════════════════ -->
    <Transition name="modal-fade">
      <div
        v-if="showHoldingModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="editingHolding ? '보유 종목 수정' : '보유 종목 추가'"
        @click.self="closeHoldingModal"
      >
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeHoldingModal"></div>

        <div class="relative z-10 w-full max-w-md bg-base-100 border border-base-content/12 rounded-2xl shadow-2xl overflow-hidden">
          <!-- 모달 헤더 -->
          <div class="flex items-center justify-between px-5 py-4 border-b border-base-content/8">
            <h3 class="text-sm font-black text-white tracking-tight">
              {{ editingHolding ? '보유 종목 수정' : '보유 종목 추가' }}
            </h3>
            <button
              @click="closeHoldingModal"
              class="w-7 h-7 flex items-center justify-center rounded-lg text-base-content/40 hover:text-base-content/80 hover:bg-base-200/60 transition-all cursor-pointer"
              aria-label="닫기"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- 모달 폼 -->
          <form @submit.prevent="submitHoldingForm" class="p-4 sm:p-5 space-y-4">

            <!-- 종목 선택 (추가 모드만) -->
            <div v-if="!editingHolding" class="space-y-1.5" ref="holdingSearchContainer">
              <label class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">종목 선택</label>

              <!-- 선택된 종목 표시 -->
              <div v-if="holdingForm.symbol" class="flex items-center justify-between px-3 py-2.5 rounded-xl bg-indigo-500/8 border border-indigo-500/25">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-black text-white">{{ holdingForm.symbol }}</span>
                  <span
                    class="text-xs font-bold font-mono px-1.5 py-0.5 rounded"
                    :class="holdingForm.market === 'KR' ? 'text-rose-400/70 bg-rose-500/6' : 'text-emerald-400/70 bg-emerald-500/6'"
                  >{{ holdingForm.market }}</span>
                  <span v-if="holdingForm.stockName" class="text-xs text-base-content/50 truncate max-w-32">{{ holdingForm.stockName }}</span>
                </div>
                <button
                  type="button"
                  @click="clearHoldingStock"
                  class="text-base-content/30 hover:text-base-content/60 cursor-pointer"
                  aria-label="종목 선택 취소"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <!-- 검색 입력 (종목 미선택 시) -->
              <div v-else class="relative">
                <div class="flex items-center gap-1.5">
                  <!-- 시장 선택 -->
                  <div class="tabs tabs-boxed bg-base-200/70 p-0.5 rounded-lg border border-base-content/6 gap-0 shrink-0">
                    <button
                      v-for="m in searchModeOptions"
                      :key="m.value"
                      type="button"
                      @click="holdingSearchMode = m.value; holdingSearchResults = []"
                      :class="[
                        'tab rounded-md text-xs font-extrabold transition-all duration-200 cursor-pointer px-2 py-1',
                        holdingSearchMode === m.value
                          ? 'tab-active bg-indigo-600/15 border border-indigo-500/25 text-indigo-400 shadow-sm'
                          : 'text-base-content/40 hover:text-base-content/70 border border-transparent'
                      ]"
                    >{{ m.label }}</button>
                  </div>
                  <input
                    v-model="holdingQuery"
                    @input="onHoldingSearchInput"
                    @focus="showHoldingSearchDropdown = true"
                    type="text"
                    placeholder="종목명 / 티커 검색..."
                    class="input input-sm input-bordered flex-1 font-semibold text-xs focus:outline-none focus:border-indigo-500/60 placeholder:text-base-content/25 bg-base-200/50 rounded-lg"
                    aria-label="보유 종목 검색"
                    autocomplete="off"
                  />
                </div>

                <!-- 검색 결과 드롭다운 -->
                <Transition name="fade-slide">
                  <div
                    v-if="showHoldingSearchDropdown && holdingSearchResults.length > 0"
                    class="absolute left-0 right-0 top-full mt-1 border border-base-content/10 rounded-xl shadow-2xl z-50 max-h-52 overflow-y-auto backdrop-blur-xl bg-base-100/97 custom-scrollbar"
                  >
                    <div
                      v-for="stock in holdingSearchResults"
                      :key="stock.ticker"
                      @click.stop="selectHoldingStock(stock)"
                      class="flex items-center justify-between px-3 py-2.5 cursor-pointer hover:bg-indigo-500/6 transition-colors border-b border-base-content/4 last:border-b-0 group"
                    >
                      <div class="flex flex-col min-w-0 flex-1 mr-2">
                        <div class="flex items-center gap-1.5 flex-wrap">
                          <span class="text-white font-bold text-sm group-hover:text-indigo-300 transition-colors">{{ stock.name }}</span>
                          <span class="px-1 py-0.5 rounded text-xs font-bold font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/15">{{ stock.ticker }}</span>
                          <span
                            class="text-xs font-bold font-mono px-1 py-0.5 rounded"
                            :class="stock.isKorean ? 'text-rose-400/70 bg-rose-500/6' : 'text-emerald-400/70 bg-emerald-500/6'"
                          >{{ stock.isKorean ? 'KR' : 'US' }}</span>
                        </div>
                        <span v-if="stock.subName" class="text-xs text-base-content/35 mt-0.5 truncate">{{ stock.subName }}</span>
                      </div>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-base-content/20 group-hover:text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                      </svg>
                    </div>
                  </div>
                </Transition>
              </div>
            </div>

            <!-- 수정 모드: 종목 정보 (읽기 전용) -->
            <div v-if="editingHolding" class="flex items-center gap-2 px-3 py-2.5 rounded-xl bg-base-200/40 border border-base-content/8">
              <span class="text-sm font-black text-white">{{ editingHolding.symbol }}</span>
              <span
                class="text-xs font-bold font-mono px-1.5 py-0.5 rounded"
                :class="editingHolding.market === 'KR' ? 'text-rose-400/70 bg-rose-500/6' : 'text-emerald-400/70 bg-emerald-500/6'"
              >{{ editingHolding.market }}</span>
              <span class="text-xs text-base-content/40 truncate">{{ editingHolding.name }}</span>
            </div>

            <!-- 수량 -->
            <div class="space-y-1.5">
              <label for="hp-qty" class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">수량</label>
              <input
                id="hp-qty"
                v-model="holdingForm.quantity"
                type="number"
                min="0.0001"
                step="any"
                placeholder="보유 수량"
                class="input input-sm input-bordered w-full font-mono text-sm focus:outline-none focus:border-indigo-500/60 bg-base-200/50 rounded-lg"
                :class="formErrors.quantity ? 'border-error/60' : ''"
                required
              />
              <p v-if="formErrors.quantity" class="text-xs text-error font-bold font-mono mt-0.5">{{ formErrors.quantity }}</p>
            </div>

            <!-- 평단가 -->
            <div class="space-y-1.5">
              <label for="hp-avg" class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">
                평단가
                <span class="font-normal text-base-content/35 ml-1 normal-case">
                  ({{ holdingFormMarket === 'KR' ? '원화' : 'USD $' }})
                </span>
              </label>
              <input
                id="hp-avg"
                v-model="holdingForm.average_price"
                type="number"
                min="0.0001"
                step="any"
                :placeholder="holdingFormMarket === 'KR' ? '매입 평단 (원)' : '매입 평단 (USD)'"
                class="input input-sm input-bordered w-full font-mono text-sm focus:outline-none focus:border-indigo-500/60 bg-base-200/50 rounded-lg"
                :class="formErrors.average_price ? 'border-error/60' : ''"
                required
              />
              <p v-if="formErrors.average_price" class="text-xs text-error font-bold font-mono mt-0.5">{{ formErrors.average_price }}</p>
            </div>

            <!-- 매입환율 (US 종목만) -->
            <div v-if="holdingFormMarket === 'US'" class="space-y-1.5">
              <label for="hp-fx" class="text-xs font-extrabold text-base-content/50 tracking-wider uppercase">
                매입환율
                <span class="font-normal text-base-content/35 ml-1 normal-case">USD/KRW — 매입 당시 환율</span>
              </label>
              <input
                id="hp-fx"
                v-model="holdingForm.avg_fx_rate"
                type="number"
                min="1"
                step="0.01"
                placeholder="예: 1350.00"
                class="input input-sm input-bordered w-full font-mono text-sm focus:outline-none focus:border-indigo-500/60 bg-base-200/50 rounded-lg"
                :class="formErrors.avg_fx_rate ? 'border-error/60' : ''"
                required
              />
              <p v-if="formErrors.avg_fx_rate" class="text-xs text-error font-bold font-mono mt-0.5">{{ formErrors.avg_fx_rate }}</p>
            </div>

            <!-- 폼 레벨 에러 -->
            <div
              v-if="formErrors._form"
              class="flex items-start gap-2 px-3 py-2.5 rounded-lg bg-error/8 border border-error/20"
              role="alert"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-error/70 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-xs text-error/80 font-bold font-mono">{{ formErrors._form }}</span>
            </div>

            <!-- 버튼 영역 -->
            <div class="flex gap-2 pt-1">
              <button
                type="button"
                @click="closeHoldingModal"
                class="btn btn-sm btn-ghost flex-1 font-bold border border-base-content/10 hover:bg-base-200/40 rounded-xl cursor-pointer"
              >취소</button>
              <button
                type="submit"
                :disabled="actionLoading || (!editingHolding && !holdingForm.symbol)"
                class="btn btn-sm flex-1 font-bold rounded-xl cursor-pointer bg-indigo-600 hover:bg-indigo-500 text-white border-0 disabled:opacity-40"
              >
                <span v-if="actionLoading" class="loading loading-spinner loading-xs"></span>
                {{ editingHolding ? '저장' : '추가' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import StockChart from './StockChart.vue';
import { localSearch, normalizeKrTicker, SEARCHABLE_STOCKS } from '../stocksKnown.js';
import {
  formatWon,
  formatProfitWon,
  formatProfitUSD,
  formatProfitRate,
  formatQuantity,
  formatPrice,
  profitColorClass,
  profitBadgeClass,
  displayName as _displayName,
} from '../utils/format.js';

const SEARCH_MODE_OPTIONS = [
  { value: 'kr',  label: 'KR' },
  { value: 'us',  label: 'US' },
  { value: 'all', label: '전체' },
];

// ── props / emits ──────────────────────────────────────────────
const props = defineProps({
  holdings: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['refresh']);

// ── 상수 ────────────────────────────────────────────────────────
const searchModeOptions = SEARCH_MODE_OPTIONS;

// ── 보유 종목 모달 ────────────────────────────────────────────
const showHoldingModal = ref(false);
const editingHolding = ref(null);
const holdingForm = ref({
  symbol: '',
  market: '',
  stockName: '',
  quantity: '',
  average_price: '',
  avg_fx_rate: '',
});
const holdingQuery = ref('');
const holdingSearchMode = ref('all');
const holdingSearchResults = ref([]);
const showHoldingSearchDropdown = ref(false);
let holdingSearchDebounce = null;
const formErrors = ref({});
const actionLoading = ref(false);

// ── 토스트 ────────────────────────────────────────────────────
const toast = ref({ show: false, type: 'success', message: '' });
let toastTimer = null;

// ── 차트 모달 ─────────────────────────────────────────────────
const showChartModal = ref(false);
const chartModalItem = ref(null);
const chartCandles = ref([]);
const chartSelectedTimeframe = ref('3m');
const chartLoading = ref(false);
const chartError = ref(false);
const chartErrorMessage = ref('');
let chartPollTimer = null;

// ── 템플릿 ref ────────────────────────────────────────────────
const holdingSearchContainer = ref(null);

// ── computed ──────────────────────────────────────────────────
const holdingFormMarket = computed(() => {
  if (editingHolding.value) return editingHolding.value.market;
  return holdingForm.value.market;
});

const chartModalAveragePrice = computed(() => {
  if (!chartModalItem.value) return null;
  const avg = chartModalItem.value.average_price;
  return (avg !== null && avg !== undefined) ? Number(avg) : null;
});

// ── API base ──────────────────────────────────────────────────
function apiBase() {
  const host = window.location.hostname || 'localhost';
  return `http://${host}:8000`;
}

// ── 토스트 ───────────────────────────────────────────────────
function showToast(message, type = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  toast.value = { show: true, type, message };
  toastTimer = setTimeout(() => {
    toast.value = { show: false, type: 'success', message: '' };
  }, 3500);
}

// ── 보유 종목 모달 ────────────────────────────────────────────
function openHoldingModal(item = null) {
  editingHolding.value = item || null;
  formErrors.value = {};
  if (item) {
    holdingForm.value = {
      symbol: item.symbol,
      market: item.market,
      stockName: item.name || '',
      quantity: String(item.quantity),
      average_price: String(item.average_price),
      avg_fx_rate: item.avg_fx_rate ? String(item.avg_fx_rate) : '',
    };
  } else {
    holdingForm.value = {
      symbol: '',
      market: '',
      stockName: '',
      quantity: '',
      average_price: '',
      avg_fx_rate: '',
    };
    holdingQuery.value = '';
    holdingSearchResults.value = [];
    showHoldingSearchDropdown.value = false;
    holdingSearchMode.value = 'all';
  }
  showHoldingModal.value = true;
}

function closeHoldingModal() {
  showHoldingModal.value = false;
  editingHolding.value = null;
  holdingQuery.value = '';
  holdingSearchResults.value = [];
  showHoldingSearchDropdown.value = false;
  formErrors.value = {};
}

function clearHoldingStock() {
  holdingForm.value.symbol = '';
  holdingForm.value.market = '';
  holdingForm.value.stockName = '';
  holdingQuery.value = '';
  holdingSearchResults.value = [];
}

// 검색 입력 처리
function onHoldingSearchInput() {
  if (holdingSearchDebounce) clearTimeout(holdingSearchDebounce);
  const q = holdingQuery.value.trim();
  if (!q) {
    holdingSearchResults.value = [];
    return;
  }

  // 로컬 즉시 표시
  const localResults = localSearch(q, holdingSearchMode.value);
  holdingSearchResults.value = localResults;
  if (localResults.length > 0) showHoldingSearchDropdown.value = true;

  // 백엔드 디바운스 머지
  holdingSearchDebounce = setTimeout(async () => {
    const apiResults = await fetchStockSearchApi(q, holdingSearchMode.value);
    holdingSearchResults.value = mergeSearchResults(
      localSearch(q, holdingSearchMode.value),
      apiResults
    );
    showHoldingSearchDropdown.value = true;
  }, 300);
}

function selectHoldingStock(stock) {
  holdingForm.value.symbol = stock.isKorean ? normalizeKrTicker(stock.ticker) : stock.ticker;
  holdingForm.value.market = stock.isKorean ? 'KR' : 'US';
  holdingForm.value.stockName = stock.name || '';
  holdingQuery.value = '';
  holdingSearchResults.value = [];
  showHoldingSearchDropdown.value = false;
  if (holdingForm.value.market === 'KR') {
    holdingForm.value.avg_fx_rate = '';
  }
}

// 폼 제출 (추가 or 수정)
async function submitHoldingForm() {
  formErrors.value = {};

  const qty = parseFloat(holdingForm.value.quantity);
  const avg = parseFloat(holdingForm.value.average_price);
  const market = holdingFormMarket.value;
  const fxRate = parseFloat(holdingForm.value.avg_fx_rate);

  if (!editingHolding.value && !holdingForm.value.symbol) {
    formErrors.value._form = '종목을 먼저 검색해서 선택해주세요';
    return;
  }
  if (isNaN(qty) || qty <= 0) {
    formErrors.value.quantity = '수량은 0보다 커야 합니다';
    return;
  }
  if (isNaN(avg) || avg <= 0) {
    formErrors.value.average_price = '평단가는 0보다 커야 합니다';
    return;
  }
  if (market === 'US') {
    if (!holdingForm.value.avg_fx_rate || isNaN(fxRate) || fxRate <= 0) {
      formErrors.value.avg_fx_rate = 'USD 종목은 매입환율(USD/KRW)이 필수입니다';
      return;
    }
  }

  if (actionLoading.value) return;
  actionLoading.value = true;

  try {
    if (editingHolding.value) {
      const body = { quantity: qty, average_price: avg };
      if (market === 'US') body.avg_fx_rate = fxRate;
      await axios.patch(
        `${apiBase()}/api/portfolio/${editingHolding.value.portfolio_id}`,
        body,
        { headers: { Accept: 'application/json' } }
      );
      showToast(`${editingHolding.value.symbol} 수정 완료`, 'success');
    } else {
      const body = {
        symbol: holdingForm.value.symbol,
        market,
        quantity: qty,
        average_price: avg,
        source: 'manual',
      };
      if (market === 'US') body.avg_fx_rate = fxRate;
      await axios.post(
        `${apiBase()}/api/portfolio`,
        body,
        { headers: { Accept: 'application/json' } }
      );
      showToast(`${holdingForm.value.symbol} 보유 추가 완료`, 'success');
    }
    closeHoldingModal();
    emit('refresh');
  } catch (e) {
    if (e.response?.status === 422) {
      const backendErrors = e.response.data?.errors || {};
      const backendMsg = e.response.data?.message || '';
      if (Object.keys(backendErrors).length > 0) {
        if (backendErrors.quantity) formErrors.value.quantity = backendErrors.quantity[0];
        if (backendErrors.average_price) formErrors.value.average_price = backendErrors.average_price[0];
        if (backendErrors.avg_fx_rate) formErrors.value.avg_fx_rate = backendErrors.avg_fx_rate[0];
        if (!Object.keys(formErrors.value).length) {
          formErrors.value._form = backendMsg || '입력 값을 확인해주세요';
        }
      } else {
        formErrors.value._form = backendMsg || '입력 값을 확인해주세요';
      }
    } else {
      const msg = e.response?.data?.message || e.message || '요청에 실패했습니다';
      formErrors.value._form = `오류: ${msg}`;
      console.error('[HoldingsPanel] submitHoldingForm', e);
    }
  } finally {
    actionLoading.value = false;
  }
}

// 보유 종목 삭제
async function deleteHolding(item) {
  if (actionLoading.value) return;
  if (!confirm(`'${item.name || item.symbol}'을(를) 보유 목록에서 삭제할까요?`)) return;
  actionLoading.value = true;
  try {
    await axios.delete(
      `${apiBase()}/api/portfolio/${item.portfolio_id}`,
      { headers: { Accept: 'application/json' } }
    );
    showToast(`${item.symbol} 삭제 완료`, 'success');
    emit('refresh');
  } catch (e) {
    const msg = e.response?.data?.message || '삭제에 실패했습니다';
    showToast(msg, 'error');
    console.error('[HoldingsPanel] deleteHolding', e);
  } finally {
    actionLoading.value = false;
  }
}

// ── 종목 검색 API ─────────────────────────────────────────────
async function fetchStockSearchApi(q, mode) {
  if (!q) return [];
  try {
    const res = await axios.get(`${apiBase()}/api/stocks/search`, {
      params: { q, type: mode },
      headers: { Accept: 'application/json' },
    });
    return (res.data || []).map(s => ({
      ticker: s.ticker || s.symbol,
      name: s.name || s.ticker || s.symbol,
      subName: s.exchange ? `${s.exchange} | ${s.ticker || s.symbol}` : '',
      isKorean: s.isKorean ?? (/(\.KS|\.KQ)$/i.test(s.ticker || '') || /^\d/.test(s.ticker || '')),
    }));
  } catch (e) {
    console.error('[HoldingsPanel] fetchStockSearchApi', e);
    return [];
  }
}

function mergeSearchResults(localResults, apiResults) {
  const seen = new Set(localResults.map(r => r.ticker));
  const merged = [...localResults];
  apiResults.forEach(item => {
    if (!seen.has(item.ticker)) {
      merged.push(item);
      seen.add(item.ticker);
    }
  });
  return merged.slice(0, 12);
}

// ── 드롭다운 외부 클릭 / ESC ──────────────────────────────────
function handleDocumentClick(e) {
  if (holdingSearchContainer.value && !holdingSearchContainer.value.contains(e.target)) {
    showHoldingSearchDropdown.value = false;
  }
}

function handleKeyDown(e) {
  if (e.key !== 'Escape') return;
  if (showChartModal.value) {
    closeChartModal();
  } else if (showHoldingModal.value) {
    closeHoldingModal();
  }
}

// ── 차트 모달 ─────────────────────────────────────────────────
function openChartModal(item) {
  chartModalItem.value = item;
  chartSelectedTimeframe.value = '3m';
  chartCandles.value = [];
  chartError.value = false;
  chartErrorMessage.value = '';
  showChartModal.value = true;
  fetchChartCandles();
  startChartPoll();
}

function closeChartModal() {
  showChartModal.value = false;
  stopChartPoll();
}

async function fetchChartCandles() {
  if (!chartModalItem.value) return;
  const symbol = chartModalItem.value.symbol;
  const tf = chartSelectedTimeframe.value;
  chartLoading.value = true;
  chartError.value = false;
  try {
    const res = await axios.get(
      `${apiBase()}/api/stocks/${encodeURIComponent(symbol)}`,
      {
        params: { timeframe: tf },
        headers: { Accept: 'application/json' },
      }
    );
    const data = res.data;
    chartCandles.value = Array.isArray(data.candles) ? data.candles : [];

    if (data.current_price !== undefined) {
      chartModalItem.value = {
        ...chartModalItem.value,
        current_price: data.current_price ?? chartModalItem.value.current_price,
        change_amount: data.change_amount ?? chartModalItem.value.change_amount,
        change_percent: data.change_percent ?? chartModalItem.value.change_percent,
        session: data.session ?? chartModalItem.value.session,
      };
    }
  } catch (e) {
    chartError.value = true;
    if (e.response?.status === 404) {
      chartErrorMessage.value = `${symbol} 데이터를 찾을 수 없습니다`;
    } else if (e.request) {
      chartErrorMessage.value = '백엔드 서버에 연결할 수 없습니다';
    } else {
      chartErrorMessage.value = `차트 로드 실패: ${e.message}`;
    }
    console.error('[HoldingsPanel] fetchChartCandles', e);
  } finally {
    chartLoading.value = false;
  }
}

function onChartTimeframeChange(tf) {
  if (chartSelectedTimeframe.value === tf) return;
  chartSelectedTimeframe.value = tf;
  chartCandles.value = [];
  fetchChartCandles();
}

function startChartPoll() {
  stopChartPoll();
  chartPollTimer = setInterval(() => {
    if (showChartModal.value && !chartLoading.value) {
      fetchChartCandles();
    }
  }, 30000);
}

function stopChartPoll() {
  if (chartPollTimer) {
    clearInterval(chartPollTimer);
    chartPollTimer = null;
  }
}

function sessionBadgeToLabel(badge) {
  switch (badge) {
    case 'REG': return '정규장';
    case 'PRE': return '프리마켓';
    case 'AFT': return '애프터마켓';
    default:    return '';
  }
}

// ── 로컬 유틸 ────────────────────────────────────────────────

function calcUSDProfit(item) {
  const cur = Number(item.current_price);
  const avg = Number(item.average_price);
  const qty = Number(item.quantity);
  if (isNaN(cur) || isNaN(avg) || isNaN(qty)) return null;
  return (cur - avg) * qty;
}

// displayName: format.js 의 공용 함수에 SEARCHABLE_STOCKS 를 바인딩해 사용
function displayName(item) {
  return _displayName(item, SEARCHABLE_STOCKS);
}

function sessionBadgeClass(badge) {
  switch (badge) {
    case 'REG': return 'bg-indigo-500/12 text-indigo-400 border-indigo-500/25';
    case 'PRE': return 'bg-amber-500/12 text-amber-400 border-amber-500/25';
    case 'AFT': return 'bg-purple-500/12 text-purple-400 border-purple-500/25';
    default:    return 'bg-base-200/40 text-base-content/25 border-base-content/8';
  }
}

function sessionBadgeKo(badge) {
  switch (badge) {
    case 'REG': return '정규장';
    case 'PRE': return '프리';
    case 'AFT': return '애프터';
    default:    return '—';
  }
}

// ── 라이프사이클 ──────────────────────────────────────────────
onMounted(() => {
  document.addEventListener('click', handleDocumentClick);
  document.addEventListener('keydown', handleKeyDown);
});

onBeforeUnmount(() => {
  stopChartPoll();
  document.removeEventListener('click', handleDocumentClick);
  document.removeEventListener('keydown', handleKeyDown);
  if (holdingSearchDebounce) clearTimeout(holdingSearchDebounce);
  if (toastTimer) clearTimeout(toastTimer);
});
</script>

<style scoped>
.overflow-x-auto {
  -webkit-overflow-scrolling: touch;
}

.custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 99px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(99,102,241,0.4); }

.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.25s ease;
}
.toast-slide-enter-from,
.toast-slide-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.15s ease;
}
.fade-slide-enter-from,
.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: all 0.2s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}
</style>
