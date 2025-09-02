<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Finance;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\DataResource;
use App\Models\ChartOfAccount;

class TransactionController extends Controller
{
    public $startDate;
    public $endDate;

    public function __construct()
    {
        $this->startDate = Carbon::now()->startOfDay();
        $this->endDate = Carbon::now()->endOfDay();
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $transactions = Transaction::with('contact')
            ->select(
                'invoice',
                'transaction_type',
                'status',
                DB::raw('MAX(date_issued) as date_issued'),
                DB::raw('SUM(CASE WHEN transaction_type = "Sales" THEN price * quantity ELSE cost * quantity END) as total_value')
            )
            ->when(
                $request->search,
                fn($q) =>
                $q->where('invoice', 'like', '%' . $request->search . '%')
            )
            ->when(
                $request->warehouse_id,
                fn($q, $warehouse_id) =>
                $q->where('warehouse_id', $warehouse_id)
            )
            ->groupBy('invoice', 'transaction_type', 'status')
            ->orderByDesc(DB::raw('MAX(date_issued)'))
            ->get();

        return new DataResource($transactions, true, "Successfully fetched transactions");
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
        $request->validate([
            'cart' => 'required|array',
            'transaction_type' => 'required|string',
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function purchaseOrder(Request $request)
    {
        $request->validate([
            'date_issued' => 'required|date',
            'cart' => 'required|array',
            'paymentMethod' => 'required|string|in:cash,credit',
            'discount' => 'numeric',
            'shipping_cost' => 'numeric'
        ]);

        if ($request->paymentMethod == "cash") {
            $request->validate([
                'paymentAccountID' => 'required|exists:chart_of_accounts,id'
            ]);
        }

        if ($request->paymentMethod == "credit") {
            $request->validate([
                'contact_id' => [
                    'required',
                    'exists:contacts,id',
                    Rule::notIn([1])
                ]
            ]);
        }

        $newinvoice = Journal::purchase_journal();

        DB::beginTransaction();
        try {
            $totalCost = 0;

            $transaction = Transaction::create([
                'date_issued' => $request->date_issued,
                'invoice' => $newinvoice,
                'transaction_type' => "Purchase",
                'status' => "On Delivery",
                'contact_id' => $request->contact_id ?? 1,
                'payment_method' => $request->paymentMethod == "credit" ? "Credit" : "Cash/Bank Transfer",
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id,
            ]);

            // Tahap 1: Hitung total pembelian
            foreach ($request->cart as $item) {
                $totalCost += $item['current_cost'] * $item['quantity'];
            }

            // Tahap 2: Buat transaksi & alokasikan diskon secara proporsional
            foreach ($request->cart as $item) {
                $subtotal = $item['current_cost'] * $item['quantity'];
                $diskonTeralokasi = 0;

                if ($request->discount > 0 && $totalCost > 0) {
                    // Hitung porsi diskon proporsional berdasarkan kontribusi item terhadap total cost
                    $diskonTeralokasi = ($subtotal / $totalCost) * $request->discount;
                }

                $hargaBersihTotal = $subtotal - $diskonTeralokasi;
                $itemCostAfterDiscount = $item['quantity'] > 0
                    ? $hargaBersihTotal / $item['quantity']
                    : $item['current_cost'];

                $transaction->stock_movements()->create([
                    'date_issued' => $request->date_issued,
                    'product_id' => $item['id'],
                    'quantity' => $item['quantity'],
                    'cost' => $itemCostAfterDiscount,
                    'price' => 0,
                    'warehouse_id' => auth()->user()->role->warehouse_id,
                    'transaction_type' => "Purchase"
                ]);

                // Product::updateStock($item['id'], $item['quantity'], auth()->user()->role->warehouse_id);
                Product::updateCost($item['id']);
            }

            $journal = Journal::create([
                'invoice' => $newinvoice,  // Menggunakan metode statis untuk invoice
                'date_issued' => $request->date_issued ?? now(),
                'transaction_type' => 'Purchase',
                'description' => 'Pembelian Barang',
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id
            ]);

            $journal->entries()->createMany([
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => $request->paymentMethod == "cash" ? $request->paymentAccountID : 11,
                    'debit' => 0,
                    'credit' => $totalCost
                ],
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => ChartOfAccount::INVENTORY,
                    'debit' => $totalCost,
                    'credit' => 0
                ],
            ]);

            if ($request->shipping_cost > 0) {
                $journal->entries()->createMany([
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::SHIPPING_EXPENSE,
                        'debit' => $request->shipping_cost,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => $request->paymentMethod == "cash" ? $request->paymentAccountID : 11,
                        'debit' => 0,
                        'credit' => $request->shipping_cost,
                    ],
                ]);
            }

            if ($request->paymentMethod == "credit") {
                Finance::create([
                    'date_issued' => $request->date_issued ?? now(),
                    'due_date' => Carbon::parse($request->date_issued)->addDays(30) ?? now()->addDays(30),
                    'invoice' => $newinvoice,
                    'description' => 'Pembelian Barang',
                    'bill_amount' => $totalCost + $request->shipping_cost - $request->discount,
                    'payment_amount' => 0,
                    'payment_status' => 0,
                    'payment_nth' => 0,
                    'finance_type' => "Payable",
                    'journal_id' => $journal->id,
                    'contact_id' => $request->contact_id,
                    'user_id' => auth()->user()->id,
                    'warehouse_id' => auth()->user()->role->warehouse_id
                ]);
            }


            DB::commit();

            return response()->json(['success' => true, 'message' => 'Transaction created successfully'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating transaction: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function salesOrder(Request $request)
    {
        $request->validate([
            'date_issued' => 'required|date',
            'cart' => 'required|array',
            'paymentMethod' => 'required|string|in:cash,credit',
            'discount' => 'numeric',
            'shipping_cost' => 'numeric'
        ]);

        if ($request->paymentMethod == "cash") {
            $request->validate([
                'paymentAccountID' => 'required|exists:chart_of_accounts,id'
            ]);
        }

        if ($request->paymentMethod == "credit") {
            $request->validate([
                'contact_id' => [
                    'required',
                    'exists:contacts,id',
                    Rule::notIn([1])
                ]
            ]);
        }

        $newinvoice = Journal::sales_journal();

        DB::beginTransaction();
        try {
            $totalPrice = 0;
            $totalCost = 0;
            $transaction = Transaction::create([
                'date_issued' => $request->date_issued,
                'invoice' => $newinvoice,
                'transaction_type' => "Sales",
                'status' => "Confirmed",
                'contact_id' => $request->contact_id ?? 1,
                'payment_method' => $request->paymentMethod == "credit" ? "Credit" : "Cash/Bank Transfer",
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id,
            ]);

            // Tahap 1: Hitung total pembelian
            foreach ($request->cart as $item) {
                $totalPrice += $item['price'] * $item['quantity'];
                $totalCost += $item['current_cost'] * $item['quantity'];
            }

            foreach ($request->cart as $item) {
                $subtotal = $item['price'] * $item['quantity'];
                $diskonTeralokasi = 0;

                if ($request->discount > 0 && $totalPrice > 0) {
                    // Hitung porsi diskon proporsional berdasarkan kontribusi item terhadap total cost
                    $diskonTeralokasi = ($subtotal / $totalPrice) * $request->discount;
                }

                $hargaBersihTotal = $subtotal - $diskonTeralokasi;
                $itemPriceAfterDiscount = $item['quantity'] > 0
                    ? $hargaBersihTotal / $item['quantity']
                    : $item['price'];

                $transaction->stock_movements()->create([
                    'date_issued' => $request->date_issued,
                    'product_id' => $item['id'],
                    'quantity' => -$item['quantity'],
                    'cost' => $item['current_cost'],
                    'price' => $itemPriceAfterDiscount,
                    'warehouse_id' => auth()->user()->role->warehouse_id,
                    'transaction_type' => "Sales"
                ]);
            }

            $journal = Journal::create([
                'invoice' => $newinvoice,  // Menggunakan metode statis untuk invoice
                'date_issued' => $request->date_issued ?? now(),
                'transaction_type' => 'Sales',
                'description' => 'Penjualan Barang',
                'finance_type' => $request->paymentMethod == 'credit' ? 'Receivable' : null,
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id
            ]);

            if ($request->paymentMethod == "credit") {
                Finance::create([
                    'date_issued' => $request->date_issued ?? now(),
                    'due_date' => $request->date_issued ?? now()->addDays(30),
                    'invoice' => $newinvoice,
                    'description' => 'Penjualan Barang',
                    'bill_amount' => -$totalPrice,
                    'payment_amount' => 0,
                    'payment_nth' => 0,
                    'finance_type' => 'Receivable',
                    'contact_id' => $request->contact_id,
                    'user_id' => auth()->user()->id,
                    'journal_id' => $journal->id
                ]);
            }

            $journal->entries()->createMany([
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => $request->paymentAccountID,
                    'debit' => $totalPrice,
                    'credit' => 0
                ],
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => ChartOfAccount::INCOME_FROM_SALES,
                    'debit' => 0,
                    'credit' => $totalPrice
                ],
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => ChartOfAccount::INVENTORY,
                    'debit' => 0,
                    'credit' => $totalCost
                ],
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => ChartOfAccount::COST_OF_GOODS_SOLD,
                    'debit' => $totalCost,
                    'credit' => 0
                ]
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Transaction created successfully'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating transaction: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateTrxStatus(Request $request)
    {
        DB::beginTransaction();
        try {
            $transaction = Transaction::find($request->id);
            $transaction->status = $request->status;
            $transaction->save();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Transaction status updated successfully'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating transaction status: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getTrxByWarehouse($warehouse, $startDate, $endDate, Request $request)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $transactions = Transaction::with(['contact', 'warehouse'])
            ->when($warehouse !== "all", function ($query) use ($warehouse) {
                $query->where('warehouse_id', $warehouse);
            })
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->when($request->invoice, function ($query) use ($request) {
                $query->where('invoice', 'like', '%' . $request->invoice . '%');
            })
            ->paginate(10);

        return new DataResource($transactions, true, "Successfully fetched transactions");
    }

    public function getTrxByInvoice($invoice)
    {
        $transactions = Transaction::with(['contact', 'warehouse', 'stock_movements.product'])->where('invoice', $invoice)->first();
        return new DataResource($transactions, true, "Successfully fetched transactions");
    }
}
