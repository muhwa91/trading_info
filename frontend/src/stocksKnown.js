/**
 * stocksKnown.js
 * 한글·초성 즉시 검색을 위한 공유 인기종목 목록.
 * Watchlist.vue와 PortfolioDashboard.vue가 함께 사용한다.
 *
 * 필드:
 *   ticker  — Yahoo Finance 형식 (KR은 .KS/.KQ 포함)
 *   koName  — 한글 이름
 *   enName  — 영문 공식명
 *   chosung — 한글 초성 (koName의 초성을 이어붙인 문자열)
 */
export const SEARCHABLE_STOCKS = [
  { ticker: 'TSLA',      koName: '테슬라',                           enName: 'Tesla, Inc.',                                  chosung: 'ㅌㅅㄹ' },
  { ticker: 'AAPL',      koName: '애플',                             enName: 'Apple Inc.',                                   chosung: 'ㅇㅍ' },
  { ticker: 'NVDA',      koName: '엔비디아',                         enName: 'NVIDIA Corporation',                           chosung: 'ㅇㅂㄷㅇ' },
  { ticker: 'MSFT',      koName: '마이크로소프트',                   enName: 'Microsoft Corporation',                        chosung: 'ㅁㅇㅋㄹㅅㅍㅌ' },
  { ticker: 'AMZN',      koName: '아마존',                           enName: 'Amazon.com, Inc.',                             chosung: 'ㅇㅁㅈ' },
  { ticker: 'GOOGL',     koName: '구글',                             enName: 'Alphabet Inc.',                                chosung: 'ㄱㄱ' },
  { ticker: 'MU',        koName: '마이크론 테크놀로지',              enName: 'Micron Technology, Inc.',                      chosung: 'ㅁㅇㅋㄹㅌㅋㄴㄹㅈ' },
  { ticker: 'META',      koName: '메타',                             enName: 'Meta Platforms, Inc.',                         chosung: 'ㅁㅌ' },
  { ticker: 'NFLX',      koName: '넷플릭스',                        enName: 'Netflix, Inc.',                                chosung: 'ㄴㅍㄹㅅ' },
  { ticker: 'AMD',       koName: '에이엠디',                         enName: 'Advanced Micro Devices, Inc.',                 chosung: 'ㅇㅇㅁㄷ' },
  { ticker: 'INTC',      koName: '인텔',                             enName: 'Intel Corporation',                            chosung: 'ㅇㅌ' },
  { ticker: 'AVGO',      koName: '브로드컴',                         enName: 'Broadcom Inc.',                                chosung: 'ㅂㄹㄷㅋ' },
  { ticker: 'QCOM',      koName: '퀄컴',                             enName: 'Qualcomm Incorporated',                        chosung: 'ㅋㅋ' },
  { ticker: 'BABA',      koName: '알리바바',                         enName: 'Alibaba Group Holding Limited',                chosung: 'ㅇㄹㅂㅂ' },
  { ticker: 'NKE',       koName: '나이키',                           enName: 'Nike, Inc.',                                   chosung: 'ㄴㅇㅋ' },
  { ticker: 'SBUX',      koName: '스타벅스',                        enName: 'Starbucks Corporation',                        chosung: 'ㅅㅌㅂㅅ' },
  { ticker: 'DIS',       koName: '디즈니',                           enName: 'The Walt Disney Company',                      chosung: 'ㄷㅈㄴ' },
  { ticker: 'TSM',       koName: '티에스엠씨',                      enName: 'Taiwan Semiconductor Manufacturing',           chosung: 'ㅌㅇㅅㅁㅆ' },
  { ticker: 'COIN',      koName: '코인베이스',                      enName: 'Coinbase Global, Inc.',                        chosung: 'ㅋㅇㅂㅇㅅ' },
  { ticker: 'PLTR',      koName: '팔란티어',                        enName: 'Palantir Technologies Inc.',                   chosung: 'ㅍㄹㅌㅇ' },
  { ticker: 'SOXL',      koName: '속슬 (반도체 3배 레버리지 ETF)', enName: 'Direxion Daily Semiconductor Bull 3X',          chosung: 'ㅅㅅ' },
  { ticker: 'TQQQ',      koName: '티큐큐큐 (나스닥 3배 레버리지 ETF)', enName: 'ProShares UltraPro QQQ',                   chosung: 'ㅌㅋㅋㅋ' },
  { ticker: 'MSTR',      koName: '마이크로스트래티지',              enName: 'MicroStrategy Incorporated',                   chosung: 'ㅁㅇㅋㄹㅅㅌㄹㅌㅈ' },
  { ticker: 'RIVN',      koName: '리비안',                           enName: 'Rivian Automotive, Inc.',                      chosung: 'ㄹㅂㅇ' },
  { ticker: 'SHOP',      koName: '쇼피파이',                        enName: 'Shopify Inc.',                                 chosung: 'ㅅㅍㅍㅇ' },
  { ticker: 'SNOW',      koName: '스노우플레이크',                  enName: 'Snowflake Inc.',                               chosung: 'ㅅㄴㅇㅍㄹㅇㅋ' },
  { ticker: 'CRWD',      koName: '크라우드스트라이크',              enName: 'CrowdStrike Holdings, Inc.',                   chosung: 'ㅋㄹㅇㄷㅅㅌㄹㅇㅋ' },
  { ticker: 'PANW',      koName: '팔로알토 네트웍스',               enName: 'Palo Alto Networks, Inc.',                     chosung: 'ㅍㄹㅇㄹㅌㄴㅌㅇㅅ' },
  { ticker: 'ARM',       koName: '암홀딩스',                        enName: 'Arm Holdings plc',                             chosung: 'ㅇㅎㄷㅇㅅ' },
  { ticker: 'SMCI',      koName: '수퍼마이크로컴퓨터',              enName: 'Super Micro Computer, Inc.',                   chosung: 'ㅅㅍㅁㅇㅋㄹㅋㅍㅇㅌ' },
  { ticker: '0167A0.KS', koName: 'SOL AI반도체TOP2플러스',         enName: 'SOL AI Semiconductor TOP2 Plus ETF',           chosung: 'ㅅㅇㅇㅂㄷㅊㅌㅍㅍㄹㅅ' },
  { ticker: '005930.KS', koName: '삼성전자',                        enName: 'Samsung Electronics Co., Ltd.',                chosung: 'ㅅㅅㅈㅈ' },
  { ticker: '000660.KS', koName: 'SK하이닉스',                     enName: 'SK Hynix Inc.',                                chosung: 'ㅅㅋㅎㅇㄴㅅ' },
  { ticker: '009150.KS', koName: '삼성전기',                        enName: 'Samsung Electro-Mechanics Co., Ltd.',          chosung: 'ㅅㅅㅈㄱ' },
  { ticker: '035420.KS', koName: '네이버',                          enName: 'NAVER Corporation',                            chosung: 'ㄴㅇㅂ' },
  { ticker: '035720.KS', koName: '카카오',                          enName: 'Kakao Corp.',                                  chosung: 'ㅋㅋㅇ' },
  { ticker: '068270.KS', koName: '셀트리온',                        enName: 'Celltrion, Inc.',                              chosung: 'ㅅㅌㄹㅇ' },
  { ticker: '051910.KS', koName: 'LG화학',                          enName: 'LG Chem, Ltd.',                                chosung: 'ㄹㅈㅎㅎ' },
  { ticker: '006400.KS', koName: '삼성SDI',                         enName: 'Samsung SDI Co., Ltd.',                        chosung: 'ㅅㅅㅅㄷㅇ' },
  { ticker: '207940.KS', koName: '삼성바이오로직스',                enName: 'Samsung Biologics Co., Ltd.',                  chosung: 'ㅅㅅㅂㅇㅇㄹㅈㅅ' },
  { ticker: '373220.KS', koName: 'LG에너지솔루션',                  enName: 'LG Energy Solution, Ltd.',                     chosung: 'ㄹㅈㅇㄴㄹㅈㅅㄹㅅ' },
];

