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
                'init_cost' => 100000,
                'current_cost' => 150000,
                'price' => 950000,
                'category_id' => $sparepartId,
            ],
            [
                'code' => 'BTR0001',
                'name' => 'Baterai Samsung A12 Original',
                'init_cost' => 50000,
                'current_cost' => 80000,
                'price' => 180000,
                'category_id' => $sparepartId,
            ],
            [
                'code' => 'ACC0001',
                'name' => 'Kabel Data Type-C',
                'init_cost' => 20000,
                'current_cost' => 30000,
                'price' => 30000,
                'category_id' => $accessoriesId,
            ],
            [
                'code' => 'CHG0001',
                'name' => 'Charger Vivo Fast Charging 18W',
                'init_cost' => 50000,
                'current_cost' => 75000,
                'price' => 75000,
                'category_id' => $accessoriesId,
            ],
            [
                'code' => 'TGR0001',
                'name' => 'Tempered Glass Full Cover',
                'init_cost' => 20000,
                'current_cost' => 30000,
                'price' => 20000,
                'category_id' => $accessoriesId,
            ],

            // Produk Jasa (service)
            [
                'code' => 'SRV0001',
                'name' => 'Ganti LCD',
                'init_cost' => 0,
                'current_cost' => 0,
                'price' => 400000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0002',
                'name' => 'Flashing Software',
                'init_cost' => 0,
                'current_cost' => 0,
                'price' => 80000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0003',
                'name' => 'Unlock Pola / Sandi',
                'init_cost' => 0,
                'current_cost' => 0,
                'price' => 100000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0004',
                'name' => 'Ganti Konektor Charger',
                'init_cost' => 0,
                'current_cost' => 0,
                'price' => 100000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
            [
                'code' => 'SRV0005',
                'name' => 'Jasa Pembersihan HP dari Air',
                'init_cost' => 0,
                'current_cost' => 0,
                'price' => 50000,
                'is_service' => true,
                'category_id' => $serviceId,
            ],
        ];

        foreach ($products as $product) {
            Product::create([
                'code' => $product['code'],
                'name' => $product['name'],
                'init_cost' => $product['init_cost'],
                'current_cost' => $product['current_cost'],
                'price' => $product['price'],
                'is_service' => $product['is_service'] ?? false,
                'category_id' => $product['category_id'],
            ]);
        }
    }
}
