<?php

namespace App\Http\Controllers\Order;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\Product;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Mail\StockAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DueOrderController extends Controller
{
    public function index()
    {
        $orders = Order::where('due', '>', 0)
            ->latest()
            ->with('customer')
            ->get();

        return view('due.index', [
            'orders' => $orders
        ]);
    }

    public function show(Order $order)
    {
        $order->loadMissing(['customer', 'details']);

        return view('due.show', [
           'order' => $order
        ]);
    }

    public function edit(Order $order)
    {
        $order->loadMissing(['customer', 'details']);

        $customers = Customer::select(['id', 'name'])->get();

        return view('due.edit', [
            'order' => $order,
            'customers' => $customers
        ]);
    }

    public function update(Order $order, Request $request)
    {
        // Validate the payment amount
        $validatedData = $request->validate([
            'pay' => 'required|numeric|min:0'
        ]);
    
        // Retrieve current values
        $total = $order->total; // Ensure this is the total amount of the order
        $currentPay = $order->pay; // This should be the amount already paid
    
        // Calculate the new paid amount
        $newPay = $currentPay + $validatedData['pay'];
    
        // Calculate the new due amount
        $newDue = $total - $newPay;
       
        // Update the order with new values
        $order->update([
            'due' => $newDue,
            'pay' => $newPay
        ]);
    
        // Mark the order as complete if the due amount is zero or less
        if ($newDue <= 0) {
            $order->update([
                'order_status' => 1 // 1 represents "complete" status
            ]);
    
            // Process stock updates and send alert emails if needed
            $products = OrderDetails::where('order_id', $order->id)->get();
            $stockAlertProducts = [];
    
            foreach ($products as $product) {
                $productEntity = Product::find($product->product_id);
                $newQty = $productEntity->quantity - $product->quantity;
    
                if ($newQty < $productEntity->quantity_alert) {
                    $stockAlertProducts[] = $productEntity;
                }
    
                $productEntity->update(['quantity' => $newQty]);
            }
    
            // Send stock alert email if any products have low stock
            if (count($stockAlertProducts) > 0) {
                $listAdmin = User::pluck('email');
                Mail::to($listAdmin)->send(new StockAlert($stockAlertProducts));
            }
        }
    
        return redirect()
            ->route('due.index')
            ->with('success', 'Due amount has been updated!');
    }
    
}
