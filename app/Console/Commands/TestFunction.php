<?php

namespace App\Console\Commands;

use App\Jobs\LicensedJurisdictionsJob;
use App\Jobs\OrdersCreateJob;
use App\Jobs\OrdersUpdateJob;
use App\Jobs\SyncProducts;
use App\Mail\ExciseProductDelete;
use App\Models\ProductInfo;
use App\Models\ScheduelTask;
use App\Models\Setting\AvalaraCredential;
use App\Models\Setting\FailoverCheckout;
use App\Models\Setting\StaticSetting;
use App\Models\User;
use App\Traits\Helpers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TestFunction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:function';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
    }
}
