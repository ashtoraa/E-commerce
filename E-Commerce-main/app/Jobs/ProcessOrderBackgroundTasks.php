<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrderBackgroundTasks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;

   
    public $tries = 3;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle()
    {
       
        $this->generateInvoice();

     
        $this->sendNotification();

        Log::info("Asynchronous Processing Completed for Order ID: " . $this->order->id);
    }

    private function generateInvoice()
    {
        
        usleep(1500000); 
        Log::info("Invoice generated successfully for Order # " . $this->order->invoice_number);
    }

    private function sendNotification()
    {
       
        usleep(1000000); 
        Log::info("Notification email sent to User ID: " . $this->order->user_id);
    }
}