<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Finance;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\DataResource;

class ServiceOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $orders = ServiceOrder::with(['contact', 'user', 'warehouse'])
            ->where('order_number', 'like', '%' . $request->search . '%')
            ->orderBy('order_number', 'desc')
            ->paginate(10)
            ->onEachSide(0);

        return new DataResource($orders, true, "Successfully fetched service orders");
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
            'date_issued' => 'required|date',
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'phone_number' => 'required|string|max:15',
            'phone_type' => 'required|string|max:30',
            'address' => 'required|string|max:160'
        ]);

        DB::beginTransaction();
        try {
            $serviceOrder = ServiceOrder::create([
                'date_issued' => $request->date_issued,
                'order_number' => ServiceOrder::generateOrderNumber(auth()->user()->role->warehouse_id, auth()->user()->id),
                'name' => $request->name,
                'description' => $request->description,
                'phone_number' => $request->phone_number,
                'phone_type' => $request->phone_type,
                'address' => $request->address,
                'status' => 'Pending',
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id
            ]);

            Contact::firstOrCreate(
                ['phone_number' => $request->phone_number],
                [
                    'name' => $request->name,
                    'type' => 'Customer',
                    'address' => $request->address
                ]
            );


            DB::commit();
            return response()->json(['success' => true, 'message' => 'Service order created successfully', 'data' => $serviceOrder], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceOrder $serviceOrder)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ServiceOrder $serviceOrder)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ServiceOrder $serviceOrder)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceOrder $serviceOrder)
    {
        //
    }

    public function GetOrderByOrderNumber($order_number)
    {
        $order = ServiceOrder::with(['contact', 'user', 'warehouse', 'technician', 'transaction.product'])
            ->where('order_number', $order_number)
            ->first();


        return new DataResource($order, true, "Successfully fetched service order");
    }

    public function updateOrderStatus(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'status' => 'required|string'
        ]);

        $order = ServiceOrder::where('order_number', $request->order_number)->first();

        if ($order) {
            $order->status = $request->status;
            $order->technician_id = auth()->user()->id;
            $order->save();
            return response()->json(['success' => true, 'message' => 'Order status updated to ' . $request->status . '', 'data' => $order], 200);
        } else {
            return response()->json(['success' => false, 'message' => 'Service order not found'], 404);
        }
    }

    public function makePayment(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'date_issued' => 'required|date',
            'paymentAccountID' => 'required|exists:chart_of_accounts,id',
            'paymentMethod' => 'required'

        ]);

        $order = ServiceOrder::where('order_number', $request->order_number)->first();
        $totalPrice = $order->transaction()->selectRaw('SUM(quantity * price) as total')->value('total');
        $totalCost = $order->transaction()->selectRaw('SUM(quantity * cost) as total')->value('total');

        if ($order) {
            DB::beginTransaction();
            try {
                $journal = Journal::create([
                    'invoice' => $order->invoice,  // Menggunakan metode statis untuk invoice
                    'date_issued' => $request->date_issued ?? now(),
                    'transaction_type' => 'Sales',
                    'description' => 'Pembayaran Service Order ' . $order->order_number,
                    'user_id' => auth()->user()->id,
                    'warehouse_id' => auth()->user()->role->warehouse_id
                ]);

                if ($request->paymentMethod == "credit") {
                    Finance::create([
                        'date_issued' => $request->date_issued ?? now(),
                        'due_date' => $request->date_issued ?? now()->addDays(30),
                        'invoice' => $order->invoice,
                        'description' => 'Pembayaran Service Order ' . $order->order_number,
                        'bill_amount' => -$totalPrice,
                        'payment_amount' => 0,
                        'payment_nth' => 0,
                        'payment_status' => 0,
                        'finance_type' => 'Receivable',
                        'contact_id' => $order->contact->id,
                        'user_id' => auth()->user()->id,
                        'journal_id' => $journal->id
                    ]);
                }

                $journal->entries()->createMany([
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => $request->paymentAccountID,
                        'debit' => -$totalPrice,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => 16,
                        'debit' => 0,
                        'credit' => -$totalPrice
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => 10,
                        'debit' => 0,
                        'credit' => -$totalCost
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => 21,
                        'debit' => -$totalCost,
                        'credit' => 0
                    ]
                ]);

                $order->transaction()->update([
                    'transaction_type' => 'Sales'
                ]);

                $order->status = "Completed";
                $order->save();

                DB::commit();
                return response()->json(['success' => true, 'message' => 'Payment made successfully', 'data' => $order], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e->getMessage());
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Service order not found'], 404);
        }
    }

    public function addPartsToOrder(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'parts' => 'required|array'
        ]);

        $order = ServiceOrder::where('order_number', $request->order_number)->first();
        Log::info($order);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Service order not found'], 404);
        }

        $transactionExists = Transaction::where('invoice', $order->invoice)->exists();

        $newinvoice = $transactionExists ? $order->invoice : Journal::order_journal();
        $warehouseId = auth()->user()->role->warehouse_id;
        $userId = auth()->user()->id;

        DB::beginTransaction();
        try {
            foreach ($request->parts as $item) {
                $cost = Product::find($item['id'])->cost;
                Transaction::create([
                    'date_issued' => now(),
                    'invoice' => $newinvoice,
                    'product_id' => $item['id'],
                    'quantity' => -$item['quantity'],
                    'price' => $item['price'],
                    'cost' => $cost,
                    'transaction_type' => $request->transaction_type,
                    'contact_id' => 1,
                    'warehouse_id' => $warehouseId,
                    'user_id' => $userId
                ]);
            }

            $order->invoice = $newinvoice;
            $order->status = "Finished";
            $order->save();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Parts added to order successfully', 'data' => $order], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
