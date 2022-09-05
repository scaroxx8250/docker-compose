<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use App\Helpers\ESQueryHelper;
use Illuminate\Support\Facades\Log;

class SyncOpensearchData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'opensearch:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'One-way syncing of production cluster using staging data';

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
        // This command is written solely for running on production.

        // first get a list of all indices on staging (ignore .tasks, sort alphabetically)
        $indices_staging = SyncOpensearchData::getAllIndices('staging');
        Log::debug("Staging indices:\n" . implode("\n", $indices_staging) . "\n");

        // then get a list of all indices on production (ignore .tasks, sort alphabetically)
        $indices_production = SyncOpensearchData::getAllIndices('production');
        Log::debug("Production indices:\n" . implode("\n", $indices_production) . "\n");

        // for every index on staging,
        for ($i = 0; $i < count($indices_staging); $i++) {

            Log::debug("Checking production cluster for " . $indices_staging[$i] . ":\n");

            // get the index mapping and edit it to be suitable for index creation (remove the outer nesting of index name)
            $mapping = SyncOpensearchData::getIndexMapping($indices_staging[$i], 'staging');
            Log::debug("Mapping found for " . $indices_staging[$i] . ":\n" . $mapping . "\n");

            // check if the index already exists in production
            if (in_array($indices_staging[$i], $indices_production)) {

                Log::debug("Found.\n");

                // delete the existing index and recreate from staging
                SyncOpensearchData::deleteIndex($indices_staging[$i], 'production');
                SyncOpensearchData::createIndex($indices_staging[$i], $mapping, 'production');
            } else {

                Log::debug("Not found, creating new index:\n");
                // create that index on production with mapping
                SyncOpensearchData::createIndex($indices_staging[$i], $mapping, 'production');

            }

            Log::debug("Reindexing from staging:\n");
            //  reindex the new production index with data from the corresponding staging index
            SyncOpensearchData::reindexProductionData('staging', $indices_staging[$i], $indices_staging[$i]);

            Log::debug("Adding alias to " . $indices_staging[$i] . ":\n");
            //  add a corresponding alias to the new index
            $index_pieces = explode("_", $indices_staging[$i]);
            if (substr(end($index_pieces), 0, 1) == 'v') {
                array_pop($index_pieces);
            }
            $alias = implode("_", $index_pieces);
            SyncOpensearchData::addAlias('production', $indices_staging[$i], $alias);
        }
        
        return 0;

    }

    public static function getAllIndices($target) {
        $indices = [];
        $client = new Client();

        // get the list of indices, sorted alphabetically
        if ($target == 'staging') {
            // if running locally
            // $env = 'http://34.87.35.51:9200/';
            // if running via pipeline on prod
            $env = 'http://10.148.0.4:9200/';
            $res = $client->request('GET', $env . '_cat/indices?pretty&s=i');
        } else if ($target == 'production') {
            // if running locally
            // $env = 'http://35.240.168.38:9200/';
            // if running via pipeline on prod
            $env = 'http://127.0.0.1:9200/';
            $res = $client->request('GET', $env . '_cat/indices?pretty&s=i');
        }
        
        $table = $res->getBody();
        
        // chunk up the response using whitespace as separator
        $segments = preg_split('/[\s]+/', $table);
        
        $indices = SyncOpensearchData::formatIndicesArray($segments);

        return $indices;
    }

    public static function formatIndicesArray($segments) {
        $indices = [];
        for ($i = 0; $i < count($segments); $i++) {
            
            // isolate the index names
            if (($i - 2) % 10 == 0) {

                // ignore indices starting with '.', addresses, properties, postal_code and new_launches
                if (substr($segments[$i], 0, 1) != '.' 
                    && substr($segments[$i], 0, 9) != "addresses" 
                    && substr($segments[$i], 0, 10) != "properties" 
                    && substr($segments[$i], 0, 11) != "postal_code" 
                    && substr($segments[$i], 0, 12) != "new_launches"
                    && substr($segments[$i], 0, 27) != "hdb_sales_transactions_data") {
                    $indices[] = $segments[$i];
                }
            }
        }
        Log::debug("Formatted indices array:\n" . implode("\n", $indices) . "\n");
        return $indices;
    }

    public static function getIndexMapping($index_name, $target) {
        $client = new Client();
        if ($target == 'staging') {
            // if running locally
            // $env = 'http://34.87.35.51:9200/';
            // if running via pipeline on prod
            $env = 'http://10.148.0.4:9200/';
            $res = $client->request('GET', $env . $index_name . '/_mapping');
        } else if ($target == 'production') {
            // if running locally
            // $env = 'http://35.240.168.38:9200/';
            // if running via pipeline on prod
            $env = 'http://127.0.0.1:9200/';
            $res = $client->request('GET', $env . $index_name . '/_mapping');
        }

        $mapping = $res->getBody();

        // remove the wrapping index name
        list($index_name, $remaining) = explode(":", $mapping, 2);
        $mapping_formatted = substr($remaining, 0, strlen($remaining) - 1);

        return $mapping_formatted;
    }

    public static function createIndex($index_name, $mapping, $target = 'production') {
        if ($target == 'staging') {
            // if running locally
            // $env = '34.87.35.51:9200';
            // if running via pipeline on prod
            $env = '10.148.0.4:9200';
        } else if ($target == 'production') {
            // if running locally
            // $env = '35.240.168.38:9200';
            // if running via pipeline on prod
            $env = '127.0.0.1:9200';
        }
        $client = new Client(['base_uri' => 'http://' . $env]);
        $res = $client->request('PUT', '/' . $index_name, [
            'auth' =>  [env('ES_USERNAME'), env('ES_PASSWORD')],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $mapping . "\n"
        ]);

        Log::debug($index_name . " created on " . $target . ": \n" . $res->getBody() . "\n\n");
        return 0;
    }

    public static function deleteIndex($index_name, $target = 'production') {
        if ($target == 'staging') {
            // if running locally
            // $env = '34.87.35.51:9200';
            // if running via pipeline on prod
            $env = '10.148.0.4:9200';
        } else if ($target == 'production') {
            // if running locally
            // $env = '35.240.168.38:9200';
            // if running via pipeline on prod
            $env = '127.0.0.1:9200';
        }
        $client = new Client();
        $res = $client->delete('http://' . $env . '/' . $index_name);

        Log::debug($index_name . " deleted on " . $target . ": \n" . $res->getBody() . "\n\n");
        return 0;
    }

    public static function reindexProductionData($source, $source_index, $dest_index) {
        // if running locally
        // $env = 'http://35.240.168.38:9200/';
        // if running via pipeline on prod
        $env = 'http://127.0.0.1:9200/';
        $url = $env . "_reindex?wait_for_completion=false&requests_per_second=-1";

        // reindex from staging
        if ($source == 'staging') {
            $host = "http://10.148.0.4:9200";
            $body = array(
                "source" => array(
                    "index" => $source_index,
                    "remote" => array(
                        "host" => $host
                    )
                ),
                "dest" => array(
                    "index" => $dest_index
                )
            );
        } else if ($source == 'production') {

            // reindex from another existing prod index
            $body = array(
                "source" => array(
                    "index" => $source_index
                ),
                "dest" => array(
                    "index" => $dest_index
                )
            );
        }
        $client = new Client();
        $res = $client->request('POST', $url, [
            'auth' =>  [env('ES_USERNAME'), env('ES_PASSWORD')],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body) . "\n"
        ]);

        Log::debug($dest_index . " reindexed from " . $source . " as host: \n" . $res->getBody() . "\n");
        $taskId = substr($res->getBody(), 9, -2);
        $cli = new Client();
        $re = $cli->request('GET', $env . "_tasks/" . $taskId);
        Log::debug($re->getBody() . "\n\n");
        return 0;
    }

    public static function addAlias($target, $index_name, $alias) {
        $body = array(
            "actions" => [array(
                "add" => array(
                    "index" => $index_name,
                    "alias" => $alias
                )
            )]
        );

        if ($target == 'staging') {
            // if running locally
            // $env = 'http://34.87.35.51:9200';
            // if running via pipeline on prod
            $env = 'http://10.148.0.4:9200';
        } else if ($target == 'production') {
            // if running locally
            // $env = 'http://35.240.168.38:9200';
            // if running via pipeline on prod
            $env = 'http://127.0.0.1:9200';
        }
        $client = new Client();
        $res = $client->request('POST', $env . '/_aliases', [
            'auth' =>  [env('ES_USERNAME'), env('ES_PASSWORD')],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body) . "\n"
        ]);

        Log::debug("Alias " . $alias . " added to " . $index_name . " on " . $target . ":\n" . $res->getBody() . "\n\n");
        return 0;
    }
}
