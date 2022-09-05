<?php

namespace App\Console\Commands;

use App\Helpers\AddressHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class LoadHDBSalesTransactionData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hdbsales:load';

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
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', '4096M');
        ini_set('post_max_size', '4096M');
        ini_set('upload_max_filesize', '4096M');

        $path = storage_path() . "/app/public/json/hdb_sales_transactions_prod.json";
        $json = json_decode(file_get_contents($path), true);

        // print("Done\n");
        // break up the massive dataset into smaller chunks to circumvent the upload limit
        $start = 0;
        $num = 0;
        $length = 1000;
        $end = 999;
        while ($start < count($json)) {
            // resize for the final iteration
            if ($end >= count($json)) {
                $end = count($json) - 1;
                $length = $end - $start + 1;
            }
            $txn_chunk = array_slice($json, $start, $length);
            $to_upload = [];
            foreach ($txn_chunk as $obj) {
                array_push($to_upload, json_encode(['index' => ["_index" => "hdb_sales_transactions_data"]]));
                array_push($to_upload, json_encode($obj));
            }
            $to_upload = join("\r\n", $to_upload);

            $client = new Client(['base_uri' => 'http://' . '35.240.168.38:9200']);
            $res = $client->request('POST', '/_bulk', [
                'auth' =>  [env('ES_USERNAME'), env('ES_PASSWORD')],
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $to_upload . "\n"
            ]);

            print("num: " . $num . "\n");
            print("start: " . $start . "\n");
            print("length: " . $length . "\n");
            print("end: " . $end . "\n\n");

            $num++;
            $start += $length;
            $end += $length;
        }

        return 0;
    }
}
