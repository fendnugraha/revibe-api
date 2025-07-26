<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\WarehouseStock;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sparepartId = ProductCategory::where('name', 'Sparepart')->value('id');
        $accessoriesId = ProductCategory::where('name', 'Aksesoris')->value('id');
        $serviceId = ProductCategory::where('name', 'Service')->value('id');

        $products = [
            // Produk Fisik (unit)
            [
                'code' => 'LCD0001',
                'name' => 'LCD iPhone 11 Original',
                'cost' => 750000,
                'price' => 950000,
                'category_id' => $sparepartId,
            ],
            [
                'code' => 'BTR0001',
                'name' => 'Baterai Samsung A12 Original',
                'cost' => 120000,
                'price' => 180000,
                'category_id' => $sparepartId,
            ],
            [
                'code' => 'ACC0001',
                'name' => 'Kabel Data Type-C',
                'cost' => 15000,
                'price' => 30000,
                'category_id' => $accessoriesId,
            ],
            [
                'code' => 'CHG0001',
                'name' => 'Charger Vivo Fast Charging 18W',
                'cost' => 40000,
                'price' => 75000,
                'category_id' => $accessoriesId,
            ],
            [
                'code' => 'TGR0001',
                'name' => 'Tempered Glass Full Cover',
                'cost' => 8000,
                'price' => 20000,
                'category_id' => $accessoriesId,
            ],

            // Produk Jasa (service)
            [
                'code' => 'SRV0001',
                'name' => 'Ganti LCD',
                'cost' => 0,
                'price' => 400000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0002',
                'name' => 'Flashing Software',
                'cost' => 0,
                'price' => 80000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0003',
                'name' => 'Unlock Pola / Sandi',
                'cost' => 0,
                'price' => 100000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0004',
                'name' => 'Ganti Konektor Charger',
                'cost' => 0,
                'price' => 100000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0005',
                'name' => 'Jasa Pembersihan HP dari Air',
                'cost' => 0,
                'price' => 50000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
        ];

        foreach ($products as $product) {
            $product = Product::updateOrCreate(['code' => $product['code']], $product);

            WarehouseStock::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => 1,
                ],
                [
                    'init_stock' => 10, // stok awal default
                    'current_stock' => 10,
                    'balance_date' => now(),
                ]
            );
        }
    }
}
