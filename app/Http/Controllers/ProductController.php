<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\DataResource;
use App\Models\ChartOfAccount;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = Product::with('category')->when($request->search, function ($query, $search) {
            $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('code', 'like', '%' . $search . '%');
        })
            ->orderBy('name')
            ->paginate(10)->onEachSide(0);
        return new DataResource($products, true, "Successfully fetched products");
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate(
            [
                'name' => 'required|string|max:255|unique:products,name',
                'category_id' => 'required',  // Make sure category_id is present
                'price' => 'required|numeric',
                'cost' => 'required|numeric',
                'is_service' => 'required|boolean'
            ]
        );

        $product = Product::create([
            'name' => $request->name,
            'category_id' => $request->category_id,
            'price' => $request->price,
            'init_cost' => $request->cost,
            'current_cost' => $request->cost,
            'is_service' => $request->is_service
        ]);

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $request->validate(
            [
                'name' => 'required|string|max:255|unique:products,name,' . $product->id,
                'category' => 'required|exists:product_categories,name',  // Make sure category_id is present
                'price' => 'required|numeric|min:' . $product->cost,
                'cost' => 'required|numeric',
            ]
        );

        $product->update([
            'name' => $request->name,
            'category' => $request->category,
            'price' => $request->price,
            'cost' => $request->cost
        ]);

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->refresh()
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $transactionsExist = $product->transactions()->exists();
        if ($transactionsExist) {
            return response()->json([
                'success' => false,
                'message' => 'Product cannot be deleted because it has transactions'
            ], 400);
        }

        $product->delete();
        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ], 200);
    }

    public function getAllProducts()
    {
        $products = Product::orderBy('name')->get();
        return new DataResource($products, true, "Successfully fetched products");
    }

    public function getAllProductsByWarehouse($warehouse, $endDate, Request $request)
    {
        $status = $request->status;
        $products = Product::withSum([
            'stock_movements' => function ($query) use ($warehouse, $endDate, $status) {
                $query->where('warehouse_id', $warehouse)
                    ->where('date_issued', '<=', Carbon::parse($endDate)->endOfDay())
                    ->when($status, function ($query) use ($status) {
                        $query->where('status', $status);
                    });
            }
        ], 'quantity')
            ->where('is_service', false)
            ->orderBy('name')
            ->get();

        // Ambil semua stok awal sekaligus
        $warehouseStocks = WarehouseStock::where('warehouse_id', $warehouse)
            ->pluck('init_stock', 'product_id'); // hasil: [product_id => init_stock]
        // Tambahkan init_stock dan current_stock ke setiap product
        $products->transform(function ($product) use ($warehouseStocks) {
            $initStock = $warehouseStocks[$product->id] ?? 0;
            $qty = $product->stock_movements_sum_quantity ?? 0;

            $product->init_stock = $initStock;
            $product->current_stock = $initStock + $qty;

            return $product;
        });

        return new DataResource($products, true, "Successfully fetched products");
    }

    public function stockAdjustment(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric',
            'warehouse_id' => 'required|exists:warehouses,id',
            'cost' => 'required|numeric',
        ]);

        if (!$request->is_initial) {
            $request->validate([
                'date' => 'required|date',
                'account_id' => 'required|exists:chart_of_accounts,id',
                'adjustmentType' => 'required|in:in,out',
            ]);
        }

        $product = Product::findOrFail($request->product_id);

        DB::beginTransaction();
        try {
            // jika initial stock lama ada -> hapus dulu
            if ($request->is_initial) {
                $initProduct = StockMovement::where('product_id', $request->product_id)
                    ->where('is_initial', true)
                    ->where('warehouse_id', $request->warehouse_id)
                    ->first();

                if ($initProduct && $initProduct->transaction) {
                    $oldTransaction = $initProduct->transaction;

                    // hapus journal lama
                    Journal::where('invoice', $oldTransaction->invoice)->delete();

                    // hapus stock movement lama
                    $oldTransaction->stock_movements()->delete();

                    // hapus transaksi lama
                    $oldTransaction->delete();
                }
            }

            // buat transaksi baru
            $newInvoice = Journal::adjustment_journal();

            $transaction = Transaction::create([
                'date_issued' => $request->date ?? now(),
                'invoice' => $newInvoice,
                'transaction_type' => "Adjustment",
                'status' => "Active",
                'contact_id' => $request->contact_id ?? 1,
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            $journal = Journal::create([
                'invoice' => $newInvoice,
                'date_issued' => $request->date ?? now(),
                'transaction_type' => 'Adjustment',
                'description' => 'Penyesuaian Stok. Note: ' . ($request->description ?? ''),
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            if ($request->is_initial) {
                // jurnal initial stock
                $journal->entries()->createMany([
                    [
                        'chart_of_account_id' => ChartOfAccount::INVENTORY,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'chart_of_account_id' => ChartOfAccount::MODAL_EQUITY,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ],
                ]);

                $product->update(['init_cost' => $request->cost]);

                $transaction->stock_movements()->create([
                    'date_issued' => $request->date ?? now(),
                    'product_id' => $request->product_id,
                    'quantity' => $request->quantity,
                    'cost' => $request->cost,
                    'price' => 0,
                    'is_initial' => true,
                    'warehouse_id' => $request->warehouse_id,
                    'transaction_type' => "Adjustment",
                ]);
            } else {
                // jurnal adjustment biasa
                $journal->entries()->createMany([
                    [
                        'chart_of_account_id' => $request->adjustmentType == "in" ? ChartOfAccount::INVENTORY : $request->account_id,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'chart_of_account_id' => $request->adjustmentType == "in" ? $request->account_id : ChartOfAccount::INVENTORY,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ],
                ]);

                $transaction->stock_movements()->create([
                    'date_issued' => $request->date,
                    'product_id' => $request->product_id,
                    'quantity' => $request->adjustmentType == "in" ? $request->quantity : -$request->quantity,
                    'cost' => $request->cost,
                    'price' => 0,
                    'warehouse_id' => $request->warehouse_id,
                    'transaction_type' => "Adjustment",
                ]);
            }

            $newCost = Product::updateCost($product->id);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Stock adjustment successful',
                'data' => [
                    'product_id' => $product->id,
                    'new_cost' => $newCost,
                    'warehouse_id' => $request->warehouse_id,
                ]
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 400);
        }
    }

    public function stockReversal(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric',
            'warehouse_id' => 'required|exists:warehouses,id',
            'cost' => 'required|numeric',
            'account_id' => 'required|exists:chart_of_accounts,id',
        ]);

        if ($request->transaction_type == "Sales") {
            $request->validate([
                'price' => 'required|numeric',
            ]);

            $quantity = $request->quantity;
            $account_id = ChartOfAccount::INVENTORY;
        } else {
            $quantity = -$request->quantity;
            $account_id = $request->account_id;
        }

        $product = Product::findOrFail($request->product_id);

        DB::beginTransaction();
        try {
            $newInvoice = Journal::adjustment_journal();

            $transaction = Transaction::create([
                'invoice' => $newInvoice,
                'date_issued' => $request->date ?? now(),
                'transaction_type' => 'Return',
                'status' => "Active",
                'contact_id' => $request->contact_id ?? 1,
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            $journal = Journal::create([
                'invoice' => $newInvoice,
                'date_issued' => $request->date ?? now(),
                'transaction_type' => 'Return',
                'status' => "Active",
                'description' => 'Retur Stok ' . $product->name . '. Note: ' . ($request->description ?? ''),
                'contact_id' => $request->contact_id ?? 1,
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            if ($request->transaction_type == "Sales") {
                $journal->entries()->createMany([
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => $request->account_id,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->price
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::INCOME_FROM_SALES,
                        'debit' => $request->quantity * $request->price,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::INVENTORY,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::COST_OF_GOODS_SOLD,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ]
                ]);
            } else {
                $journal->entries()->createMany([
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => $account_id,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::INVENTORY,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ]
                ]);
            }

            $transaction->stock_movements()->create([
                'date_issued' => $request->date,
                'product_id' => $request->product_id,
                'quantity' => $quantity,
                'cost' => $request->cost,
                'price' => $request->price,
                'warehouse_id' => $request->warehouse_id,
                'transaction_type' => "Return",
            ]);

            Product::updateCost($product->id);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Stock reversal successful',
                'data' => [
                    'product_id' => $product->id,
                    'warehouse_id' => $request->warehouse_id,
                ]
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 400);
        }
    }
}
