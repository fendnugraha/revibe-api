<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                $product->code = self::generateProductCode($product->category_id);
            }
        });
    }

    public static function generateProductCode(int $category): string
    {
        $categoryModel = ProductCategory::find($category);

        $lastCode = Product::where('category_id', $category)
            ->selectRaw('MAX(RIGHT(code, 4)) AS lastCode')
            ->value('lastCode');

        $nextCode = str_pad((int) $lastCode + 1, 4, '0', STR_PAD_LEFT);

        return $categoryModel->code_prefix . $nextCode;
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
            return false;
        }

        $query = StockMovement::where('product_id', $product->id)
            ->selectRaw('SUM(quantity) as totalQuantity, SUM(cost * quantity) as totalCost')
            ->whereIn('transaction_type', ['Purchase', 'Adjustment', 'OpeningBalance']);

        if (!empty($condition)) {
            $query->where($condition);
        }

        $transaction = $query->first();

        $totalQuantity = $transaction->totalQuantity ?? 0;
        $totalCost     = $transaction->totalCost ?? 0;

        $newCost = ($totalQuantity > 0) ? ($totalCost / $totalQuantity) : 0;

        Log::info('Hitung ulang cost rata-rata', [
            'totalCost' => $totalCost,
            'totalQuantity' => $totalQuantity,
            'newCost' => $newCost,
        ]);

        $product->update([
            'current_cost' => $newCost,
        ]);

        return $newCost;
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

    public function stock_movements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
