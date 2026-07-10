import { createApp } from 'vue'
import { createVAutoAnimate } from '@formkit/auto-animate/vue'
import './style.css'
import App from './App.vue'

const app = createApp(App)

// v-auto-animate: 리스트 추가/삭제/재정렬 FLIP 모션.
// 값은 DESIGN.md 토큰과 정합(--dur-move 200ms, --ease-out).
// prefers-reduced-motion 은 auto-animate 가 기본 존중(Web Animations API 사용 → 전역 CSS 무관).
app.directive(
  'auto-animate',
  createVAutoAnimate({ duration: 200, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' }),
)

app.mount('#app')
