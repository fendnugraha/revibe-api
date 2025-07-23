<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\ProductCategoryController;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user()->load('role');
});

Route::group(['middleware' => ['auth:sanctum']], function () {
    //user area

    Route::apiResource('users', UserController::class);
    Route::get('get-all-users', [UserController::class, 'getAllUsers']);
    Route::put('users/{id}/update-password', [UserController::class, 'updatePassword']);

    //end user area

    //product area
    Route::apiResource('products', ProductController::class);
    Route::apiResource('product-categories', ProductCategoryController::class);
    Route::get('get-all-products', [ProductController::class, 'getAllProducts']);
    //end product area

    //account area

    Route::apiResource('accounts', ChartOfAccountController::class);
    Route::get('category-accounts', [ChartOfAccountController::class, 'getAccountCategories']);
    Route::get('get-account-by-account-id', [ChartOfAccountController::class, 'getAccountByAccountId']);
    Route::get('balance-sheet-report/{startDate}/{endDate}', [ChartOfAccountController::class, 'balanceSheetReport']);
    Route::get('profit-loss-report/{startDate}/{endDate}', [ChartOfAccountController::class, 'profitLossReport']);
    Route::get('cash-flow-report/{startDate}/{endDate}', [ChartOfAccountController::class, 'cashFlowReport']);
    //end account area

    //contacts
    Route::apiResource('contacts', ContactController::class);
    Route::get('get-all-contacts', [ContactController::class, 'getAllContacts']);

    //end contacts

    //warehouse

    Route::apiResource('warehouse', WarehouseController::class);
    Route::get('get-all-warehouses', [WarehouseController::class, 'getAllWarehouses']);

    //end warehouse

    //order
    Route::apiResource('orders', ServiceOrderController::class);
    Route::get('get-all-orders', [ServiceOrderController::class, 'getAllOrders']);
    Route::get('get-order-by-order-number/{order_number}', [ServiceOrderController::class, 'GetOrderByOrderNumber']);
    Route::post('update-order-status', [ServiceOrderController::class, 'updateOrderStatus']);
    Route::post('make-payment', [ServiceOrderController::class, 'makePayment']);
    Route::post('add-parts-to-order', [ServiceOrderController::class, 'addPartsToOrder']);
    //end order

    //transactions
    Route::apiResource('transactions', TransactionController::class);
    Route::get('get-trx-by-warehouse/{warehouse}/{startDate}/{endDate}', [TransactionController::class, 'getTrxByWarehouse']);
});
