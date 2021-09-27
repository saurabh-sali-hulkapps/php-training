<?php

namespace App\Jobs;

use App\Models\ExciseByProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting\AvalaraCredential;
use App\Models\Setting\FailoverCheckout;
use App\Models\Setting\ProductForExcise;
use App\Models\Setting\ProductIdentifierForExcise;
use App\Models\Setting\StaticSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AvalaraExciseHelper;
use App\Services\OrderJobHelper;
use App\Traits\Helpers;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OrdersCreateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain
     *
     * @var ShopDomain
     */
    public $shopDomain;

    /**
     * The webhook data
     *
     * @var object
     */
    protected $data;

    /**
     * Create a new job instance.
     *
     * @param $shopDomain
     * @param $data
     */
    public function __construct($shopDomain, $data)
    {
        $this->shopDomain = $shopDomain;
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $orderJobHelper = new OrderJobHelper();
        $orderJobHelper->orderJobHandle($this->shopDomain, $this->data, true);
    }
}
