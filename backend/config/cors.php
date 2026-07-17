<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    // routes/api.php 가 실제로 쓰는 메서드만 (OPTIONS 는 미들웨어가 자체 처리)
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE'],

    // 개발 오리진만 — '*' 는 아무 웹사이트의 JS 가 보유종목(quantity·average_price)을
    // 읽고 POST/DELETE 까지 하게 한다(이 API 는 인증이 없어 쿠키가 필요 없다).
    // Vite(5173)·REST(8000) 모두 localhost 바인딩이라 LAN/모바일 접속은 현재 불가능 →
    // 이 목록으로 깨지는 사용 경로는 없다. LAN 을 열게 되면 그 오리진을 여기 추가할 것.
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
