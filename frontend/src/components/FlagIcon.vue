<!--
  국기 아이콘 (KR/US) — 인라인 SVG.
  Windows(Segoe UI Emoji)는 🇰🇷/🇺🇸 국기 이모지를 "KR"/"US" 글자로 폴백 렌더하므로
  이모지 대신 SVG로 그린다(twemoji 등 무거운 의존성 없이 2개뿐).
  ponytail: 트라이그램은 배지 크기(16px)에서 실·허선 구분이 불가능하여 실선 3바로 단순화.
-->
<template>
  <!-- 대한민국 국기(태극기) -->
  <svg
    v-if="market === 'KR'"
    class="flag-icon"
    viewBox="0 0 24 16"
    role="img"
    aria-label="국내(KR)"
  >
    <rect width="24" height="16" fill="#fff" />
    <!-- 태극 (적=상, 청=하) -->
    <circle cx="12" cy="8" r="3.4" fill="#CD2E3A" />
    <path
      d="M12 4.6 a3.4 3.4 0 0 1 0 6.8 a1.7 1.7 0 0 1 0 -3.4 a1.7 1.7 0 0 0 0 -3.4 z"
      fill="#0047A0"
    />
    <!-- 4괘 (실선 3바로 단순화, 대각선 방향에 맞춰 회전) -->
    <g fill="#000">
      <g transform="translate(5 4) rotate(-56.3)">
        <rect x="-1.6" y="-1.1" width="3.2" height="0.6" />
        <rect x="-1.6" y="-0.3" width="3.2" height="0.6" />
        <rect x="-1.6" y="0.5" width="3.2" height="0.6" />
      </g>
      <g transform="translate(19 4) rotate(56.3)">
        <rect x="-1.6" y="-1.1" width="3.2" height="0.6" />
        <rect x="-1.6" y="-0.3" width="3.2" height="0.6" />
        <rect x="-1.6" y="0.5" width="3.2" height="0.6" />
      </g>
      <g transform="translate(5 12) rotate(56.3)">
        <rect x="-1.6" y="-1.1" width="3.2" height="0.6" />
        <rect x="-1.6" y="-0.3" width="3.2" height="0.6" />
        <rect x="-1.6" y="0.5" width="3.2" height="0.6" />
      </g>
      <g transform="translate(19 12) rotate(-56.3)">
        <rect x="-1.6" y="-1.1" width="3.2" height="0.6" />
        <rect x="-1.6" y="-0.3" width="3.2" height="0.6" />
        <rect x="-1.6" y="0.5" width="3.2" height="0.6" />
      </g>
    </g>
  </svg>

  <!-- 미국 국기 (13줄무늬 + 유니온) -->
  <svg
    v-else
    class="flag-icon"
    viewBox="0 0 24 16"
    role="img"
    aria-label="미국(US)"
  >
    <rect width="24" height="16" fill="#B22234" />
    <!-- 흰 줄무늬 6개 (13줄무늬, stripe 높이 ≈1.23) -->
    <g fill="#fff">
      <rect y="1.23" width="24" height="1.23" />
      <rect y="3.69" width="24" height="1.23" />
      <rect y="6.15" width="24" height="1.23" />
      <rect y="8.62" width="24" height="1.23" />
      <rect y="11.08" width="24" height="1.23" />
      <rect y="13.54" width="24" height="1.23" />
    </g>
    <!-- 유니온(청) — 상단 7줄무늬 폭 -->
    <rect width="9.6" height="8.62" fill="#3C3B6E" />
    <!-- 별 (점으로 단순화, 3×3 격자) -->
    <g fill="#fff">
      <circle cx="1.9" cy="1.7" r="0.42" /><circle cx="4.8" cy="1.7" r="0.42" /><circle cx="7.7" cy="1.7" r="0.42" />
      <circle cx="1.9" cy="4.3" r="0.42" /><circle cx="4.8" cy="4.3" r="0.42" /><circle cx="7.7" cy="4.3" r="0.42" />
      <circle cx="1.9" cy="6.9" r="0.42" /><circle cx="4.8" cy="6.9" r="0.42" /><circle cx="7.7" cy="6.9" r="0.42" />
    </g>
  </svg>
</template>

<script setup>
defineProps({
  // 'KR' | 'US' — 그 외 값은 US 국기로 폴백
  market: { type: String, default: 'US' },
});
</script>
