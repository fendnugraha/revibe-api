<?php

namespace App\Http\Controllers;

use App\Models\Contact;
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
}
