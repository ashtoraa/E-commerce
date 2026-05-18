<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product as ProductModel;

class Product extends Controller
{
    public function allProducts()
    {
        return ProductModel::all();
    }

    public function showAny($id)
    {
        $product = ProductModel::find($id);

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        return $product;
    }

    // 🔹 إضافة منتج جديد
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
        ]);

        // رأس المال لا يجب أن يكون أكبر من سعر البيع غالباً
        if ($validated['cost_price'] > $validated['price']) {
            return response()->json([
                'message' => 'Cost price cannot be greater than selling price'
            ], 400);
        }

        $validated['user_id'] = auth()->id();

        $product = ProductModel::create($validated);

        return response()->json([
            'message' => 'Product created successfully',
            'data' => $product
        ]);
    }

    // 🔹 عرض منتجات المستخدم الحالي
    public function MyProducts()
    {
        $products = ProductModel::where('user_id', auth()->id())->get();

        return response()->json([
            'data' => $products
        ]);
    }

    // 🔹 تعديل الكمية فقط
    public function updateQuantity(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:0'
        ]);

        $product = ProductModel::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        $product->quantity = $request->quantity;
        $product->save();

        return response()->json([
            'message' => 'Quantity updated successfully',
            'data' => $product
        ]);
    }

    // 🔹 تعديل السعر وتكلفة المنتج
    public function updatePrices(Request $request, $id)
    {
        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
        ]);

        if ($validated['cost_price'] > $validated['price']) {
            return response()->json([
                'message' => 'Cost price cannot be greater than selling price'
            ], 400);
        }

        $product = ProductModel::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        $product->price = $validated['price'];
        $product->cost_price = $validated['cost_price'];
        $product->save();

        return response()->json([
            'message' => 'Product prices updated successfully',
            'data' => $product
        ]);
    }

    // 🔹 حذف المنتج
    public function destroy($id)
    {
        $product = ProductModel::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ]);
    }
}