/**
 * 종목 목록에서 로컬 매칭을 수행한다.
 *
 * @param {string} query  사용자 입력 (trim 완료 상태 권장)
 * @param {string} mode   'kr' | 'us' | 'all'
 * @returns {{ ticker, name, subName, isKorean }[]}
 */
export function localSearch(query, mode) {
  if (!query) return [];

  const q = query.toLowerCase();

  return SEARCHABLE_STOCKS.filter(stock => {
    const isKorean = /(\.KS|\.KQ)$/i.test(stock.ticker) || /^\d/.test(stock.ticker);
    if (mode === 'kr' && !isKorean) return false;
    if (mode === 'us' && isKorean) return false;

    return (
      stock.ticker.toLowerCase().includes(q) ||
      stock.koName.toLowerCase().includes(q) ||
      stock.enName.toLowerCase().includes(q) ||
      stock.chosung.includes(q)
    );
  }).map(stock => {
    const isKorean = /(\.KS|\.KQ)$/i.test(stock.ticker) || /^\d/.test(stock.ticker);
    return {
      ticker: stock.ticker,
      name: stock.koName,
      subName: stock.enName,
      isKorean,
    };
  });
}

/**
 * KR 티커에서 .KS/.KQ 접미사를 제거한다.
 * 백엔드 DB의 symbol 컬럼은 접미사가 없는 형태(예: '005930', '0167A0')를 사용한다.
 *
 * @param {string} ticker
 * @returns {string}
 */
export function normalizeKrTicker(ticker) {
  return ticker.replace(/(\.KS|\.KQ)$/i, '');
}
