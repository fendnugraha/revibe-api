<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'transactions_sum_quantity' => 'float',
        'transactions_sum_cost' => 'float',
        'transactions_sum_price' => 'float'
    ];
    protected static function booted(): void
    {
        static::creating(function ($product) {
            if (!$product->code) {
                $product->code = self::generateProductCode($product->category);
            }
        });
    }

    public static function generateProductCode(string $category): string
    {
        $categoryModel = ProductCategory::where('name', $category)->firstOrFail();

        $lastCode = Product::where('category', $category)
            ->selectRaw('MAX(RIGHT(code, 4)) AS lastCode')
            ->value('lastCode');

        $nextCode = str_pad((int) $lastCode + 1, 4, '0', STR_PAD_LEFT);

        return $categoryModel->prefix . $nextCode;
    }

    public static function updateStock($id, $newQty, $warehouse_id)
    {
        $updateWarehouseStock = WarehouseStock::where('warehouse_id', $warehouse_id)->where('product_id', $id)->first();
        if ($updateWarehouseStock) {
            $updateWarehouseStock->current_stock += $newQty;
            $updateWarehouseStock->save();
        } else {
            $warehouseStock = new WarehouseStock();
            $warehouseStock->warehouse_id = $warehouse_id;
            $warehouseStock->product_id = $id;
            $warehouseStock->current_stock = $newQty;
            $warehouseStock->save();
        }

        return true;
    }

    public static function updateCost($id, $condition = [])
    {
        $product = Product::find($id);

        if (!$product) {
            return false; // Exit if the product does not exist
        }

        $transaction = Transaction::select(
            'product_id',
            DB::raw('SUM(cost * quantity) as totalCost'),
            DB::raw('SUM(quantity) as totalQuantity')
        )
            ->where('product_id', $product->id)
            ->where('transaction_type', 'Purchase')
            ->when(!empty($condition), function ($query) use ($condition) {
                $query->where($condition);
            })
            ->groupBy('product_id')
            ->first();

        if (!$transaction || $transaction->totalQuantity == 0) {
            // No transactions or zero quantity
            Product::where('id', $product->id)->update([
                'cost' => 0, // Set cost to 0 or leave unchanged based on requirements
            ]);
            return false;
        }

        // Calculate new cost
        $newCost = $transaction->totalCost / $transaction->totalQuantity;

        // Update the product's cost
        Product::where('id', $product->id)->update([
            'cost' => $newCost,
        ]);

        return true;
    }


    public static function updateCostAndStock($id, $newQty, $newStock, $newCost, $warehouse_id)
    {
        $product = Product::find($id);

        $initial_stock = $product->end_stock;
        $initial_cost = $product->cost;
        $initTotal = $initial_stock * $initial_cost;

        $newTotal = $newStock * $newCost;

        $updatedCost = ($initTotal + $newTotal) / ($initial_stock + $newStock);

        $product_log = Transaction::where('product_id', $product->id)->sum('quantity');
        $end_Stock = $product->stock + $product_log;
        Product::where('id', $product->id)->update([
            'end_Stock' => $end_Stock,
            'cost' => $updatedCost,
        ]);

        $updateWarehouseStock = WarehouseStock::where('warehouse_id', $warehouse_id)->where('product_id', $product->id)->first();
        if ($updateWarehouseStock) {
            $updateWarehouseStock->current_stock += $newQty;
            $updateWarehouseStock->save();
        } else {
            $warehouseStock = new WarehouseStock();
            $warehouseStock->warehouse_id = $warehouse_id;
            $warehouseStock->product_id = $product->id;
            $warehouseStock->init_stock = 0;
            $warehouseStock->current_stock = $newQty;
            $warehouseStock->save();
        }

        return $data = [
            'updatedCost' => $updatedCost,
            'end_Stock' => $end_Stock
        ];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function warehouseStock()
    {
        return $this->hasMany(WarehouseStock::class);
    }
}
