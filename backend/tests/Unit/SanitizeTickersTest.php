<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\WebSocketAgentServer;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * WebSocketAgentServer::sanitizeTickers() 보안 경계 단위 테스트.
 *
 * 배경 (2026-07-17 보안 게이트 H-1):
 *   subscribe 메시지의 tickers 는 외부(브라우저 WS 클라이언트) 입력이며,
 *   여기를 통과한 값이 그대로 토스/Yahoo 외부 API 호출·캐시 키로 흘러든다.
 *   임의 문자열(경로탈출·명령주입·과길이)을 차단하고, 상한·중복을 정리해야 한다.
 *
 * 검증 방식:
 *   소스 grep·정규식 복붙이 아니라 **실제 sanitizeTickers 메서드를 호출**해 동작을 본다.
 *   메서드는 self:: 상수와 $this->warn() 만 참조하므로,
 *   newInstanceWithoutConstructor() 로 격리 생성(앱 컨테이너·외부 서비스 미접촉)하고
 *   $output 만 버퍼 출력으로 주입한다 — 완전 hermetic(라이브 API 무접촉).
 */
class SanitizeTickersTest extends TestCase
{
    /**
     * 생성자를 우회해 커맨드를 만들고(외부 서비스 resolve 회피),
     * warn() 이 죽지 않도록 버퍼 출력만 주입한 뒤, private sanitizeTickers 를 호출한다.
     *
     * @param  mixed  $input
     * @return array<int, string>
     */
    private function sanitize($input): array
    {
        $reflection = new \ReflectionClass(WebSocketAgentServer::class);
        /** @var WebSocketAgentServer $command */
        $command = $reflection->newInstanceWithoutConstructor();

        // warn() 는 $this->output->writeln 을 호출한다 — 버퍼 출력으로 흡수(콘솔 오염·null 폭발 방지).
        $outputProp = new ReflectionProperty($command, 'output');
        $outputProp->setAccessible(true);
        $outputProp->setValue(
            $command,
            new OutputStyle(new ArrayInput([]), new BufferedOutput)
        );

        $method = new ReflectionMethod($command, 'sanitizeTickers');
        $method->setAccessible(true);

        /** @var array<int, string> $result */
        $result = $method->invoke($command, $input);

        return $result;
    }

    // ── 통과: 실사용 심볼 전종 ──────────────────────────────────────

    #[Test]
    public function test_all_real_symbols_pass_through_unchanged(): void
    {
        $symbols = [
            'MU',          // 미국 티커
            '005930',      // 국내 6자리
            '0167A0',      // 국내 신형(영문 포함)
            'NQ=F',        // 나스닥 선물
            'KOSPI_NIGHT', // 야간선물 합성
            '^KS11',       // 코스피 종합 지수
            '^KS200',      // 코스피200 지수
            'USDKRW=X',    // 환율
            'BRK.B',       // 점 포함 티커
        ];

        // 전부 유효 → 순서·값 그대로 반환되어야 한다.
        $this->assertSame($symbols, $this->sanitize($symbols));
    }

    // ── 차단: 형식 위반 ────────────────────────────────────────────

    #[Test]
    public function test_path_traversal_is_blocked(): void
    {
        $this->assertSame([], $this->sanitize(['../../etc/passwd']));
    }

    #[Test]
    public function test_command_injection_is_blocked(): void
    {
        $this->assertSame([], $this->sanitize(['MU;rm -rf']));
    }

    #[Test]
    public function test_symbol_with_space_is_blocked(): void
    {
        $this->assertSame([], $this->sanitize(['A B']));
    }

    #[Test]
    public function test_over_length_symbol_is_blocked(): void
    {
        // 16자 (상한 15 초과) → 차단
        $this->assertSame([], $this->sanitize(['ABCDEFGHIJKLMNOP']));
    }

    #[Test]
    public function test_exactly_15_chars_passes(): void
    {
        // 경계: 15자는 통과해야 한다(상한 15 포함).
        $fifteen = 'ABCDEFGHIJKLMNO';
        $this->assertSame(15, strlen($fifteen));
        $this->assertSame([$fifteen], $this->sanitize([$fifteen]));
    }

    #[Test]
    public function test_empty_string_is_blocked(): void
    {
        // 패턴은 {1,15} — 빈 문자열은 0자라 차단.
        $this->assertSame([], $this->sanitize(['']));
    }

    #[Test]
    public function test_non_string_non_numeric_values_are_dropped(): void
    {
        // 배열·객체·null·bool 은 통째로 무시, 유효 문자열만 살아남는다.
        $input = [['nested'], (object) ['x' => 1], null, true, false, 'MU'];
        $this->assertSame(['MU'], $this->sanitize($input));
    }

    #[Test]
    public function test_non_array_input_returns_empty(): void
    {
        $this->assertSame([], $this->sanitize('MU'));
        $this->assertSame([], $this->sanitize(null));
        $this->assertSame([], $this->sanitize(42));
    }

    // ── 상한 절단 ──────────────────────────────────────────────────

    #[Test]
    public function test_truncates_at_max_subscriptions(): void
    {
        // 70개 고유 유효 심볼 투입 → 상한(60)에서 잘려야 한다.
        $input = [];
        for ($i = 0; $i < 70; $i++) {
            $input[] = 'SYM' . $i; // SYM0..SYM69, 전부 유효
        }

        $result = $this->sanitize($input);

        $this->assertCount(60, $result);
        // 앞에서부터 채우므로 첫 60개가 남는다.
        $this->assertSame('SYM0', $result[0]);
        $this->assertSame('SYM59', $result[59]);
    }

    // ── 중복 제거 & 숫자 심볼 문자열 보존 ──────────────────────────

    #[Test]
    public function test_deduplicates_and_keeps_numeric_symbol_as_string(): void
    {
        // '123456' 을 두 번 → 하나로, 그리고 **string** 으로 남아야 한다.
        // 배열 키로 중복 제거하면 순수 숫자 키가 int 로 강등된다(in_array 로 방어한 이유).
        $result = $this->sanitize(['123456', '123456']);

        // assertSame 은 값+타입을 엄격 비교 → int 123456 이면 실패한다.
        $this->assertSame(['123456'], $result);
    }

    #[Test]
    public function test_numeric_int_input_is_coerced_to_string(): void
    {
        // JSON 이 숫자로 온 경우(int) → 문자열로 정규화되어 캐시 키 일관성 유지.
        $this->assertSame(['123456'], $this->sanitize([123456]));
    }

    #[Test]
    public function test_mixed_valid_invalid_preserves_order_of_valid(): void
    {
        $input = ['MU', '../bad', 'NQ=F', 'x y', 'MU', '005930'];
        // 유효만, 순서 유지, 중복(MU) 제거.
        $this->assertSame(['MU', 'NQ=F', '005930'], $this->sanitize($input));
    }
}
