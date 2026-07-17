<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('/stocks/search', [StockController::class, 'searchStocks']);
Route::get('/stocks/{ticker}', [StockController::class, 'getStockData']);
Route::get('/stocks/{ticker}/earnings', [StockController::class, 'getEarningsDate']);
Route::get('/indices', [StockController::class, 'getIndices']);

// 포트폴리오 트래커 (STEP 2)
Route::get('/prices', [PortfolioController::class, 'prices']);

// 포트폴리오 트래커 (STEP 3)
Route::get('/portfolio/dashboard', [DashboardController::class, 'dashboard']);
Route::post('/portfolio', [PortfolioController::class, 'store']);
Route::patch('/portfolio/{id}', [PortfolioController::class, 'update']);
Route::delete('/portfolio/{id}', [PortfolioController::class, 'destroy']);

Route::post('/watchlist', [WatchlistController::class, 'store']);
Route::delete('/watchlist/{id}', [WatchlistController::class, 'destroy']);
