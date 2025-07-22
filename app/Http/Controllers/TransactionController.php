<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Http\Resources\DataResource;

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
    public function index()
    {
        $transactions = Transaction::with(['product', 'contact'])->orderBy('created_at', 'desc')->paginate(10);

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

    public function getTrxByWarehouse($warehouse, $startDate, $endDate, Request $request)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $transactions = Transaction::with(['product', 'contact'])
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where(function ($query) use ($request) {
                if ($request->search) {
                    $query->where('invoice', 'like', '%' . $request->search . '%')
                        ->orWhereHas('product', function ($query) use ($request) {
                            $query->where('name', 'like', '%' . $request->search . '%');
                        });
                } else {
                    $query;
                }
            })
            ->where(function ($query) use ($warehouse) {
                if ($warehouse === "all") {
                    $query;
                } else {
                    $query->where('warehouse_id', $warehouse);
                }
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return new DataResource($transactions, true, "Successfully fetched transactions");
    }
}
