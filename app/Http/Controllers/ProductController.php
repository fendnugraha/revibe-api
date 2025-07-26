<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\WarehouseStock;
use App\Http\Resources\DataResource;
use Carbon\Carbon;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = Product::when($request->search, function ($query, $search) {
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
                'category' => 'required',  // Make sure category_id is present
                'price' => 'required|numeric',
                'cost' => 'required|numeric',
            ]
        );

        $product = Product::create([
            'name' => $request->name,
            'category' => $request->category,
            'price' => $request->price,
            'cost' => $request->cost
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
            'transactions' => function ($query) use ($warehouse, $endDate, $status) {
                $query->where('warehouse_id', $warehouse)
                    ->where('date_issued', '<=', Carbon::parse($endDate)->endOfDay())
                    ->when($status, function ($query) use ($status) {
                        $query->where('status', $status);
                    });
            }
        ], 'quantity')->orderBy('name')->get();

        // Ambil semua stok awal sekaligus
        $warehouseStocks = WarehouseStock::where('warehouse_id', $warehouse)
            ->pluck('init_stock', 'product_id'); // hasil: [product_id => init_stock]

        // Tambahkan init_stock dan current_stock ke setiap product
        $products->transform(function ($product) use ($warehouseStocks) {
            $initStock = $warehouseStocks[$product->id] ?? 0;
            $qty = $product->transactions_sum_quantity ?? 0;

            $product->init_stock = $initStock;
            $product->current_stock = $initStock + $qty;

            return $product;
        });

        return new DataResource($products, true, "Successfully fetched products");
    }
}
